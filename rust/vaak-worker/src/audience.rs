//! Recheck durable audiences before serving cached or newly hydrated cards.
use anyhow::Result;
use serde_json::Value;
use std::collections::{HashMap, HashSet};
use tokio_postgres::Client;

#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Surface { Home, Profile, Local, Federated }

impl Surface {
    pub fn timeline(view: &str) -> Self {
        match view { "local" => Self::Local, "feed" => Self::Federated, _ => Self::Home }
    }
}

#[derive(Default)]
struct Audience {
    visibility: String,
    author: String,
    recipients: HashSet<String>,
}

fn key(uri: &str) -> String { uri.trim().split('#').next().unwrap_or("").trim_end_matches('/').to_string() }

fn allowed(a: &Audience, viewer: &str, following: &HashSet<String>, surface: Surface, nested: bool) -> bool {
    let vis = a.visibility.trim().to_ascii_lowercase();
    // Public timeline discovery excludes unlisted posts. A quote is a direct
    // reference, so its public/unlisted target remains readable.
    if !nested && matches!(surface, Surface::Local | Surface::Federated | Surface::Profile) {
        return vis == "public";
    }
    if matches!(vis.as_str(), "public" | "unlisted") { return true; }
    if viewer.is_empty() { return false; }
    if a.author == viewer { return true; }
    if vis == "local" { return a.author.starts_with("https://mkultra.monster/users/"); }
    if a.recipients.contains(viewer) { return true; }
    matches!(vis.as_str(), "private" | "followers" | "followers_only") && following.contains(&a.author)
}

fn children(st: &Value) -> Vec<&Value> {
    let mut out = Vec::new();
    if let Some(v) = st.get("reblog").filter(|v| v.is_object()) { out.push(v); }
    for field in ["quote", "vaak_quote_preview"] {
        if let Some(v) = st.get(field).filter(|v| v.is_object()) {
            out.push(v.get("quoted_status").or_else(|| v.get("status")).filter(|v| v.is_object()).unwrap_or(v));
        }
    }
    out
}

fn collect(st: &Value, ids: &mut HashSet<String>, depth: usize) {
    if depth > 16 { return; }
    if let Some(uri) = st.get("uri").or_else(|| st.get("url")).and_then(Value::as_str) {
        let id = key(uri);
        if id.starts_with("https://") || id.starts_with("http://") { ids.insert(id); }
    }
    for child in children(st) { collect(child, ids, depth + 1); }
}

fn tree_allowed(st: &Value, records: &HashMap<String, Audience>, viewer: &str, following: &HashSet<String>, surface: Surface, depth: usize) -> bool {
    if depth > 16 { return false; }
    let id = key(st.get("uri").or_else(|| st.get("url")).and_then(Value::as_str).unwrap_or(""));
    if let Some(a) = records.get(&id) {
        if !allowed(a, viewer, following, surface, depth > 0) { return false; }
    } else if id.starts_with("https://mkultra.monster/users/") && id.contains("/notes/") {
        // Deleted local notes and missing durable audiences cannot be rescued
        // by an old Redis card that still claims to be public.
        return false;
    } else {
        let a = Audience {
            visibility: st.get("visibility").and_then(Value::as_str).unwrap_or("public").into(),
            author: st.get("account").and_then(|a| a.get("uri")).and_then(Value::as_str).map(key).unwrap_or_default(),
            ..Default::default()
        };
        if !allowed(&a, viewer, following, surface, depth > 0) { return false; }
    }
    children(st).into_iter().all(|child| tree_allowed(child, records, viewer, following, surface, depth + 1))
}

pub async fn filter_statuses(db: &Client, owner: i64, surface: Surface, statuses: &mut Vec<Value>) -> Result<()> {
    if statuses.is_empty() { return Ok(()); }
    let mut ids = HashSet::new();
    for st in statuses.iter() { collect(st, &mut ids, 0); }
    let ids: Vec<String> = ids.into_iter().collect();
    let mut records = HashMap::new();
    // Restrict the lookup to this bounded page, including quote/boost targets.
    for row in db.query(
        "SELECT DISTINCT ON (rtrim(object_id, '/')) rtrim(object_id, '/'),
                COALESCE(visibility, 'public'), rtrim(COALESCE(actor_id, ''), '/')
         FROM events WHERE type = ANY(ARRAY['Create','Update','Quote','QuotePost'])
           AND action_taken = ANY(ARRAY['log','local_observe','local_fav_update'])
           AND (object_id = ANY($1) OR object_id = ANY($2))
         ORDER BY rtrim(object_id, '/'), id DESC",
        &[&ids, &ids.iter().map(|id| format!("{id}/")).collect::<Vec<_>>()],
    ).await? {
        records.insert(row.get::<_, String>(0), Audience { visibility: row.get(1), author: row.get(2), ..Default::default() });
    }
    let variants: Vec<String> = ids.iter().flat_map(|id| [id.clone(), format!("{id}/")]).collect();
    for row in db.query(
        "SELECT id, COALESCE(visibility, 'public'), COALESCE(to_json, '[]'), COALESCE(cc_json, '[]')
         FROM outbox_notes WHERE id = ANY($1)", &[&variants],
    ).await? {
        let id = key(&row.get::<_, String>(0));
        let mut recipients = HashSet::new();
        for col in [2, 3] {
            if let Ok(Value::Array(values)) = serde_json::from_str::<Value>(&row.get::<_, String>(col)) {
                for v in values { if let Some(s) = v.as_str() { recipients.insert(key(s)); } }
            }
        }
        let author = id.split("/notes/").next().unwrap_or("").to_string();
        records.insert(id, Audience { visibility: row.get(1), author, recipients });
    }
    let mut viewer = String::new();
    let mut following = HashSet::new();
    if owner > 0 {
        if let Some(row) = db.query_opt("SELECT actor_id FROM ap_users WHERE id = $1 AND disabled_at IS NULL", &[&owner]).await? {
            viewer = key(&row.get::<_, String>(0));
        }
        if !viewer.is_empty() {
            // An owner-scoped delivered mention is proof of an explicit recipient.
            for row in db.query("SELECT object_id FROM mentions WHERE owner_user_id = $1 AND deleted_at IS NULL AND activity_type IN ('Create','Update','Quote','QuotePost') AND object_id = ANY($2)", &[&owner, &variants]).await? {
                let id = key(&row.get::<_, String>(0));
                if let Some(a) = records.get_mut(&id) { a.recipients.insert(viewer.clone()); }
            }
            // Accepted local followers are authoritative. Following a private
            // local account while its request is pending grants no audience.
            for row in db.query(
                "SELECT rtrim(owner_actor_id, '/') FROM followers WHERE rtrim(actor_id, '/') = $1
                 UNION SELECT rtrim(actor_id, '/') FROM following WHERE rtrim(owner_actor_id, '/') = $1
                   AND actor_id NOT LIKE 'https://mkultra.monster/users/%'", &[&viewer],
            ).await? { following.insert(row.get::<_, String>(0)); }
        }
    }
    statuses.retain(|st| tree_allowed(st, &records, &viewer, &following, surface, 0));
    for st in statuses { stamp_visibility(st, &records, 0); }
    Ok(())
}

fn stamp_visibility(st: &mut Value, records: &HashMap<String, Audience>, depth: usize) {
    if depth > 16 { return; }
    let id = key(st.get("uri").or_else(|| st.get("url")).and_then(Value::as_str).unwrap_or(""));
    if let Some(a) = records.get(&id) { st["visibility"] = Value::String(a.visibility.clone()); }
    if let Some(v) = st.get_mut("reblog").filter(|v| v.is_object()) { stamp_visibility(v, records, depth + 1); }
    for field in ["quote", "vaak_quote_preview"] {
        if let Some(v) = st.get_mut(field).filter(|v| v.is_object()) {
            if v.get("quoted_status").is_some() {
                if let Some(child) = v.get_mut("quoted_status") { stamp_visibility(child, records, depth + 1); }
            } else if v.get("status").is_some() {
                if let Some(child) = v.get_mut("status") { stamp_visibility(child, records, depth + 1); }
            } else { stamp_visibility(v, records, depth + 1); }
        }
    }
}

/// Off-page self-thread parents must pass the same rules as visible rows.
pub async fn filter_parents(db: &Client, owner: i64, surface: Surface, parents: &mut HashMap<String, Value>) -> Result<()> {
    if parents.is_empty() { return Ok(()); }
    let keys: Vec<String> = parents.keys().cloned().collect();
    let mut values: Vec<Value> = keys.iter().filter_map(|k| parents.remove(k)).collect();
    let hidden = crate::hidden::load_hidden_sets(db, owner).await?;
    filter_statuses(db, owner, surface, &mut values).await?;
    let moderation = crate::hidden::load_viewer_moderation(db, owner).await?;
    crate::notif_embed::stamp_viewer_moderation(&mut values, &moderation);
    for st in values {
        if !(if surface == Surface::Profile { crate::hidden::status_hidden_on_profile(&st, &hidden) } else { crate::hidden::status_hidden(&st, &hidden) }) {
            let id = key(st.get("uri").and_then(Value::as_str).unwrap_or(""));
            if let Some(k) = keys.iter().find(|k| key(k) == id) { parents.insert(k.clone(), st); }
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn private_audiences_require_accepted_follow_or_explicit_recipient() {
        let a = Audience { visibility: "private".into(), author: "https://mkultra.monster/users/alice".into(), ..Default::default() };
        let viewer = "https://mkultra.monster/users/bob";
        assert!(!allowed(&a, "", &HashSet::new(), Surface::Home, false));
        assert!(!allowed(&a, viewer, &HashSet::new(), Surface::Home, false));
        let following = HashSet::from([a.author.clone()]);
        assert!(allowed(&a, viewer, &following, Surface::Home, false));
        assert!(!allowed(&a, viewer, &following, Surface::Local, false));
        let mut direct = Audience { visibility: "direct".into(), ..a };
        assert!(!allowed(&direct, viewer, &following, Surface::Home, false));
        direct.recipients.insert(viewer.into());
        assert!(allowed(&direct, viewer, &following, Surface::Home, false));
    }

    #[test]
    fn unlisted_is_directly_readable_but_not_public_discovery() {
        let a = Audience { visibility: "unlisted".into(), ..Default::default() };
        assert!(allowed(&a, "", &HashSet::new(), Surface::Home, false));
        assert!(allowed(&a, "", &HashSet::new(), Surface::Federated, true));
        assert!(!allowed(&a, "", &HashSet::new(), Surface::Federated, false));
        assert!(!allowed(&a, "", &HashSet::new(), Surface::Profile, false));
    }

    #[test]
    fn stale_public_card_cannot_override_durable_private_quote_or_deleted_note() {
        let uri = "https://mkultra.monster/users/alice/notes/secret";
        let st = json!({"uri":"at://friend/post/x", "quote":{"quoted_status":{"uri":uri,"visibility":"public"}}});
        let records = HashMap::from([(uri.into(), Audience {visibility:"private".into(), ..Default::default()})]);
        assert!(!tree_allowed(&st, &records, "", &HashSet::new(), Surface::Home, 0));
        assert!(!tree_allowed(&json!({"uri":uri,"visibility":"public"}), &HashMap::new(), "", &HashSet::new(), Surface::Home, 0));
    }
}

/// Recheck cached notification cards against current viewer rules.
pub async fn filter_notifications(db: &Client, owner: i64, items: &mut Vec<Value>) -> Result<()> {
    crate::home_hydrate_ranked::refresh_local_accounts(db, items).await?;
    let hidden = crate::hidden::load_hidden_sets(db, owner).await?;
    let mut statuses = Vec::new();
    for (idx, item) in items.iter().enumerate() {
        if let Some(st) = item.get("status").filter(|s| s.is_object()) {
            let mut st = st.clone();
            st["_vaak_policy_index"] = Value::from(idx);
            statuses.push(st);
        }
    }
    filter_statuses(db, owner, Surface::Home, &mut statuses).await?;
    let mut allowed = HashMap::new();
    for mut st in statuses {
        let idx = st["_vaak_policy_index"].as_u64().unwrap() as usize;
        st.as_object_mut().unwrap().remove("_vaak_policy_index");
        allowed.insert(idx, st);
    }
    let mut idx = 0;
    items.retain_mut(|item| {
        let original_idx = idx;
        idx += 1;
        if crate::hidden::status_hidden(item, &hidden) { return false; }
        if item.get("status").is_some_and(Value::is_object) {
            let Some(st) = allowed.remove(&original_idx) else { return false; };
            if crate::hidden::status_hidden(&st, &hidden) { return false; }
            item["status"] = st;
        }
        true
    });
    Ok(())
}
