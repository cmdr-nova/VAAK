//! Mute / block sets for notification filtering (parity with ap_row_is_hidden).

use anyhow::{Context, Result};
use serde_json::Value;
use std::collections::{HashMap, HashSet};
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};
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
    /// Populated only for selected canonical local outbox objects on ordinary surfaces.
    pub ordinary_owned_notes: HashMap<String, String>,
    /// Bridgy Fed Bluesky handles (`user.host.ap.brid.gy`, lowercased) of
    /// blocked fediverse actors. A block hides the bridged account too.
    pub bridged_blocked_handles: HashSet<String>,
}

impl HiddenSets {
    // Equivalent to the original exact-domain or ".domain" suffix predicate.
    // Check every ASCII dot boundary, including empty/repeated-dot suffixes.
    fn host_blocked(&self, host: &str) -> bool {
        let blocked = |candidate: &str| self.user_blocked_domains.contains(candidate)
            || self.instance_blocked_domains.contains(candidate);
        blocked(host) || host.match_indices('.').any(|(offset, _)| blocked(&host[offset + 1..]))
    }

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
            if self.host_blocked(&host_l)
            {
                return true;
            }
        }
        // A Bridgy Fed account is `user.host.ap.brid.gy` on Bluesky, so a
        // blocked fediverse actor or domain also hides its bridged account.
        if let Some(handle) = bridgy_ap_handle(actor) {
            let fedi = &handle[..handle.len() - BRIDGY_AP_SUFFIX.len()];
            if self.bridged_blocked_handles.contains(&handle) || self.host_blocked(fedi) {
                return true;
            }
        }
        false
    }
}

const BRIDGY_AP_SUFFIX: &str = ".ap.brid.gy";

/// Lowercased Bluesky handle when `actor` names a Bridgy Fed bridged
/// fediverse account, as a bare handle or a bsky.app profile URL.
fn bridgy_ap_handle(actor: &str) -> Option<String> {
    let actor = actor.trim().trim_end_matches('/');
    let handle = match actor.get(..25) {
        Some(prefix) if prefix.eq_ignore_ascii_case("https://bsky.app/profile/") => raw_percent_decode(&actor[25..]),
        _ => actor.to_owned(),
    };
    let handle = handle.trim_start_matches('@').to_ascii_lowercase();
    (handle.len() > BRIDGY_AP_SUFFIX.len() && handle.ends_with(BRIDGY_AP_SUFFIX) && !handle.contains(['/', '@', ':'])).then_some(handle)
}

/// Bridgy Fed handles for `user@host`: underscores become hyphens on Bluesky,
/// so both spellings are kept.
fn bridgy_handles_for(username: &str, host: &str) -> Vec<String> {
    let username = username.trim().trim_start_matches('@').to_ascii_lowercase();
    let host = host.trim().trim_end_matches('.').to_ascii_lowercase();
    if username.is_empty() || host.is_empty() || username.contains(['/', '@', ':']) || host.contains(['/', '@', ':']) {
        return Vec::new();
    }
    let mut out = vec![format!("{username}.{host}{BRIDGY_AP_SUFFIX}")];
    if username.contains('_') {
        out.push(format!("{}.{host}{BRIDGY_AP_SUFFIX}", username.replace('_', "-")));
    }
    out
}

/// `user@host` named by a blocked actor value, from the value itself
/// (`@user@host`, `/users/user`, `/@user`) when no cached actor row exists.
fn fedi_account_of(actor: &str) -> Option<(String, String)> {
    static ACCT_RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    static PATH_RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    if let Some(c) = ACCT_RE.get_or_init(|| regex::Regex::new(r"^@?([^@/\s]+)@([^@/\s]+)$").unwrap()).captures(actor.trim()) {
        return Some((c[1].to_owned(), c[2].to_owned()));
    }
    let c = PATH_RE.get_or_init(|| regex::Regex::new(r"(?i)^https://([^/]+)/(?:users/|@)([^/?#]+)$").unwrap()).captures(actor.trim().trim_end_matches('/'))?;
    let (decoded, _) = path_segment_forms(&c[2]);
    (!decoded.is_empty() && !decoded.bytes().all(|b| b.is_ascii_digit())).then(|| (decoded, c[1].to_owned()))
}

/// Fill `bridged_blocked_handles` from blocked fediverse actors, using the
/// cached actor's username and host (Wafrn, Misskey and others don't put
/// the username in the URL).
async fn load_bridged_blocks(db: &Client, blocked: &[String], sets: &mut HiddenSets) -> Result<()> {
    let actors: Vec<String> = blocked.iter().filter(|a| a.starts_with("https://") && !a.contains(".brid.gy/")).cloned().collect();
    let mut cached = HashSet::new();
    if !actors.is_empty() {
        for row in db
            .query("SELECT actor_id, COALESCE(username, ''), COALESCE(host, '') FROM remote_actors WHERE actor_id = ANY($1)", &[&actors])
            .await
            .context("select blocked remote_actors")?
        {
            let actor: String = row.get(0);
            let (username, host): (String, String) = (row.get(1), row.get(2));
            let handles = bridgy_handles_for(&username, &host);
            if !handles.is_empty() {
                cached.insert(actor);
                sets.bridged_blocked_handles.extend(handles);
            }
        }
    }
    for actor in blocked {
        if cached.contains(actor) {
            continue;
        }
        if let Some((username, host)) = fedi_account_of(actor) {
            sets.bridged_blocked_handles.extend(bridgy_handles_for(&username, &host));
        }
    }
    Ok(())
}

/// Apply viewer moderation to already-hydrated cards as well as ranked IDs.
/// Cached envelopes can outlive a mute/block change, so both the card author
/// and a boost/reblog's underlying author must be checked at read time.
pub fn status_hidden(status: &Value, hidden: &HiddenSets) -> bool {
    status_hidden_at(status, hidden, 0, false, false)
}

/// Word mutes on ordinary posts may exempt a proven own root. Discovery uses status_hidden.
pub fn ordinary_status_hidden(status: &Value, hidden: &HiddenSets) -> bool {
    ordinary_ask_questions_hidden(status, hidden, 0) || status_hidden_at(status, hidden, 0, false, true)
}

pub fn ordinary_status_hidden_on_profile(status: &Value, hidden: &HiddenSets) -> bool {
    ordinary_ask_questions_hidden(status, hidden, 0) || status_hidden_at(status, hidden, 0, true, true)
}

fn ordinary_ask_questions_hidden(status:&Value,hidden:&HiddenSets,depth:usize)->bool {
    if depth>16{return true}
    let question=status.get("vaak_ask").and_then(|ask|ask.get("question").or_else(||ask.get("ask_question"))).and_then(Value::as_str).unwrap_or("");
    let text=normalize_muted_text(question);
    if hidden.muted_phrases.iter().any(|phrase|!phrase.is_empty()&&text.contains(phrase)){return true}
    ["reblog","quote","vaak_quote_preview"].iter().any(|field|status.get(field).filter(|child|child.is_object()).is_some_and(|child|{
        let child=child.get("quoted_status").filter(|value|value.is_object()).or_else(||child.get("status").filter(|value|value.is_object())).unwrap_or(child);
        ordinary_ask_questions_hidden(child,hidden,depth+1)
    }))
}

/// Obtain grants from current durable ownership, never a cached account or client flag.
/// Only requested canonical IDs are read, in bounded batches; no owner-wide history scan.
pub async fn load_ordinary_owned_notes(db: &Client, owner: i64, ids: &[String], hidden: &mut HiddenSets) -> Result<()> {
    hidden.ordinary_owned_notes.clear();
    if owner < 1 || hidden.muted_phrases.is_empty() { return Ok(()); }
    let mut variants: Vec<String> = ids.iter().flat_map(|id| { let key=id.trim().trim_end_matches('/'); [key.to_owned(),format!("{key}/")] }).filter(|id|!id.is_empty()).collect();
    variants.sort(); variants.dedup();
    for batch in variants.chunks(200) {
        let selected=batch.to_vec();
        for row in db.query(ORDINARY_OWNED_NOTES_SQL, &[&owner,&selected]).await.context("selected ordinary local ownership")? {
            let id:String=row.get(0); let actor:String=row.get(1); hidden.ordinary_owned_notes.insert(id, actor);
        }
    }
    Ok(())
}

pub const ORDINARY_OWNED_NOTES_SQL: &str = "SELECT DISTINCT rtrim(o.id,'/'),rtrim(u.actor_id,'/') FROM outbox_notes o JOIN ap_users u ON u.id=$1::bigint AND u.disabled_at IS NULL AND $1::bigint>0 WHERE o.id=ANY($2) AND rtrim(u.actor_id,'/')<>'' AND left(o.id,length(rtrim(u.actor_id,'/')||'/notes/'))=rtrim(u.actor_id,'/')||'/notes/'";

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

/// Library local canonical roots may opt in; retained foreign native and quote trees remain strict.
pub fn ordinary_library_status_hidden(status: &Value, hidden: &HiddenSets) -> bool {
    // Raw native protocol envelopes are authoritative too. Dispatch only the
    // PostView/FeedView shapes; arbitrary normalized metadata is not native.
    let post_view = |post:&Value| post.get("uri").and_then(Value::as_str).is_some_and(|uri|uri.starts_with("at://"))
        && post.get("author").is_some_and(Value::is_object);
    let raw_native = post_view(status) || status.get("post").is_some_and(post_view);
    ordinary_status_hidden(status, hidden) || (raw_native && native_tree_hidden(status, hidden, 0))
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
    status_hidden_at(status, hidden, 0, true, false)
}

fn status_hidden_at(status: &Value, hidden: &HiddenSets, depth: usize, profile_root: bool, ordinary_root: bool) -> bool {
    if depth > 16 { return true; }
    // Flattened fallback previews sometimes have no account. Their object
    // host still obeys domain blocks, including real subdomains.
    for field in ["uri", "url"] {
        if let Some(reference) = status.get(field).and_then(Value::as_str) {
            if let Some(host) = host_of(reference) {
                if hidden.host_blocked(&host) { return true; }
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
    let exempt = ordinary_root && depth == 0 && status.get("uri").and_then(Value::as_str)
        .and_then(|uri| hidden.ordinary_owned_notes.get(uri.trim().trim_end_matches('/')))
        .is_some_and(|actor| status.get("account").and_then(|account|account.get("uri")).and_then(Value::as_str)
            .is_some_and(|candidate|candidate.trim().trim_end_matches('/')==actor));
    if !exempt && hidden.muted_phrases.iter().any(|phrase| !phrase.is_empty() && text.contains(phrase)) { return true; }
    if let Some(reblog) = status.get("reblog").filter(|v| v.is_object()) {
        if status_hidden_at(reblog, hidden, depth + 1, false, false) { return true; }
    }
    for field in ["quote", "vaak_quote_preview"] {
        if let Some(quote) = status.get(field).filter(|v| v.is_object()) {
            let nested = quote.get("quoted_status").or_else(|| quote.get("status")).filter(|v| v.is_object()).unwrap_or(quote);
            if status_hidden_at(nested, hidden, depth + 1, false, false) { return true; }
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

/// Case-insensitive substring match after the same normalization as status_hidden.
/// An empty phrase list matches nothing. There is no whole-word or expiry flag.
pub fn texts_match_muted_phrases(phrases: &[String], texts: &[&str]) -> bool {
    if phrases.iter().all(|phrase| phrase.is_empty()) {
        return false;
    }
    let text = texts.iter().copied().map(normalize_muted_text).filter(|part| !part.is_empty()).collect::<Vec<_>>().join(" ");
    if text.is_empty() {
        return false;
    }
    phrases.iter().any(|phrase| !phrase.is_empty() && text.contains(phrase.as_str()))
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
    static BSKY_ACTOR_RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    static FEDERATED_ACTOR_RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    let actor = norm_actor(s);
    if actor.is_empty() { return Vec::new(); }
    let mut aliases = vec![actor.clone()];
    if actor.starts_with("did:") {
        aliases.push(format!("https://bsky.app/profile/{actor}"));
        aliases.push(format!("https://bsky.app/profile/{}", crate::library::actions::query(&actor)));
    } else if let Some(captures) = BSKY_ACTOR_RE.get_or_init(|| regex::Regex::new(r"(?i)^https://bsky\.app/profile/([^/?#]+)$").unwrap()).captures(&actor) {
        let decoded = raw_percent_decode(&captures[1]);
        aliases.push(decoded.clone());
        aliases.push(format!("https://bsky.app/profile/{decoded}"));
        aliases.push(format!("https://bsky.app/profile/{}", crate::library::actions::query(&decoded)));
    } else if let Some(captures) = FEDERATED_ACTOR_RE.get_or_init(|| regex::Regex::new(r"(?i)^https://([^/]+)/(users/|@)([^/?#]+)$").unwrap()).captures(&actor) {
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
    let mut blocked_actors = Vec::new();

    let mute_rows = async {
        db.query(
            "SELECT actor_id FROM ap_mutes WHERE owner_user_id = $1::bigint",
            &[&owner_user_id],
        )
        .await
        .context("select ap_mutes")
    };
    let user_block_rows = async {
        db.query(
            "SELECT scope, value FROM ap_user_blocks WHERE owner_user_id = $1::bigint",
            &[&owner_user_id],
        )
        .await
        .context("select ap_user_blocks")
    };
    let inst_rows = async {
        db.query("SELECT scope, value, COALESCE(to_jsonb(ap_blocks)->>'kind', 'block') FROM ap_blocks", &[])
            .await
            .context("select ap_blocks")
    };
    let word_rows = async {
        if owner_user_id > 0 {
            db.query("SELECT phrase FROM ap_muted_words WHERE owner_user_id = $1::bigint", &[&owner_user_id])
                .await
                .context("load muted words")
        } else {
            Ok(Vec::new())
        }
    };
    let (mute_rows, user_block_rows, inst_rows, word_rows) = tokio::try_join!(mute_rows, user_block_rows, inst_rows, word_rows)?;
    for row in mute_rows {
        let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let a = norm_actor(&a);
        if !a.is_empty() {
            insert_actor_aliases(&mut sets.muted_actors, &a);
        }
    }

    for row in user_block_rows {
        let scope: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        match scope.as_str() {
            "actor" => {
                let a = norm_actor(&value);
                if !a.is_empty() {
                    insert_actor_aliases(&mut sets.user_blocked_actors, &a);
                    blocked_actors.push(a);
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
                    } else { insert_actor_aliases(&mut sets.instance_blocked_actors, &a); blocked_actors.push(a); }
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
        for row in word_rows {
            let phrase: String = row.get(0);
            let phrase = normalize_muted_text(&phrase);
            if !phrase.is_empty() { sets.muted_phrases.push(phrase); }
        }
    }
    if let Err(error) = load_bridged_blocks(db, &blocked_actors, &mut sets).await {
        tracing::warn!(%error, "bridged block handles skipped");
    }
    Ok(sets)
}

/// Bluesky identities named by moderation rows: (DIDs, lowercased handles).
fn bsky_moderation_refs(sets: &HiddenSets) -> (Vec<String>, Vec<String>) {
    let mut dids = HashSet::new();
    let mut handles = HashSet::new();
    for set in [&sets.muted_actors, &sets.user_blocked_actors, &sets.instance_blocked_actors, &sets.instance_muted_actors] {
        for actor in set {
            let part = match actor.get(..25) {
                Some(prefix) if prefix.eq_ignore_ascii_case("https://bsky.app/profile/") => raw_percent_decode(&actor[25..]),
                _ if actor.starts_with("did:") => actor.clone(),
                _ => continue,
            };
            if part.starts_with("did:") { dids.insert(part); }
            else if !part.is_empty() && !part.contains('/') { handles.insert(part.trim_start_matches('@').to_ascii_lowercase()); }
        }
    }
    (dids.into_iter().collect(), handles.into_iter().collect())
}

/// Add both halves of each known (DID, handle) pair to every set naming either half.
fn apply_bsky_identity_pairs(sets: &mut HiddenSets, pairs: &[(String, String)]) {
    for (did, handle) in pairs {
        let handle = handle.to_ascii_lowercase();
        let handle_url = format!("https://bsky.app/profile/{handle}");
        let mut forms: Vec<String> = actor_aliases(did);
        forms.extend(actor_aliases(&handle_url));
        let names = |set: &HashSet<String>| forms.iter().any(|form| set.contains(form) || set.contains(&form.to_ascii_lowercase()))
            || set.iter().any(|alias| alias.eq_ignore_ascii_case(&handle) || alias.eq_ignore_ascii_case(&handle_url));
        for set in [&mut sets.muted_actors, &mut sets.user_blocked_actors, &mut sets.instance_blocked_actors] {
            if names(set) { insert_actor_aliases(set, did); insert_actor_aliases(set, &handle_url); }
        }
        if names(&sets.instance_muted_actors) {
            for alias in forms.iter() { sets.instance_muted_actors.insert(alias.to_ascii_lowercase()); }
        }
    }
}

/// Moderation rows store whichever Bluesky form the viewer acted on (Home cards
/// carry the DID), while notifications name the notifier by handle URL. Resolve
/// the missing half from local Bluesky caches, as PHP ap_actor_moderation_aliases
/// does, so a DID block also hides handle-keyed notifications and vice versa.
/// Best effort: a failed lookup keeps the syntactic aliases.
pub async fn expand_bsky_identities(db: &Client, sets: &mut HiddenSets) {
    let (dids, handles) = bsky_moderation_refs(sets);
    if dids.is_empty() && handles.is_empty() { return; }
    let lookup = async {
        let mut pairs = Vec::new();
        let handle_urls: Vec<String> = handles.iter().map(|h| format!("https://bsky.app/profile/{h}")).collect();
        let (post_rows, profile_rows) = tokio::try_join!(
            async {
                db.query(
                    "SELECT d, (SELECT lower(author_handle) FROM bsky_posts WHERE author_did = d AND author_handle IS NOT NULL AND author_handle <> '' LIMIT 1)
                     FROM unnest($1::text[]) AS d
                     UNION ALL
                     SELECT (SELECT author_did FROM bsky_posts WHERE lower(author_handle) = h AND author_did LIKE 'did:%' LIMIT 1), h
                     FROM unnest($2::text[]) AS h",
                    &[&dids, &handles],
                ).await.context("bsky moderation identities")
            },
            async {
                db.query(
                    "SELECT did, profile_json FROM bsky_actor_profiles
                     WHERE did = ANY($1) OR lower(actor_ref) = ANY($2) OR lower(actor_ref) = ANY($3)",
                    &[&dids, &handles, &handle_urls],
                ).await.context("bsky moderation profiles")
            },
        )?;
        for row in post_rows {
            let did: Option<String> = row.get(0);
            let handle: Option<String> = row.get(1);
            if let (Some(did), Some(handle)) = (did, handle) {
                if did.starts_with("did:") && !handle.is_empty() { pairs.push((did, handle)); }
            }
        }
        for row in profile_rows {
            let profile: Value = serde_json::from_str(&row.get::<_, String>(1)).unwrap_or(Value::Null);
            let did = row.get::<_, Option<String>>(0).filter(|d| d.starts_with("did:"))
                .or_else(|| profile.get("did").and_then(Value::as_str).map(str::to_owned));
            let handle = profile.get("handle").and_then(Value::as_str).map(str::to_ascii_lowercase);
            if let (Some(did), Some(handle)) = (did, handle) {
                if did.starts_with("did:") && !handle.is_empty() { pairs.push((did, handle)); }
            }
        }
        anyhow::Ok(pairs)
    };
    match lookup.await {
        Ok(pairs) => apply_bsky_identity_pairs(sets, &pairs),
        Err(error) => tracing::warn!(%error, "bsky moderation identity lookup failed"),
    }
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

/// Canonical proof-only reader: one fresh SQL snapshot of the same five
/// tables. This stores no decisions and uses the same actor/domain/word
/// normalization as the ordinary reader; absent tables remain query errors.
pub(crate) async fn load_canonical_library_hidden_sets(db:&Client,owner:i64)->Result<HiddenSets>{
    if owner<1{anyhow::bail!("Library moderation requires an owner")}
    let rows=db.query("SELECT 'mute' AS source, NULL::text AS scope, actor_id::text AS value, NULL::text AS kind FROM ap_mutes WHERE owner_user_id=$1::bigint
        UNION ALL SELECT 'user',scope::text,value::text,NULL::text FROM ap_user_blocks WHERE owner_user_id=$1::bigint
        UNION ALL SELECT 'instance',scope::text,value::text,COALESCE(to_jsonb(ap_blocks)->>'kind','block') FROM ap_blocks
        UNION ALL SELECT 'word',NULL::text,phrase::text,NULL::text FROM ap_muted_words WHERE owner_user_id=$1::bigint
        UNION ALL SELECT DISTINCT 'bsky',NULL::text,did::text,NULL::text FROM bsky_hide_dids WHERE owner_user_id::bigint=$1",&[&owner]).await.context("read current canonical Library moderation")?;
    let mut sets=HiddenSets::default();
    for row in rows{
        let source:String=row.try_get(0)?;
        let value:String=row.try_get::<_,Option<String>>(2)?.unwrap_or_default();
        match source.as_str(){
            "mute"=>{let actor=norm_actor(&value);if !actor.is_empty(){insert_actor_aliases(&mut sets.muted_actors,&actor)}},
            "word"=>{let phrase=normalize_muted_text(&row.try_get::<_,String>(2)?);if !phrase.is_empty(){sets.muted_phrases.push(phrase)}},
            "bsky"=>{let did=norm_actor(&value);if !did.is_empty(){sets.user_blocked_actors.insert(did.clone());sets.user_blocked_actors.insert(format!("https://bsky.app/profile/{did}"));}},
            "user"|"instance"=>{
                let scope:String=row.try_get::<_,Option<String>>(1)?.unwrap_or_default();
                if scope=="actor"{
                    let actor=norm_actor(&value);if actor.is_empty(){continue}
                    if source=="user"{insert_actor_aliases(&mut sets.user_blocked_actors,&actor)}
                    else if row.try_get::<_,String>(3)?=="mute"{for alias in actor_aliases(&actor){sets.instance_muted_actors.insert(alias.to_ascii_lowercase());}}
                    else{insert_actor_aliases(&mut sets.instance_blocked_actors,&actor)}
                }else if scope=="domain"{
                    let host=value.trim().trim_start_matches('.').to_ascii_lowercase();if host.is_empty(){continue}
                    if source=="user"{sets.user_blocked_domains.insert(host);}else{sets.instance_blocked_domains.insert(host);}
                }
            },
            _=>anyhow::bail!("unknown canonical moderation source"),
        }
    }
    Ok(sets)
}

/// Ordinary Search-only fresh moderation batch. Guest reads retain their
/// original optional-word table contract. Authenticated Search still reads
/// words (and errors) before its caller clears phrases by explicit policy.
/// Bluesky hide rows are deliberately absent: these are ordinary AP controls.
pub(crate) async fn load_search_hidden_sets(db:&Client,owner:i64)->Result<HiddenSets>{
    if owner<1{return load_hidden_sets(db,owner).await}
    let rows=db.query("SELECT 'mute' AS source, NULL::text AS scope, actor_id AS value, NULL::text AS kind FROM ap_mutes WHERE owner_user_id=$1::bigint
        UNION ALL SELECT 'user',scope,value,NULL::text FROM ap_user_blocks WHERE owner_user_id=$1::bigint
        UNION ALL SELECT 'instance',scope,value,COALESCE(to_jsonb(ap_blocks)->>'kind','block') FROM ap_blocks
        UNION ALL SELECT 'word',NULL::text,phrase,NULL::text FROM ap_muted_words WHERE owner_user_id=$1::bigint",&[&owner]).await.context("read current ordinary Search moderation")?;
    let mut sets=HiddenSets::default();
    for row in rows{
        let source:String=row.try_get(0)?;
        let value:String=row.try_get::<_,Option<String>>(2)?.unwrap_or_default();
        match source.as_str(){
            "mute"=>{let actor=norm_actor(&value);if !actor.is_empty(){insert_actor_aliases(&mut sets.muted_actors,&actor)}},
            "word"=>{let phrase=normalize_muted_text(&row.try_get::<_,String>(2)?);if !phrase.is_empty(){sets.muted_phrases.push(phrase)}},
            "user"|"instance"=>{
                let scope:String=row.try_get::<_,Option<String>>(1)?.unwrap_or_default();
                if scope=="actor"{
                    let actor=norm_actor(&value);if actor.is_empty(){continue}
                    if source=="user"{insert_actor_aliases(&mut sets.user_blocked_actors,&actor)}
                    else if row.try_get::<_,String>(3)?=="mute"{for alias in actor_aliases(&actor){sets.instance_muted_actors.insert(alias.to_ascii_lowercase());}}
                    else{insert_actor_aliases(&mut sets.instance_blocked_actors,&actor)}
                }else if scope=="domain"{
                    let host=value.trim().trim_start_matches('.').to_ascii_lowercase();if host.is_empty(){continue}
                    if source=="user"{sets.user_blocked_domains.insert(host);}else{sets.instance_blocked_domains.insert(host);}
                }
            },
            _=>anyhow::bail!("unknown Search moderation source"),
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
    let (mute_rows, block_rows, depri_rows, session_row, unblur_row) = tokio::join!(
        async {
            db.query(
                "SELECT actor_id FROM ap_mutes WHERE owner_user_id = $1::bigint",
                &[&owner_user_id],
            ).await
        },
        async {
            db.query(
                "SELECT id::bigint, value FROM ap_user_blocks
                 WHERE owner_user_id = $1::bigint AND scope = 'actor'",
                &[&owner_user_id],
            ).await
        },
        async {
            db.query(
                "SELECT actor_id FROM ap_deprioritized_actors WHERE owner_user_id = $1::bigint",
                &[&owner_user_id],
            ).await
        },
        async {
            db.query_opt(
                "SELECT COALESCE(handle, ''), COALESCE(did, '')
                 FROM bsky_sessions WHERE owner_user_id = $1::bigint LIMIT 1",
                &[&owner_user_id],
            ).await
        },
        async {
            db.query_opt(
                "SELECT COALESCE(p.auto_unblur_sensitive::bigint, 0) FROM ap_users u
                 JOIN actor_profile p ON p.actor_key = u.actor_key WHERE u.id = $1::bigint AND u.disabled_at IS NULL",
                &[&owner_user_id],
            ).await
        },
    );
    for row in mute_rows.context("select ap_mutes for moderation menu")? {
        let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let a = norm_actor(&a);
        if !a.is_empty() {
            out.muted_actors.insert(a);
        }
    }
    for row in block_rows.context("select ap_user_blocks for moderation menu")? {
        let id: i64 = row.try_get(0)?;
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let a = norm_actor(&value);
        if !a.is_empty() && id > 0 {
            out.blocked_actors.insert(a, id);
        }
    }
    if let Ok(rows) = depri_rows {
        for row in rows {
            let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
            insert_actor_aliases(&mut out.deprioritized_actors, &a);
        }
    }
    if let Ok(Some(row)) = session_row {
        let handle: String = row.try_get(0).unwrap_or_default();
        let did: String = row.try_get(1).unwrap_or_default();
        for key in [handle, did] {
            let key = key.trim().trim_start_matches('@').to_ascii_lowercase();
            if !key.is_empty() {
                out.own_bsky_keys.insert(key);
            }
        }
    }
    if let Some(row) = unblur_row? {
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

/// `bsky_hide_dids` primary key is `(owner_user_id, did, reason, list_uri)`.
/// This is one index range for the owner, not a lookup per notification.
pub const BSKY_HIDE_DIDS_SQL: &str =
    "SELECT DISTINCT did FROM bsky_hide_dids WHERE owner_user_id = $1";

/// Cached `bsky_posts.author_handle → author_did`. Expression index
/// `idx_bsky_posts_lower_handle` covers `lower(author_handle)`.
pub const BSKY_HANDLE_DID_SQL: &str =
    "SELECT lower(author_handle), author_did FROM bsky_posts \
     WHERE lower(author_handle) = ANY($1::text[]) AND author_did LIKE 'did:%'";

/// `bsky_actor_profiles.actor_ref` is the primary key.
pub const BSKY_PROFILE_DID_SQL: &str =
    "SELECT actor_ref, did, profile_json FROM bsky_actor_profiles WHERE actor_ref = ANY($1::text[])";

const BSKY_HIDE_TTL: Duration = Duration::from_secs(10);

struct BskyHideEntry {
    at: Instant,
    dids: HashSet<String>,
}

fn bsky_hide_cache() -> &'static Mutex<HashMap<i64, BskyHideEntry>> {
    static CACHE: OnceLock<Mutex<HashMap<i64, BskyHideEntry>>> = OnceLock::new();
    CACHE.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Forget the in-memory hide sets. Tests and a hide-set rebuild use this.
pub fn clear_bsky_hide_cache() {
    if let Ok(mut guard) = bsky_hide_cache().lock() {
        guard.clear();
    }
}

/// One indexed read of the owner's Bluesky hide set, reused for a few seconds.
/// A miss or a database error is not cached.
pub async fn load_bsky_hide_dids(db: &Client, owner: i64) -> Result<HashSet<String>> {
    if owner < 1 {
        return Ok(HashSet::new());
    }
    {
        let guard = bsky_hide_cache()
            .lock()
            .unwrap_or_else(|err| err.into_inner());
        if let Some(hit) = guard.get(&owner) {
            if hit.at.elapsed() < BSKY_HIDE_TTL {
                return Ok(hit.dids.clone());
            }
        }
    }
    let rows = db
        .query(BSKY_HIDE_DIDS_SQL, &[&owner])
        .await
        .context("bsky hide dids")?;
    let mut dids = HashSet::new();
    for row in rows {
        let did = row
            .try_get::<_, Option<String>>(0)?
            .unwrap_or_default()
            .trim()
            .to_string();
        if did.starts_with("did:") {
            dids.insert(did);
        }
    }
    let mut guard = bsky_hide_cache().lock().unwrap_or_else(|err| err.into_inner());
    if guard.len() > 32 {
        guard.clear();
    }
    guard.insert(
        owner,
        BskyHideEntry {
            at: Instant::now(),
            dids: dids.clone(),
        },
    );
    Ok(dids)
}

/// Bluesky DID for a notification sender, or the handle that still needs the
/// local cache. The embedded status is the viewer's own post for likes and
/// reposts, so only the notifier is read.
pub fn notification_sender_bsky_identity(item: &Value) -> (Option<String>, Option<String>) {
    let mut handle = None;
    for reference in notification_sender_refs(item) {
        let (did, next_handle) = bsky_ref_identity(&reference);
        if did.is_some() {
            return (did, None);
        }
        if handle.is_none() {
            handle = next_handle;
        }
    }
    (None, handle)
}

pub fn notification_sender_did(item: &Value, handles: &HashMap<String, String>) -> Option<String> {
    let (did, handle) = notification_sender_bsky_identity(item);
    if did.is_some() {
        return did;
    }
    handle.and_then(|name| handles.get(&name).cloned()).filter(|did| did.starts_with("did:"))
}

/// Drop notifications whose Bluesky sender is in `hide`. `handles` is the
/// page's local handle→DID cache. Senders that are not Bluesky stay.
pub fn drop_bsky_hidden_senders(
    items: &mut Vec<Value>,
    hide: &HashSet<String>,
    handles: &HashMap<String, String>,
) {
    if hide.is_empty() {
        return;
    }
    items.retain(|item| match notification_sender_did(item, handles) {
        Some(did) => !hide.contains(&did),
        None => true,
    });
}

/// Drop notifications from DIDs in the owner's `bsky_hide_dids` rows
/// (block, mute, listblock, listmute). One indexed read, cached briefly,
/// plus at most two handle-cache reads for the whole page. No network.
///
/// `audience::filter_notifications` calls this for the web page, the
/// Mastodon notifications route, and the warm Redis list.
/// `notif_page::load_notification_page` calls it on each database batch
/// before that batch is counted as full. A hide-set read failure keeps
/// the page (mutes and blocks still apply) instead of failing the list.
pub async fn drop_bsky_hidden_notifications(
    db: &Client,
    owner: i64,
    items: &mut Vec<Value>,
) -> Result<()> {
    if owner < 1 || items.is_empty() || !items.iter().any(notification_may_be_bluesky) {
        return Ok(());
    }
    let hide = match load_bsky_hide_dids(db, owner).await {
        Ok(set) => set,
        Err(error) => {
            tracing::warn!(%error, "notification bsky hide set skipped");
            return Ok(());
        }
    };
    if hide.is_empty() {
        return Ok(());
    }
    let mut pending = Vec::new();
    for item in items.iter() {
        let (did, handle) = notification_sender_bsky_identity(item);
        if did.is_none() {
            if let Some(handle) = handle {
                if !pending.contains(&handle) {
                    pending.push(handle);
                }
            }
        }
    }
    let handles = if pending.is_empty() {
        HashMap::new()
    } else {
        match load_bsky_handle_dids(db, &pending).await {
            Ok(map) => map,
            Err(error) => {
                tracing::warn!(%error, "notification bsky handle cache skipped");
                HashMap::new()
            }
        }
    };
    drop_bsky_hidden_senders(items, &hide, &handles);
    Ok(())
}

async fn load_bsky_handle_dids(db: &Client, handles: &[String]) -> Result<HashMap<String, String>> {
    let mut found = HashMap::new();
    if handles.is_empty() {
        return Ok(found);
    }
    let handle_list = handles.to_vec();
    for row in db
        .query(BSKY_HANDLE_DID_SQL, &[&handle_list])
        .await
        .context("bsky handle dids")?
    {
        let handle = row
            .try_get::<_, Option<String>>(0)?
            .unwrap_or_default()
            .trim()
            .to_ascii_lowercase();
        let did = row.try_get::<_, Option<String>>(1)?.unwrap_or_default().trim().to_string();
        if !handle.is_empty() && did.starts_with("did:") {
            found.insert(handle, did);
        }
    }
    let still: Vec<String> = handles
        .iter()
        .filter(|handle| !found.contains_key(handle.as_str()))
        .flat_map(|handle| {
            [
                handle.clone(),
                format!("https://bsky.app/profile/{handle}"),
            ]
        })
        .collect();
    if still.is_empty() {
        return Ok(found);
    }
    let rows = match db.query(BSKY_PROFILE_DID_SQL, &[&still]).await {
        Ok(rows) => rows,
        Err(error) => {
            tracing::warn!(%error, "notification bsky profile cache skipped");
            return Ok(found);
        }
    };
    for row in rows {
        let actor_ref = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let mut did = row.try_get::<_, Option<String>>(1)?.unwrap_or_default().trim().to_string();
        if !did.starts_with("did:") {
            let profile: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
            did = serde_json::from_str::<Value>(&profile)
                .ok()
                .and_then(|json| json.get("did").and_then(Value::as_str).map(str::to_owned))
                .unwrap_or_default()
                .trim()
                .to_string();
        }
        let handle = profile_ref_handle(&actor_ref);
        if let Some(handle) = handle {
            if did.starts_with("did:") && !found.contains_key(&handle) {
                found.insert(handle, did);
            }
        }
    }
    Ok(found)
}

fn profile_ref_handle(actor_ref: &str) -> Option<String> {
    let actor_ref = actor_ref.trim();
    let path = actor_ref
        .get(..25)
        .filter(|prefix| prefix.eq_ignore_ascii_case("https://bsky.app/profile/"))
        .map(|_| &actor_ref[25..])
        .unwrap_or(actor_ref);
    let handle = raw_percent_decode(path)
        .trim()
        .trim_start_matches('@')
        .to_ascii_lowercase();
    let handle = handle.split(['/', '?', '#']).next().unwrap_or("").to_string();
    if handle.is_empty() || handle.starts_with("did:") || handle.contains('/') {
        None
    } else {
        Some(handle)
    }
}

fn notification_may_be_bluesky(item: &Value) -> bool {
    notification_sender_refs(item).into_iter().any(|reference| {
        let (did, handle) = bsky_ref_identity(&reference);
        did.is_some() || handle.is_some()
    })
}

fn notification_sender_refs(item: &Value) -> Vec<String> {
    let mut refs = Vec::new();
    push_sender_refs(item, &mut refs);
    if let Some(row) = item.get("row").filter(|row| row.is_object()) {
        push_sender_refs(row, &mut refs);
    }
    refs
}

fn push_sender_refs(src: &Value, refs: &mut Vec<String>) {
    for key in ["activity_id", "actor_id"] {
        push_ref(src.get(key).and_then(Value::as_str), refs);
    }
    if let Some(object_id) = src.get("object_id").and_then(Value::as_str) {
        if object_id.trim_start().starts_with("at://") {
            push_ref(Some(object_id), refs);
        }
    }
    if let Some(author) = src.get("author").filter(|author| author.is_object()) {
        push_ref(author.get("did").and_then(Value::as_str), refs);
        push_ref(author.get("handle").and_then(Value::as_str), refs);
    }
    let Some(account) = src.get("account").filter(|account| account.is_object()) else {
        return;
    };
    let mut bluesky = false;
    for key in ["uri", "url", "id"] {
        let value = account.get(key).and_then(Value::as_str);
        if let Some(value) = value {
            let trimmed = value.trim();
            if trimmed.len() >= 25 && trimmed[..25].eq_ignore_ascii_case("https://bsky.app/profile/")
                || trimmed.starts_with("at://")
                || trimmed.starts_with("did:")
            {
                bluesky = true;
            }
        }
        push_ref(value, refs);
    }
    if bluesky {
        push_ref(account.get("username").and_then(Value::as_str), refs);
        push_ref(account.get("acct").and_then(Value::as_str), refs);
    }
}

fn push_ref(value: Option<&str>, refs: &mut Vec<String>) {
    let Some(value) = value.map(str::trim).filter(|value| !value.is_empty()) else {
        return;
    };
    refs.push(value.to_string());
}

fn bsky_ref_identity(raw: &str) -> (Option<String>, Option<String>) {
    let raw = raw.trim();
    if raw.is_empty() || raw.bytes().all(|byte| byte.is_ascii_digit()) {
        return (None, None);
    }
    if raw.starts_with("did:plc:") || raw.starts_with("did:web:") {
        return (Some(raw.to_string()), None);
    }
    if let Some(rest) = raw.strip_prefix("at://") {
        if let Some(did) = rest.split(['/', '?', '#']).next() {
            if did.starts_with("did:") {
                return (Some(did.to_string()), None);
            }
        }
    }
    if raw.len() >= 25 && raw[..25].eq_ignore_ascii_case("https://bsky.app/profile/") {
        let part = raw_percent_decode(&raw[25..]);
        let part = part.split(['/', '?', '#']).next().unwrap_or("").trim().trim_start_matches('@');
        if part.starts_with("did:") {
            return (Some(part.to_string()), None);
        }
        if !part.is_empty() && !part.contains('/') {
            return (None, Some(part.to_ascii_lowercase()));
        }
        return (None, None);
    }
    if let Some(index) = raw.to_ascii_lowercase().find("did:plc:") {
        let did: String = raw[index..]
            .chars()
            .take_while(|ch| ch.is_ascii_alphanumeric() || *ch == ':' || *ch == '.' || *ch == '_' || *ch == '-' || *ch == '%')
            .collect();
        if did.starts_with("did:plc:") && did.len() > "did:plc:".len() {
            return (Some(did), None);
        }
    }
    let bare = raw
        .strip_prefix('@')
        .unwrap_or(raw)
        .split('@')
        .next()
        .unwrap_or(raw)
        .trim();
    if raw.to_ascii_lowercase().ends_with("@bsky.app") || !raw.contains(['/', ':']) {
        let handle = bare.trim_start_matches('@').to_ascii_lowercase();
        if is_bsky_handle(&handle) {
            return (None, Some(handle));
        }
    }
    (None, None)
}

fn is_bsky_handle(handle: &str) -> bool {
    let mut labels = handle.split('.');
    let Some(first) = labels.next() else {
        return false;
    };
    let Some(last) = labels.next_back() else {
        return false;
    };
    !first.is_empty()
        && first.chars().all(|ch| ch.is_ascii_alphanumeric() || ch == '-')
        && (2..=24).contains(&last.len())
        && last.chars().all(|ch| ch.is_ascii_alphabetic())
        && handle.chars().all(|ch| ch.is_ascii_alphanumeric() || ch == '-' || ch == '.')
        && !handle.contains("..")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn bsky_did_moderation_hides_handle_keyed_notifiers_and_back() {
        let did = "did:plc:abc123";
        let handle = "pest.bsky.social";
        let mut sets = HiddenSets::default();
        insert_actor_aliases(&mut sets.user_blocked_actors, &format!("https://bsky.app/profile/{did}"));
        for alias in actor_aliases("https://bsky.app/profile/Loud.Example") { sets.instance_muted_actors.insert(alias.to_ascii_lowercase()); }
        insert_actor_aliases(&mut sets.muted_actors, "https://bsky.app/profile/Quiet.Example");
        let (dids, handles) = bsky_moderation_refs(&sets);
        assert_eq!(dids, vec![did.to_string()]);
        let mut handles = handles; handles.sort();
        assert_eq!(handles, vec!["loud.example".to_string(), "quiet.example".to_string()]);
        assert!(!sets.is_hidden(&format!("https://bsky.app/profile/{handle}")));
        apply_bsky_identity_pairs(&mut sets, &[
            (did.into(), handle.into()),
            ("did:plc:loud".into(), "loud.example".into()),
            ("did:plc:quiet".into(), "quiet.example".into()),
            ("did:plc:other".into(), "other.example".into()),
        ]);
        assert!(sets.is_hidden(&format!("https://bsky.app/profile/{handle}")));
        assert!(sets.is_hidden(handle));
        assert!(sets.is_hidden("https://bsky.app/profile/did:plc:loud"));
        assert!(sets.is_hidden("did:plc:quiet"));
        assert!(!sets.is_hidden("https://bsky.app/profile/other.example"));
        assert!(!sets.is_hidden("did:plc:other"));
        let notification = serde_json::json!({"type":"favourite","account":{"uri":format!("https://bsky.app/profile/{handle}"),"url":format!("https://bsky.app/profile/{handle}")}});
        assert!(status_hidden(&notification, &sets));
    }

    #[test]
    fn domain_lookup_matches_original_predicate_for_arbitrary_string_boundaries() {
        // Preserve the actual original production formula as an independent oracle.
        let original = |hidden: &HiddenSets, host: &str| hidden.user_blocked_domains.iter()
            .chain(hidden.instance_blocked_domains.iter())
            .any(|domain| host == *domain || host.ends_with(&format!(".{domain}")));
        let table = [
            ("example.com", "example.com", true),
            ("sub.example.com", "example.com", true),
            ("badexample.com", "example.com", false),
            ("example.com.", "example.com", false),
            ("example.com.", "", true),
            (".example.com", "example.com", true),
            ("a..b", ".b", true),
            ("a..b", "b", true),
            ("a..b", "..b", false),
            ("a...", "..", true),
            (".", "", true),
            ("", "", true),
            ("", ".", false),
            ("EXAMPLE.COM", "example.com", false),
            ("é.example.com", "example.com", true),
            ("é。example.com", "example.com", false),
            ("a．b", "b", false),
            ("a.é", "é", true),
            ("127.0.0.1", "1", true),
            ("[::1]", "::1", false),
            ("[::1]", "[::1]", true),
            ("::ffff:192.0.2.1", "1", true),
            ("a.\0", "\0", true),
            ("a. b", "b", false),
            ("a. b", " b", true),
        ];
        let mut strings = vec![String::new()];
        let mut frontier = vec![String::new()];
        for _ in 0..3 {
            let next = frontier.iter().flat_map(|prefix| ["a", "A", ".", "é", "。", ":"].into_iter()
                .map(move |part| format!("{prefix}{part}"))).collect::<Vec<_>>();
            strings.extend(next.iter().cloned());frontier = next;
        }
        for (host, domain, expected) in table {
            let hidden = HiddenSets { user_blocked_domains: HashSet::from([domain.into()]), ..Default::default() };
            assert_eq!(original(&hidden, host), expected, "original edge host={host:?} domain={domain:?}");
            assert_eq!(hidden.host_blocked(host), expected, "edge host={host:?} domain={domain:?}");
            strings.push(host.into());strings.push(domain.into());
        }
        strings.sort();strings.dedup();
        for domain in &strings {
            for placement in 0..3 {
                let mut hidden = HiddenSets::default();
                if placement != 1 { hidden.user_blocked_domains.insert(domain.clone()); }
                if placement != 0 { hidden.instance_blocked_domains.insert(domain.clone()); }
                for host in &strings {
                    assert_eq!(hidden.host_blocked(host), original(&hidden, host),
                        "host={host:?} domain={domain:?} placement={placement}");
                }
            }
        }
        // Mixed sets exercise union semantics and several matching suffixes.
        let hidden = HiddenSets {
            user_blocked_domains: HashSet::from([".b".into(), "EXAMPLE.COM".into(), "é".into()]),
            instance_blocked_domains: HashSet::from(["b".into(), "example.com".into(), "".into()]),
            ..Default::default()
        };
        for host in &strings {
            assert_eq!(hidden.host_blocked(host), original(&hidden, host), "mixed host={host:?}");
            assert!(!HiddenSets::default().host_blocked(host), "empty sets host={host:?}");
        }
    }

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

#[cfg(test)]
mod canonical_policy_differential {
    use super::*;
    fn same(actual:&HiddenSets,reference:&HiddenSets){
        assert_eq!(actual.muted_actors,reference.muted_actors);
        assert_eq!(actual.user_blocked_actors,reference.user_blocked_actors);
        assert_eq!(actual.user_blocked_domains,reference.user_blocked_domains);
        assert_eq!(actual.instance_blocked_actors,reference.instance_blocked_actors);
        assert_eq!(actual.instance_muted_actors,reference.instance_muted_actors);
        assert_eq!(actual.instance_blocked_domains,reference.instance_blocked_domains);
        let mut a=actual.muted_phrases.clone();let mut b=reference.muted_phrases.clone();a.sort();b.sort();assert_eq!(a,b);
    }
    /// Compare the actual original five-table reader against the combined
    /// reader on both schema widths and live mutations, not a mirrored oracle.
    #[tokio::test]
    #[ignore = "requires isolated PostgreSQL fixture"]
    async fn combined_policy_matches_original_current_reader(){
        let url=std::env::var("VAAK_CANONICAL_POLICY_TEST_DATABASE_URL").expect("explicit isolated fixture database required");
        let db=crate::db::connect(&url).await.unwrap();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("library_proof_policy_{}_{}",std::process::id(),width.to_ascii_lowercase());
            db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path TO {schema};
                CREATE TABLE ap_mutes(owner_user_id {width},actor_id TEXT);
                CREATE TABLE ap_user_blocks(owner_user_id {width},scope TEXT,value TEXT);
                CREATE TABLE ap_blocks(scope TEXT,value TEXT,kind TEXT);
                CREATE TABLE ap_muted_words(owner_user_id {width},phrase TEXT);
                CREATE TABLE bsky_hide_dids(owner_user_id {width},did TEXT);
                INSERT INTO ap_mutes VALUES(1,' https://Example.test/users/Case/ '),(1,''),(1,NULL),(2,'https://outside.test/users/other');
                INSERT INTO ap_user_blocks VALUES(1,'actor','https://blocked.test/@User/'),(1,'domain',' .SUB.Blocked.test '),(1,'actor',' '),(1,'unknown','ignored'),(2,'actor','https://other.test/u');
                INSERT INTO ap_blocks VALUES('actor','https://INSTANCE.test/users/Mute/','mute'),('actor','https://instance.test/users/Block/','block'),('domain','.SERVER.test',NULL),('actor','',NULL),('unknown','ignored','block');
                INSERT INTO ap_muted_words VALUES(1,' Cats &amp; DOGS '),(1,''),(2,'other viewer');
                INSERT INTO bsky_hide_dids VALUES(1,' did:plc:blocked/ '),(1,'did:plc:blocked/'),(1,NULL),(1,''),(2,'did:plc:other');")).await.unwrap();
            for owner in [1,2]{
                let a=load_canonical_library_hidden_sets(&db,owner).await.unwrap();let b=load_library_hidden_sets(&db,owner).await.unwrap();same(&a,&b);
                for card in [serde_json::json!({"account":{"uri":"https://Example.test/users/Case/"}}),serde_json::json!({"quote":{"quoted_status":{"account":{"uri":"https://instance.test/users/mute"}}}}),serde_json::json!({"content":"Cats &amp; dogs"}),serde_json::json!({"uri":"https://sub.server.test/notes/1"}),serde_json::json!({"post":{"uri":"at://did:plc:blocked/app.bsky.feed.post/a","author":{"did":"did:plc:blocked"}}})]{assert_eq!(library_status_hidden(&card,&a),library_status_hidden(&card,&b));}
            }
            db.batch_execute("DELETE FROM ap_mutes;DELETE FROM ap_user_blocks;DELETE FROM ap_blocks;DELETE FROM ap_muted_words;DELETE FROM bsky_hide_dids;").await.unwrap();
            same(&load_canonical_library_hidden_sets(&db,1).await.unwrap(),&load_library_hidden_sets(&db,1).await.unwrap());
            db.batch_execute("INSERT INTO ap_muted_words VALUES(1,'new withdrawal');INSERT INTO ap_blocks VALUES('actor','https://late.test/users/a','mute');").await.unwrap();
            let current=load_canonical_library_hidden_sets(&db,1).await.unwrap();same(&current,&load_library_hidden_sets(&db,1).await.unwrap());assert!(library_status_hidden(&serde_json::json!({"content":"NEW withdrawal"}),&current));
            assert!(load_canonical_library_hidden_sets(&db,0).await.is_err());assert!(load_library_hidden_sets(&db,0).await.is_err());
            db.batch_execute("DROP TABLE ap_muted_words").await.unwrap();assert!(load_canonical_library_hidden_sets(&db,1).await.is_err());assert!(load_library_hidden_sets(&db,1).await.is_err());
            db.batch_execute(&format!("SET search_path TO public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }
    }
    #[test]
    fn ordinary_own_word_scope_preserves_discovery_foreign_nested_and_actor_policy() {
        let actor="https://fixture.test/users/own_name";
        let uri=format!("{actor}/notes/media");
        let mut hidden=HiddenSets{muted_phrases:vec!["café & dogs".into()],ordinary_owned_notes:HashMap::from([(uri.clone(),actor.into())]),..Default::default()};
        let own=serde_json::json!({"uri":uri,"account":{"uri":actor},"content":"<p>CAFÉ &amp; dogs</p>","media_attachments":[{"type":"image"}]});
        assert!(status_hidden(&own,&hidden));assert!(!ordinary_status_hidden(&own,&hidden));
        let mut text=own.clone();text["media_attachments"]=serde_json::json!([]);assert!(!ordinary_status_hidden(&text,&hidden));
        let mut peer=own.clone();peer["uri"]=serde_json::json!("https://fixture.test/users/peer/notes/peer");assert!(ordinary_status_hidden(&peer,&hidden));
        let mut forged=own.clone();forged["account"]["uri"]=serde_json::json!("https://fixture.test/users/peer");assert!(ordinary_status_hidden(&forged,&hidden));
        for field in ["quote","vaak_quote_preview"] {let mut nested=own.clone();nested[field]=serde_json::json!({"status":peer});assert!(ordinary_status_hidden(&nested,&hidden));}
        let mut reblog=own.clone();reblog["reblog"]=peer.clone();assert!(ordinary_status_hidden(&reblog,&hidden));
        hidden.user_blocked_actors.insert(actor.into());assert!(ordinary_status_hidden(&own,&hidden));hidden.user_blocked_actors.clear();
        hidden.instance_muted_actors.insert(actor.into());assert!(ordinary_status_hidden(&own,&hidden));hidden.instance_muted_actors.clear();
        hidden.user_blocked_domains.insert("fixture.test".into());assert!(ordinary_status_hidden(&own,&hidden));
    }

    #[tokio::test]
    #[ignore="requires isolated PostgreSQL fixture"]
    async fn ordinary_own_word_current_sql_both_widths() {
        let url=std::env::var("VAAK_CANONICAL_POLICY_TEST_DATABASE_URL").expect("explicit isolated fixture required");
        let db=crate::db::connect(&url).await.unwrap();
        for width in ["INTEGER","BIGINT"] {
            let schema=format!("ordinary_words_{}_{}",std::process::id(),width.to_ascii_lowercase());
            db.batch_execute(&format!("CREATE SCHEMA {schema}; SET search_path={schema};CREATE TABLE ap_users(id {width},actor_id TEXT,disabled_at TEXT);CREATE TABLE outbox_notes(id TEXT);INSERT INTO ap_users VALUES(1,'https://fixture.test/users/own_name',NULL),(2,'https://fixture.test/users/ownXname',NULL);INSERT INTO outbox_notes VALUES('https://fixture.test/users/own_name/notes/media'),('https://fixture.test/users/ownXname/notes/peer')")).await.unwrap();
            let ids=vec!["https://fixture.test/users/own_name/notes/media".into(),"https://fixture.test/users/ownXname/notes/peer".into()];
            let mut hidden=HiddenSets{muted_phrases:vec!["café & dogs".into()],..Default::default()};
            load_ordinary_owned_notes(&db,1,&ids,&mut hidden).await.unwrap();assert_eq!(hidden.ordinary_owned_notes.len(),1);
            let own=serde_json::json!({"uri":ids[0],"account":{"uri":"https://fixture.test/users/own_name"},"content":"CAFÉ &amp; dogs"});
            assert!(!ordinary_status_hidden(&own,&hidden));assert!(status_hidden(&own,&hidden));
            db.batch_execute("UPDATE ap_users SET disabled_at='now' WHERE id=1").await.unwrap();load_ordinary_owned_notes(&db,1,&ids,&mut hidden).await.unwrap();assert!(ordinary_status_hidden(&own,&hidden));
            db.batch_execute("UPDATE ap_users SET disabled_at=NULL,actor_id='https://fixture.test/users/new_owner' WHERE id=1").await.unwrap();load_ordinary_owned_notes(&db,1,&ids,&mut hidden).await.unwrap();assert!(ordinary_status_hidden(&own,&hidden));
            db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }
    }

}

#[cfg(test)]
#[path="search_batch_hidden_fixture.rs"]
pub(crate) mod batch_differential;

#[cfg(test)]
mod bridgy_block_tests {
    use super::*;

    #[test]
    fn blocked_fediverse_actor_hides_its_bridgy_account() {
        let mut hidden = HiddenSets::default();
        hidden.bridged_blocked_handles.extend(bridgy_handles_for("Cool_Cat", "app.wafrn.net"));
        for actor in [
            "https://bsky.app/profile/cool-cat.app.wafrn.net.ap.brid.gy",
            "https://bsky.app/profile/cool_cat.app.wafrn.net.ap.brid.gy",
            "cool-cat.app.wafrn.net.ap.brid.gy",
            "@Cool-Cat.app.wafrn.net.ap.brid.gy",
        ] {
            assert!(hidden.is_hidden(actor), "{actor}");
        }
        assert!(!hidden.is_hidden("https://bsky.app/profile/other.app.wafrn.net.ap.brid.gy"));
        assert!(!hidden.is_hidden("https://bsky.app/profile/cool-cat.bsky.social"));
    }

    #[test]
    fn blocked_domain_hides_bridgy_accounts_from_it() {
        for (personal, server) in [(true, false), (false, true)] {
            let mut hidden = HiddenSets::default();
            if personal { hidden.user_blocked_domains.insert("bad.example".into()); }
            if server { hidden.instance_blocked_domains.insert("bad.example".into()); }
            assert!(hidden.is_hidden("https://bsky.app/profile/alice.bad.example.ap.brid.gy"));
            assert!(hidden.is_hidden("https://bsky.app/profile/alice.social.bad.example.ap.brid.gy"));
            assert!(!hidden.is_hidden("https://bsky.app/profile/alice.notbad.example.ap.brid.gy"));
            assert!(!hidden.is_hidden("https://bsky.app/profile/alice.bad.example"));
        }
    }

    #[test]
    fn bridgy_status_card_is_hidden_by_account_handle() {
        let mut hidden = HiddenSets::default();
        hidden.bridged_blocked_handles.extend(bridgy_handles_for("alice", "mastodon.example"));
        let status = serde_json::json!({
            "uri": "at://did:plc:bridged/app.bsky.feed.post/1",
            "content": "hi",
            "account": {"id": "did:plc:bridged", "uri": "https://bsky.app/profile/alice.mastodon.example.ap.brid.gy"},
        });
        assert!(status_hidden(&status, &hidden));
    }

    #[test]
    fn account_comes_from_acct_or_profile_path() {
        assert_eq!(fedi_account_of("@alice@mastodon.example"), Some(("alice".into(), "mastodon.example".into())));
        assert_eq!(fedi_account_of("https://mastodon.example/users/alice"), Some(("alice".into(), "mastodon.example".into())));
        assert_eq!(fedi_account_of("https://mastodon.example/@alice"), Some(("alice".into(), "mastodon.example".into())));
        assert_eq!(fedi_account_of("https://misskey.example/users/9abc"), Some(("9abc".into(), "misskey.example".into())));
        assert_eq!(fedi_account_of("https://misskey.example/users/12345"), None);
        assert_eq!(fedi_account_of("https://app.wafrn.net/fediverse/blog/alice"), None);
    }
}

#[cfg(test)]
mod bsky_hide_notification_tests {
    use super::{
        drop_bsky_hidden_senders, notification_sender_did, BSKY_HANDLE_DID_SQL, BSKY_HIDE_DIDS_SQL,
        BSKY_PROFILE_DID_SQL,
    };
    use serde_json::{json, Value};
    use std::collections::{HashMap, HashSet};

    fn hide() -> HashSet<String> {
        HashSet::from([
            "did:plc:listblock".into(),
            "did:plc:block".into(),
            "did:plc:mute".into(),
            "did:plc:listmute".into(),
        ])
    }

    fn handles() -> HashMap<String, String> {
        HashMap::from([
            ("broadeyeview.bsky.social".into(), "did:plc:listblock".into()),
            ("blocked.bsky.social".into(), "did:plc:block".into()),
            ("muted.bsky.social".into(), "did:plc:mute".into()),
            ("listmuted.bsky.social".into(), "did:plc:listmute".into()),
            ("friend.bsky.social".into(), "did:plc:friend".into()),
        ])
    }

    fn account(uri: &str) -> Value {
        json!({
            "id": "9001",
            "uri": uri,
            "url": uri,
            "username": uri.trim_start_matches("https://bsky.app/profile/"),
            "acct": uri.trim_start_matches("https://bsky.app/profile/"),
        })
    }

    fn notif(id: &str, kind: &str, account: Value, activity_id: Option<&str>) -> Value {
        let mut item = json!({
            "id": id,
            "type": kind,
            "account": account,
            "status": {"content": "hello", "account": {"uri": "https://mkultra.monster/users/ada"}},
        });
        if let Some(activity_id) = activity_id {
            item["activity_id"] = json!(activity_id);
        }
        if kind == "follow" {
            item["status"] = Value::Null;
            item["object_id"] = json!(format!("at://{}/app.bsky.graph.follow/{id}", "did:plc:listblock"));
        }
        item
    }

    #[test]
    fn hide_sql_is_one_indexed_owner_read() {
        assert_eq!(
            BSKY_HIDE_DIDS_SQL,
            "SELECT DISTINCT did FROM bsky_hide_dids WHERE owner_user_id = $1"
        );
        assert!(BSKY_HANDLE_DID_SQL.contains("lower(author_handle) = ANY($1::text[])"));
        assert!(BSKY_PROFILE_DID_SQL.contains("actor_ref = ANY($1::text[])"));
        assert!(!BSKY_HIDE_DIDS_SQL.contains("http"));
    }

    #[test]
    fn every_reason_hides_every_notification_type() {
        let viewer = account("https://mkultra.monster/users/ada");
        let mut items = vec![
            // Production shape: handle URL only, DID comes from the local cache.
            notif(
                "1997",
                "mention",
                account("https://bsky.app/profile/broadeyeview.bsky.social"),
                None,
            ),
            notif(
                "reply",
                "mention",
                account("https://bsky.app/profile/blocked.bsky.social"),
                Some("at://did:plc:block/app.bsky.feed.post/reply"),
            ),
            notif(
                "like",
                "favourite",
                account("https://bsky.app/profile/muted.bsky.social"),
                Some("at://did:plc:mute/app.bsky.feed.like/1"),
            ),
            notif(
                "repost",
                "reblog",
                account("https://bsky.app/profile/listmuted.bsky.social"),
                Some("at://did:plc:listmute/app.bsky.feed.repost/1"),
            ),
            notif(
                "follow",
                "follow",
                account("https://bsky.app/profile/broadeyeview.bsky.social"),
                None,
            ),
            notif(
                "quote",
                "quote",
                account("https://bsky.app/profile/did:plc:block"),
                Some("at://did:plc:block/app.bsky.feed.post/quote"),
            ),
            notif(
                "keep-mention",
                "mention",
                account("https://bsky.app/profile/friend.bsky.social"),
                Some("at://did:plc:friend/app.bsky.feed.post/1"),
            ),
            notif("keep-fedi", "mention", account("https://friend.example/users/kind"), None),
            json!({
                "id": "keep-like",
                "type": "favourite",
                "account": account("https://bsky.app/profile/friend.bsky.social"),
                "status": {
                    "uri": "https://mkultra.monster/users/ada/notes/live",
                    "content": "mine",
                    "account": viewer,
                },
            }),
        ];
        // A like's embedded status is the viewer's post. A hidden DID on that
        // status must not drop a visible notifier, and must not keep a hidden one.
        items[2]["status"]["account"] = account("https://mkultra.monster/users/ada");
        drop_bsky_hidden_senders(&mut items, &hide(), &handles());
        let ids: Vec<&str> = items.iter().filter_map(|item| item["id"].as_str()).collect();
        assert_eq!(ids, vec!["keep-mention", "keep-fedi", "keep-like"]);
    }

    #[test]
    fn direct_at_uri_beats_a_stale_handle_and_a_missing_cache_stays() {
        let item = json!({
            "id": "1",
            "type": "reply",
            "activity_id": "at://did:plc:listblock/app.bsky.feed.post/1",
            "account": {"uri": "https://bsky.app/profile/renamed.example"},
            "row": {"actor_id": "https://bsky.app/profile/renamed.example"},
        });
        assert_eq!(
            notification_sender_did(&item, &HashMap::new()).as_deref(),
            Some("did:plc:listblock")
        );
        let unresolved = json!({
            "id": "2",
            "type": "mention",
            "account": {"uri": "https://bsky.app/profile/unknown.bsky.social"},
        });
        assert_eq!(notification_sender_did(&unresolved, &HashMap::new()), None);
        let mut items = vec![unresolved, json!({"id": "3", "type": "follow", "account": {"uri": "https://remote.test/users/ann"}})];
        drop_bsky_hidden_senders(&mut items, &hide(), &HashMap::new());
        assert_eq!(items.len(), 2);
    }

    #[test]
    fn raw_database_rows_drop_before_the_page_is_counted() {
        let mut items = vec![
            json!({
                "id": "p-listblock",
                "type": "mention",
                "activity_id": "at://did:plc:listblock/app.bsky.feed.post/1",
                "actor_id": "https://bsky.app/profile/broadeyeview.bsky.social",
            }),
            json!({
                "id": "p-block",
                "type": "mention",
                "activity_id": "at://did:plc:block/app.bsky.feed.post/reply",
                "actor_id": "https://bsky.app/profile/blocked.bsky.social",
            }),
            json!({
                "id": "p-mute",
                "type": "favourite",
                "activity_id": "at://did:plc:mute/app.bsky.feed.like/1",
                "actor_id": "https://bsky.app/profile/muted.bsky.social",
                "object_id": "https://mkultra.monster/users/ada/notes/live",
            }),
            json!({
                "id": "p-listmute",
                "type": "follow",
                "actor_id": "https://bsky.app/profile/listmuted.bsky.social",
                "object_id": "at://did:plc:listmute/app.bsky.graph.follow/1",
            }),
            json!({
                "id": "p-friend",
                "type": "quote",
                "activity_id": "at://did:plc:friend/app.bsky.feed.post/1",
                "actor_id": "https://bsky.app/profile/friend.bsky.social",
            }),
        ];
        drop_bsky_hidden_senders(&mut items, &hide(), &HashMap::new());
        let ids: Vec<&str> = items.iter().filter_map(|item| item["id"].as_str()).collect();
        assert_eq!(ids, vec!["p-friend"]);
    }

    #[test]
    fn empty_hide_set_keeps_the_page() {
        let mut items = vec![notif(
            "1997",
            "mention",
            account("https://bsky.app/profile/broadeyeview.bsky.social"),
            Some("at://did:plc:listblock/app.bsky.feed.post/1"),
        )];
        drop_bsky_hidden_senders(&mut items, &HashSet::new(), &handles());
        assert_eq!(items.len(), 1);
    }
}
