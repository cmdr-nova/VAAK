//! Library admission for saved cards.
//!
//! PHP still blocks, mutes, deletes, and changes audiences. This module
//! repeats the read-only checks `admin_library_status_allowed` and the
//! content-warning setting apply before a card is painted. It stays unused
//! while `VAAK_LIBRARY_NATIVE_RENDER` is off.
use serde_json::Value;

use super::card::php_trim;

/// Prefix hard-coded in `ap_visibility_status_audience_allowed`.
const LOCAL_USERS: &str = "https://mkultra.monster/users/";

#[derive(Clone, Copy)]
enum EventUse { Deleted, Audience }

pub(crate) fn status_allowed(status: &Value, owner: i64, ordinary: bool, policy: &Value) -> bool {
    if owner < 1 { return false; }
    if !not_deleted(status, 0, policy) || ask_muted(status, owner, 0, policy) { return false; }
    if let Some(post) = status.get("bsky_post").filter(|value| is_collection(value)) {
        if !bsky_allowed(post, owner, 0, policy) { return false; }
    }
    let hidden = if ordinary {
        ask_muted(status, owner, 0, policy) || status_hidden(status, owner, 0, true, policy)
    } else {
        status_hidden(status, owner, 0, false, policy)
    };
    !hidden && audience_allowed(status, owner, 0, policy)
}

pub(crate) fn actor_hidden(actor: &str, owner: i64, policy: &Value) -> bool {
    let actor = norm_actor(actor);
    if actor.is_empty() { return false; }
    if listed(policy, "server_blocks", &actor) || listed(policy, "server_mutes", &actor) { return true; }
    if owner < 1 { return false; }
    if is_did(&actor) {
        if did_hidden(policy, owner, &actor, true) || user_has(policy, "user_blocks", owner, &actor) || user_has(policy, "user_mutes", owner, &actor) {
            return true;
        }
        return false;
    }
    if user_has(policy, "user_blocks", owner, &actor) || user_has(policy, "user_mutes", owner, &actor) { return true; }
    if user_has(policy, "deprioritized", owner, &actor) { return false; }
    user_has(policy, "row_hidden", owner, &actor)
}

pub(crate) fn render_allowed(status: &Value, owner: i64, plain: &str, hydrated: &Value, previews: &Value, policy: &Value) -> bool {
    let id = field(status, "id");
    let rss = status.get("vaak_rss_item_id").is_some_and(php_truthy) || field(status, "source") == "rss" || id.starts_with("rss:");
    if !rss && !status_allowed(status, owner, true, policy) { return false; }
    if owner < 1 { return false; }
    let ask = status.get("vaak_ask").filter(|value| value.is_object());
    let question = ask.and_then(|ask| ask.get("question").or_else(|| ask.get("ask_question"))).and_then(Value::as_str).unwrap_or("");
    if muted(owner, &[question], policy) { return false; }
    if muted(owner, &[plain, field(status, "spoiler_text")], policy)
        && !note_owned(policy, field(status, "uri"), owner, account_field(status, "uri")) { return false; }
    for child in collection(hydrated) {
        if is_collection(child) && !status_allowed(child, owner, false, policy) { return false; }
    }
    for child in collection(previews) {
        if !child.is_object() { continue; }
        if let Some(post) = child.get("post").filter(|value| is_collection(value)) {
            if !bsky_allowed(post, owner, 0, policy) { return false; }
        }
        let url = child.get("url").and_then(Value::as_str).or_else(|| child.get("uri").and_then(Value::as_str)).unwrap_or("");
        let rss_preview = php_truthy_at(child, "is_rss");
        if !rss_preview && !url.is_empty() && actor_hidden(url, owner, policy) { return false; }
        let key = policy_key(url);
        if !rss_preview {
            if let Some(saved) = policy.get("retained").and_then(|rows| rows.get(&key)).filter(|value| value.is_object()) {
                if !status_allowed(saved, owner, false, policy) { return false; }
            }
        }
        let mut actor = trimmed(child.get("did").and_then(Value::as_str).unwrap_or("")).to_owned();
        if actor.is_empty() {
            actor = trimmed(child.get("post").and_then(|post| post.get("author")).and_then(|author| author.get("did")).and_then(Value::as_str).unwrap_or("")).to_owned();
        }
        if actor.starts_with("did:") && !bsky_allowed(&serde_json::json!({"author": {"did": actor}}), owner, 0, policy) { return false; }
        let handle = trimmed(child.get("handle").and_then(Value::as_str).unwrap_or(""));
        let account = if child.get("account").is_some_and(Value::is_object) {
            child.get("account").cloned().unwrap_or(Value::Null)
        } else {
            serde_json::json!({"uri": actor, "url": if handle.is_empty() { String::new() } else { format!("https://bsky.app/profile/{handle}") }})
        };
        let normalized = serde_json::json!({"uri": url, "content": child.get("text").and_then(Value::as_str).unwrap_or(""), "account": account});
        // A preview marked as RSS still needs the current subscription row.
        // This read model has no subscription table, so that preview stays closed.
        if rss_preview || !status_allowed(&normalized, owner, false, policy) { return false; }
    }
    true
}

fn not_deleted(status: &Value, depth: usize, policy: &Value) -> bool {
    if depth > 16 { return false; }
    let uri = policy_key(status.get("uri").and_then(Value::as_str).or_else(|| status.get("url").and_then(Value::as_str)).unwrap_or(""));
    if !uri.is_empty() {
        if let Some(event) = select_event(policy, &uri, EventUse::Deleted) {
            if field(event, "action_taken") == "deleted" || matches!(field(event, "type"), "Delete" | "Tombstone") { return false; }
        } else if let Some(mention) = newest_mention(policy, &uri) {
            if deleted_marker(mention.get("deleted_at").unwrap_or(&Value::Null)) { return false; }
        }
    }
    nested_all(status, |child| not_deleted(child, depth + 1, policy))
}

fn ask_muted(status: &Value, owner: i64, depth: usize, policy: &Value) -> bool {
    if depth > 16 { return true; }
    let ask = status.get("vaak_ask").filter(|value| value.is_object());
    let question = ask.and_then(|ask| ask.get("question").or_else(|| ask.get("ask_question"))).and_then(Value::as_str).unwrap_or("");
    if muted(owner, &[question], policy) { return true; }
    !nested_all(status, |child| !ask_muted(child, owner, depth + 1, policy))
}

fn status_hidden(status: &Value, owner: i64, depth: usize, ordinary_root: bool, policy: &Value) -> bool {
    if depth > 16 { return true; }
    if ["uri", "url", "id"].iter().any(|key| {
        let actor = account_field(status, key);
        !actor.is_empty() && actor_hidden(actor, owner, policy)
    }) { return true; }
    let exempt = ordinary_root && depth == 0 && note_owned(policy, field(status, "uri"), owner, account_field(status, "uri"));
    if !exempt && muted(owner, &[field(status, "content"), field(status, "spoiler_text")], policy) { return true; }
    !nested_all(status, |child| !status_hidden(child, owner, depth + 1, false, policy))
}

fn audience_allowed(status: &Value, owner: i64, depth: usize, policy: &Value) -> bool {
    if depth > 16 { return false; }
    let uri = policy_key(status.get("uri").and_then(Value::as_str).or_else(|| status.get("url").and_then(Value::as_str)).unwrap_or(""));
    let mut visibility = match status.get("visibility") {
        None | Some(Value::Null) => "public".to_owned(),
        Some(value) => value.as_str().unwrap_or("").to_lowercase(),
    };
    let mut author = norm_actor(account_field(status, "uri"));
    let mut recipients = Vec::new();
    if uri.starts_with("https://") || uri.starts_with("http://") {
        if let Some(event) = select_event(policy, &uri, EventUse::Audience) {
            visibility = lowered(field(event, "visibility"));
            author = norm_actor(field(event, "actor_id"));
        }
        if let Some(note) = outbox_row(policy, &uri) {
            visibility = lowered(field(note, "visibility"));
            author = note_author(&uri);
            recipients = recipients_of(note);
        } else if uri.starts_with(LOCAL_USERS) && uri.contains("/notes/") {
            return false;
        }
    }
    if visibility != "public" && visibility != "unlisted" {
        let viewer = if owner > 0 { owner_actor(policy, owner) } else { String::new() };
        if viewer.is_empty() { return false; }
        let mut allowed = author == viewer || recipients.iter().any(|recipient| recipient == &viewer);
        if !allowed && visibility == "local" { allowed = author.starts_with(LOCAL_USERS); }
        if !allowed { allowed = mention_grants(policy, owner, &uri); }
        if !allowed && matches!(visibility.as_str(), "private" | "followers" | "followers_only") {
            allowed = follow_grants(policy, &author, &viewer);
        }
        if !allowed { return false; }
    }
    nested_all(status, |child| audience_allowed(child, owner, depth + 1, policy))
}

fn bsky_allowed(item: &Value, owner: i64, depth: usize, policy: &Value) -> bool {
    if owner < 1 || depth > 16 { return false; }
    match item {
        Value::Array(items) => items.iter().all(|child| !is_collection(child) || bsky_allowed(child, owner, depth + 1, policy)),
        Value::Object(object) => {
            for key in ["author", "by"] {
                let Some(author) = object.get(key).filter(|value| value.is_object()) else { continue; };
                let did = trimmed(author.get("did").and_then(Value::as_str).unwrap_or(""));
                let handle = trimmed(author.get("handle").and_then(Value::as_str).unwrap_or("")).to_lowercase();
                if !did.is_empty() && did_hidden(policy, owner, did, false) { return false; }
                for actor in [did.to_owned(), if did.is_empty() { String::new() } else { format!("https://bsky.app/profile/{did}") }, if handle.is_empty() { String::new() } else { format!("https://bsky.app/profile/{handle}") }] {
                    if !actor.is_empty() && actor_hidden(&actor, owner, policy) { return false; }
                }
            }
            if object.get("viewer").and_then(|viewer| viewer.get("blockedBy")).is_some_and(php_truthy)
                || object.get("author").and_then(|author| author.get("viewer")).and_then(|viewer| viewer.get("blockedBy")).is_some_and(php_truthy) {
                return false;
            }
            if muted(owner, &[object.get("text").and_then(Value::as_str).unwrap_or(""), ""], policy) { return false; }
            object.values().all(|child| !is_collection(child) || bsky_allowed(child, owner, depth + 1, policy))
        }
        _ => true,
    }
}

fn note_owned(policy: &Value, uri: &str, owner: i64, expected: &str) -> bool {
    let uri = norm_actor(uri);
    if owner < 1 || uri.is_empty() { return false; }
    let Some(user) = rows(policy, "users").find(|user| number(&user["id"]) == owner && user.get("disabled_at").is_none_or(Value::is_null)) else { return false; };
    let actor = norm_actor(field(user, "actor_id"));
    if actor.is_empty() { return false; }
    let Some(note) = rows(policy, "outbox").find(|note| norm_actor(field(note, "id")) == uri) else { return false; };
    let prefix = format!("{actor}/notes/");
    field(note, "id").starts_with(&prefix) && norm_actor(expected) == actor
}

fn follow_grants(policy: &Value, author: &str, viewer: &str) -> bool {
    if author.is_empty() || viewer.is_empty() { return false; }
    let local = author.starts_with(LOCAL_USERS);
    let table = if local { "followers" } else { "following" };
    rows(policy, table).any(|row| {
        let left = field(row, "owner_actor_id");
        let right = field(row, "actor_id");
        if local { same_actor(left, author) && same_actor(right, viewer) } else { same_actor(left, viewer) && same_actor(right, author) }
    })
}

fn mention_grants(policy: &Value, owner: i64, uri: &str) -> bool {
    rows(policy, "mentions").any(|row| {
        number(&row["owner_user_id"]) == owner
            && row.get("deleted_at").is_none_or(Value::is_null)
            && matches!(field(row, "activity_type"), "Create" | "Update" | "Quote" | "QuotePost")
            && same_actor(field(row, "object_id"), uri)
    })
}

fn select_event<'a>(policy: &'a Value, uri: &str, use_for: EventUse) -> Option<&'a Value> {
    if uri.is_empty() { return None; }
    let has_source = rows(policy, "events").any(|event| same_actor(field(event, "object_id"), uri) && matches!(field(event, "type"), "Create" | "Update"));
    rows(policy, "events").filter(|event| {
        if !same_actor(field(event, "object_id"), uri) { return false; }
        let kind = field(event, "type");
        let quote = matches!(kind, "Quote" | "QuotePost") && !has_source;
        match use_for {
            EventUse::Deleted => matches!(kind, "Create" | "Update" | "Delete" | "Tombstone") || quote,
            EventUse::Audience => matches!(field(event, "action_taken"), "log" | "local_observe" | "local_fav_update")
                && (matches!(kind, "Create" | "Update") || quote),
        }
    }).max_by_key(|event| number(&event["id"]))
}

fn newest_mention<'a>(policy: &'a Value, uri: &str) -> Option<&'a Value> {
    rows(policy, "mentions").filter(|row| same_actor(field(row, "object_id"), uri)).max_by_key(|row| number(&row["id"]))
}

fn outbox_row<'a>(policy: &'a Value, uri: &str) -> Option<&'a Value> {
    rows(policy, "outbox").find(|note| same_actor(field(note, "id"), uri))
}

fn recipients_of(note: &Value) -> Vec<String> {
    ["to_json", "cc_json"].iter().flat_map(|key| {
        let parsed = serde_json::from_str::<Value>(field(note, key)).unwrap_or(Value::Null);
        parsed.as_array().cloned().unwrap_or_default().into_iter().filter_map(|item| item.as_str().map(norm_actor))
    }).collect()
}

fn note_author(uri: &str) -> String {
    uri.split_once("/notes/").map(|(author, _)| author.to_owned()).unwrap_or_else(|| uri.to_owned())
}

fn owner_actor(policy: &Value, owner: i64) -> String {
    if owner < 1 { return String::new(); }
    rows(policy, "users").find(|user| number(&user["id"]) == owner).map(|user| norm_actor(field(user, "actor_id"))).unwrap_or_default()
}

fn muted(owner: i64, texts: &[&str], policy: &Value) -> bool {
    if owner < 1 { return false; }
    let phrases = phrases(policy, owner);
    if phrases.is_empty() { return false; }
    let mut hay = String::new();
    for text in texts {
        let normalized = normalize_hay(text);
        if normalized.is_empty() { continue; }
        if !hay.is_empty() { hay.push(' '); }
        hay.push_str(&normalized);
    }
    !hay.is_empty() && phrases.iter().any(|phrase| !phrase.is_empty() && hay.contains(phrase))
}

fn phrases(policy: &Value, owner: i64) -> Vec<String> {
    let mut out = Vec::new();
    for row in rows(policy, "words").filter(|row| number(&row["owner_user_id"]) == owner) {
        let phrase = field(row, "phrase").trim().to_lowercase();
        if !phrase.is_empty() && !out.contains(&phrase) { out.push(phrase); }
    }
    out
}

fn normalize_hay(text: &str) -> String {
    let decoded = crate::home_hydrate_ranked::html_entity_decode(text);
    let mut plain = String::new();
    let mut tag = false;
    for character in decoded.chars() {
        match character {
            '<' => tag = true,
            '>' if tag => tag = false,
            _ if !tag => plain.push(character),
            _ => {}
        }
    }
    plain.split_whitespace().collect::<Vec<_>>().join(" ").to_lowercase()
}

fn nested_all(status: &Value, mut allow: impl FnMut(&Value) -> bool) -> bool {
    for key in ["reblog", "quote", "vaak_quote_preview"] {
        if let Some(child) = nested(status, key) {
            if !allow(child) { return false; }
        }
    }
    true
}

fn nested<'a>(status: &'a Value, key: &str) -> Option<&'a Value> {
    let child = status.get(key).filter(|value| value.is_object())?;
    if child.get("quoted_status").is_some_and(Value::is_object) { return child.get("quoted_status"); }
    if child.get("status").is_some_and(Value::is_object) { return child.get("status"); }
    Some(child)
}

fn did_hidden(policy: &Value, owner: i64, did: &str, ignore_case: bool) -> bool {
    let did = trimmed(did);
    if did.is_empty() { return false; }
    rows(policy, "hide_dids").any(|row| {
        number(&row["owner_user_id"]) == owner && {
            let stored = field(row, "did");
            stored == did || (ignore_case && stored.eq_ignore_ascii_case(did))
        }
    })
}

fn listed(policy: &Value, key: &str, actor: &str) -> bool {
    policy.get(key).and_then(Value::as_array).is_some_and(|items| items.iter().any(|item| same_actor(item.as_str().unwrap_or(""), actor)))
}

fn user_has(policy: &Value, key: &str, owner: i64, actor: &str) -> bool {
    policy.get(key).and_then(|map| map.get(owner.to_string())).and_then(Value::as_array)
        .is_some_and(|items| items.iter().any(|item| same_actor(item.as_str().unwrap_or(""), actor)))
}

fn rows<'a>(policy: &'a Value, key: &str) -> impl Iterator<Item = &'a Value> {
    policy.get(key).and_then(Value::as_array).into_iter().flatten()
}

fn collection(value: &Value) -> impl Iterator<Item = &Value> {
    value.as_array().into_iter().flatten()
}

fn is_collection(value: &Value) -> bool { value.is_object() || value.is_array() }

fn policy_key(reference: &str) -> String {
    let trimmed = php_trim(reference);
    let head = trimmed.split_once('#').map(|(head, _)| head).unwrap_or(trimmed);
    head.trim_end_matches('/').to_owned()
}

fn norm_actor(actor: &str) -> String { php_trim(actor).trim_end_matches('/').to_owned() }

fn same_actor(left: &str, right: &str) -> bool {
    let right = norm_actor(right);
    !right.is_empty() && norm_actor(left) == right
}

fn field<'a>(value: &'a Value, key: &str) -> &'a str { value.get(key).and_then(Value::as_str).unwrap_or("") }

fn account_field<'a>(status: &'a Value, key: &str) -> &'a str {
    status.get("account").and_then(|account| account.get(key)).and_then(Value::as_str).unwrap_or("")
}

fn lowered(value: &str) -> String {
    if value.is_empty() { "public".to_owned() } else { value.to_lowercase() }
}

fn number(value: &Value) -> i64 {
    value.as_i64().or_else(|| value.as_str().and_then(|text| text.parse().ok())).unwrap_or(0)
}

fn trimmed(value: &str) -> &str { php_trim(value) }

fn is_did(actor: &str) -> bool { actor.len() >= 4 && actor.as_bytes()[..4].eq_ignore_ascii_case(b"did:") }

fn deleted_marker(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(flag) => *flag,
        Value::Number(number) => number.as_i64() != Some(0) && number.as_f64() != Some(0.0),
        Value::String(text) => !text.is_empty() && text != "0",
        Value::Array(items) => !items.is_empty(),
        Value::Object(map) => !map.is_empty(),
    }
}

fn php_truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(flag) => *flag,
        Value::Number(number) => number.as_f64().is_some_and(|number| number != 0.0),
        Value::String(text) => !text.is_empty() && text != "0",
        Value::Array(items) => !items.is_empty(),
        Value::Object(map) => !map.is_empty(),
    }
}

fn php_truthy_at(value: &Value, key: &str) -> bool { value.get(key).is_some_and(php_truthy) }

#[cfg(test)]
mod tests {
    use super::*;

    fn flag(value: &Value, key: &str) -> bool { value.get(key).and_then(Value::as_bool).unwrap_or(false) }
    fn text<'a>(value: &'a Value, key: &str) -> &'a str { value.get(key).and_then(Value::as_str).unwrap_or("") }
    fn owner_of(value: &Value) -> i64 { value.get("owner").and_then(Value::as_i64).unwrap_or(0) }

    fn capture() -> Value {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/library-privacy-capture.php");
        let output = std::process::Command::new(file.parent().unwrap().join("php-policy-test")).arg(&file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        serde_json::from_slice(&output.stdout).unwrap()
    }

    #[test]
    fn library_privacy_and_cw_match_php() {
        let captured = capture();
        let cases = captured["cases"].as_array().unwrap();
        let mut seen = Vec::new();
        for case in cases {
            let id = text(case, "id");
            seen.push(id.to_owned());
            let owner = owner_of(case);
            let policy = &case["policy"];
            let status = &case["status"];
            assert_eq!(status_allowed(status, owner, true, policy), flag(case, "allowed_ordinary"), "{id} ordinary");
            assert_eq!(status_allowed(status, owner, false, policy), flag(case, "allowed_strict"), "{id} strict");
            assert_eq!(render_allowed(status, owner, text(case, "plain"), &case["hydrated"], &case["previews"], policy), flag(case, "render_allowed"), "{id} render");
            assert_eq!(actor_hidden(account_field(status, "uri"), owner, policy), flag(case, "actor_hidden"), "{id} actor");
        }
        for id in ["guest_public", "guest_negative", "guest_server_block", "public", "unlisted", "stale_private", "private_follower", "private_revoked", "private_author", "private_mk_following", "private_mk_follower", "direct_mention", "direct_revoked", "direct_absent", "direct_announce", "direct_follow", "local_member", "local_remote_author", "local_empty_viewer", "missing_local_note", "outbox_recipient", "outbox_stranger", "outbox_public_override", "personal_block", "personal_mute", "server_block", "server_mute", "block_via_url", "deprioritized_visible", "row_hidden", "did_mute", "word_stranger", "word_in_attribute", "word_own", "word_nested_quote", "word_own_disabled", "ask_own", "ask_nested", "deleted_tombstone", "deleted_action", "deleted_mention", "mention_zero", "newer_delete", "private_child", "private_child_granted", "create_beats_quote_event", "quote_only_private", "quote_only_public", "fragment_private", "depth_16", "depth_17", "bsky_ok", "bsky_hide_did", "bsky_blocked_by", "bsky_blocked_by_false", "bsky_muted_text", "bsky_nested_did", "plain_word", "plain_word_own", "hydrated_own_word", "preview_blocked_actor", "retained_private_preview"] {
            assert!(seen.iter().any(|seen| seen == id), "required PHP privacy case {id}");
        }
        let case = |id: &str| cases.iter().find(|case| text(case, "id") == id).unwrap();
        assert!(flag(case("guest_server_block"), "actor_hidden") && !flag(case("guest_server_block"), "allowed_ordinary"));
        assert!(flag(case("deprioritized_visible"), "allowed_ordinary") && !flag(case("deprioritized_visible"), "actor_hidden"));
        assert!(flag(case("word_own"), "allowed_ordinary") && !flag(case("word_own"), "allowed_strict"));
        assert!(!flag(case("word_nested_quote"), "allowed_ordinary"));
        assert!(flag(case("depth_16"), "allowed_ordinary") && !flag(case("depth_17"), "allowed_ordinary"));
        assert!(flag(case("private_child_granted"), "allowed_ordinary") && !flag(case("private_child"), "allowed_ordinary"));
        assert!(flag(case("plain_word"), "allowed_ordinary") && !flag(case("plain_word"), "render_allowed"));
        let mut cw_seen = Vec::new();
        for cw in captured["cw"].as_array().unwrap() {
            let id = text(cw, "id");
            cw_seen.push(id.to_owned());
            assert_eq!(crate::library::card::cw_gate(text(cw, "spoiler"), flag(cw, "sensitive"), text(cw, "inner"), flag(cw, "auto")), text(cw, "html"), "{id} content warning");
        }
        for id in ["open_plain", "sensitive_closed", "sensitive_open", "custom_stays_closed", "empty_inner", "neither", "whitespace_inner", "trimmed_spoiler", "spoiler_only"] {
            assert!(cw_seen.iter().any(|seen| seen == id), "required PHP content-warning case {id}");
        }
        let custom = captured["cw"].as_array().unwrap().iter().find(|cw| text(cw, "id") == "custom_stays_closed").unwrap();
        assert!(text(custom, "html").contains("Tom &amp; &quot;Jerry&#039;s&quot; &lt;cw&gt;"));
        assert!(text(custom, "html").starts_with("<details"));
        let whitespace = captured["cw"].as_array().unwrap().iter().find(|cw| text(cw, "id") == "whitespace_inner").unwrap();
        assert!(text(whitespace, "html").contains("<div class=\"cw-body\"></div>"));
        assert!(!text(whitespace, "html").contains("sensitive media"));
    }
}
