//! Mute / block sets for notification filtering (parity with ap_row_is_hidden).

use anyhow::{Context, Result};
use serde_json::Value;
use std::collections::{HashMap, HashSet};
use tokio_postgres::Client;

#[derive(Debug, Default, Clone)]
pub struct HiddenSets {
    pub muted_actors: HashSet<String>,
    pub user_blocked_actors: HashSet<String>,
    pub user_blocked_domains: HashSet<String>,
    pub instance_blocked_actors: HashSet<String>,
    pub instance_blocked_domains: HashSet<String>,
    pub muted_phrases: Vec<String>,
}

impl HiddenSets {
    pub fn is_hidden(&self, actor_id: &str) -> bool {
        self.actor_hidden(actor_id, false)
    }

    fn actor_hidden(&self, actor_id: &str, ignore_personal_mute: bool) -> bool {
        let actor = actor_id.trim().trim_end_matches('/');
        if actor.is_empty() {
            return false;
        }
        for alias in actor_aliases(actor) {
            if (!ignore_personal_mute && self.muted_actors.contains(&alias))
                || self.user_blocked_actors.contains(&alias)
                || self.instance_blocked_actors.contains(&alias)
            {
                return true;
            }
        }
        let host = host_of(actor);
        if let Some(host) = host {
            let host_l = host.to_ascii_lowercase();
            if self.user_blocked_domains.contains(&host_l)
                || self.instance_blocked_domains.contains(&host_l)
            {
                return true;
            }
        }
        false
    }
}

/// Apply viewer moderation to already-hydrated cards as well as ranked IDs.
/// Cached envelopes can outlive a mute/block change, so both the card author
/// and a boost/reblog's underlying author must be checked at read time.
pub fn status_hidden(status: &Value, hidden: &HiddenSets) -> bool {
    status_hidden_at(status, hidden, 0, false)
}

/// A directly opened profile may show its muted author, while nested authors
/// still obey personal mutes. Blocks and muted words apply throughout.
pub fn status_hidden_on_profile(status: &Value, hidden: &HiddenSets) -> bool {
    status_hidden_at(status, hidden, 0, true)
}

fn status_hidden_at(status: &Value, hidden: &HiddenSets, depth: usize, profile_root: bool) -> bool {
    if depth > 16 { return true; }
    if let Some(account) = status.get("account") {
        if ["uri", "url", "id"].iter().any(|field| account.get(field).and_then(Value::as_str).is_some_and(|actor| hidden.actor_hidden(actor, profile_root && depth == 0))) {
            return true;
        }
    }
    let text = ["content", "spoiler_text"].iter()
        .filter_map(|field| status.get(field).and_then(Value::as_str))
        .map(normalize_muted_text).collect::<Vec<_>>().join(" ");
    if hidden.muted_phrases.iter().any(|phrase| !phrase.is_empty() && text.contains(phrase)) { return true; }
    if let Some(reblog) = status.get("reblog").filter(|v| v.is_object()) {
        if status_hidden_at(reblog, hidden, depth + 1, false) { return true; }
    }
    for field in ["quote", "vaak_quote_preview"] {
        if let Some(quote) = status.get(field).filter(|v| v.is_object()) {
            let nested = quote.get("quoted_status").or_else(|| quote.get("status")).filter(|v| v.is_object()).unwrap_or(quote);
            if status_hidden_at(nested, hidden, depth + 1, false) { return true; }
        }
    }
    false
}

fn normalize_muted_text(text: &str) -> String {
    let decoded = crate::home_hydrate_ranked::html_entity_decode(text);
    let mut plain = String::new();
    let mut tag = false;
    for c in decoded.chars() {
        match c { '<' => tag = true, '>' => tag = false, _ if !tag => plain.push(c), _ => {} }
    }
    plain.split_whitespace().collect::<Vec<_>>().join(" ").to_lowercase()
}

fn host_of(actor: &str) -> Option<String> {
    let rest = actor.strip_prefix("https://")?;
    let host = rest.split('/').next()?;
    if host.is_empty() {
        None
    } else {
        Some(host.to_ascii_lowercase())
    }
}

fn norm_actor(s: &str) -> String {
    s.trim().trim_end_matches('/').to_ascii_lowercase()
}

fn actor_aliases(s: &str) -> Vec<String> {
    let actor = norm_actor(s);
    if actor.is_empty() {
        return Vec::new();
    }
    let mut aliases = vec![actor.clone()];
    if let Some(rest) = actor.strip_prefix("https://") {
        let mut parts = rest.splitn(2, '/');
        let host = parts.next().unwrap_or("");
        let path = parts.next().unwrap_or("").trim_matches('/');
        if !host.is_empty() {
            if let Some(name) = path.strip_prefix("users/") {
                if !name.is_empty() {
                    aliases.push(format!("https://{host}/@{name}"));
                }
            } else if let Some(name) = path.strip_prefix('@') {
                if !name.is_empty() {
                    aliases.push(format!("https://{host}/users/{name}"));
                }
            }
        }
    }
    aliases.sort();
    aliases.dedup();
    aliases
}

fn insert_actor_aliases(set: &mut HashSet<String>, actor: &str) {
    for alias in actor_aliases(actor) {
        set.insert(alias);
    }
}

pub async fn load_hidden_sets(db: &Client, owner_user_id: i64) -> Result<HiddenSets> {
    let mut sets = HiddenSets::default();

    let mute_rows = db
        .query(
            "SELECT actor_id FROM ap_mutes WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_mutes")?;
    for row in mute_rows {
        let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let a = norm_actor(&a);
        if !a.is_empty() {
            insert_actor_aliases(&mut sets.muted_actors, &a);
        }
    }

    let user_block_rows = db
        .query(
            "SELECT scope, value FROM ap_user_blocks WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_user_blocks")?;
    for row in user_block_rows {
        let scope: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        match scope.as_str() {
            "actor" => {
                let a = norm_actor(&value);
                if !a.is_empty() {
                    insert_actor_aliases(&mut sets.user_blocked_actors, &a);
                }
            }
            "domain" => {
                let h = value.trim().trim_start_matches('.').to_ascii_lowercase();
                if !h.is_empty() {
                    sets.user_blocked_domains.insert(h);
                }
            }
            _ => {}
        }
    }

    let inst_rows = db
        .query("SELECT scope, value FROM ap_blocks", &[])
        .await
        .context("select ap_blocks")?;
    for row in inst_rows {
        let scope: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        match scope.as_str() {
            "actor" => {
                let a = norm_actor(&value);
                if !a.is_empty() {
                    insert_actor_aliases(&mut sets.instance_blocked_actors, &a);
                }
            }
            "domain" => {
                let h = value.trim().trim_start_matches('.').to_ascii_lowercase();
                if !h.is_empty() {
                    sets.instance_blocked_domains.insert(h);
                }
            }
            _ => {}
        }
    }

    if owner_user_id > 0 {
        for row in db.query("SELECT phrase FROM ap_muted_words WHERE owner_user_id = $1", &[&owner_user_id]).await.context("load muted words")? {
            let phrase: String = row.get(0);
            let phrase = normalize_muted_text(&phrase);
            if !phrase.is_empty() { sets.muted_phrases.push(phrase); }
        }
    }
    Ok(sets)
}

/// Personal mute/block/deprioritize state for lean ⋯ menus (PHP `block_quick_actions`).
#[derive(Debug, Default, Clone)]
pub struct ViewerModeration {
    pub auto_unblur_sensitive: bool,
    pub muted_actors: HashSet<String>,
    /// actor_id → ap_user_blocks.id (scope=actor) for Unblock forms.
    pub blocked_actors: HashMap<String, i64>,
    /// Home soft-rank targets. Never includes the viewer.
    pub deprioritized_actors: HashSet<String>,
    /// Lowercased Bluesky handle and DID for this viewer, so own Bluesky
    /// posts do not offer personal moderation.
    pub own_bsky_keys: HashSet<String>,
}

impl ViewerModeration {
    pub fn is_muted(&self, actor_id: &str) -> bool {
        let a = norm_actor(actor_id);
        !a.is_empty() && self.muted_actors.contains(&a)
    }

    pub fn block_id(&self, actor_id: &str) -> Option<i64> {
        let a = norm_actor(actor_id);
        if a.is_empty() {
            return None;
        }
        self.blocked_actors.get(&a).copied()
    }

    pub fn is_deprioritized(&self, actor_id: &str) -> bool {
        if actor_id.trim().is_empty() {
            return false;
        }
        actor_aliases(actor_id)
            .into_iter()
            .any(|alias| self.deprioritized_actors.contains(&alias))
    }

    /// True when `actor_id` is this viewer's linked Bluesky profile.
    pub fn is_self_bsky(&self, actor_id: &str) -> bool {
        if self.own_bsky_keys.is_empty() {
            return false;
        }
        let actor = norm_actor(actor_id);
        let Some(rest) = actor.strip_prefix("https://bsky.app/profile/") else {
            return false;
        };
        let key = rest.trim_matches('/').split('/').next().unwrap_or("");
        !key.is_empty() && self.own_bsky_keys.contains(key)
    }
}

pub async fn load_viewer_moderation(db: &Client, owner_user_id: i64) -> Result<ViewerModeration> {
    let mut out = ViewerModeration::default();
    if owner_user_id < 1 {
        return Ok(out);
    }
    let mute_rows = db
        .query(
            "SELECT actor_id FROM ap_mutes WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_mutes for moderation menu")?;
    for row in mute_rows {
        let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let a = norm_actor(&a);
        if !a.is_empty() {
            out.muted_actors.insert(a);
        }
    }
    let block_rows = db
        .query(
            "SELECT id, value FROM ap_user_blocks
             WHERE owner_user_id = $1 AND scope = 'actor'",
            &[&owner_user_id],
        )
        .await
        .context("select ap_user_blocks for moderation menu")?;
    for row in block_rows {
        let id: i64 = row.try_get(0)?;
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let a = norm_actor(&value);
        if !a.is_empty() && id > 0 {
            out.blocked_actors.insert(a, id);
        }
    }
    if let Ok(rows) = db
        .query(
            "SELECT actor_id FROM ap_deprioritized_actors WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
    {
        for row in rows {
            let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
            insert_actor_aliases(&mut out.deprioritized_actors, &a);
        }
    }
    if let Ok(row) = db
        .query_opt(
            "SELECT COALESCE(handle, ''), COALESCE(did, '')
             FROM bsky_sessions WHERE owner_user_id = $1 LIMIT 1",
            &[&owner_user_id],
        )
        .await
    {
        if let Some(row) = row {
            let handle: String = row.try_get(0).unwrap_or_default();
            let did: String = row.try_get(1).unwrap_or_default();
            for key in [handle, did] {
                let key = key.trim().trim_start_matches('@').to_ascii_lowercase();
                if !key.is_empty() {
                    out.own_bsky_keys.insert(key);
                }
            }
        }
    }
    if let Some(row) = db.query_opt(
        "SELECT COALESCE(p.auto_unblur_sensitive, 0)::bigint FROM ap_users u
         JOIN actor_profile p ON p.actor_key = u.actor_key WHERE u.id = $1 AND u.disabled_at IS NULL",
        &[&owner_user_id],
    ).await? {
        out.auto_unblur_sensitive = row.get::<_, i64>(0) != 0;
    }
    Ok(out)
}

pub async fn is_favourited(db: &Client, owner_user_id: i64, object_id: &str) -> Result<bool> {
    let object_id = object_id.trim_end_matches('/');
    if object_id.is_empty() || !object_id.starts_with("https://") {
        return Ok(false);
    }
    let row = db
        .query_opt(
            "SELECT 1 FROM masto_favourites
             WHERE owner_user_id = $1 AND (object_id = $2 OR object_id = $3)
             LIMIT 1",
            &[&owner_user_id, &object_id, &format!("{object_id}/")],
        )
        .await
        .context("select masto_favourites")?;
    Ok(row.is_some())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn moderation_matches_actor_url_aliases_case_insensitively() {
        let mut hidden = HiddenSets::default();
        insert_actor_aliases(&mut hidden.muted_actors, "https://mas.corq.co/users/rogue_corq");
        assert!(hidden.is_hidden("https://mas.corq.co/users/rogue_corq/"));
        assert!(hidden.is_hidden("https://MAS.CORQ.CO/@ROGUE_CORQ"));
        assert!(!hidden.is_hidden("https://mas.corq.co/users/other"));
    }
}

pub async fn load_post_subscriptions(db: &Client, owner_user_id: i64) -> Result<HashSet<String>> {
    let rows = db
        .query(
            "SELECT target_actor_id FROM ap_post_subscriptions WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_post_subscriptions")?;
    let mut out = HashSet::new();
    for row in rows {
        let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let a = norm_actor(&a);
        if !a.is_empty() {
            out.insert(a);
        }
    }
    Ok(out)
}

pub fn content_addresses_local(content: &str, local_actor_id: &str) -> bool {
    let local = local_actor_id.trim_end_matches('/');
    if local.is_empty() || content.is_empty() {
        return false;
    }
    if content.contains(local) {
        return true;
    }
    let key = local.rsplit('/').next().unwrap_or("");
    if key.is_empty() || !key.chars().all(|c| c.is_ascii_alphanumeric() || c == '_') {
        return false;
    }
    let host = host_of(local).unwrap_or_else(|| "mkultra.monster".into());
    let plain = strip_tags_light(content);
    // @key or @key@host
    let needle1 = format!("@{key}");
    let needle2 = format!("@{key}@{host}");
    plain.to_ascii_lowercase().contains(&needle1.to_ascii_lowercase())
        || plain.to_ascii_lowercase().contains(&needle2.to_ascii_lowercase())
}

fn strip_tags_light(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut in_tag = false;
    for c in s.chars() {
        match c {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ if !in_tag => out.push(c),
            _ => {}
        }
    }
    out
}

#[cfg(test)]
mod privacy_tests {
    use super::*;
    use serde_json::json;
    #[test]
    fn muted_words_filter_body_warning_and_nested_network_cards() {
        let hidden = HiddenSets { muted_phrases: vec!["spoiler phrase".into(), "cats & dogs".into()], ..Default::default() };
        assert!(status_hidden(&json!({"content":"<p>SPOILER <em>phrase</em></p>"}), &hidden));
        assert!(status_hidden(&json!({"content":"fine", "spoiler_text":"Cats &amp; dogs"}), &hidden));
        assert!(status_hidden(&json!({"content":"Cats &#x26; dogs"}), &hidden));
        for field in ["quote", "vaak_quote_preview"] {
            assert!(status_hidden(&json!({field: {"status":{"content":"spoiler phrase"}}}), &hidden));
        }
        assert!(status_hidden(&json!({"reblog":{"quote":{"quoted_status":{"content":"spoiler phrase"}}}}), &hidden));
        assert!(!status_hidden(&json!({"content":"A clean RSS or followed post"}), &hidden));
    }
    #[test]
    fn blocked_domain_in_quote_cannot_hide_behind_allowed_author() {
        let hidden = HiddenSets { user_blocked_domains: HashSet::from(["blocked.test".into()]), ..Default::default() };
        assert!(status_hidden(&json!({"account":{"uri":"https://friend.test/users/a"},"vaak_quote_preview":{"status":{"account":{"url":"https://blocked.test/@b"}}}}), &hidden));
    }
}

#[cfg(test)]
mod profile_mute_tests {
    use super::*;
    use serde_json::json;
    #[test]
    fn opening_muted_profile_does_not_unmute_quoted_authors() {
        let author = "https://example.test/users/alice";
        let hidden = HiddenSets { muted_actors: HashSet::from([author.into()]), ..Default::default() };
        assert!(!status_hidden_on_profile(&json!({"account":{"uri":author},"content":"profile post"}), &hidden));
        assert!(status_hidden_on_profile(&json!({"account":{"uri":"https://friend.test/users/bob"},"quote":{"quoted_status":{"account":{"uri":author},"content":"muted quote"}}}), &hidden));
    }
}
