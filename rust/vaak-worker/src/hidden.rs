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
    /// PHP server mutes compare syntactic actor aliases case-insensitively;
    /// personal mutes and personal/server blocks preserve URI path case.
    pub instance_muted_actors: HashSet<String>,
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
                || self.instance_muted_actors.contains(&alias.to_ascii_lowercase())
            {
                return true;
            }
        }
        let host = host_of(actor);
        if let Some(host) = host {
            let host_l = host.to_ascii_lowercase();
            if self.user_blocked_domains.iter().chain(self.instance_blocked_domains.iter())
                .any(|domain| host_l == *domain || host_l.ends_with(&format!(".{domain}")))
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

/// Library consumes normalized cards together with their authoritative native
/// source trees. Native author and remote block policy cannot be recovered from
/// the compact quote HTML options, so inspect the retained payload recursively.
pub fn library_status_hidden(status: &Value, hidden: &HiddenSets) -> bool {
    // Raw native protocol envelopes are authoritative too. Dispatch only the
    // PostView/FeedView shapes; arbitrary normalized metadata is not native.
    let post_view = |post:&Value| post.get("uri").and_then(Value::as_str).is_some_and(|uri|uri.starts_with("at://"))
        && post.get("author").is_some_and(Value::is_object);
    let raw_native = post_view(status) || status.get("post").is_some_and(post_view);
    status_hidden(status, hidden) || (raw_native && native_tree_hidden(status, hidden, 0))
        || library_native_sources_hidden(status, hidden, 0)
}

fn library_native_sources_hidden(status: &Value, hidden: &HiddenSets, depth: usize) -> bool {
    if depth > 16 { return true; }
    if let Some(preview) = status.get("vaak_quote_preview") {
        let text = normalize_muted_text(preview.get("text").and_then(Value::as_str).unwrap_or(""));
        if hidden.muted_phrases.iter().any(|phrase| !phrase.is_empty() && text.contains(phrase)) { return true; }
    }
    if status.get("bsky_post").filter(|value| value.is_object() || value.is_array())
        .is_some_and(|native| native_tree_hidden(native, hidden, 0)) { return true; }
    for field in ["reblog", "quote", "vaak_quote_preview"] {
        if let Some(child) = status.get(field).filter(|value| value.is_object()) {
            let child = child.get("quoted_status").or_else(||child.get("status")).filter(|value|value.is_object()).unwrap_or(child);
            if library_native_sources_hidden(child, hidden, depth+1) { return true; }
        }
    }
    false
}

fn native_tree_hidden(value: &Value, hidden: &HiddenSets, depth: usize) -> bool {
    if depth > 16 { return true; }
    match value {
        Value::Array(items) => items.iter().any(|item| native_tree_hidden(item, hidden, depth + 1)),
        Value::Object(object) => {
            for key in ["author", "by"] {
                if let Some(author) = object.get(key) {
                    let did = author.get("did").and_then(Value::as_str).unwrap_or("").trim();
                    let handle = author.get("handle").and_then(Value::as_str).unwrap_or("").trim().to_lowercase();
                    if (!did.is_empty() && hidden.is_hidden(did))
                        || (!handle.is_empty() && hidden.is_hidden(&format!("https://bsky.app/profile/{handle}"))) {
                        return true;
                    }
                }
            }
            if object.get("viewer").and_then(|viewer| viewer.get("blockedBy")).is_some_and(php_truthy)
                || object.get("author").and_then(|author|author.get("viewer")).and_then(|viewer|viewer.get("blockedBy")).is_some_and(php_truthy) {
                return true;
            }
            if let Some(text) = object.get("text").and_then(Value::as_str) {
                let text = normalize_muted_text(text);
                if hidden.muted_phrases.iter().any(|phrase| !phrase.is_empty() && text.contains(phrase)) { return true; }
            }
            object.values().filter(|child| child.is_object() || child.is_array())
                .any(|child| native_tree_hidden(child, hidden, depth + 1))
        }
        _ => false,
    }
}

fn php_truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(value) => *value,
        Value::Number(value) => value.as_f64().is_some_and(|number| number != 0.0),
        Value::String(value) => !value.is_empty() && value != "0",
        Value::Array(value) => !value.is_empty(),
        Value::Object(value) => !value.is_empty(),
    }
}

/// A directly opened profile may show its muted author, while nested authors
/// still obey personal mutes. Blocks and muted words apply throughout.
pub fn status_hidden_on_profile(status: &Value, hidden: &HiddenSets) -> bool {
    status_hidden_at(status, hidden, 0, true)
}

fn status_hidden_at(status: &Value, hidden: &HiddenSets, depth: usize, profile_root: bool) -> bool {
    if depth > 16 { return true; }
    // Flattened fallback previews sometimes have no account. Their object
    // host still obeys domain blocks, including real subdomains.
    for field in ["uri", "url"] {
        if let Some(reference) = status.get(field).and_then(Value::as_str) {
            if let Some(host) = host_of(reference) {
                if hidden.user_blocked_domains.iter().chain(hidden.instance_blocked_domains.iter())
                    .any(|domain| host == *domain || host.ends_with(&format!(".{domain}"))) { return true; }
            }
        }
    }
    // Late Bluesky quote previews may carry only an AT URI plus a web handle,
    // without account/did fields. The record URI is the trusted DID identity.
    if let Some(uri) = status.get("uri").and_then(Value::as_str) {
        if let Some(did) = uri.strip_prefix("at://").and_then(|rest| rest.split('/').next()).filter(|did| did.starts_with("did:")) {
            if hidden.actor_hidden(did, profile_root && depth == 0) { return true; }
        }
    }
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
    let url = reqwest::Url::parse(actor).ok()?;
    if !matches!(url.scheme(), "http" | "https") { return None; }
    url.host_str().map(|host| host.to_ascii_lowercase())
}

fn norm_actor(s: &str) -> String {
    s.trim().trim_end_matches('/').to_owned()
}

pub(crate) fn actor_aliases(s: &str) -> Vec<String> {
    let actor = norm_actor(s);
    if actor.is_empty() { return Vec::new(); }
    let mut aliases = vec![actor.clone()];
    if actor.starts_with("did:") {
        aliases.push(format!("https://bsky.app/profile/{actor}"));
        aliases.push(format!("https://bsky.app/profile/{}", crate::library::actions::query(&actor)));
    } else if let Some(captures) = regex::Regex::new(r"(?i)^https://bsky\.app/profile/([^/?#]+)$").unwrap().captures(&actor) {
        let decoded = raw_percent_decode(&captures[1]);
        aliases.push(decoded.clone());
        aliases.push(format!("https://bsky.app/profile/{decoded}"));
        aliases.push(format!("https://bsky.app/profile/{}", crate::library::actions::query(&decoded)));
    } else if let Some(captures) = regex::Regex::new(r"(?i)^https://([^/]+)/(users/|@)([^/?#]+)$").unwrap().captures(&actor) {
        let host = captures[1].to_ascii_lowercase();
        let (decoded, encoded) = path_segment_forms(&captures[3]);
        for variant in [decoded, encoded] {
            if !variant.is_empty() && variant.bytes().all(|byte| byte.is_ascii_digit()) {
                aliases.push(format!("https://{host}/{}{variant}", &captures[2]));
            } else if !variant.is_empty() {
                aliases.push(format!("https://{host}/users/{variant}"));
                aliases.push(format!("https://{host}/@{variant}"));
            }
        }
    }
    aliases.sort(); aliases.dedup(); aliases
}

/// AP path segment forms decode unreserved bytes and valid UTF-8 names only.
/// Encoded reserved delimiters retain their identity and never double-encode.
pub(crate) fn path_segment_forms(raw: &str) -> (String, String) {
    let bytes = raw.as_bytes();
    let mut decoded = String::new();
    let mut index = 0;
    while index < bytes.len() {
        let start = index;
        let mut run = Vec::new();
        while index + 2 < bytes.len() && bytes[index] == b'%' {
            let Some(byte) = std::str::from_utf8(&bytes[index+1..index+3]).ok()
                .and_then(|hex| u8::from_str_radix(hex, 16).ok()) else { break; };
            run.push(byte);
            index += 3;
        }
        if run.is_empty() {
            let character = raw[index..].chars().next().expect("segment character");
            decoded.push(character);
            index += character.len_utf8();
            continue;
        }
        let render = |unicode: bool| {
            let mut out = Vec::new();
            for byte in &run {
                if byte.is_ascii_alphanumeric() || b"._~-".contains(byte) || (unicode && *byte >= 128) {
                    out.push(*byte);
                } else { out.extend_from_slice(format!("%{byte:02X}").as_bytes()); }
            }
            out
        };
        let candidate = String::from_utf8(render(true)).unwrap_or_else(|_| {
            String::from_utf8(render(false)).expect("escaped invalid byte run")
        });
        decoded.push_str(&candidate);
        debug_assert!(index > start);
    }
    let mut encoded=String::new();
    for byte in decoded.as_bytes(){if *byte>=128{encoded.push_str(&format!("%{byte:02X}"));}else{encoded.push(*byte as char);}}
    (decoded,encoded)
}

pub(crate) fn raw_percent_decode(value: &str) -> String {
    let bytes = value.as_bytes(); let mut decoded = Vec::new(); let mut index = 0;
    while index < bytes.len() {
        if bytes[index] == b'%' && index + 2 < bytes.len() {
            if let Ok(hex) = std::str::from_utf8(&bytes[index + 1..index + 3]) {
                if let Ok(byte) = u8::from_str_radix(hex, 16) { decoded.push(byte); index += 3; continue; }
            }
        }
        decoded.push(bytes[index]); index += 1;
    }
    String::from_utf8_lossy(&decoded).into_owned()
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
            "SELECT actor_id FROM ap_mutes WHERE owner_user_id = $1::bigint",
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
            "SELECT scope, value FROM ap_user_blocks WHERE owner_user_id = $1::bigint",
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
        .query("SELECT scope, value, COALESCE(to_jsonb(ap_blocks)->>'kind', 'block') FROM ap_blocks", &[])
        .await
        .context("select ap_blocks")?;
    for row in inst_rows {
        let scope: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let kind: String = row.try_get(2)?;
        match scope.as_str() {
            "actor" => {
                let a = norm_actor(&value);
                if !a.is_empty() {
                    if kind == "mute" {
                        for alias in actor_aliases(&a) { sets.instance_muted_actors.insert(alias.to_ascii_lowercase()); }
                    } else { insert_actor_aliases(&mut sets.instance_blocked_actors, &a); }
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
        for row in db.query("SELECT phrase FROM ap_muted_words WHERE owner_user_id = $1::bigint", &[&owner_user_id]).await.context("load muted words")? {
            let phrase: String = row.get(0);
            let phrase = normalize_muted_text(&phrase);
            if !phrase.is_empty() { sets.muted_phrases.push(phrase); }
        }
    }
    Ok(sets)
}

/// Library reads are authenticated and additionally obey the owner's durable
/// Bluesky moderation set. These rows include native blocks, mutes and subscribed
/// moderation lists. Absence in older schemas is an error, never an empty set.
pub async fn load_library_hidden_sets(db: &Client, owner_user_id: i64) -> Result<HiddenSets> {
    if owner_user_id < 1 { anyhow::bail!("Library moderation requires an owner"); }
    let mut sets = load_hidden_sets(db, owner_user_id).await?;
    for row in db.query("SELECT DISTINCT did FROM bsky_hide_dids WHERE owner_user_id::bigint = $1", &[&owner_user_id])
        .await.context("read current Library Bluesky moderation")? {
        let did: Option<String> = row.try_get(0)?;
        let did = norm_actor(&did.unwrap_or_default());
        if !did.is_empty() {
            sets.user_blocked_actors.insert(did.clone());
            sets.user_blocked_actors.insert(format!("https://bsky.app/profile/{did}"));
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
            "SELECT actor_id FROM ap_mutes WHERE owner_user_id = $1::bigint",
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
            "SELECT id::bigint, value FROM ap_user_blocks
             WHERE owner_user_id = $1::bigint AND scope = 'actor'",
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
            "SELECT actor_id FROM ap_deprioritized_actors WHERE owner_user_id = $1::bigint",
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
             FROM bsky_sessions WHERE owner_user_id = $1::bigint LIMIT 1",
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
        "SELECT COALESCE(p.auto_unblur_sensitive::bigint, 0) FROM ap_users u
         JOIN actor_profile p ON p.actor_key = u.actor_key WHERE u.id = $1::bigint AND u.disabled_at IS NULL",
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
             WHERE owner_user_id = $1::bigint AND (object_id = $2 OR object_id = $3)
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
    fn moderation_preserves_actor_path_case_and_expands_structural_aliases() {
        let mut hidden = HiddenSets::default();
        insert_actor_aliases(&mut hidden.muted_actors, "https://mas.corq.co/users/rogue_corq");
        assert!(hidden.is_hidden("https://mas.corq.co/users/rogue_corq/"));
        assert!(hidden.is_hidden("https://MAS.CORQ.CO/@rogue_corq"));
        assert!(!hidden.is_hidden("https://mas.corq.co/@ROGUE_CORQ"));
        assert!(!hidden.is_hidden("https://mas.corq.co/users/other"));
    }
}

pub async fn load_post_subscriptions(db: &Client, owner_user_id: i64) -> Result<HashSet<String>> {
    let rows = db
        .query(
            "SELECT target_actor_id FROM ap_post_subscriptions WHERE owner_user_id = $1::bigint",
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
    fn actual_php_actor_aliases_preserve_numeric_and_encoded_identity_boundaries() {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/migration-library-component-capture.php");
        let output = std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).arg(file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        let reference: Value = serde_json::from_slice(&output.stdout).unwrap();
        for (actor, expected) in reference["actors"].as_object().unwrap() {
            let expected: HashSet<String> = expected.as_array().unwrap().iter().map(|alias| alias.as_str().unwrap().trim_end_matches('/').to_owned()).collect();
            assert_eq!(actor_aliases(actor).into_iter().collect::<HashSet<_>>(), expected, "actual PHP aliases {actor}");
        }
    }
    #[test]
    fn library_preserves_native_quote_author_remote_policy_and_text_checks() {
        let hidden = HiddenSets { user_blocked_actors: HashSet::from(["did:plc:hidden".into()]),
            muted_phrases: vec!["secret phrase".into()], ..Default::default() };
        let clean = json!({"account":{"uri":"https://allowed.test/users/a"},"bsky_post":{
            "uri":"at://did:plc:allowed/app.bsky.feed.post/a", "author":{"did":"did:plc:allowed"},
            "record":{"text":"clean"},"embed":{"record":{"record":{"author":{"did":"did:plc:allowed"},"value":{"text":"clean"}}}}}});
        assert!(!library_status_hidden(&clean, &hidden));
        let mut nested = clean.clone();
        nested["bsky_post"]["embed"]["record"]["record"]["author"]["did"] = json!("did:plc:hidden");
        assert!(library_status_hidden(&nested, &hidden));
        let mut blocked = clean.clone();
        blocked["bsky_post"]["author"]["viewer"] = json!({"blockedBy":true});
        assert!(library_status_hidden(&blocked, &hidden));
        blocked["bsky_post"]["author"]["viewer"] = json!({"blockedBy":false,"muted":true,"blocking":"stale"});
        assert!(!library_status_hidden(&blocked, &hidden));
        let mut phrase = clean.clone();
        phrase["bsky_post"]["record"]["text"] = json!("SECRET phrase");
        assert!(library_status_hidden(&phrase, &hidden));
        assert!(library_status_hidden(&json!({"vaak_quote_preview":{"text":"secret phrase"}}), &hidden));
        assert!(library_status_hidden(&json!({"bsky_post":{"author":{"did":"did:plc:hidden"}}}), &hidden));
    }
    #[test]
    fn raw_post_and_feed_views_keep_native_author_text_and_remote_policy() {
        let mut hidden=HiddenSets::default();hidden.user_blocked_actors.insert("did:plc:hidden".into());hidden.muted_phrases.push("hidden & phrase".into());
        let base=serde_json::json!({"uri":"at://did:plc:allowed/app.bsky.feed.post/key","author":{"did":"did:plc:allowed"},"record":{"text":"allowed"}});
        assert!(!library_status_hidden(&base,&hidden));
        let mut nested=base.clone();nested["embed"]=serde_json::json!({"record":{"author":{"did":"did:plc:hidden"}}});assert!(library_status_hidden(&nested,&hidden));
        let mut words=base.clone();words["record"]["text"]=serde_json::json!("hidden & phrase");assert!(library_status_hidden(&words,&hidden));
        let mut blocked=base.clone();blocked["author"]["viewer"]=serde_json::json!({"blockedBy":true});assert!(library_status_hidden(&blocked,&hidden));
        let feed=serde_json::json!({"post":base,"reason":{"by":{"did":"did:plc:hidden"}}});assert!(library_status_hidden(&feed,&hidden));
    }

    #[test]
    fn native_handle_is_lowercased_without_changing_activitypub_path_case() {
        let mut hidden=HiddenSets::default();hidden.user_blocked_actors.insert("https://bsky.app/profile/alice.test".into());
        let native=serde_json::json!({"account":{"uri":"https://allowed.test/users/a"},"bsky_post":{"author":{"handle":" ALICE.TEST "}}});
        assert!(library_status_hidden(&native,&hidden));
        hidden.user_blocked_actors.insert("https://ap.test/users/alice".into());
        assert!(!hidden.is_hidden("https://ap.test/users/Alice"));
    }

    #[test]
    fn library_native_depth_is_scoped_to_authoritative_post_roots() {
        let hidden=HiddenSets::default();
        let chain=|depth:usize| { let mut node=json!({}); for _ in 0..depth { node=json!({"child":node}); } node };
        for depth in [14,15,16] { assert!(!library_status_hidden(&json!({"bsky_post":chain(depth)}),&hidden)); }
        assert!(library_status_hidden(&json!({"bsky_post":chain(17)}),&hidden));
        assert!(!library_status_hidden(&json!({"metadata":chain(18)}),&hidden));
        assert!(!library_status_hidden(&json!({"metadata":{"viewer":{"blockedBy":true}}}),&hidden));
    }
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
        assert!(status_hidden(&json!({"account":{"uri":"https://friend.test/users/a"},"vaak_quote_preview":{"url":"https://sub.blocked.test/posts/q", "acct":"@author@blocked.test", "text":"hidden preview"}}), &hidden));
    }
    #[test]
    fn domain_suffix_blocks_real_subdomains_without_overmatching() {
        let hidden = HiddenSets { user_blocked_domains: HashSet::from(["blocked.test".into()]), ..Default::default() };
        for host in ["blocked.test", "media.blocked.test", "DEEP.media.blocked.test"] {
            assert!(hidden.is_hidden(&format!("https://{host}/users/alice")));
        }
        assert!(!hidden.is_hidden("https://notblocked.test/users/alice"));
        assert!(!hidden.is_hidden("https://blocked.test.evil.test/users/alice"));
        assert!(hidden.is_hidden("http://sub.blocked.test:8080/users/alice/"));
        assert!(hidden.is_hidden("HTTPS://BLOCKED.TEST/users/alice"));
    }
    #[test]
    fn library_bluesky_did_and_nested_normalized_actor_are_hidden() {
        let did = "did:plc:blocked";
        let hidden = HiddenSets { user_blocked_actors: HashSet::from([did.into(), format!("https://bsky.app/profile/{did}")]), ..Default::default() };
        assert!(status_hidden(&json!({"account":{"id":did,"uri":format!("https://bsky.app/profile/{did}")}}), &hidden));
        assert!(status_hidden(&json!({"quote":{"quoted_status":{"account":{"id":did}}}}), &hidden));
        assert!(hidden.is_hidden("https://bsky.app/profile/did%3Aplc%3Ablocked/"));
        assert!(status_hidden(&json!({"vaak_quote_preview":{"uri":"at://did:plc:blocked/app.bsky.feed.post/k", "url":"https://bsky.app/profile/new-handle.test/post/k", "handle":"new-handle.test"}}), &hidden));
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
