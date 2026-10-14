//! Pure follow-state and quiet-host fallback decisions.
//!
//! This module does not deliver activities, open the database, or fetch
//! remote outboxes. The PHP scheduler is the live path. Parity replay and
//! unit tests call these functions directly.

use regex::Regex;
use serde_json::{json, Map, Value};
use std::sync::OnceLock;

fn state_normalize(state: &str) -> String {
    match state.trim().to_ascii_lowercase().as_str() {
        "pending" | "accepted" | "rejected" | "unknown" => state.trim().to_ascii_lowercase(),
        _ => "unknown".into(),
    }
}

fn state_apply(current: &str, event: &str) -> String {
    match event.trim().to_ascii_lowercase().as_str() {
        "send" => "pending".into(),
        "accept" => "accepted".into(),
        "reject" => "rejected".into(),
        _ => state_normalize(current),
    }
}

fn state_id(value: &Value) -> String {
    match value {
        Value::String(text) => {
            let text = text.trim();
            if text.starts_with("https://") {
                text.trim_end_matches('/').to_string()
            } else {
                String::new()
            }
        }
        Value::Object(map) => map.get("id").map(state_id).unwrap_or_default(),
        _ => String::new(),
    }
}

fn host_of(url: &str) -> String {
    let rest = url.trim().trim_start_matches("https://");
    let host = rest.split('/').next().unwrap_or("");
    let host = host.split('@').next_back().unwrap_or(host);
    host.split(':').next().unwrap_or("").trim_end_matches('.').to_ascii_lowercase()
}

fn host_re() -> &'static Regex {
    static HOST: OnceLock<Regex> = OnceLock::new();
    HOST.get_or_init(|| {
        Regex::new(r"^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$")
            .expect("host pattern")
    })
}

fn fallback_host(raw: &str) -> String {
    let mut raw = raw.trim().to_ascii_lowercase();
    if raw.is_empty() {
        return String::new();
    }
    if let Some(index) = raw.find("://") {
        raw = raw[index + 3..].to_string();
    }
    raw = raw.split('/').next().unwrap_or("").to_string();
    if let Some((_, host)) = raw.rsplit_once('@') {
        raw = host.to_string();
    }
    raw = raw.trim_end_matches('.').to_string();
    if let Some(stripped) = raw.rsplit_once(':') {
        if stripped.1.chars().all(|ch| ch.is_ascii_digit()) {
            raw = stripped.0.to_string();
        }
    }
    if host_re().is_match(&raw) {
        raw
    } else {
        String::new()
    }
}

fn actor_is_local(actor_id: &str, local_actor: &str) -> bool {
    let actor_id = actor_id.trim().trim_end_matches('/');
    let local_actor = local_actor.trim().trim_end_matches('/');
    if actor_id.is_empty() || local_actor.is_empty() || !actor_id.starts_with("https://") {
        return false;
    }
    if actor_id == local_actor {
        return true;
    }
    let local_host = host_of(local_actor);
    let actor_host = host_of(actor_id);
    let user_path = actor_id
        .trim_start_matches("https://")
        .split_once('/')
        .map(|(_, path)| format!("/{path}"))
        .unwrap_or_default();
    static USER: OnceLock<Regex> = OnceLock::new();
    let user = USER.get_or_init(|| Regex::new(r"^/users/[A-Za-z0-9_]+$").expect("user path"));
    !local_host.is_empty() && actor_host == local_host && user.is_match(&user_path)
}

fn classify(activity: &Value, local_actor: &str) -> Value {
    let kind = activity.get("type").and_then(Value::as_str).unwrap_or("").trim().to_ascii_lowercase();
    if kind != "accept" && kind != "reject" {
        return Value::Null;
    }
    let state = if kind == "accept" { "accepted" } else { "rejected" };
    let activity_actor = activity.get("actor").map(state_id).unwrap_or_default();
    let Some(object) = activity.get("object") else { return Value::Null };
    if let Some(map) = object.as_object() {
        let object_type = map.get("type").and_then(Value::as_str).unwrap_or("");
        if object_type.eq_ignore_ascii_case("Follow") {
            let inner_actor = map.get("actor").map(state_id).unwrap_or_default();
            let inner_object = map.get("object").map(state_id).unwrap_or_default();
            let follow_id = map.get("id").map(state_id).unwrap_or_default();
            if !inner_actor.is_empty() && actor_is_local(&inner_actor, local_actor) {
                return json!({
                    "kind": "outbound",
                    "state": state,
                    "remote_actor": activity_actor,
                    "local_actor": inner_actor,
                    "follow_id": follow_id,
                    "target_actor": inner_object,
                });
            }
            if !inner_object.is_empty() && actor_is_local(&inner_object, local_actor) {
                return json!({
                    "kind": "ack",
                    "state": state,
                    "remote_actor": inner_actor,
                    "local_actor": inner_object,
                    "follow_id": follow_id,
                    "target_actor": inner_object,
                });
            }
            return Value::Null;
        }
    }
    if let Some(text) = object.as_str() {
        let text = text.trim();
        if text.starts_with("https://") {
            return json!({
                "kind": "by_id",
                "state": state,
                "remote_actor": activity_actor,
                "local_actor": "",
                "follow_id": text.trim_end_matches('/'),
                "target_actor": "",
            });
        }
    }
    Value::Null
}

fn actor_matches(activity_actor: &str, followed_actor: &str) -> bool {
    let activity_actor = activity_actor.trim().trim_end_matches('/');
    let followed_actor = followed_actor.trim().trim_end_matches('/');
    if activity_actor.is_empty() || followed_actor.is_empty() || !activity_actor.starts_with("https://") {
        return false;
    }
    if activity_actor == followed_actor {
        return true;
    }
    let left = host_of(activity_actor);
    let right = host_of(followed_actor);
    !left.is_empty() && left == right
}

fn follow_event(kind: &str) -> bool {
    matches!(kind.trim().to_ascii_lowercase().as_str(), "follow" | "accept" | "reject")
}

fn should_log(ok: bool, kind: &str) -> bool {
    !ok || follow_event(kind)
}

fn scrub(text: &str, limit: usize) -> String {
    static PEM: OnceLock<Regex> = OnceLock::new();
    static SIG: OnceLock<Regex> = OnceLock::new();
    static KEY: OnceLock<Regex> = OnceLock::new();
    static AUTH: OnceLock<Regex> = OnceLock::new();
    static BEARER: OnceLock<Regex> = OnceLock::new();
    static DIGEST: OnceLock<Regex> = OnceLock::new();
    static BARE: OnceLock<Regex> = OnceLock::new();
    static SPACE: OnceLock<Regex> = OnceLock::new();
    let pem = PEM.get_or_init(|| Regex::new(r"(?s)-----BEGIN [^-]+-----.*?-----END [^-]+-----").unwrap());
    let sig = SIG.get_or_init(|| Regex::new(r#"(?i)signature="[^"]*""#).unwrap());
    let key = KEY.get_or_init(|| Regex::new(r#"(?i)keyid="[^"]*""#).unwrap());
    let auth = AUTH.get_or_init(|| Regex::new(r"(?i)authorization:\s*\S+").unwrap());
    let bearer = BEARER.get_or_init(|| Regex::new(r"(?i)bearer\s+\S+").unwrap());
    let digest = DIGEST.get_or_init(|| Regex::new(r"(?i)digest:\s*sha-256=\S+").unwrap());
    let bare = BARE.get_or_init(|| Regex::new(r"(?i)\b(?:signature|digest|keyid)\s*[:=]\s*\S+").unwrap());
    let space = SPACE.get_or_init(|| Regex::new(r"\s+").unwrap());
    let mut text = pem.replace_all(text, "").into_owned();
    for pattern in [sig, key, auth, bearer, digest, bare] {
        text = pattern.replace_all(&text, "").into_owned();
    }
    if text.to_ascii_lowercase().contains("private key") {
        return String::new();
    }
    let text = space.replace_all(&text, " ").trim().to_string();
    if limit > 0 && text.len() > limit {
        let mut end = limit;
        while end > 0 && !text.is_char_boundary(end) {
            end -= 1;
        }
        return text[..end].to_string();
    }
    text
}

fn log_line(host: &str, kind: &str, status: i64, ok: bool, body: &str, path: &str) -> String {
    let host = fallback_host(host);
    let host = if host.is_empty() { "-".into() } else { host };
    let mut kind_clean: String = kind.chars().filter(|ch| ch.is_ascii_alphabetic()).collect();
    if kind_clean.is_empty() || kind_clean.len() > 32 {
        kind_clean = "Activity".into();
    }
    let path = path.split('?').next().unwrap_or("");
    static PATH: OnceLock<Regex> = OnceLock::new();
    let path_re = PATH.get_or_init(|| Regex::new(r"^/[A-Za-z0-9._~%!$&'()*+,;=:@/-]{0,80}$").unwrap());
    let path = if path_re.is_match(path) { path } else { "" };
    let mut line = format!("deliver host={host} type={kind_clean} status={status} ok={}", if ok { "1" } else { "0" });
    if !path.is_empty() {
        line.push_str(" path=");
        line.push_str(path);
    }
    if !ok {
        let snippet = scrub(body, 120);
        if !snippet.is_empty() {
            line.push_str(" body=");
            line.push_str(&snippet);
        }
    }
    line
}

fn flag_on(env: &Map<String, Value>, key: &str, default: bool) -> bool {
    let Some(raw) = env.get(key) else { return default };
    let raw = match raw {
        Value::Bool(value) => return *value,
        Value::String(text) => text.trim().to_ascii_lowercase(),
        other => other.to_string().trim().to_ascii_lowercase(),
    };
    if raw.is_empty() {
        return default;
    }
    !matches!(raw.as_str(), "0" | "false" | "off" | "no")
}

fn env_int(env: &Map<String, Value>, key: &str, default: i64, min: i64, max: i64) -> i64 {
    let Some(raw) = env.get(key) else { return default };
    let parsed = match raw {
        Value::Number(number) => number.as_i64(),
        Value::String(text) if !text.trim().is_empty() => text.trim().parse::<i64>().ok(),
        _ => None,
    };
    parsed.map(|value| value.clamp(min, max)).unwrap_or(default)
}

fn config_from_env(env: &Map<String, Value>) -> Value {
    let raw_hosts = env
        .get("VAAK_FOLLOW_FALLBACK_HOSTS")
        .and_then(Value::as_str)
        .unwrap_or("misskey.io");
    let mut hosts = Vec::new();
    for part in raw_hosts.split(|ch: char| ch.is_whitespace() || ch == ',') {
        let host = fallback_host(part);
        if !host.is_empty() && !hosts.contains(&host) {
            hosts.push(host);
        }
    }
    let silence_hours = env_int(env, "VAAK_FOLLOW_FALLBACK_SILENCE_HOURS", 24, 1, 168);
    let pending_hours = env_int(env, "VAAK_FOLLOW_FALLBACK_PENDING_HOURS", 6, 1, 168);
    let min_minutes = env_int(env, "VAAK_FOLLOW_FALLBACK_MIN_INTERVAL_MINUTES", 15, 15, 30);
    json!({
        "enabled": flag_on(env, "VAAK_FOLLOW_FALLBACK", true),
        "hosts": hosts,
        "silence_seconds": silence_hours * 3600,
        "pending_seconds": pending_hours * 3600,
        "page_limit": env_int(env, "VAAK_FOLLOW_FALLBACK_PAGE_LIMIT", 5, 1, 8),
        "max_accounts": env_int(env, "VAAK_FOLLOW_FALLBACK_MAX_ACCOUNTS", 8, 1, 20),
        "min_interval_seconds": min_minutes * 60,
        "featured_limit": 3,
        "request_budget": 3,
    })
}

fn due(last_run: i64, now: i64, min_seconds: i64) -> bool {
    if min_seconds < 1 || last_run <= 0 {
        return true;
    }
    now - last_run >= min_seconds
}

fn backoff_seconds(fail_count: i64, status: i64) -> i64 {
    let fail_count = fail_count.max(1);
    let shift = (fail_count - 1).clamp(0, 6) as u32;
    let delay = 15 * 60 * (1i64 << shift);
    if status == 429 {
        return delay.min(6 * 3600);
    }
    if status >= 500 || status == 0 {
        return delay.min(2 * 3600);
    }
    6 * 3600
}

fn plan(config: &Value, accounts: &[Value], host_seen: &Map<String, Value>, now: i64) -> Vec<String> {
    if config.get("enabled").and_then(Value::as_bool) != Some(true) {
        return Vec::new();
    }
    let mut force = Vec::new();
    if let Some(hosts) = config.get("hosts").and_then(Value::as_array) {
        for host in hosts {
            if let Some(text) = host.as_str() {
                let host = fallback_host(text);
                if !host.is_empty() && !force.contains(&host) {
                    force.push(host);
                }
            }
        }
    }
    let pending_after = config.get("pending_seconds").and_then(Value::as_i64).unwrap_or(21600);
    let silence_after = config.get("silence_seconds").and_then(Value::as_i64).unwrap_or(86400);
    let mut ranked = Vec::new();
    for account in accounts {
        let actor = account.get("actor_id").and_then(Value::as_str).unwrap_or("").trim().trim_end_matches('/').to_string();
        let host = fallback_host(account.get("host").and_then(Value::as_str).unwrap_or(""));
        let state = state_normalize(account.get("follow_state").and_then(Value::as_str).unwrap_or(""));
        let state_at = account.get("state_at").and_then(Value::as_i64).unwrap_or(0);
        let backoff = account.get("backoff_until").and_then(Value::as_i64).unwrap_or(0);
        if actor.is_empty() || host.is_empty() || state == "rejected" || backoff > now {
            continue;
        }
        let mut reason = "";
        let mut seen_at = 0i64;
        if force.iter().any(|item| item == &host) {
            reason = "force";
        } else if state == "pending" && state_at > 0 && now - state_at >= pending_after {
            reason = "pending";
        } else if let Some(stamp) = host_seen.get(&host).and_then(Value::as_i64) {
            if now - stamp >= silence_after {
                reason = "silent";
                seen_at = stamp;
            }
        }
        if reason.is_empty() {
            continue;
        }
        ranked.push((actor, reason, state_at, seen_at));
    }
    let rank = |reason: &str| match reason {
        "force" => 0,
        "pending" => 1,
        "silent" => 2,
        _ => 9,
    };
    ranked.sort_by(|a, b| {
        rank(a.1).cmp(&rank(b.1)).then_with(|| {
            if a.1 == "pending" && a.2 != b.2 {
                a.2.cmp(&b.2)
            } else if a.1 == "silent" && a.3 != b.3 {
                a.3.cmp(&b.3)
            } else {
                a.0.cmp(&b.0)
            }
        })
    });
    let cap = config.get("max_accounts").and_then(Value::as_i64).unwrap_or(8).max(1) as usize;
    let mut out = Vec::new();
    for (actor, _, _, _) in ranked {
        if out.contains(&actor) {
            continue;
        }
        out.push(actor);
        if out.len() >= cap {
            break;
        }
    }
    out
}

fn select_new(object_ids: &[Value], seen: &[Value], limit: i64, stop_at_seen: bool) -> Value {
    let mut known = Vec::new();
    for id in seen {
        if let Some(text) = id.as_str() {
            let text = text.trim().trim_end_matches('/').to_string();
            if !text.is_empty() && !known.contains(&text) {
                known.push(text);
            }
        }
    }
    let mut ids = Vec::new();
    let mut stopped = false;
    if limit >= 1 {
        for id in object_ids {
            let Some(text) = id.as_str() else { continue };
            let text = text.trim().trim_end_matches('/');
            if text.is_empty() || !text.starts_with("https://") {
                continue;
            }
            if known.iter().any(|item| item == text) {
                if stop_at_seen {
                    stopped = true;
                    break;
                }
                continue;
            }
            ids.push(text.to_string());
            if ids.len() as i64 >= limit {
                break;
            }
        }
    }
    json!({"ids": ids, "stopped": stopped})
}

fn page(doc: &Value, actor_id: &str) -> Value {
    let actor_id = actor_id.trim().trim_end_matches('/');
    let raw_items = doc.get("orderedItems").and_then(Value::as_array).or_else(|| doc.get("items").and_then(Value::as_array));
    let mut items = Vec::new();
    if let Some(raw_items) = raw_items {
        for item in raw_items {
            let Some(map) = item.as_object() else { continue };
            let kind = map.get("type").and_then(Value::as_str).unwrap_or("");
            if kind == "Announce" {
                continue;
            }
            if kind == "Create" {
                let act_actor = map.get("actor").map(state_id).unwrap_or_default();
                if act_actor.is_empty() || act_actor != actor_id {
                    continue;
                }
                let Some(object) = map.get("object").and_then(Value::as_object) else { continue };
                let object_id = object.get("id").map(state_id).unwrap_or_default();
                if object_id.is_empty() {
                    continue;
                }
                items.push(json!({"object_id": object_id, "activity": item}));
                continue;
            }
            if !matches!(kind, "Note" | "Article" | "Question" | "Page") {
                continue;
            }
            let attributed = map.get("attributedTo").or_else(|| map.get("actor")).map(state_id).unwrap_or_default();
            if !attributed.is_empty() && attributed != actor_id {
                continue;
            }
            let object_id = map.get("id").map(state_id).unwrap_or_default();
            if object_id.is_empty() || actor_id.is_empty() {
                continue;
            }
            let to = map.get("to").cloned().filter(Value::is_array).unwrap_or_else(|| json!(["https://www.w3.org/ns/activitystreams#Public"]));
            let cc = map.get("cc").cloned().filter(Value::is_array).unwrap_or_else(|| json!([]));
            items.push(json!({
                "object_id": object_id,
                "activity": {
                    "type": "Create",
                    "actor": actor_id,
                    "to": to,
                    "cc": cc,
                    "object": item,
                }
            }));
        }
    }
    let mut next = String::new();
    if items.is_empty() {
        match doc.get("first") {
            Some(Value::String(url)) if url.starts_with("https://") => next = url.clone(),
            Some(Value::Object(map)) => next = map.get("id").map(state_id).unwrap_or_default(),
            _ => {}
        }
    }
    json!({"items": items, "next": next})
}

fn object_map(value: &Value) -> Map<String, Value> {
    value.as_object().cloned().unwrap_or_default()
}

pub fn replay(input: &Value) -> anyhow::Result<Value> {
    let op = input.get("op").and_then(Value::as_str).unwrap_or("");
    let output = match op {
        "state" => json!(state_apply(
            input.get("current").and_then(Value::as_str).unwrap_or(""),
            input.get("event").and_then(Value::as_str).unwrap_or(""),
        )),
        "classify" => classify(input.get("activity").unwrap_or(&Value::Null), input.get("local").and_then(Value::as_str).unwrap_or("")),
        "actor_matches" => json!(actor_matches(
            input.get("activity_actor").and_then(Value::as_str).unwrap_or(""),
            input.get("followed_actor").and_then(Value::as_str).unwrap_or(""),
        )),
        "scrub" => json!(scrub(input.get("text").and_then(Value::as_str).unwrap_or(""), input.get("limit").and_then(Value::as_u64).unwrap_or(120) as usize)),
        "log_line" => json!(log_line(
            input.get("host").and_then(Value::as_str).unwrap_or(""),
            input.get("type").and_then(Value::as_str).unwrap_or(""),
            input.get("status").and_then(Value::as_i64).unwrap_or(0),
            input.get("ok").and_then(Value::as_bool).unwrap_or(false),
            input.get("body").and_then(Value::as_str).unwrap_or(""),
            input.get("path").and_then(Value::as_str).unwrap_or(""),
        )),
        "should_log" => json!(should_log(input.get("ok").and_then(Value::as_bool).unwrap_or(false), input.get("type").and_then(Value::as_str).unwrap_or(""))),
        "config" => config_from_env(&object_map(input.get("env").unwrap_or(&Value::Null))),
        "due" => json!(due(
            input.get("last_run").and_then(Value::as_i64).unwrap_or(0),
            input.get("now").and_then(Value::as_i64).unwrap_or(0),
            input.get("min_seconds").and_then(Value::as_i64).unwrap_or(0),
        )),
        "backoff" => json!(backoff_seconds(input.get("fail_count").and_then(Value::as_i64).unwrap_or(1), input.get("status").and_then(Value::as_i64).unwrap_or(0))),
        "plan" => json!(plan(
            input.get("config").unwrap_or(&Value::Null),
            input.get("accounts").and_then(Value::as_array).map(Vec::as_slice).unwrap_or(&[]),
            &object_map(input.get("host_seen").unwrap_or(&Value::Null)),
            input.get("now").and_then(Value::as_i64).unwrap_or(0),
        )),
        "select" => select_new(
            input.get("object_ids").and_then(Value::as_array).map(Vec::as_slice).unwrap_or(&[]),
            input.get("seen").and_then(Value::as_array).map(Vec::as_slice).unwrap_or(&[]),
            input.get("limit").and_then(Value::as_i64).unwrap_or(0),
            input.get("stop_at_seen").and_then(Value::as_bool).unwrap_or(true),
        ),
        "page" => page(input.get("doc").unwrap_or(&Value::Null), input.get("actor").and_then(Value::as_str).unwrap_or("")),
        "host" => json!(fallback_host(input.get("raw").and_then(Value::as_str).unwrap_or(""))),
        other => anyhow::bail!("unsupported follow fallback op {other}"),
    };
    Ok(output)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn default_force_host_and_pending_are_planned() {
        let config = config_from_env(&Map::new());
        assert_eq!(config["hosts"][0], "misskey.io");
        assert_eq!(config["min_interval_seconds"], 900);
        let now = 1_700_000_000;
        let accounts = vec![
            json!({"actor_id":"https://misskey.io/users/a","host":"misskey.io","follow_state":"unknown","state_at":0,"backoff_until":0}),
            json!({"actor_id":"https://misskey.io/users/no","host":"misskey.io","follow_state":"rejected","state_at":0,"backoff_until":0}),
            json!({"actor_id":"https://pending.example/users/d","host":"pending.example","follow_state":"pending","state_at":now - 7 * 3600,"backoff_until":0}),
            json!({"actor_id":"https://fresh.example/users/c","host":"fresh.example","follow_state":"accepted","state_at":0,"backoff_until":0}),
        ];
        let mut seen = Map::new();
        seen.insert("fresh.example".into(), json!(now));
        let planned = plan(&config, &accounts, &seen, now);
        assert_eq!(planned, vec![
            "https://misskey.io/users/a".to_string(),
            "https://pending.example/users/d".to_string(),
        ]);
    }

    #[test]
    fn scrub_drops_signature_material() {
        let cleaned = scrub("nope signature=\"abcDEF+/=\" bearer SECRET digest: sha-256=", 120);
        assert!(!cleaned.to_ascii_lowercase().contains("signature"));
        assert!(!cleaned.contains("SECRET"));
        assert!(!cleaned.to_ascii_lowercase().contains("sha-256"));
        assert!(cleaned.contains("nope"));
    }
}
