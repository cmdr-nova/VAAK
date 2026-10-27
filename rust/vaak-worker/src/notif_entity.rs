//! Mastodon notification entities for the database page.
//!
//! PHP `ap_masto_notification_entity` picks a different status for each type.
//! A mention, quote, or subscribed post is the notifier's own status (snowflake
//! type 2, remote account), even when its object is one of our notes. A
//! favourite or reblog is our post: the local note, then a local-account
//! event, then a Bluesky mirror twin, and only then a placeholder. This module
//! builds that fetch-shaped JSON from rows already loaded in a fixed batch.
//! It does not fetch, warm actors, or write.

use std::collections::{HashMap, HashSet};

use anyhow::Result;
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::notif;
use crate::notif_page::{format_notif_time, remote_account_id};
use crate::profile_materialize::{
    notification_clean_text, notification_html_tags, notification_media_json, notification_normalized_html,
    notification_plain_html, notification_poll_json, notification_profile_fields, notification_render_pack,
};

const REMOTE_AVATAR: &str = "https://mkultra.monster/img/avatar/default.webp";
const LOCAL_AVATAR: &str = "https://mkultra.monster/img/avatar/local-default.webp";
const LOCAL_HEADER: &str = "https://mkultra.monster/img/gifs/waves.gif";
const LOCAL_CREATED: &str = "2018-01-01T00:00:00.000Z";
const REMOTE_CREATED: &str = "2020-01-01T00:00:00.000Z";

#[derive(Clone, Debug)]
pub struct OwnerView {
    pub user_id: i64,
    pub actor_id: String,
    pub actor_key: String,
    pub username: String,
}

/// One kept notification, already typed and id'd by the page scan.
#[derive(Clone, Debug)]
pub struct NotifInput {
    pub id: String,
    pub kind: &'static str,
    pub db_id: i64,
    pub created: String,
    pub actor_id: String,
    pub type_name: String,
    pub object_id: String,
    pub content: String,
    pub activity_id: String,
    pub is_bsky: bool,
    pub owner_actor: String,
    /// Poll rows store the poll's status local id here. Mention rows leave 0.
    pub status_local_id: i64,
    pub in_reply_to: String,
    pub media_urls: String,
    pub spoiler: String,
    pub sensitive: bool,
}

#[derive(Clone, Debug, Default)]
struct StatusRow {
    local_id: i64,
    note_id: String,
    published: String,
    content_text: String,
    spoiler_text: String,
    sensitive: bool,
    visibility: String,
    edited_at: String,
    in_reply_to_local_id: Option<i64>,
}

#[derive(Clone, Debug, Default)]
struct OutboxRow {
    content: String,
    raw_create_json: String,
    in_reply_to: String,
}

#[derive(Clone, Debug, Default)]
struct EventRow {
    id: i64,
    created_at: String,
    actor_id: String,
    object_id: String,
    kind: String,
    summary: String,
    media_urls: String,
    spoiler_text: String,
    sensitive: bool,
    visibility: String,
    in_reply_to: String,
}

#[derive(Clone, Debug, Default)]
struct MentionParent {
    id: i64,
    created_at: String,
    actor_id: String,
}

#[derive(Clone, Debug, Default)]
pub struct PageFacts {
    statuses: HashMap<String, StatusRow>,
    by_local: HashMap<i64, String>,
    outbox: HashMap<String, OutboxRow>,
    media: HashMap<i64, Vec<Value>>,
    polls: HashMap<i64, Value>,
    pins: HashSet<i64>,
    events: HashMap<String, EventRow>,
    mention_parents: HashMap<String, MentionParent>,
    /// at:// URI and https://bsky.app permalink, both mapped to a local note id.
    bsky_note: HashMap<String, String>,
    cards: HashMap<String, Value>,
    fav_ids: HashSet<String>,
    fav_objects: HashSet<String>,
    bookmark_ids: HashSet<String>,
    bookmark_objects: HashSet<String>,
    reblog_ids: HashSet<String>,
    users: HashMap<String, Value>,
    profiles: HashMap<String, Value>,
    remotes: HashMap<String, Value>,
    avatars: HashMap<String, String>,
    headers: HashMap<String, String>,
    followers: HashMap<String, i64>,
    following: HashMap<String, i64>,
    statuses_count: HashMap<String, i64>,
    /// Bluesky handle or DID, lowercased, to `vaak:{user id}` when the account is linked.
    identities: HashMap<String, String>,
}

fn trim_slash(value: &str) -> String {
    value.trim().trim_end_matches('/').to_string()
}

fn time_seed(raw: &str) -> String {
    let raw = raw.trim();
    if raw.is_empty() {
        return String::new();
    }
    if notif::parse_epoch_secs(raw).is_some() {
        return raw.to_string();
    }
    if raw.ends_with("+00") || raw.ends_with("-00") {
        let padded = format!("{raw}00");
        if notif::parse_epoch_secs(&padded).is_some() {
            return padded;
        }
    }
    let swapped = raw.replace(' ', "T");
    if notif::parse_epoch_secs(&swapped).is_some() {
        return swapped;
    }
    raw.to_string()
}

fn stamp(raw: &str) -> String {
    let seed = time_seed(raw);
    if seed.is_empty() {
        return format_notif_time(None);
    }
    format_notif_time(Some(&seed))
}

fn snow(raw: &str, id: i64, kind: i64) -> String {
    let seed = time_seed(raw);
    notif::snowflake_id(if seed.is_empty() { None } else { Some(&seed) }, id, kind)
}

fn json_string(value: &Value, key: &str) -> String {
    match value.get(key) {
        Some(Value::String(text)) => text.clone(),
        Some(Value::Number(number)) => number.to_string(),
        _ => String::new(),
    }
}

fn json_i64(value: &Value, key: &str) -> i64 {
    value
        .get(key)
        .and_then(|item| item.as_i64().or_else(|| item.as_str().and_then(|text| text.parse().ok())))
        .unwrap_or(0)
}

fn json_truth(value: &Value, key: &str) -> bool {
    match value.get(key) {
        Some(Value::Bool(flag)) => *flag,
        Some(Value::Number(number)) => number.as_i64().unwrap_or(0) != 0,
        Some(Value::String(text)) => matches!(text.to_ascii_lowercase().as_str(), "1" | "t" | "true" | "yes"),
        _ => false,
    }
}

fn local_key_of(url: &str) -> Option<String> {
    let url = trim_slash(url);
    for prefix in [
        "https://mkultra.monster/users/",
        "https://www.mkultra.monster/users/",
        "https://vaak.monster/users/",
        "https://www.vaak.monster/users/",
    ] {
        if let Some(rest) = url.strip_prefix(prefix) {
            let key = rest.split('/').next().unwrap_or("");
            if !key.is_empty() && key.chars().all(|ch| ch.is_ascii_alphanumeric() || ch == '_') {
                return Some(key.to_string());
            }
        }
    }
    None
}

fn local_note_actor(url: &str) -> Option<String> {
    let url = trim_slash(url);
    let key = local_key_of(&url)?;
    if url.contains("/notes/") {
        return Some(format!("https://mkultra.monster/users/{key}"));
    }
    None
}

fn encode_part(raw: &str) -> String {
    let mut out = String::new();
    for byte in raw.bytes() {
        if byte.is_ascii_alphanumeric() || b"-_.~".contains(&byte) {
            out.push(byte as char);
        } else {
            out.push_str(&format!("%{byte:02X}"));
        }
    }
    out
}

fn https_url(raw: &str) -> Option<String> {
    let raw = raw.trim();
    if raw.starts_with("https://") && !raw.chars().any(char::is_whitespace) && raw.split('/').nth(2).is_some_and(|host| !host.is_empty()) {
        Some(raw.to_string())
    } else {
        None
    }
}

fn group_key(type_name: &str, actor: &str, group_ref: &str) -> String {
    let actor = {
        let trimmed = trim_slash(actor);
        if trimmed.is_empty() { "-".to_string() } else { trimmed }
    };
    let mut hasher = Sha256::new();
    hasher.update(format!("{actor}|{group_ref}").as_bytes());
    let hexed = hex::encode(hasher.finalize());
    format!("{type_name}-{}", &hexed[..24])
}

fn quote_approval() -> Value {
    json!({"automatic": ["public"], "manual": [], "current_user": "automatic"})
}

fn visibility_of(raw: &str) -> String {
    match raw.trim().to_ascii_lowercase().as_str() {
        "local" => "unlisted".into(),
        "followers" | "followers_only" | "mutual" | "private" => "private".into(),
        "public" | "unlisted" | "direct" => raw.trim().to_ascii_lowercase(),
        _ => "public".into(),
    }
}

fn account_id_for_key(key: &str, user: Option<&Value>) -> String {
    if key == "cmdr_nova" {
        return "1".into();
    }
    let uid = user.map(|row| json_i64(row, "id")).unwrap_or(0);
    if uid > 0 { uid.to_string() } else { String::new() }
}

fn local_account(actor_or_note: &str, facts: &PageFacts, owner: &OwnerView) -> Value {
    let key = local_key_of(actor_or_note).unwrap_or_else(|| owner.actor_key.clone());
    let actor = format!("https://mkultra.monster/users/{key}");
    let user = facts.users.get(&actor).or_else(|| facts.users.get(&key));
    let profile = facts.profiles.get(&key);
    let username = user
        .map(|row| json_string(row, "username"))
        .filter(|name| !name.is_empty())
        .unwrap_or_else(|| if key == owner.actor_key && !owner.username.is_empty() { owner.username.clone() } else { key.clone() });
    let mut display = profile.map(|row| json_string(row, "name").trim().to_string()).unwrap_or_default();
    if display.is_empty() {
        display = username.clone();
    }
    if profile.is_some_and(|row| json_truth(row, "vanity_verified")) {
        display.push_str(" ✓");
    }
    if profile.is_some_and(|row| json_truth(row, "automated")) {
        display.push_str(" 🤖");
    }
    let avatar = profile
        .and_then(|row| https_url(&json_string(row, "icon_url")))
        .unwrap_or_else(|| LOCAL_AVATAR.to_string());
    let header = profile
        .and_then(|row| https_url(&json_string(row, "image_url")))
        .unwrap_or_else(|| LOCAL_HEADER.to_string());
    let note = profile.map(|row| json_string(row, "summary")).unwrap_or_default();
    let fields = profile.map(notification_profile_fields).unwrap_or_default();
    let followers = facts.followers.get(&actor).copied().unwrap_or(0);
    let following = facts.following.get(&actor).copied().unwrap_or(0);
    // PHP counts at most 200 outbox notes for every local account except
    // cmdr_nova, whose public-post total also reads a static file. The page
    // uses the grouped outbox count and stops at 200 so that file stays unread.
    let statuses = facts.statuses_count.get(&actor).copied().unwrap_or(0).min(200);
    json!({
        "id": account_id_for_key(&key, user),
        "username": username,
        "acct": username,
        "display_name": display,
        "locked": profile.is_some_and(|row| json_truth(row, "manually_approves")),
        "bot": false,
        "discoverable": profile.is_some_and(|row| json_truth(row, "discoverable")),
        "indexable": profile.map(|row| value_present_truth(row, "indexable")).unwrap_or(true),
        "group": false,
        "created_at": LOCAL_CREATED,
        "note": note,
        "url": format!("https://vaak.monster/users/{key}"),
        "uri": actor,
        "avatar": avatar,
        "avatar_static": avatar,
        "header": header,
        "header_static": header,
        "followers_count": followers,
        "following_count": following,
        "statuses_count": statuses,
        "last_status_at": null,
        "emojis": [],
        "fields": fields
    })
}

fn value_present_truth(row: &Value, key: &str) -> bool {
    if row.get(key).is_none() || row.get(key).is_some_and(Value::is_null) {
        return true;
    }
    json_truth(row, key)
}

fn remote_account(actor: &str, facts: &PageFacts) -> Value {
    let actor = trim_slash(actor);
    let row = facts.remotes.get(&actor);
    let mut username = row.map(|item| json_string(item, "username").trim().trim_start_matches('@').to_string()).unwrap_or_default();
    let mut host = row.map(|item| json_string(item, "host").trim().to_ascii_lowercase()).unwrap_or_default();
    if host.is_empty() {
        host = actor.split('/').nth(2).unwrap_or("").to_ascii_lowercase();
    }
    let mut acct_key_name = String::new();
    if username.is_empty() {
        if let Some(name) = path_handle(&actor, regex_users()) {
            acct_key_name = name.clone();
            username = name;
        } else if let Some(name) = path_handle(&actor, regex_profile()) {
            // Bluesky profile IRIs display the handle. PHP's account id still
            // keys on the actor IRI until a remote_actors row supplies a username.
            username = name;
        } else if let Some(name) = actor.rsplit('/').next() {
            // PHP's acct-key regex does not match /users/{name}. The visible
            // username is still the path's last segment; the id stays the actor IRI.
            let name = name.trim().trim_start_matches('@');
            if !name.is_empty() && !name.chars().all(|ch| ch.is_ascii_digit()) && !crate::profile_identity::placeholder(name) {
                username = name.to_string();
            }
        }
    } else if !crate::profile_identity::placeholder(&username) && !username.chars().all(|ch| ch.is_ascii_digit()) {
        acct_key_name = username.clone();
    }
    if username.is_empty() {
        username = "user".into();
    }
    let mut acct = if host.is_empty() { username.clone() } else { format!("{username}@{host}") };
    let avatar = peer_avatar(&actor)
        .map(str::to_string)
        .or_else(|| facts.avatars.get(&actor).and_then(|url| https_url(url)))
        .or_else(|| row.and_then(|item| https_url(&json_string(item, "icon_source_url"))))
        .unwrap_or_else(|| REMOTE_AVATAR.to_string());
    let header = if peer_avatar(&actor).is_some() {
        avatar.clone()
    } else {
        facts.headers.get(&actor).and_then(|url| https_url(url))
            .or_else(|| row.and_then(|item| https_url(&json_string(item, "image_source_url"))))
            .unwrap_or_else(|| avatar.clone())
    };
    let mut web = actor.clone();
    let lower_user = username.to_ascii_lowercase();
    if host == "bsky.app" && !username.is_empty() && !crate::profile_identity::placeholder(&username) && !lower_user.starts_with("did:") {
        acct = username.clone();
        web = format!("https://bsky.app/profile/{}", encode_part(&username));
    } else if !host.is_empty() && username != "user" && !crate::profile_identity::placeholder(&username) {
        web = format!("https://{host}/@{}", encode_part(&username));
    }
    if let Some(bare) = crate::home_hydrate_ranked::local_api_acct(&username, &host, &actor) {
        acct = bare;
    }
    let seed = if !acct_key_name.is_empty() && !host.is_empty() && host != "unknown" {
        format!("acct:{}@{}", acct_key_name.to_ascii_lowercase(), host)
    } else {
        actor.clone()
    };
    json!({
        "id": remote_account_id(&seed),
        "username": username,
        "acct": acct,
        "display_name": row.map(|item| json_string(item, "display_name")).filter(|text| !text.is_empty()).unwrap_or_else(|| username.clone()),
        "locked": false,
        "bot": false,
        "discoverable": true,
        "group": false,
        "created_at": REMOTE_CREATED,
        "note": "",
        "url": web,
        "uri": actor,
        "avatar": avatar,
        "avatar_static": avatar,
        "header": header,
        "header_static": header,
        "followers_count": 0,
        "following_count": 0,
        "statuses_count": 0,
        "last_status_at": null,
        "emojis": [],
        "fields": []
    })
}

fn peer_avatar(actor: &str) -> Option<&'static str> {
    match trim_slash(actor).as_str() {
        "https://waffles.baeddel.social/fediverse/blog/admin" | "https://app.wafrn.net/fediverse/blog/admin" => {
            Some("https://mkultra.monster/img/avatar/wafrn-approvals.webp")
        }
        _ => None,
    }
}

fn regex_users() -> &'static regex::Regex {
    static RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?i)/(?:users|@)([^/]+)$").unwrap())
}

fn regex_profile() -> &'static regex::Regex {
    static RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?i)/profile/([^/]+)$").unwrap())
}

fn path_handle(actor: &str, pattern: &regex::Regex) -> Option<String> {
    let raw = pattern.captures(actor)?.get(1)?.as_str().trim().trim_start_matches('@');
    let name = rawurldecode(raw);
    if name.is_empty() || name.chars().all(|ch| ch.is_ascii_digit()) || crate::profile_identity::placeholder(&name) {
        None
    } else {
        Some(name)
    }
}

fn rawurldecode(raw: &str) -> String {
    let bytes = raw.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut index = 0;
    while index < bytes.len() {
        if bytes[index] == b'%' && index + 2 < bytes.len() {
            if let Ok(byte) = u8::from_str_radix(std::str::from_utf8(&bytes[index + 1..index + 3]).unwrap_or(""), 16) {
                out.push(byte);
                index += 3;
                continue;
            }
        }
        out.push(bytes[index]);
        index += 1;
    }
    String::from_utf8(out).unwrap_or_else(|_| raw.to_string())
}

fn account_for_actor(actor: &str, facts: &PageFacts, owner: &OwnerView) -> Value {
    let actor = trim_slash(actor);
    if actor.is_empty() {
        return local_account(&owner.actor_id, facts, owner);
    }
    if local_key_of(&actor).is_some() || facts.users.contains_key(&actor) {
        return local_account(&actor, facts, owner);
    }
    remote_account(&actor, facts)
}

fn mention_link(actor: &str, facts: &PageFacts, owner: &OwnerView) -> Value {
    let actor = trim_slash(actor);
    if actor == "https://mkultra.monster/users/cmdr_nova" {
        return json!({"id": "1", "username": "cmdr_nova", "url": "https://mkultra.monster/users/cmdr_nova", "acct": "cmdr_nova"});
    }
    let account = account_for_actor(&actor, facts, owner);
    let uri = json_string(&account, "uri");
    let url = if uri.starts_with("https://") { uri } else if !actor.is_empty() { actor } else { json_string(&account, "url") };
    json!({"id": account["id"], "username": account["username"], "url": url, "acct": account["acct"]})
}

fn push_mention(mentions: &mut Vec<Value>, mention: Value) {
    let acct = json_string(&mention, "acct");
    if let Some(existing) = mentions.iter_mut().find(|item| json_string(item, "acct").eq_ignore_ascii_case(&acct)) {
        *existing = mention;
    } else {
        mentions.push(mention);
    }
}

fn pack_text(raw: &str, extras: &[String], facts: &PageFacts, owner: &OwnerView) -> (String, Vec<Value>, Vec<Value>) {
    let cleaned = notification_clean_text(raw);
    let mut mentions = Vec::new();
    for actor in extras {
        let actor = trim_slash(actor);
        if !actor.is_empty() {
            push_mention(&mut mentions, mention_link(&actor, facts, owner));
        }
    }
    let acct_re = regex::Regex::new(r"(?i)(^|[^A-Za-z0-9_])@([A-Za-z0-9_]+)@([a-z0-9.-]+\.[a-z]{2,})").unwrap();
    for caps in acct_re.captures_iter(&cleaned) {
        let user = caps[2].to_string();
        let host = caps[3].to_ascii_lowercase();
        let acct = format!("{user}@{host}");
        if mentions.iter().any(|item| json_string(item, "acct").eq_ignore_ascii_case(&acct)) {
            continue;
        }
        let actor = format!("https://{host}/users/{}", encode_part(&user));
        let mut link = mention_link(&actor, facts, owner);
        link["acct"] = json!(acct);
        link["username"] = json!(user);
        mentions.push(link);
    }
    let bare_re = regex::Regex::new(r"(^|[^A-Za-z0-9_@])@([A-Za-z0-9_]{2,32})").unwrap();
    for caps in bare_re.captures_iter(&cleaned) {
        let end = caps.get(0).map(|item| item.end()).unwrap_or(0);
        if cleaned[end..].chars().next().is_some_and(|ch| ch.is_ascii_alphanumeric() || "_@".contains(ch)) {
            continue;
        }
        let user = &caps[2];
        if mentions.iter().any(|item| json_string(item, "username").eq_ignore_ascii_case(user)) {
            continue;
        }
        if user.eq_ignore_ascii_case("cmdr_nova") {
            push_mention(&mut mentions, mention_link("https://mkultra.monster/users/cmdr_nova", facts, owner));
            continue;
        }
        if let Some((actor, _)) = facts.remotes.iter().find(|(_, row)| json_string(row, "username").eq_ignore_ascii_case(user)) {
            push_mention(&mut mentions, mention_link(actor, facts, owner));
        } else if facts.users.keys().any(|actor| local_key_of(actor).is_some_and(|key| key.eq_ignore_ascii_case(user))) {
            push_mention(&mut mentions, mention_link(&format!("https://mkultra.monster/users/{user}"), facts, owner));
        }
    }
    if !cleaned.is_empty() && (cleaned.contains('@') || cleaned.contains('#')) {
        if let Ok(pack) = notification_render_pack(&cleaned, &mentions) {
            let tags = pack.get("tags").and_then(Value::as_array).cloned().unwrap_or_default();
            let listed = pack.get("mentions").and_then(Value::as_array).cloned().unwrap_or(mentions);
            return (json_string(&pack, "content"), listed, tags);
        }
    }
    let html = notification_plain_html(&cleaned).unwrap_or_default();
    if html.contains('#') {
        let (html, tags) = notification_html_tags(&html);
        return (html, mentions, tags);
    }
    (html, mentions, Vec::new())
}

fn mention_media(mention_id: i64, raw: &str) -> Vec<Value> {
    let list: Value = serde_json::from_str(raw).unwrap_or(Value::Null);
    let mut out = Vec::new();
    for url in list.as_array().into_iter().flatten().filter_map(|item| item.as_str()) {
        let Some(clean) = https_url(url) else { continue };
        let lower = clean.to_ascii_lowercase();
        let gif = lower.contains(".gif") || lower.contains("tenor.com") || lower.contains("giphy.com") || lower.contains("klipy.com");
        let kind = if gif { "gifv" } else { "image" };
        out.push(json!({
            "id": format!("{}{}", 2_000_000 + mention_id, out.len() + 1),
            "type": kind,
            "url": clean,
            "preview_url": clean,
            "remote_url": clean,
            "preview_remote_url": null,
            "text_url": null,
            "meta": null,
            "description": if gif { Value::String("GIF".into()) } else { Value::Null },
            "blurhash": null,
            "mediaType": if gif { Value::String("image/gif".into()) } else { Value::Null }
        }));
        if out.len() == 4 {
            break;
        }
    }
    out
}

fn flags(status: &mut Value, facts: &PageFacts) {
    let id = json_string(status, "id");
    let object = trim_slash(&json_string(status, "uri"));
    status["favourited"] = json!(facts.fav_ids.contains(&id) || facts.fav_objects.contains(&object));
    status["bookmarked"] = json!(facts.bookmark_ids.contains(&id) || facts.bookmark_objects.contains(&object));
    status["reblogged"] = json!(facts.reblog_ids.contains(&id));
}

fn card_for(text: &str, blocked: bool, facts: &PageFacts) -> Value {
    if blocked {
        return Value::Null;
    }
    let Ok(Some(url)) = crate::direct_preview::extract_url(text) else {
        return Value::Null;
    };
    let key = crate::direct_preview::normalize(&url).unwrap_or(url);
    facts.cards.get(&key).cloned().unwrap_or(Value::Null)
}

fn preview_card(row: &Value) -> Value {
    if !row.is_object() || json_string(row, "url").is_empty() {
        return Value::Null;
    }
    if row.get("status").and_then(Value::as_str).is_some_and(|status| status != "ok") {
        return Value::Null;
    }
    let kind = match json_string(row, "type").as_str() {
        "link" | "photo" | "video" | "rich" => json_string(row, "type"),
        _ => "link".into(),
    };
    let image = row.get("image").filter(|item| item.is_string()).cloned().unwrap_or(Value::Null);
    json!({
        "url": json_string(row, "url"),
        "title": json_string(row, "title"),
        "description": json_string(row, "description"),
        "type": kind,
        "author_name": "",
        "author_url": "",
        "provider_name": json_string(row, "provider_name"),
        "provider_url": json_string(row, "provider_url"),
        "html": "",
        "width": if kind == "video" { 480 } else { 0 },
        "height": if kind == "video" { 270 } else { 0 },
        "image": image,
        "embed_url": "",
        "blurhash": null,
        "language": null,
        "published_at": null,
        "authors": []
    })
}

fn quote_target_from_text(text: &str) -> Option<String> {
    if !(text.contains("↪ QT") || text.contains("↪QT") || text.to_ascii_lowercase().contains("quote-inline") || text.contains("_misskey_quote") || regex_re().is_match(text)) {
        return None;
    }
    let decoded = crate::home_hydrate_ranked::html_entity_decode(text);
    https_in(&decoded).or_else(|| https_in(text))
}

fn regex_re() -> &'static regex::Regex {
    static RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?i)(?:^|\n)\s*RE:\s*https?://").unwrap())
}

fn https_in(text: &str) -> Option<String> {
    let re = regex::Regex::new(r#"https://[^\s<>'"]+"#).ok()?;
    let url = re.captures(text)?.get(0)?.as_str();
    let url = url.trim_end_matches(['.', ',', ')', ';', ']', '!', '?', '\'', '"']);
    https_url(url).map(|item| trim_slash(&item))
}

fn quote_target_from_create(raw: &str) -> Option<String> {
    let parsed: Value = serde_json::from_str(raw).ok()?;
    for key in ["quote", "quoteUri", "quoteUrl", "_misskey_quote"] {
        let value = &parsed["object"][key];
        let url = value.as_str().or_else(|| ["id", "url", "href"].iter().find_map(|name| value.get(*name).and_then(Value::as_str)));
        if let Some(url) = url.map(str::trim).filter(|item| item.starts_with("https://") || item.starts_with("at://")) {
            return Some(trim_slash(url));
        }
    }
    None
}

fn bsky_target(url: &str) -> bool {
    let url = url.trim();
    url.starts_with("https://bsky.app/") || url.starts_with("at://") || url.contains("bsky.brid.gy")
}

fn at_uri_from_bsky(url: &str, facts: &PageFacts) -> Option<String> {
    let url = url.trim();
    if url.starts_with("at://") {
        return Some(url.to_string());
    }
    let re = regex::Regex::new(r"(?i)^https://bsky\.app/profile/([^/]+)/post/([^/?#]+)").ok()?;
    let caps = re.captures(url)?;
    let actor = caps[1].replace("%3A", ":").replace("%3a", ":");
    let rkey = caps[2].to_string();
    if actor.starts_with("did:") {
        return Some(format!("at://{actor}/app.bsky.feed.post/{rkey}"));
    }
    facts.bsky_note.keys().find(|key| key.starts_with("at://") && key.ends_with(&format!("/app.bsky.feed.post/{rkey}"))).cloned()
        .or_else(|| facts.bsky_note.get(&trim_slash(url)).filter(|note| note.starts_with("at://")).cloned())
}

fn mapped_note(url: &str, facts: &PageFacts) -> Option<String> {
    let url = trim_slash(url);
    if let Some(note) = facts.bsky_note.get(&url) {
        if note.starts_with("https://") {
            return Some(trim_slash(note));
        }
    }
    let at = at_uri_from_bsky(&url, facts)?;
    facts.bsky_note.get(&at).filter(|note| note.starts_with("https://")).map(|note| trim_slash(note))
}

fn reply_ids(url: &str, facts: &PageFacts, owner: &OwnerView) -> (Value, Value, Option<String>) {
    let url = trim_slash(url);
    if url.is_empty() {
        return (Value::Null, Value::Null, None);
    }
    let resolved = if url.starts_with("https://bsky.app/") || url.starts_with("at://") {
        mapped_note(&url, facts).unwrap_or(url)
    } else {
        url.clone()
    };
    if let Some(actor) = local_note_actor(&resolved) {
        if let Some(row) = facts.statuses.get(&resolved) {
            let id = snow(&row.published, row.local_id, 0);
            let account = local_account(&resolved, facts, owner);
            return (json!(id), account["id"].clone(), Some(actor));
        }
    }
    if let Some(event) = facts.events.get(&resolved) {
        let id = snow(&event.created_at, event.id, 1);
        let account = if !event.actor_id.is_empty() {
            account_for_actor(&event.actor_id, facts, owner)["id"].clone()
        } else {
            Value::Null
        };
        return (json!(id), account, Some(event.actor_id.clone()).filter(|actor| !actor.is_empty()));
    }
    if let Some(parent) = facts.mention_parents.get(&resolved) {
        let id = snow(&parent.created_at, parent.id, 2);
        let account = if parent.actor_id.is_empty() {
            Value::Null
        } else {
            account_for_actor(&parent.actor_id, facts, owner)["id"].clone()
        };
        return (json!(id), account, Some(parent.actor_id.clone()).filter(|actor| !actor.is_empty()));
    }
    if resolved.starts_with("https://") {
        let actor = actor_from_object(&resolved);
        let account = actor.as_ref().map(|item| account_for_actor(item, facts, owner)["id"].clone()).unwrap_or(Value::Null);
        let id = {
            let mut crc: u32 = 0xFFFF_FFFF;
            for byte in resolved.as_bytes() {
                crc ^= u32::from(*byte);
                for _ in 0..8 {
                    let mask = (!(crc & 1)).wrapping_add(1);
                    crc = (crc >> 1) ^ (0xEDB8_8320 & mask);
                }
            }
            (900_000_000 + (u64::from(!crc) % 99_999_999)).to_string()
        };
        return (json!(id), account, actor);
    }
    (Value::Null, Value::Null, None)
}

fn actor_from_object(raw: &str) -> Option<String> {
    for pattern in [
        r"(?i)^(https://[^/]+)/users/([^/]+)/(?:statuses|notes|objects)/",
        r"(?i)^(https://[^/]+)/@([^/]+)/(?:statuses/)?[A-Za-z0-9_-]+",
    ] {
        let re = regex::Regex::new(pattern).ok()?;
        if let Some(caps) = re.captures(raw) {
            return Some(format!("{}/users/{}", &caps[1], encode_part(&caps[2])));
        }
    }
    None
}

fn apply_content_pack(status: &mut Value, plain: &str, html: &str, extras: &[String], facts: &PageFacts, owner: &OwnerView) {
    let source = if !plain.is_empty() { plain.to_string() } else { notification_clean_text(html) };
    if source.is_empty() {
        return;
    }
    if source.contains('@') || source.contains('#') {
        let (packed, mentions, tags) = pack_text(&source, extras, facts, owner);
        let markup = html.contains("class=\"u-url mention\"") || html.contains("class='u-url mention'") || html.contains("class=\"mention\"");
        if !markup && !packed.is_empty() {
            status["content"] = json!(packed);
        } else if html.contains('<') && html.contains('#') {
            let (next, found) = notification_html_tags(html);
            status["content"] = json!(next);
            if !found.is_empty() {
                status["tags"] = json!(found);
            }
        } else if !packed.is_empty() {
            status["content"] = json!(packed);
        }
        status["mentions"] = json!(mentions);
        if status.get("tags").and_then(Value::as_array).is_none_or(|tags| tags.is_empty()) && !tags.is_empty() {
            status["tags"] = json!(tags);
        }
    } else if html.contains('#') {
        let (next, tags) = notification_html_tags(html);
        status["content"] = json!(next);
        status["tags"] = json!(tags);
    }
}

fn local_status(note: &str, facts: &PageFacts, owner: &OwnerView, attach_quote: bool) -> Option<Value> {
    let note = trim_slash(note);
    let row = facts.statuses.get(&note)?.clone();
    let outbox = facts.outbox.get(&note);
    let mut text = row.content_text.clone();
    if matches!(text.as_str(), "(quote)" | "(media)" | "(poll)") {
        text.clear();
    }
    let outbox_html = outbox.map(|item| item.content.clone()).unwrap_or_default();
    let mut content = if !outbox_html.trim().is_empty() && !notification_clean_text(&outbox_html).is_empty() {
        notification_normalized_html(&outbox_html)
    } else {
        notification_plain_html(&text).unwrap_or_default()
    };
    if content.is_empty() {
        content = "<p></p>".into();
    }
    let (mut reply_id, mut reply_account, mut parent) = (Value::Null, Value::Null, None);
    if let Some(parent_local) = row.in_reply_to_local_id {
        if let Some(parent_note) = facts.by_local.get(&parent_local) {
            if let Some(parent_row) = facts.statuses.get(parent_note) {
                reply_id = json!(snow(&parent_row.published, parent_row.local_id, 0));
                let account = local_account(parent_note, facts, owner);
                reply_account = account["id"].clone();
                parent = Some(trim_slash(&json_string(&account, "uri")));
            }
        }
    }
    if reply_id.is_null() {
        if let Some(outbox) = outbox {
            if outbox.in_reply_to.starts_with("https://") {
                let (id, account, actor) = reply_ids(&outbox.in_reply_to, facts, owner);
                reply_id = id;
                reply_account = account;
                parent = actor;
            }
        }
    }
    let extras = parent.clone().into_iter().collect::<Vec<_>>();
    let media = facts.media.get(&row.local_id).cloned().unwrap_or_default();
    let pinned = facts.pins.contains(&row.local_id);
    let poll = facts.polls.get(&row.local_id).cloned().unwrap_or(Value::Null);
    let mut status = json!({
        "id": snow(&row.published, row.local_id, 0),
        "created_at": stamp(&row.published),
        "in_reply_to_id": reply_id,
        "in_reply_to_account_id": reply_account,
        "sensitive": row.sensitive,
        "spoiler_text": row.spoiler_text,
        "visibility": visibility_of(&row.visibility),
        "language": "en",
        "uri": note,
        "url": note,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "quotes_count": 0,
        "edited_at": if row.edited_at.is_empty() { Value::Null } else { json!(stamp(&row.edited_at)) },
        "favourited": false,
        "reblogged": false,
        "muted": false,
        "bookmarked": false,
        "pinned": pinned,
        "content": content,
        "reblog": null,
        "application": {"name": "mkultra.monster", "website": "https://mkultra.monster"},
        "account": local_account(&note, facts, owner),
        "media_attachments": media,
        "mentions": [],
        "tags": [],
        "emojis": [],
        "card": null,
        "poll": poll,
        "quote_approval": quote_approval()
    });
    apply_content_pack(&mut status, &text, &content, &extras, facts, owner);
    let card_source = if !text.is_empty() { text } else { json_string(&status, "content") };
    status["card"] = card_for(&card_source, !media_empty(&status), facts);
    if attach_quote {
        if let Some(target) = outbox.and_then(|item| quote_target_from_create(&item.raw_create_json)) {
            let author = local_note_actor(&note).unwrap_or_default();
            let known = facts.statuses.contains_key(&trim_slash(&target));
            let authorized = known || bsky_target(&target) || target.starts_with(&format!("{author}/notes/"));
            status["quote"] = json!(quote_entity(&target, authorized, facts, owner));
        }
    }
    flags(&mut status, facts);
    Some(status)
}

fn media_empty(status: &Value) -> bool {
    status.get("media_attachments").and_then(Value::as_array).is_none_or(|items| items.is_empty())
}

fn quote_entity(target: &str, authorized: bool, facts: &PageFacts, owner: &OwnerView) -> Value {
    if !authorized {
        return json!({"state": "pending", "quoted_status": null, "quoted_status_id": null});
    }
    let note = mapped_note(target, facts).unwrap_or_else(|| trim_slash(target));
    if let Some(mut status) = local_status(&note, facts, owner, false) {
        if let Some(map) = status.as_object_mut() {
            map.remove("quote");
        }
        let id = json_string(&status, "id");
        return json!({"state": "accepted", "quoted_status_id": id, "quoted_status": status});
    }
    if let Some(mut status) = event_status(&note, facts, owner) {
        if let Some(map) = status.as_object_mut() {
            map.remove("quote");
        }
        let id = json_string(&status, "id");
        return json!({"state": "accepted", "quoted_status_id": id, "quoted_status": status});
    }
    json!({"state": "pending", "quoted_status": null, "quoted_status_id": null})
}

fn event_status(object: &str, facts: &PageFacts, owner: &OwnerView) -> Option<Value> {
    let event = facts.events.get(&trim_slash(object))?.clone();
    let account = account_for_actor(&event.actor_id, facts, owner);
    let text = notification_clean_text(&event.summary);
    let (content, mentions, tags) = if text.contains('@') || text.contains('#') {
        pack_text(&text, &[], facts, owner)
    } else {
        (notification_plain_html(&text).unwrap_or_default(), Vec::new(), Vec::new())
    };
    let media = mention_media(event.id, &event.media_urls);
    let mut status = json!({
        "id": snow(&event.created_at, event.id, 1),
        "created_at": stamp(&event.created_at),
        "in_reply_to_id": Value::Null,
        "in_reply_to_account_id": Value::Null,
        "sensitive": event.sensitive || !event.spoiler_text.is_empty(),
        "spoiler_text": event.spoiler_text,
        "visibility": visibility_of(&event.visibility),
        "language": "en",
        "uri": event.object_id,
        "url": event.object_id,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "edited_at": null,
        "favourited": false,
        "reblogged": false,
        "muted": false,
        "bookmarked": false,
        "pinned": false,
        "content": content,
        "reblog": null,
        "application": null,
        "account": account,
        "media_attachments": media,
        "mentions": mentions,
        "tags": tags,
        "emojis": [],
        "card": null,
        "poll": null,
        "quote": null,
        "quote_approval": quote_approval()
    });
    if event.kind.eq_ignore_ascii_case("Announce") {
        if let Some(inner) = local_status(&event.object_id, facts, owner, false) {
            status["reblog"] = json!(inner);
            status["content"] = json!("");
            status["media_attachments"] = json!([]);
        }
    }
    flags(&mut status, facts);
    Some(status)
}

fn mention_status(item: &NotifInput, facts: &PageFacts, owner: &OwnerView) -> Value {
    let account = if item.actor_id.is_empty() {
        local_account(&owner.actor_id, facts, owner)
    } else {
        remote_account(&item.actor_id, facts)
    };
    let mut url = notif::mention_target_object_id(&item.object_id);
    if url.starts_with("at://") || url.starts_with("bsky:") {
        let at = url.trim_start_matches("bsky:");
        if let Some(https) = bsky_https(at) {
            url = https;
        }
    }
    if url.is_empty() {
        url = item.actor_id.clone();
    }
    let mut reply_url = trim_slash(&item.in_reply_to);
    if reply_url.is_empty() {
        if let Some(found) = local_note_in(&item.content) {
            reply_url = found;
        }
    }
    let (reply_id, reply_account, parent) = if reply_url.is_empty() {
        (Value::Null, Value::Null, None)
    } else {
        reply_ids(&reply_url, facts, owner)
    };
    let mut extras = Vec::new();
    if let Some(parent) = parent.clone() {
        extras.push(parent);
    }
    if !owner.actor_id.is_empty() {
        extras.push(owner.actor_id.clone());
    }
    let (content, mentions, tags) = pack_text(&item.content, &extras, facts, owner);
    let media = mention_media(item.db_id, &item.media_urls);
    let quote_url = quote_target_from_text(&item.content);
    let quote = quote_url.as_ref().map(|target| quote_entity(target, true, facts, owner));
    let mut body = content;
    if quote.as_ref().is_some_and(|item| item.get("state").and_then(Value::as_str) == Some("accepted")) {
        let plain = notification_clean_text(&body);
        if plain.contains("↪ QT") || plain.contains("↪QT") {
            let commentary = plain.split("↪").next().unwrap_or("").trim();
            body = if commentary.is_empty() {
                String::new()
            } else {
                notification_plain_html(commentary).unwrap_or_default()
            };
        }
    }
    let card_source = if quote.is_some() { body.clone() } else { item.content.clone() };
    let mut status = json!({
        "id": snow(&item.created, item.db_id, 2),
        "created_at": stamp(&item.created),
        "in_reply_to_id": reply_id,
        "in_reply_to_account_id": reply_account,
        "in_reply_to": if reply_url.is_empty() { Value::Null } else { json!(reply_url) },
        "sensitive": item.sensitive || !item.spoiler.is_empty(),
        "spoiler_text": item.spoiler,
        "visibility": "public",
        "language": "en",
        "uri": url,
        "url": url,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "edited_at": null,
        "favourited": false,
        "reblogged": false,
        "muted": false,
        "bookmarked": false,
        "pinned": false,
        "content": body,
        "reblog": null,
        "application": null,
        "account": account,
        "media_attachments": media,
        "mentions": mentions,
        "tags": tags,
        "emojis": [],
        "card": card_for(&card_source, quote.is_some() || !media.is_empty(), facts),
        "poll": null,
        "quote": quote.unwrap_or(Value::Null),
        "quote_approval": quote_approval()
    });
    flags(&mut status, facts);
    let _ = parent;
    status
}

fn bsky_https(at: &str) -> Option<String> {
    let re = regex::Regex::new(r"(?i)^at://(did:[^/]+)/app\.bsky\.feed\.post/([A-Za-z0-9]+)").ok()?;
    let caps = re.captures(at)?;
    Some(format!("https://bsky.app/profile/{}/post/{}", &caps[1], &caps[2]))
}

fn local_note_in(text: &str) -> Option<String> {
    let re = regex::Regex::new(r"(?i)https://mkultra\.monster/users/[A-Za-z0-9_]+/notes/[a-f0-9]+").ok()?;
    re.captures(text).and_then(|caps| caps.get(0).map(|item| trim_slash(item.as_str())))
}

fn liked_status(item: &NotifInput, facts: &PageFacts, owner: &OwnerView) -> Option<Value> {
    let target = notif::mention_target_object_id(&item.object_id);
    if target.starts_with("https://") {
        if facts.statuses.contains_key(&trim_slash(&target)) {
            return local_status(&target, facts, owner, true);
        }
        if facts.events.contains_key(&trim_slash(&target)) {
            let looked = event_status(&target, facts, owner)?;
            let account_url = trim_slash(&json_string(&looked["account"], "url"));
            let account_uri = trim_slash(&json_string(&looked["account"], "uri"));
            let account_id = json_string(&looked["account"], "id");
            let local_account = local_key_of(&account_url).is_some()
                || local_key_of(&account_uri).is_some()
                || (account_id == "1" && facts.users.keys().any(|actor| actor.ends_with("/cmdr_nova")))
                || facts.users.values().any(|user| json_i64(user, "id").to_string() == account_id && account_id != "0")
                || local_note_actor(&target).is_some();
            if local_account {
                if looked.get("reblog").is_some_and(|reblog| reblog.is_object()) {
                    return Some(looked["reblog"].clone());
                }
                return Some(looked);
            }
        }
        if let Some(caps) = regex::Regex::new(r"(?i)^https://mkultra\.monster/users/[A-Za-z0-9_]+/statuses/(\d+)/?$").ok()?.captures(&target) {
            if let Ok(local_id) = caps[1].parse::<i64>() {
                if let Some(note) = facts.by_local.get(&local_id) {
                    return local_status(note, facts, owner, true);
                }
            }
        }
    }
    if item.is_bsky {
        if let Some(note) = mapped_note(&target, facts) {
            if facts.statuses.contains_key(&note) {
                return local_status(&note, facts, owner, true);
            }
        }
        let mut status = mention_status(item, facts, owner);
        status["account"] = json!(local_account(&item.owner_actor, facts, owner));
        if target.starts_with("https://bsky.app/") || target.starts_with("https://mkultra.monster/") {
            status["url"] = json!(target);
            status["uri"] = json!(target);
        }
        let plain = notification_clean_text(&json_string(&status, "content"));
        if plain.is_empty() || plain == "Bluesky post" || item.content.contains("your Bluesky post") {
            status["content"] = json!("<p>Bluesky post</p>");
        }
        return Some(status);
    }
    None
}

fn notification_account(item: &NotifInput, facts: &PageFacts, owner: &OwnerView) -> Value {
    if item.actor_id.is_empty() {
        local_account(&owner.actor_id, facts, owner)
    } else {
        // PHP always builds the notifier with ap_masto_remote_account, including
        // when that actor URL is one of ours. The status author is separate.
        remote_account(&item.actor_id, facts)
    }
}

fn one_notification(item: &NotifInput, facts: &PageFacts, owner: &OwnerView) -> Option<Value> {
    let created = stamp(&item.created);
    if item.kind == "follow" || item.type_name == "follow" {
        return Some(json!({
            "id": item.id,
            "type": "follow",
            "group_key": format!("ungrouped-{}", item.id),
            "created_at": created,
            "account": notification_account(item, facts, owner),
            "status": null
        }));
    }
    if item.kind == "poll" || item.type_name == "poll" {
        let note = trim_slash(&item.object_id);
        let status = if item.status_local_id > 0 {
            facts.by_local.get(&item.status_local_id).and_then(|note| local_status(note, facts, owner, true))
        } else {
            None
        }
        .or_else(|| local_status(&note, facts, owner, true))?;
        let account = status.get("account").cloned().unwrap_or_else(|| local_account(&owner.actor_id, facts, owner));
        return Some(json!({
            "id": item.id,
            "type": "poll",
            "group_key": format!("ungrouped-{}", item.id),
            "created_at": created,
            "account": account,
            "status": status
        }));
    }
    let status = match item.type_name.as_str() {
        "favourite" | "reblog" => liked_status(item, facts, owner)?,
        "bite" => {
            let target = notif::mention_target_object_id(&item.object_id);
            if target.contains("/notes/") || target.contains("/statuses/") {
                liked_status(item, facts, owner).unwrap_or_else(|| mention_status(item, facts, owner))
            } else {
                mention_status(item, facts, owner)
            }
        }
        "update" => {
            let target = notif::mention_target_object_id(&item.object_id);
            if target.starts_with("https://") && facts.statuses.contains_key(&trim_slash(&target)) {
                local_status(&target, facts, owner, true).unwrap_or_else(|| mention_status(item, facts, owner))
            } else if target.starts_with("https://") {
                event_status(&target, facts, owner).unwrap_or_else(|| mention_status(item, facts, owner))
            } else {
                mention_status(item, facts, owner)
            }
        }
        _ => mention_status(item, facts, owner),
    };
    let actor = if item.actor_id.is_empty() { "-".to_string() } else { trim_slash(&item.actor_id) };
    let group_ref = json_string(&status, "id");
    Some(json!({
        "id": item.id,
        "type": item.type_name,
        "group_key": group_key(&item.type_name, &actor, &group_ref),
        "created_at": created,
        "account": notification_account(item, facts, owner),
        "status": status
    }))
}

fn identity_of(actor: &str, facts: &PageFacts) -> Option<String> {
    let actor = trim_slash(actor);
    if let Some(key) = local_key_of(&actor) {
        let full = format!("https://mkultra.monster/users/{key}");
        if let Some(user) = facts.users.get(&full).or_else(|| facts.users.get(&key)) {
            let uid = json_i64(user, "id");
            if uid > 0 {
                return Some(format!("vaak:{uid}"));
            }
        }
        return Some(format!("vaak:{key}"));
    }
    if let Some(rest) = actor.strip_prefix("https://bsky.app/profile/") {
        let key = rest.replace("%3A", ":").trim_start_matches('@').to_ascii_lowercase();
        return facts.identities.get(&key).cloned();
    }
    None
}

fn canonical_status(url: &str, facts: &PageFacts) -> String {
    let url = trim_slash(url);
    if url.contains("/notes/") && url.starts_with("https://mkultra.monster/users/") {
        return url;
    }
    mapped_note(&url, facts).unwrap_or(url)
}

fn drop_twin_echoes(items: &[NotifInput], facts: &PageFacts) -> Vec<usize> {
    let mut best: HashMap<String, (usize, bool)> = HashMap::new();
    let mut drop = HashSet::new();
    for (index, item) in items.iter().enumerate() {
        if item.kind != "mention" || !matches!(item.type_name.as_str(), "favourite" | "reblog") {
            continue;
        }
        let Some(ident) = identity_of(&item.actor_id, facts).filter(|key| key.starts_with("vaak:")) else {
            continue;
        };
        let canon = canonical_status(&notif::mention_target_object_id(&item.object_id), facts);
        if canon.is_empty() {
            continue;
        }
        let key = format!("{}:{canon}:{ident}", item.type_name);
        match best.get(&key).copied() {
            None => {
                best.insert(key, (index, item.is_bsky));
            }
            Some((prev, prev_bsky)) => {
                if item.is_bsky && !prev_bsky {
                    drop.insert(index);
                } else if !item.is_bsky && prev_bsky {
                    drop.insert(prev);
                    best.insert(key, (index, false));
                } else {
                    drop.insert(index);
                }
            }
        }
    }
    drop.into_iter().collect()
}

/// Fetch-shaped notifications. A favourite or reblog with no resolvable post
/// is omitted, matching PHP. Twin Bluesky echoes are dropped without a write.
pub fn build_notifications(items: &[NotifInput], facts: &PageFacts, owner: &OwnerView) -> Vec<Value> {
    let dropped = drop_twin_echoes(items, facts);
    let mut out = Vec::new();
    for (index, item) in items.iter().enumerate() {
        if dropped.contains(&index) {
            continue;
        }
        if let Some(entity) = one_notification(item, facts, owner) {
            out.push(entity);
        }
    }
    out
}

fn texts_of(value: &Value, out: &mut Vec<String>) {
    for key in ["content", "spoiler_text"] {
        if let Some(text) = value.get(key).and_then(Value::as_str) {
            if !text.is_empty() {
                out.push(text.to_string());
            }
        }
    }
    if let Some(status) = value.get("status").filter(|item| item.is_object()) {
        texts_of(status, out);
    }
    if let Some(reblog) = value.get("reblog").filter(|item| item.is_object()) {
        texts_of(reblog, out);
    }
    if let Some(quote) = value.get("quote").and_then(|item| item.get("quoted_status")).filter(|item| item.is_object()) {
        texts_of(quote, out);
    }
}

fn muted_entity(entity: &Value, phrases: &[String]) -> bool {
    if phrases.is_empty() {
        return false;
    }
    let mut texts = Vec::new();
    texts_of(entity, &mut texts);
    let borrowed: Vec<&str> = texts.iter().map(String::as_str).collect();
    crate::hidden::texts_match_muted_phrases(phrases, &borrowed)
}

/// Load every row the page needs, then build. Each query is one `ANY` batch.
/// A missing optional table warns and leaves that field empty.
pub async fn entities_for_page(db: &Client, owner: &OwnerView, items: &[NotifInput], muted: &[String]) -> Result<Vec<Value>> {
    let facts = load_facts(db, owner, items).await;
    let mut built = build_notifications(items, &facts, owner);
    if !built.is_empty() {
        let missing = notif::missing_local_notes_in_items(db, &built).await;
        built.retain(|entity| !notif::item_targets_missing_local(entity, &missing) && !muted_entity(entity, muted));
    }
    Ok(built)
}

async fn load_facts(db: &Client, owner: &OwnerView, items: &[NotifInput]) -> PageFacts {
    let mut facts = PageFacts::default();
    let mut notes = Vec::new();
    let mut actors = Vec::new();
    let mut bsky_urls = Vec::new();
    for item in items {
        if !item.actor_id.is_empty() {
            actors.push(trim_slash(&item.actor_id));
        }
        if !item.owner_actor.is_empty() {
            actors.push(trim_slash(&item.owner_actor));
        }
        let target = notif::mention_target_object_id(&item.object_id);
        if target.starts_with("https://mkultra.monster/") || target.contains("/notes/") {
            notes.push(trim_slash(&target));
        }
        if target.starts_with("https://bsky.app/") || target.starts_with("at://") || item.object_id.starts_with("at://") {
            bsky_urls.push(target);
            if item.activity_id.starts_with("at://") {
                bsky_urls.push(item.activity_id.clone());
            }
        }
        if item.in_reply_to.starts_with("https://") || item.in_reply_to.starts_with("at://") {
            notes.push(trim_slash(&item.in_reply_to));
            if item.in_reply_to.starts_with("https://bsky.app/") || item.in_reply_to.starts_with("at://") {
                bsky_urls.push(trim_slash(&item.in_reply_to));
            }
        }
        if let Some(quote) = quote_target_from_text(&item.content) {
            notes.push(quote.clone());
            if quote.starts_with("https://bsky.app/") || quote.starts_with("at://") {
                bsky_urls.push(quote);
            }
        }
    }
    if !owner.actor_id.is_empty() {
        actors.push(trim_slash(&owner.actor_id));
    }
    dedup(&mut notes);
    dedup(&mut actors);
    dedup(&mut bsky_urls);
    load_bsky(db, &bsky_urls, &mut facts).await;
    for note in facts.bsky_note.values() {
        if note.starts_with("https://") {
            notes.push(trim_slash(note));
        }
    }
    dedup(&mut notes);
    load_statuses(db, &notes, &mut facts).await;
    load_outbox(db, &notes, &mut facts).await;
    for outbox in facts.outbox.values() {
        if let Some(target) = quote_target_from_create(&outbox.raw_create_json) {
            if target.starts_with("https://") {
                notes.push(target);
            }
        }
        if outbox.in_reply_to.starts_with("https://") {
            notes.push(trim_slash(&outbox.in_reply_to));
        }
    }
    dedup(&mut notes);
    load_statuses(db, &notes, &mut facts).await;
    load_outbox(db, &notes, &mut facts).await;
    let local_ids: Vec<i64> = facts.statuses.values().map(|row| row.local_id).filter(|id| *id > 0).collect();
    load_media(db, &local_ids, &mut facts).await;
    load_polls(db, &local_ids, &owner.actor_id, &mut facts).await;
    load_pins(db, owner.user_id, &local_ids, &mut facts).await;
    load_events(db, &notes, &mut facts).await;
    load_mention_parents(db, &notes, &mut facts).await;
    for event in facts.events.values() {
        if !event.actor_id.is_empty() {
            actors.push(event.actor_id.clone());
        }
    }
    for parent in facts.mention_parents.values() {
        if !parent.actor_id.is_empty() {
            actors.push(parent.actor_id.clone());
        }
    }
    for note in &notes {
        if let Some(actor) = local_note_actor(note) {
            actors.push(actor);
        }
    }
    dedup(&mut actors);
    load_users(db, &actors, &mut facts).await;
    load_remotes(db, &actors, &mut facts).await;
    let status_ids: Vec<String> = facts.statuses.values().map(|row| snow(&row.published, row.local_id, 0)).collect();
    let count_actors: Vec<String> = facts.users.keys().filter(|actor| actor.starts_with("https://")).cloned().collect();
    let mut card_texts: Vec<String> = items.iter().map(|item| item.content.clone()).collect();
    card_texts.extend(facts.statuses.values().map(|row| row.content_text.clone()));
    load_flags(db, owner.user_id, &status_ids, &notes, &mut facts).await;
    load_counts(db, &count_actors, &mut facts).await;
    load_cards(db, &card_texts, &mut facts).await;
    load_identities(db, &mut facts).await;
    facts
}

fn dedup(values: &mut Vec<String>) {
    values.retain(|value| !value.is_empty());
    values.sort();
    values.dedup();
}

async fn quiet(db: &Client, sql: &str, params: &[&(dyn tokio_postgres::types::ToSql + Sync)]) -> Vec<tokio_postgres::Row> {
    match db.query(sql, params).await {
        Ok(rows) => rows,
        Err(err) => {
            tracing::warn!(error = %err, "notification entity lookup skipped");
            Vec::new()
        }
    }
}

async fn load_statuses(db: &Client, notes: &[String], facts: &mut PageFacts) {
    if notes.is_empty() {
        return;
    }
    for row in quiet(db, "SELECT to_jsonb(s) FROM masto_statuses s WHERE rtrim(note_id, '/') = ANY($1)", &[&notes]).await {
        let value: Value = row.get(0);
        let note = trim_slash(&json_string(&value, "note_id"));
        if note.is_empty() {
            continue;
        }
        let local_id = json_i64(&value, "local_id");
        let parent = json_i64(&value, "in_reply_to_local_id");
        facts.by_local.insert(local_id, note.clone());
        facts.statuses.insert(note.clone(), StatusRow {
            local_id,
            note_id: note,
            published: json_string(&value, "published"),
            content_text: json_string(&value, "content_text"),
            spoiler_text: json_string(&value, "spoiler_text"),
            sensitive: json_truth(&value, "sensitive"),
            visibility: {
                let raw = json_string(&value, "visibility");
                if raw.is_empty() { "public".into() } else { raw }
            },
            edited_at: json_string(&value, "edited_at"),
            in_reply_to_local_id: if parent > 0 { Some(parent) } else { None },
        });
    }
}

async fn load_outbox(db: &Client, notes: &[String], facts: &mut PageFacts) {
    if notes.is_empty() {
        return;
    }
    for row in quiet(db, "SELECT to_jsonb(n) FROM outbox_notes n WHERE rtrim(id, '/') = ANY($1)", &[&notes]).await {
        let value: Value = row.get(0);
        let id = trim_slash(&json_string(&value, "id"));
        if id.is_empty() {
            continue;
        }
        facts.outbox.insert(id, OutboxRow {
            content: json_string(&value, "content"),
            raw_create_json: json_string(&value, "raw_create_json"),
            in_reply_to: trim_slash(&json_string(&value, "in_reply_to")),
        });
    }
}

async fn load_media(db: &Client, local_ids: &[i64], facts: &mut PageFacts) {
    if local_ids.is_empty() {
        return;
    }
    let ids: Vec<i64> = local_ids.to_vec();
    for row in quiet(db, "SELECT to_jsonb(m) FROM masto_media m WHERE status_local_id = ANY($1) ORDER BY local_id ASC", &[&ids]).await {
        let value: Value = row.get(0);
        let status_id = json_i64(&value, "status_local_id");
        facts.media.entry(status_id).or_default().push(notification_media_json(&value));
    }
}

async fn load_polls(db: &Client, local_ids: &[i64], owner_actor: &str, facts: &mut PageFacts) {
    if local_ids.is_empty() {
        return;
    }
    let ids = local_ids.to_vec();
    for row in quiet(db, "SELECT to_jsonb(p) FROM masto_polls p WHERE status_local_id = ANY($1)", &[&ids]).await {
        let value: Value = row.get(0);
        let status_id = json_i64(&value, "status_local_id");
        if let Some(poll) = notification_poll_json(&value, owner_actor) {
            facts.polls.insert(status_id, poll);
        }
    }
}

async fn load_pins(db: &Client, owner: i64, local_ids: &[i64], facts: &mut PageFacts) {
    if local_ids.is_empty() || owner < 1 {
        return;
    }
    let ids = local_ids.to_vec();
    for row in quiet(db, "SELECT status_local_id::bigint FROM masto_pins WHERE owner_user_id = $1::bigint AND status_local_id = ANY($2)", &[&owner, &ids]).await {
        let id: i64 = row.get(0);
        facts.pins.insert(id);
    }
}

async fn load_events(db: &Client, notes: &[String], facts: &mut PageFacts) {
    if notes.is_empty() {
        return;
    }
    for row in quiet(
        db,
        "SELECT DISTINCT ON (rtrim(object_id, '/')) to_jsonb(e) FROM events e WHERE rtrim(object_id, '/') = ANY($1) ORDER BY rtrim(object_id, '/'), id DESC",
        &[&notes],
    )
    .await
    {
        let value: Value = row.get(0);
        let object = trim_slash(&json_string(&value, "object_id"));
        if object.is_empty() {
            continue;
        }
        facts.events.insert(object.clone(), EventRow {
            id: json_i64(&value, "id"),
            created_at: json_string(&value, "created_at"),
            actor_id: trim_slash(&json_string(&value, "actor_id")),
            object_id: object,
            kind: json_string(&value, "type"),
            summary: json_string(&value, "summary"),
            media_urls: json_string(&value, "media_urls"),
            spoiler_text: json_string(&value, "spoiler_text"),
            sensitive: json_truth(&value, "sensitive"),
            visibility: {
                let raw = json_string(&value, "visibility");
                if raw.is_empty() { "public".into() } else { raw }
            },
            in_reply_to: trim_slash(&json_string(&value, "in_reply_to")),
        });
    }
}

async fn load_mention_parents(db: &Client, notes: &[String], facts: &mut PageFacts) {
    if notes.is_empty() {
        return;
    }
    for row in quiet(
        db,
        "SELECT DISTINCT ON (rtrim(object_id, '/')) id::bigint, created_at, COALESCE(actor_id, ''), rtrim(object_id, '/') FROM mentions WHERE deleted_at IS NULL AND rtrim(object_id, '/') = ANY($1) ORDER BY rtrim(object_id, '/'), id DESC",
        &[&notes],
    )
    .await
    {
        let id: i64 = row.get(0);
        let created: Option<String> = row.get(1);
        let actor: String = row.get(2);
        let object: String = row.get(3);
        facts.mention_parents.insert(trim_slash(&object), MentionParent {
            id,
            created_at: created.unwrap_or_default(),
            actor_id: trim_slash(&actor),
        });
    }
}

async fn load_bsky(db: &Client, urls: &[String], facts: &mut PageFacts) {
    if urls.is_empty() {
        return;
    }
    let mut ats = Vec::new();
    let mut rkeys = Vec::new();
    for url in urls {
        if url.starts_with("at://") {
            ats.push(url.clone());
            if let Some(rkey) = url.split("/app.bsky.feed.post/").nth(1) {
                rkeys.push(rkey.trim_end_matches('/').to_string());
            }
        } else if let Some(caps) = regex::Regex::new(r"(?i)^https://bsky\.app/profile/([^/]+)/post/([^/?#]+)").ok().and_then(|re| re.captures(url)) {
            let actor = caps[1].replace("%3A", ":");
            let rkey = caps[2].to_string();
            rkeys.push(rkey.clone());
            if actor.starts_with("did:") {
                ats.push(format!("at://{actor}/app.bsky.feed.post/{rkey}"));
            }
        }
    }
    dedup(&mut rkeys);
    if !rkeys.is_empty() {
        for row in quiet(
            db,
            "SELECT bsky_uri, COALESCE(author_handle, '') FROM bsky_posts WHERE split_part(bsky_uri, '/app.bsky.feed.post/', 2) = ANY($1)",
            &[&rkeys],
        )
        .await
        {
            let uri: String = row.get(0);
            if uri.starts_with("at://") {
                ats.push(uri);
            }
        }
    }
    dedup(&mut ats);
    if ats.is_empty() {
        return;
    }
    for row in quiet(db, "SELECT bsky_uri, COALESCE(note_id, '') FROM bsky_crossposts WHERE bsky_uri = ANY($1)", &[&ats]).await {
        let uri: String = row.get(0);
        let note: String = row.get(1);
        if note.starts_with("https://") {
            facts.bsky_note.insert(uri, trim_slash(&note));
        }
    }
    for row in quiet(db, "SELECT bsky_uri, COALESCE(fediverse_id, ''), COALESCE(ap_object_id, '') FROM bsky_post_links WHERE bsky_uri = ANY($1)", &[&ats]).await {
        let uri: String = row.get(0);
        let fedi: String = row.get(1);
        let object: String = row.get(2);
        let note = if fedi.contains("/notes/") { fedi } else { object };
        if note.contains("/notes/") && note.starts_with("https://") && !facts.bsky_note.contains_key(&uri) {
            facts.bsky_note.insert(uri, trim_slash(&note));
        }
    }
    for url in urls {
        if url.starts_with("https://bsky.app/") {
            if let Some(at) = ats.iter().find(|at| url.ends_with(at.split("/app.bsky.feed.post/").nth(1).unwrap_or(""))) {
                if let Some(note) = facts.bsky_note.get(at).cloned() {
                    facts.bsky_note.insert(trim_slash(url), note);
                }
            }
        }
    }
}

async fn load_users(db: &Client, actors: &[String], facts: &mut PageFacts) {
    if actors.is_empty() {
        return;
    }
    let keys: Vec<String> = actors.iter().filter_map(|actor| local_key_of(actor)).collect();
    for row in quiet(db, "SELECT to_jsonb(u) FROM ap_users u WHERE rtrim(actor_id, '/') = ANY($1) OR lower(actor_key) = ANY($2)", &[&actors, &keys]).await {
        let value: Value = row.get(0);
        let actor = trim_slash(&json_string(&value, "actor_id"));
        let key = json_string(&value, "actor_key").to_ascii_lowercase();
        if !actor.is_empty() {
            facts.users.insert(actor, value.clone());
        }
        if !key.is_empty() {
            facts.users.insert(key.clone(), value);
        }
    }
    if !keys.is_empty() {
        for row in quiet(db, "SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key = ANY($1)", &[&keys]).await {
            let value: Value = row.get(0);
            let key = json_string(&value, "actor_key");
            if !key.is_empty() {
                facts.profiles.insert(key, value);
            }
        }
    }
}

async fn load_remotes(db: &Client, actors: &[String], facts: &mut PageFacts) {
    let actors: Vec<String> = actors.iter().filter(|actor| actor.starts_with("https://") && local_key_of(actor).is_none()).cloned().collect();
    if actors.is_empty() {
        return;
    }
    for row in quiet(db, "SELECT to_jsonb(a) FROM remote_actors a WHERE rtrim(actor_id, '/') = ANY($1)", &[&actors]).await {
        let value: Value = row.get(0);
        let actor = trim_slash(&json_string(&value, "actor_id"));
        if !actor.is_empty() {
            facts.remotes.insert(actor, value);
        }
    }
    for row in quiet(
        db,
        "SELECT rtrim(actor_id, '/'), kind, COALESCE(public_url, '') FROM remote_media_cache WHERE rtrim(actor_id, '/') = ANY($1) AND kind IN ('avatar', 'header')",
        &[&actors],
    )
    .await
    {
        let actor: String = row.get(0);
        let kind: String = row.get(1);
        let url: String = row.get(2);
        if kind == "avatar" {
            facts.avatars.entry(actor).or_insert(url);
        } else if kind == "header" {
            facts.headers.entry(actor).or_insert(url);
        }
    }
}

async fn load_flags(db: &Client, owner: i64, status_ids: &[String], notes: &[String], facts: &mut PageFacts) {
    if owner < 1 {
        return;
    }
    let ids = status_ids.to_vec();
    let objects = notes.to_vec();
    for row in quiet(
        db,
        "SELECT COALESCE(status_id, ''), rtrim(COALESCE(object_id, ''), '/') FROM masto_favourites WHERE owner_user_id = $1::bigint AND (status_id = ANY($2) OR rtrim(object_id, '/') = ANY($3))",
        &[&owner, &ids, &objects],
    )
    .await
    {
        let id: String = row.get(0);
        let object: String = row.get(1);
        if !id.is_empty() {
            facts.fav_ids.insert(id);
        }
        if !object.is_empty() {
            facts.fav_objects.insert(object);
        }
    }
    for row in quiet(
        db,
        "SELECT COALESCE(status_id, ''), rtrim(COALESCE(object_id, ''), '/') FROM masto_bookmarks WHERE owner_user_id = $1::bigint AND (status_id = ANY($2) OR rtrim(object_id, '/') = ANY($3))",
        &[&owner, &ids, &objects],
    )
    .await
    {
        let id: String = row.get(0);
        let object: String = row.get(1);
        if !id.is_empty() {
            facts.bookmark_ids.insert(id);
        }
        if !object.is_empty() {
            facts.bookmark_objects.insert(object);
        }
    }
    for row in quiet(db, "SELECT COALESCE(status_id, '') FROM masto_reblogs WHERE owner_user_id = $1::bigint AND status_id = ANY($2)", &[&owner, &ids]).await {
        let id: String = row.get(0);
        if !id.is_empty() {
            facts.reblog_ids.insert(id);
        }
    }
}

async fn load_counts(db: &Client, actors: &[String], facts: &mut PageFacts) {
    if actors.is_empty() {
        return;
    }
    for row in quiet(db, "SELECT rtrim(owner_actor_id, '/'), COUNT(*)::bigint FROM followers WHERE rtrim(owner_actor_id, '/') = ANY($1) GROUP BY 1", &[&actors]).await {
        facts.followers.insert(row.get(0), row.get(1));
    }
    for row in quiet(db, "SELECT rtrim(owner_actor_id, '/'), COUNT(*)::bigint FROM following WHERE rtrim(owner_actor_id, '/') = ANY($1) GROUP BY 1", &[&actors]).await {
        facts.following.insert(row.get(0), row.get(1));
    }
    for row in quiet(
        db,
        "SELECT rtrim(split_part(id, '/notes/', 1), '/'), COUNT(*)::bigint FROM outbox_notes WHERE rtrim(split_part(id, '/notes/', 1), '/') = ANY($1) GROUP BY 1",
        &[&actors],
    )
    .await
    {
        facts.statuses_count.insert(row.get(0), row.get(1));
    }
}

async fn load_cards(db: &Client, texts: &[String], facts: &mut PageFacts) {
    let mut urls = Vec::new();
    for text in texts {
        if let Ok(Some(url)) = crate::direct_preview::extract_url(text) {
            if let Ok(key) = crate::direct_preview::normalize(&url) {
                urls.push(key);
            }
        }
    }
    dedup(&mut urls);
    if urls.is_empty() {
        return;
    }
    for row in quiet(db, "SELECT to_jsonb(c) FROM link_preview_cards c WHERE url = ANY($1)", &[&urls]).await {
        let value: Value = row.get(0);
        let card = preview_card(&value);
        let url = json_string(&card, "url");
        if !url.is_empty() && !card.is_null() {
            let key = crate::direct_preview::normalize(&url).unwrap_or(url);
            facts.cards.insert(key, card);
        }
    }
}

async fn load_identities(db: &Client, facts: &mut PageFacts) {
    for row in quiet(db, "SELECT s.handle, s.did, s.owner_user_id::bigint FROM bsky_sessions s", &[]).await {
        let handle: String = row.try_get(0).unwrap_or_default();
        let did: String = row.try_get(1).unwrap_or_default();
        let uid: i64 = row.try_get(2).unwrap_or(0);
        if uid < 1 {
            continue;
        }
        let ident = format!("vaak:{uid}");
        for key in [handle, did] {
            let key = key.trim().trim_start_matches('@').to_ascii_lowercase();
            if !key.is_empty() {
                facts.identities.insert(key, ident.clone());
            }
        }
    }
}

fn api_bool(value: &Value) -> bool {
    match value {
        Value::Bool(flag) => *flag,
        Value::Number(number) => number.as_i64().unwrap_or(0) != 0,
        Value::String(text) => matches!(text.to_ascii_lowercase().as_str(), "1" | "true" | "yes"),
        _ => false,
    }
}

fn api_account(account: &Value, depth: usize) -> Option<Value> {
    if depth > 2 || !account.is_object() {
        return None;
    }
    let id = json_string(account, "id");
    if id.is_empty() {
        return None;
    }
    let mut username = json_string(account, "username");
    if username.is_empty() {
        username = "user".into();
    }
    let mut acct = json_string(account, "acct");
    if acct.is_empty() {
        acct = username.clone();
    }
    let display = account.get("display_name").and_then(Value::as_str).unwrap_or(&username).to_string();
    let avatar = https_url(&json_string(account, "avatar")).unwrap_or_else(|| REMOTE_AVATAR.into());
    let header = https_url(&json_string(account, "header")).unwrap_or_else(|| LOCAL_HEADER.into());
    let mut out = json!({
        "id": id,
        "username": username,
        "acct": acct,
        "display_name": display,
        "locked": api_bool(account.get("locked").unwrap_or(&Value::Bool(false))),
        "bot": api_bool(account.get("bot").unwrap_or(&Value::Bool(false))),
        "discoverable": api_bool(account.get("discoverable").unwrap_or(&Value::Bool(false))),
        "created_at": json_string(account, "created_at"),
        "note": json_string(account, "note"),
        "url": https_url(&json_string(account, "url")).or_else(|| https_url(&json_string(account, "uri"))),
        "avatar": avatar,
        "avatar_static": https_url(&json_string(account, "avatar_static")).unwrap_or(avatar),
        "header": header,
        "header_static": https_url(&json_string(account, "header_static")).unwrap_or(header),
        "followers_count": json_i64(account, "followers_count"),
        "following_count": json_i64(account, "following_count"),
        "statuses_count": json_i64(account, "statuses_count"),
        "last_status_at": account.get("last_status_at").filter(|item| item.is_string()).cloned().unwrap_or(Value::Null),
        "emojis": account.get("emojis").cloned().unwrap_or(json!([])),
        "fields": account.get("fields").cloned().unwrap_or(json!([]))
    });
    if depth < 2 {
        if let Some(moved) = account.get("moved").and_then(|item| api_account(item, depth + 1)) {
            out["moved"] = moved;
        }
    }
    Some(out)
}

fn api_media(list: &Value) -> Vec<Value> {
    list.as_array().into_iter().flatten().filter_map(|media| {
        let id = json_string(media, "id");
        if id.is_empty() {
            return None;
        }
        let kind = match json_string(media, "type").as_str() {
            "unknown" | "image" | "gifv" | "video" | "audio" => json_string(media, "type"),
            _ => "unknown".into(),
        };
        Some(json!({
            "id": id,
            "type": kind,
            "url": https_url(&json_string(media, "url")),
            "preview_url": https_url(&json_string(media, "preview_url")),
            "remote_url": https_url(&json_string(media, "remote_url")),
            "text_url": https_url(&json_string(media, "text_url")),
            "meta": media.get("meta").filter(|item| item.is_object()).cloned().unwrap_or(Value::Null),
            "description": media.get("description").filter(|item| item.is_string()).cloned().unwrap_or(Value::Null),
            "blurhash": media.get("blurhash").and_then(Value::as_str).filter(|text| !text.is_empty()).map(|text| json!(text)).unwrap_or(Value::Null)
        }))
    }).collect()
}

fn api_status(status: &Value, depth: usize) -> Option<Value> {
    if depth > 3 || !status.is_object() {
        return None;
    }
    let id = json_string(status, "id");
    let account = status.get("account").and_then(|item| api_account(item, 0))?;
    if id.is_empty() {
        return None;
    }
    let reblog = if depth < 1 { status.get("reblog").filter(|item| item.is_object()).and_then(|item| api_status(item, depth + 1)) } else { None };
    let mut out = json!({
        "id": id,
        "created_at": json_string(status, "created_at"),
        "edited_at": status.get("edited_at").and_then(Value::as_str).filter(|text| !text.trim().is_empty()).map(|text| json!(text)).unwrap_or(Value::Null),
        "in_reply_to_id": status.get("in_reply_to_id").and_then(Value::as_str).filter(|text| !text.is_empty()).map(|text| json!(text)).unwrap_or(Value::Null),
        "in_reply_to_account_id": status.get("in_reply_to_account_id").and_then(Value::as_str).filter(|text| !text.is_empty()).map(|text| json!(text)).unwrap_or(Value::Null),
        "sensitive": api_bool(status.get("sensitive").unwrap_or(&Value::Bool(false))),
        "spoiler_text": json_string(status, "spoiler_text"),
        "visibility": visibility_of(&json_string(status, "visibility")),
        "language": status.get("language").and_then(Value::as_str).filter(|text| !text.is_empty()).map(|text| json!(text)).unwrap_or(Value::Null),
        "uri": status.get("uri").and_then(Value::as_str).map(|text| json!(text)).unwrap_or(Value::Null),
        "url": status.get("url").and_then(Value::as_str).map(|text| json!(text)).unwrap_or(Value::Null),
        "replies_count": json_i64(status, "replies_count"),
        "reblogs_count": json_i64(status, "reblogs_count"),
        "favourites_count": json_i64(status, "favourites_count"),
        "quotes_count": json_i64(status, "quotes_count"),
        "favourited": api_bool(status.get("favourited").unwrap_or(&Value::Bool(false))),
        "reblogged": api_bool(status.get("reblogged").unwrap_or(&Value::Bool(false))),
        "bookmarked": api_bool(status.get("bookmarked").unwrap_or(&Value::Bool(false))),
        "pinned": api_bool(status.get("pinned").unwrap_or(&Value::Bool(false))),
        "content": json_string(status, "content"),
        "reblog": reblog.map(|item| json!(item)).unwrap_or(Value::Null),
        "media_attachments": api_media(status.get("media_attachments").unwrap_or(&Value::Null)),
        "mentions": status.get("mentions").cloned().unwrap_or(json!([])),
        "tags": status.get("tags").cloned().unwrap_or(json!([])),
        "emojis": status.get("emojis").cloned().unwrap_or(json!([])),
        "card": api_card(status.get("card").unwrap_or(&Value::Null)),
        "poll": status.get("poll").filter(|item| item.is_object()).cloned().unwrap_or(Value::Null),
        "account": account
    });
    if status.get("application").is_some_and(Value::is_object) {
        out["application"] = json!({
            "name": json_string(&status["application"], "name"),
            "website": https_url(&json_string(&status["application"], "website"))
        });
    }
    if status.get("quote").is_some_and(Value::is_object) {
        let quoted = status["quote"].get("quoted_status").filter(|item| item.is_object()).and_then(|item| api_status(item, depth + 1));
        let qid = quoted.as_ref().map(|item| json_string(item, "id")).filter(|id| !id.is_empty())
            .or_else(|| status["quote"].get("quoted_status_id").and_then(Value::as_str).map(str::to_string));
        out["quote"] = json!({
            "state": status["quote"].get("state").and_then(Value::as_str).unwrap_or("pending"),
            "quoted_status": quoted,
            "quoted_status_id": qid
        });
    }
    if status.get("quote_approval").is_some_and(Value::is_object) {
        out["quote_approval"] = status["quote_approval"].clone();
    }
    Some(out)
}

fn api_card(card: &Value) -> Value {
    if !card.is_object() || json_string(card, "url").is_empty() {
        return Value::Null;
    }
    let kind = match json_string(card, "type").as_str() {
        "link" | "photo" | "video" | "rich" => json_string(card, "type"),
        _ => "link".into(),
    };
    json!({
        "url": json_string(card, "url"),
        "title": json_string(card, "title"),
        "description": json_string(card, "description"),
        "type": kind,
        "image": https_url(&json_string(card, "image")),
        "width": json_i64(card, "width"),
        "height": json_i64(card, "height")
    })
}

fn api_type(raw: &str) -> Option<String> {
    let kind = raw.trim().to_ascii_lowercase();
    if kind == "bite" {
        return Some("favourite".into());
    }
    if kind.starts_with("phyrian") {
        return None;
    }
    const ALLOWED: &[&str] = &[
        "follow",
        "follow_request",
        "favourite",
        "reblog",
        "mention",
        "quote",
        "poll",
        "status",
        "update",
        "quoted_update",
        "added_to_collection",
        "collection_update",
    ];
    ALLOWED.iter().any(|item| *item == kind).then(|| kind)
}

/// Mastodon HTTP shape. Drops the account and status keys PHP's
/// `ap_masto_api_notification` does not emit. `bite` becomes `favourite`.
/// A row PHP would omit (unknown type, missing account) is removed.
pub fn api_notifications(items: &mut Vec<Value>) {
    let mut kept = Vec::with_capacity(items.len());
    for item in items.iter() {
        let Some(kind) = api_type(&json_string(item, "type")) else {
            continue;
        };
        let Some(account) = item.get("account").and_then(|account| api_account(account, 0)) else {
            continue;
        };
        let id = json_string(item, "id");
        if id.is_empty() || !id.chars().all(|ch| ch.is_ascii_digit()) || id.starts_with('0') {
            continue;
        }
        let status = item.get("status").filter(|status| status.is_object()).and_then(|status| api_status(status, 0));
        let group = json_string(item, "group_key");
        kept.push(json!({
            "id": id,
            "type": kind,
            "group_key": if group.is_empty() { format!("ungrouped-{id}") } else { group },
            "created_at": json_string(item, "created_at"),
            "account": account,
            "status": status
        }));
    }
    *items = kept;
}

#[cfg(test)]
mod tests {
    use super::*;

    fn owner_nova() -> OwnerView {
        OwnerView {
            user_id: 1,
            actor_id: "https://mkultra.monster/users/cmdr_nova".into(),
            actor_key: "cmdr_nova".into(),
            username: "cmdr_nova".into(),
        }
    }

    fn stamp_of(secs: i64) -> String {
        chrono::DateTime::<chrono::Utc>::from_timestamp(secs, 0).unwrap().to_rfc3339()
    }

    fn base_input(id: &str, db_id: i64, kind: &'static str, type_name: &str) -> NotifInput {
        NotifInput {
            id: id.into(),
            kind,
            db_id,
            created: stamp_of(1_792_857_244),
            actor_id: String::new(),
            type_name: type_name.into(),
            object_id: String::new(),
            content: String::new(),
            activity_id: String::new(),
            is_bsky: false,
            owner_actor: "https://mkultra.monster/users/cmdr_nova".into(),
            status_local_id: 0,
            in_reply_to: String::new(),
            media_urls: String::new(),
            spoiler: String::new(),
            sensitive: false,
        }
    }

    #[test]
    fn mention_keeps_the_notifier_status_when_the_target_note_exists() {
        let owner = owner_nova();
        let mut facts = PageFacts::default();
        let note: String = "https://mkultra.monster/users/cmdr_nova/notes/abc".into();
        facts.statuses.insert(note.clone(), StatusRow {
            local_id: 11249,
            note_id: note.clone(),
            published: stamp_of(1_792_857_232),
            content_text: "ours".into(),
            visibility: "public".into(),
            ..StatusRow::default()
        });
        facts.by_local.insert(11249, note.clone());
        facts.users.insert("https://mkultra.monster/users/cmdr_nova".into(), json!({"id": 17, "actor_id": "https://mkultra.monster/users/cmdr_nova", "actor_key": "cmdr_nova", "username": "cmdr_nova"}));
        facts.remotes.insert(
            "https://example.social/users/shana_flux".into(),
            json!({"actor_id": "https://example.social/users/shana_flux", "username": "shana_flux", "host": "example.social", "display_name": "Shana"}),
        );
        let mut item = base_input("1792857244500001853", 1853, "mention", "mention");
        item.actor_id = "https://example.social/users/shana_flux".into();
        item.object_id = note;
        item.content = "hello from shana".into();
        item.media_urls = r#"["https://pics.example/a.jpg"]"#.into();
        let built = build_notifications(&[item], &facts, &owner);
        assert_eq!(built.len(), 1);
        assert_eq!(built[0]["status"]["id"], "1792857244200001853");
        assert_eq!(built[0]["status"]["account"]["id"], remote_account_id("acct:shana_flux@example.social"));
        assert_ne!(built[0]["status"]["account"]["id"], "17");
        assert_eq!(built[0]["account"]["acct"], "shana_flux@example.social");
        assert_eq!(built[0]["status"]["media_attachments"][0]["url"], "https://pics.example/a.jpg");
        assert!(built[0]["status"].get("quote").is_some());
        assert!(built[0]["status"].get("quote_approval").is_some());
        assert!(built[0]["status"].get("application").is_some());
        assert_eq!(built[0]["status"]["language"], "en");
        assert!(built[0]["status"]["content"].as_str().unwrap().contains("hello from shana"));
    }

    #[test]
    fn bluesky_like_uses_the_mirrored_local_note() {
        let owner = OwnerView {
            user_id: 14,
            actor_id: "https://mkultra.monster/users/luna_naloc".into(),
            actor_key: "luna_naloc".into(),
            username: "luna_naloc".into(),
        };
        let mut facts = PageFacts::default();
        let note: String = "https://mkultra.monster/users/luna_naloc/notes/liked".into();
        facts.statuses.insert(note.clone(), StatusRow {
            local_id: 11294,
            note_id: note.clone(),
            published: stamp_of(1_792_936_811),
            content_text: "the liked post".into(),
            visibility: "public".into(),
            ..StatusRow::default()
        });
        facts.by_local.insert(11294, note.clone());
        facts.bsky_note.insert("at://did:plc:candy/app.bsky.feed.post/3abc".into(), note.clone());
        facts.bsky_note.insert("https://bsky.app/profile/did:plc:candy/post/3abc".into(), note);
        facts.users.insert("https://mkultra.monster/users/luna_naloc".into(), json!({"id": 14, "actor_key": "luna_naloc", "username": "luna_naloc", "actor_id": "https://mkultra.monster/users/luna_naloc"}));
        let mut item = base_input("1792937506500001891", 1891, "mention", "favourite");
        item.created = stamp_of(1_792_937_506);
        item.actor_id = "https://bsky.app/profile/candysins.bsky.social".into();
        item.object_id = "https://bsky.app/profile/did:plc:candy/post/3abc".into();
        item.activity_id = "at://did:plc:candy/app.bsky.feed.post/3abc#like".into();
        item.is_bsky = true;
        item.owner_actor = owner.actor_id.clone();
        let built = build_notifications(&[item], &facts, &owner);
        assert_eq!(built.len(), 1);
        assert_eq!(built[0]["status"]["id"], "1792936811000011294");
        assert_eq!(built[0]["status"]["account"]["id"], "14");
        assert_eq!(built[0]["status"]["account"]["acct"], "luna_naloc");
        assert_eq!(built[0]["account"]["acct"], "candysins.bsky.social");
        assert_eq!(built[0]["account"]["url"], "https://bsky.app/profile/candysins.bsky.social");
        assert_ne!(built[0]["status"]["id"], "1792937506200001891");
    }

    #[test]
    fn quoted_media_stays_inside_the_quote() {
        let owner = owner_nova();
        let mut facts = PageFacts::default();
        let note: String = "https://mkultra.monster/users/cmdr_nova/notes/quoted".into();
        facts.statuses.insert(note.clone(), StatusRow {
            local_id: 50,
            note_id: note.clone(),
            published: stamp_of(1_700_000_000),
            content_text: "quoted words".into(),
            visibility: "public".into(),
            ..StatusRow::default()
        });
        facts.by_local.insert(50, note.clone());
        facts.media.insert(50, vec![json!({"id": "9", "type": "image", "url": "https://pics.example/quoted.jpg", "preview_url": "https://pics.example/quoted.jpg"})]);
        facts.users.insert("https://mkultra.monster/users/cmdr_nova".into(), json!({"id": 1, "actor_key": "cmdr_nova", "username": "cmdr_nova", "actor_id": "https://mkultra.monster/users/cmdr_nova"}));
        let mut item = base_input("100", 7, "mention", "quote");
        item.actor_id = "https://example.social/users/quoter".into();
        item.content = format!("commentary\n\n↪ QT {note}");
        item.object_id = "https://example.social/users/quoter/statuses/1".into();
        let built = build_notifications(&[item], &facts, &owner);
        let status = &built[0]["status"];
        assert!(status["media_attachments"].as_array().unwrap().is_empty(), "quoted media was hoisted");
        assert_eq!(status["quote"]["quoted_status"]["media_attachments"][0]["url"], "https://pics.example/quoted.jpg");
        assert_eq!(status["quote"]["state"], "accepted");
    }

    #[test]
    fn unaddressed_mention_is_not_a_notification() {
        let our = "https://mkultra.monster/users/cmdr_nova";
        let text = "Since my website (mkultra.monster) has been quiet, here is a post.";
        assert!(!crate::hidden::content_addresses_local(text, our));
        assert!(notif::mention_notif_type("Create", "Note", "https://cmplxdecay.space/users/valerie/statuses/1", "https://cmplxdecay.space/users/valerie", text, "", our, "https://cmplxdecay.space/activities/1", &HashSet::new()).is_none());
        assert!(crate::hidden::content_addresses_local("hello @cmdr_nova", our));
        assert!(!crate::hidden::content_addresses_local("hello @cmdr_novae", our));
        assert!(!crate::hidden::content_addresses_local("mail name@cmdr_nova today", our));
    }

    #[test]
    fn api_shape_drops_the_extra_account_and_status_keys() {
        let mut items = vec![json!({
            "id": "5",
            "type": "bite",
            "group_key": "bite-abc",
            "created_at": "2026-10-24T00:00:00.000Z",
            "account": {"id": "9", "username": "a", "acct": "a@h", "uri": "https://h/users/a", "indexable": true, "group": false, "source": {"privacy": "public"}, "avatar": REMOTE_AVATAR, "header": REMOTE_AVATAR},
            "status": {"id": "6", "account": {"id": "1", "username": "cmdr_nova", "acct": "cmdr_nova", "uri": "https://mkultra.monster/users/cmdr_nova"}, "content": "x", "muted": false, "in_reply_to": "https://example", "application": null, "quote": null, "quote_approval": quote_approval(), "media_attachments": [{"id": "1", "type": "image", "url": "https://pics.example/a.jpg", "preview_remote_url": "https://pics.example/a.jpg", "mediaType": "image/jpeg"}]}
        })];
        api_notifications(&mut items);
        assert_eq!(items[0]["type"], "favourite");
        assert!(items[0]["account"].get("uri").is_none());
        assert!(items[0]["account"].get("indexable").is_none());
        assert!(items[0]["account"].get("group").is_none());
        assert!(items[0]["account"].get("source").is_none());
        assert!(items[0]["status"].get("muted").is_none());
        assert!(items[0]["status"].get("in_reply_to").is_none());
        assert!(items[0]["status"].get("application").is_none());
        assert!(items[0]["status"].get("quote").is_none());
        assert!(items[0]["status"].get("quote_approval").is_some());
        assert!(items[0]["status"]["media_attachments"][0].get("mediaType").is_none());
        assert!(items[0]["status"]["media_attachments"][0].get("preview_remote_url").is_none());
    }

    fn parity_doc() -> Value {
        let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");
        let output = std::process::Command::new("bash")
            .arg("api/bin/php-policy-test")
            .arg("api/bin/notif-db-page-parity-smoke.php")
            .current_dir(&root)
            .output()
            .expect("php parity smoke");
        assert!(
            output.status.success(),
            "php parity smoke failed\n{}",
            String::from_utf8_lossy(&output.stderr)
        );
        let text = String::from_utf8(output.stdout).expect("utf8");
        let line = text
            .lines()
            .find(|line| line.starts_with("PARITY_JSON="))
            .expect("PARITY_JSON");
        serde_json::from_str(&line["PARITY_JSON=".len()..]).expect("parity json")
    }

    fn owner_from(case: &Value) -> OwnerView {
        let key = case.get("owner_key").and_then(Value::as_str).unwrap_or("cmdr_nova");
        let user_id = case.get("owner_id").and_then(Value::as_i64).unwrap_or(1);
        let actor = case
            .get("owner_actor")
            .and_then(Value::as_str)
            .unwrap_or("https://mkultra.monster/users/cmdr_nova");
        OwnerView {
            user_id,
            actor_id: actor.into(),
            actor_key: key.into(),
            username: key.into(),
        }
    }

    fn input_from(case: &Value) -> NotifInput {
        let item = &case["item"];
        NotifInput {
            id: json_string(item, "id"),
            kind: match json_string(item, "kind").as_str() {
                "follow" => "follow",
                "poll" => "poll",
                _ => "mention",
            },
            db_id: json_i64(item, "db_id"),
            created: json_string(item, "created"),
            actor_id: json_string(item, "actor_id"),
            type_name: json_string(item, "type_name"),
            object_id: json_string(item, "object_id"),
            content: json_string(item, "content"),
            activity_id: json_string(item, "activity_id"),
            is_bsky: item.get("is_bsky").and_then(Value::as_bool).unwrap_or(false),
            owner_actor: json_string(item, "owner_actor"),
            status_local_id: json_i64(item, "status_local_id"),
            in_reply_to: json_string(item, "in_reply_to"),
            media_urls: json_string(item, "media_urls"),
            spoiler: json_string(item, "spoiler"),
            sensitive: item.get("sensitive").and_then(Value::as_bool).unwrap_or(false),
        }
    }

    fn facts_from(case: &Value, owner: &OwnerView) -> PageFacts {
        let mut facts = PageFacts::default();
        let actor = trim_slash(&owner.actor_id);
        facts.users.insert(
            actor.clone(),
            json!({"id": owner.user_id, "actor_id": actor, "actor_key": owner.actor_key, "username": owner.username}),
        );
        facts.users.insert(owner.actor_key.clone(), json!({"id": owner.user_id, "actor_key": owner.actor_key, "username": owner.username}));
        for note in case.get("notes").and_then(Value::as_array).into_iter().flatten() {
            let url = trim_slash(&json_string(note, "note_id"));
            let local_id = json_i64(note, "local_id");
            facts.by_local.insert(local_id, url.clone());
            facts.statuses.insert(url.clone(), StatusRow {
                local_id,
                note_id: url.clone(),
                published: json_string(note, "published"),
                content_text: json_string(note, "content_text"),
                visibility: {
                    let raw = json_string(note, "visibility");
                    if raw.is_empty() { "public".into() } else { raw }
                },
                ..StatusRow::default()
            });
            if let Some(key) = local_key_of(&url) {
                let uid = if key == owner.actor_key { owner.user_id } else if key == "cmdr_nova" { 1 } else { 0 };
                if uid > 0 {
                    let actor = format!("https://mkultra.monster/users/{key}");
                    facts.users.entry(actor.clone()).or_insert_with(|| json!({"id": uid, "actor_id": actor, "actor_key": key, "username": key}));
                    facts.users.entry(key.clone()).or_insert_with(|| json!({"id": uid, "actor_key": key, "username": key}));
                }
            }
        }
        for remote in case.get("remotes").and_then(Value::as_array).into_iter().flatten() {
            let actor = trim_slash(&json_string(remote, "actor_id"));
            if !actor.is_empty() {
                facts.remotes.insert(actor, remote.clone());
            }
        }
        if let Some(media) = case.get("media").and_then(Value::as_object) {
            for (id, list) in media {
                if let Ok(local_id) = id.parse::<i64>() {
                    facts.media.insert(local_id, list.as_array().cloned().unwrap_or_default());
                }
            }
        }
        if let Some(cards) = case.get("cards").and_then(Value::as_object) {
            for (url, card) in cards {
                facts.cards.insert(url.clone(), card.clone());
            }
        }
        if let Some(map) = case.get("bsky_note").and_then(Value::as_object) {
            for (key, note) in map {
                if let Some(note) = note.as_str() {
                    facts.bsky_note.insert(key.clone(), trim_slash(note));
                }
            }
        }
        facts
    }

    #[test]
    fn db_page_json_equals_php() {
        let doc = parity_doc();
        for case in doc["cases"].as_array().expect("cases") {
            let name = json_string(case, "name");
            let owner = owner_from(case);
            let item = input_from(case);
            let facts = facts_from(case, &owner);
            let mut built = build_notifications(std::slice::from_ref(&item), &facts, &owner);
            api_notifications(&mut built);
            let expected = case.get("api").and_then(Value::as_array).cloned().unwrap_or_default();
            assert_eq!(built, expected, "case {name}");
        }
        for row in doc["classify"].as_array().expect("classify") {
            let spec = &row["row"];
            let got = notif::mention_notif_type(
                &json_string(spec, "activity_type"),
                &json_string(spec, "type"),
                &json_string(spec, "object_id"),
                &json_string(spec, "actor_id"),
                &json_string(spec, "content"),
                &json_string(spec, "in_reply_to"),
                &json_string(spec, "owner_actor_id"),
                &json_string(spec, "activity_id"),
                &HashSet::new(),
            );
            let expect = row.get("type").and_then(Value::as_str);
            assert_eq!(got, expect, "classify {}", json_string(row, "name"));
        }
        let mut round = doc["api_roundtrip"]["input"].as_array().cloned().unwrap_or_default();
        api_notifications(&mut round);
        assert_eq!(round, doc["api_roundtrip"]["output"].as_array().cloned().unwrap_or_default());
        assert_eq!(
            notif::snowflake_id(Some("2026-10-24T15:27:24+00:00"), 1853, 2),
            doc["ids"]["mention_status"].as_str().unwrap()
        );
        assert_eq!(
            notif::snowflake_id(Some("2026-10-25T13:33:31+00:00"), 11294, 0),
            doc["ids"]["like_status"].as_str().unwrap()
        );
        assert_eq!(remote_account_id("acct:shana_flux@example.social"), doc["ids"]["shana"].as_str().unwrap());
        assert_eq!(
            remote_account_id("https://bsky.app/profile/candysins.bsky.social"),
            doc["ids"]["candy"].as_str().unwrap()
        );
    }

    #[test]
    fn request_origin_uses_an_allowed_forwarded_host() {
        assert_eq!(crate::notif_page::notification_request_origin(Some("mkultra.monster"), Some("vaak.monster")), "https://mkultra.monster");
        assert_eq!(crate::notif_page::notification_request_origin(Some("evil.example"), Some("vaak.monster:443")), "https://vaak.monster:443");
        assert_eq!(crate::notif_page::notification_request_origin(Some("vaak.monster.evil.test"), None), crate::notif_page::public_origin());
        assert_eq!(crate::notif_page::notification_request_origin(Some("www.vaak.monster, proxy.internal"), None), "https://www.vaak.monster");
    }
}
