//! Pure inbox origin-fallback decisions.
//!
//! Signature verification stays in PHP. This module only chooses which
//! same-host https ids may be re-fetched and which fetched document, if any,
//! may replace an unverified body. It does not open the network.

use anyhow::anyhow;
use serde_json::{json, Map, Value};

struct Parts {
    scheme: String,
    has_user: bool,
    has_pass: bool,
    host: String,
    path: Option<String>,
    query: Option<String>,
}

fn parse_https(raw: &str) -> Option<Parts> {
    let (scheme, rest) = raw.split_once("://")?;
    if scheme.is_empty() {
        return None;
    }
    let rest = rest.split_once('#').map(|(body, _)| body).unwrap_or(rest);
    let (rest, query) = match rest.split_once('?') {
        Some((body, query)) => (body, Some(query.to_string())),
        None => (rest, None),
    };
    let (userinfo, hostpath) = match rest.rfind('@') {
        Some(index) => (Some(&rest[..index]), &rest[index + 1..]),
        None => (None, rest),
    };
    let (has_user, has_pass) = match userinfo {
        Some(info) if info.contains(':') => (true, true),
        Some(_) => (true, false),
        None => (false, false),
    };
    let (host, path) = if let Some(hostpath) = hostpath.strip_prefix('[') {
        let end = hostpath.find(']')?;
        let host = hostpath[..end].to_string();
        let after = &hostpath[end + 1..];
        let path = if let Some(after_port) = after.strip_prefix(':') {
            after_port
                .find('/')
                .map(|index| after_port[index..].to_string())
        } else if after.is_empty() {
            None
        } else {
            Some(after.to_string())
        };
        (host, path)
    } else {
        let (hostport, path) = match hostpath.find('/') {
            Some(index) => (&hostpath[..index], Some(hostpath[index..].to_string())),
            None => (hostpath, None),
        };
        let host = match hostport.rsplit_once(':') {
            Some((host, port))
                if !port.is_empty() && port.bytes().all(|byte| byte.is_ascii_digit()) =>
            {
                host
            }
            _ => hostport,
        };
        (host.to_string(), path)
    };
    if host.is_empty() {
        return None;
    }
    Some(Parts {
        scheme: scheme.to_string(),
        has_user,
        has_pass,
        host,
        path,
        query,
    })
}

fn as_id(value: &Value) -> Option<String> {
    match value {
        Value::String(text) if !text.is_empty() => Some(text.clone()),
        Value::Array(items) if items.len() == 1 => as_id(&items[0]),
        Value::Object(map) => {
            if let Some(Value::String(id)) = map.get("id") {
                return Some(id.clone());
            }
            if let Some(Value::String(href)) = map.get("href") {
                return Some(href.clone());
            }
            None
        }
        _ => None,
    }
}

fn https_id(value: &Value) -> Option<String> {
    as_id(value).filter(|id| id.starts_with("https://"))
}

fn fetch_url(id: &str) -> Option<String> {
    if !id.starts_with("https://") {
        return None;
    }
    let parts = parse_https(id)?;
    if parts.scheme != "https" || parts.has_user || parts.has_pass || parts.host.contains(':') {
        return None;
    }
    let host = parts.host.to_ascii_lowercase();
    let mut path = parts.path.unwrap_or_else(|| "/".to_string());
    if path.is_empty() {
        path = "/".to_string();
    }
    let mut url = format!("https://{host}{path}");
    if let Some(query) = parts
        .query
        .filter(|query| !query.is_empty() && query != "0")
    {
        url.push('?');
        url.push_str(&query);
    }
    Some(url)
}

fn same_host(url: &str, host: &str) -> bool {
    parse_https(url).is_some_and(|parts| parts.host.eq_ignore_ascii_case(host))
}

fn norm_id(id: &str) -> String {
    let trimmed = id.trim();
    match parse_https(trimmed) {
        Some(parts) => {
            let host = parts.host.to_ascii_lowercase();
            let path = parts
                .path
                .unwrap_or_default()
                .trim_end_matches('/')
                .to_string();
            format!("https://{host}{path}")
        }
        None => trimmed
            .to_ascii_lowercase()
            .trim_end_matches('/')
            .to_string(),
    }
}

fn same_url(left: &str, right: &str) -> bool {
    norm_id(left) == norm_id(right)
}

fn posted_object(posted: Option<&Value>) -> Option<&Map<String, Value>> {
    posted.and_then(Value::as_object)
}

fn actor_host_of(posted: &Map<String, Value>) -> Option<(String, String)> {
    let claimed = posted.get("actor").and_then(https_id)?;
    let parts = parse_https(&claimed)?;
    let host = parts.host.to_ascii_lowercase();
    if host.is_empty() {
        return None;
    }
    Some((claimed, host))
}

pub fn origin_urls(posted: Option<&Value>) -> Vec<String> {
    let Some(posted) = posted_object(posted) else {
        return Vec::new();
    };
    let Some((_, actor_host)) = actor_host_of(posted) else {
        return Vec::new();
    };
    let kind = posted.get("type").and_then(Value::as_str).unwrap_or("");
    let object_id = posted.get("object").and_then(https_id);
    let activity_id = posted.get("id").and_then(https_id);
    let ordered = if kind.eq_ignore_ascii_case("Delete") {
        [object_id, activity_id]
    } else {
        [activity_id, object_id]
    };
    let mut urls = Vec::new();
    for id in ordered.into_iter().flatten() {
        let Some(fetch) = fetch_url(&id) else {
            continue;
        };
        if !same_host(&fetch, &actor_host) || urls.iter().any(|seen| seen == &fetch) {
            continue;
        }
        urls.push(fetch);
    }
    urls
}

fn doc_matches(doc: &Map<String, Value>, claimed: &str, actor_host: &str) -> bool {
    let claimed_norm = norm_id(claimed);
    let mut ids = Vec::new();
    if let Some(actor) = doc.get("actor").and_then(https_id) {
        ids.push(actor);
    }
    if let Some(attr) = doc.get("attributedTo").and_then(https_id) {
        ids.push(attr);
    }
    !ids.is_empty()
        && ids
            .iter()
            .all(|id| norm_id(id) == claimed_norm && same_host(id, actor_host))
}

fn activity_from_fetched(
    doc: &Map<String, Value>,
    claimed: &str,
    posted_type: &str,
    activity_id: Option<&str>,
    actor_host: &str,
) -> Option<Value> {
    if doc.get("actor").and_then(https_id).is_some() {
        return Some(Value::Object(doc.clone()));
    }
    let wrap = if posted_type.eq_ignore_ascii_case("Create") {
        "Create"
    } else if posted_type.eq_ignore_ascii_case("Update") {
        "Update"
    } else {
        return None;
    };
    let mut activity = Map::new();
    activity.insert(
        "@context".into(),
        json!("https://www.w3.org/ns/activitystreams"),
    );
    activity.insert("type".into(), json!(wrap));
    activity.insert("actor".into(), json!(claimed));
    activity.insert("object".into(), Value::Object(doc.clone()));
    if let Some(activity_id) = activity_id {
        let activity_fetch = fetch_url(activity_id);
        let doc_fetch = doc
            .get("id")
            .and_then(https_id)
            .as_deref()
            .and_then(fetch_url);
        if same_host(activity_id, actor_host) && activity_fetch != doc_fetch {
            activity.insert("id".into(), json!(activity_id));
        }
    }
    Some(Value::Object(activity))
}

fn status_of(res: &Map<String, Value>) -> i64 {
    match res.get("status") {
        Some(Value::Number(number)) => number.as_i64().unwrap_or(0),
        Some(Value::String(text)) => text.parse().unwrap_or(0),
        _ => 0,
    }
}

pub fn origin_decision(posted: Option<&Value>, fetches: &Map<String, Value>) -> Value {
    let Some(posted) = posted_object(posted) else {
        return Value::Null;
    };
    let Some((claimed, actor_host)) = actor_host_of(posted) else {
        return Value::Null;
    };
    let kind = posted.get("type").and_then(Value::as_str).unwrap_or("");
    let object_id = posted.get("object").and_then(https_id);
    let activity_id = posted.get("id").and_then(https_id);
    let object_fetch = object_id.as_deref().and_then(fetch_url);
    let mut saw_gone = false;
    for url in origin_urls(Some(&Value::Object(posted.clone()))) {
        let Some(res) = fetches.get(&url).and_then(Value::as_object) else {
            continue;
        };
        let status = status_of(res);
        let doc = res.get("doc").and_then(Value::as_object);
        let object_gone = kind.eq_ignore_ascii_case("Delete")
            && object_fetch
                .as_ref()
                .is_some_and(|object_url| same_url(&url, object_url))
            && (status == 404 || status == 410);
        if object_gone {
            saw_gone = true;
            continue;
        }
        let Some(doc) = doc else { continue };
        if !doc_matches(doc, &claimed, &actor_host) {
            continue;
        }
        if kind.eq_ignore_ascii_case("Delete") {
            let doc_type = doc.get("type").and_then(Value::as_str).unwrap_or("");
            if !doc_type.eq_ignore_ascii_case("Delete") {
                return Value::Null;
            }
        }
        if let Some(activity) =
            activity_from_fetched(doc, &claimed, kind, activity_id.as_deref(), &actor_host)
        {
            return activity;
        }
    }
    if saw_gone {
        if let Some(object_id) = object_id {
            let mut activity = Map::new();
            activity.insert(
                "@context".into(),
                json!("https://www.w3.org/ns/activitystreams"),
            );
            activity.insert("type".into(), json!("Delete"));
            activity.insert("actor".into(), json!(claimed));
            activity.insert("object".into(), json!(object_id));
            if let Some(activity_id) = activity_id.filter(|id| same_host(id, &actor_host)) {
                activity.insert("id".into(), json!(activity_id));
            }
            return Value::Object(activity);
        }
    }
    Value::Null
}

fn url_host(url: &str) -> String {
    parse_https(url)
        .map(|parts| parts.host.to_ascii_lowercase())
        .unwrap_or_default()
}

/// Signed delivery whose key host is not the activity actor.
/// A trusted relay or followed forwarder keeps the posted document.
/// Otherwise only an origin document may be returned.
pub fn reconcile_decision(posted: Option<&Value>, key_id: &str, trusted: bool, fetches: &Map<String, Value>) -> Value {
    let Some(posted_value) = posted else {
        return Value::Null;
    };
    if !posted_value.is_object() {
        return Value::Null;
    }
    let claimed = posted_value.get("actor").and_then(https_id);
    let matches = claimed.as_deref().is_some_and(|actor| {
        let actor_host = url_host(actor);
        let key_host = url_host(key_id);
        !actor_host.is_empty() && actor_host == key_host
    });
    if claimed.is_none() || trusted || matches {
        return posted_value.clone();
    }
    origin_decision(posted, fetches)
}

pub fn replay(input: &Value) -> anyhow::Result<Value> {
    let op = input.get("op").and_then(Value::as_str).unwrap_or("");
    let posted = input.get("posted");
    match op {
        "urls" => Ok(json!(origin_urls(posted))),
        "decide" => {
            let fetches = input
                .get("fetches")
                .and_then(Value::as_object)
                .cloned()
                .unwrap_or_default();
            Ok(origin_decision(posted, &fetches))
        }
        "reconcile" => {
            let fetches = input
                .get("fetches")
                .and_then(Value::as_object)
                .cloned()
                .unwrap_or_default();
            let key_id = input.get("keyId").and_then(Value::as_str).unwrap_or("");
            let trusted = input.get("trusted").and_then(Value::as_bool).unwrap_or(false);
            Ok(reconcile_decision(posted, key_id, trusted, &fetches))
        }
        other => Err(anyhow!("unsupported inbox origin op {other}")),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn delete_fragment_collapses_to_the_object_url() {
        let posted = json!({
            "id": "https://example.com/users/alice/statuses/1#delete",
            "type": "Delete",
            "actor": "https://example.com/users/alice",
            "object": {"id": "https://example.com/users/alice/statuses/1", "type": "Tombstone"}
        });
        assert_eq!(
            origin_urls(Some(&posted)),
            vec!["https://example.com/users/alice/statuses/1".to_string()]
        );
    }

    #[test]
    fn follow_on_another_host_has_no_fetch_url() {
        let posted = json!({
            "type": "Follow",
            "actor": "https://example.com/users/alice",
            "object": "https://mkultra.monster/users/cmdr_nova"
        });
        assert!(origin_urls(Some(&posted)).is_empty());
        assert!(origin_decision(Some(&posted), &Map::new()).is_null());
    }
}
