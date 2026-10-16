//! Pleroma-safe ActivityPub documents.
//!
//! Soapbox 2.5 `ArticleNotePageValidator.fix_url/1` accepts a binary URL or a
//! single Link map, and `ObjectValidators.Uri` allows only `http` and `https`.
//! A `url` list that includes an `at://` alternate fails the cast, so a pinned
//! Note never inserts and the actor, featured collection, and note are fetched
//! again. Bluesky links stay on `blueskyUri`. Empty media text becomes a
//! zero-width space so a scrubber cannot turn the content into a blank string.

use serde_json::{Map, Value};

const MEDIA_CONTENT: &str = "<p>&#8203;</p>";

pub(crate) fn pleroma_document(value: &Value) -> Value {
    match value {
        Value::Object(map) => Value::Object(normalize_object(map)),
        other => other.clone(),
    }
}

fn normalize_object(map: &Map<String, Value>) -> Map<String, Value> {
    let mut node = map.clone();
    if node.contains_key("url") {
        apply_url(&mut node);
    }
    apply_media_content(&mut node);
    for key in ["object", "attachment", "icon", "image", "oneOf", "anyOf"] {
        if let Some(child) = node.get(key).cloned() {
            node.insert(key.to_string(), normalize_child(child));
        }
    }
    if let Some(Value::Array(items)) = node.get("orderedItems").cloned() {
        node.insert(
            "orderedItems".to_string(),
            Value::Array(items.into_iter().map(normalize_child).collect()),
        );
    }
    node
}

fn normalize_child(value: Value) -> Value {
    match value {
        Value::Array(items) => Value::Array(items.into_iter().map(normalize_child).collect()),
        Value::Object(map) => Value::Object(normalize_object(&map)),
        other => other,
    }
}

fn apply_url(node: &mut Map<String, Value>) {
    let Some(url) = node.get("url").cloned() else {
        return;
    };
    let mut https = Vec::new();
    let mut at = Vec::new();
    collect_urls(&url, &mut https, &mut at);
    if node.get("blueskyUri").and_then(Value::as_str).map(str::trim).unwrap_or("").is_empty() {
        if let Some(uri) = at.first() {
            node.insert("blueskyUri".to_string(), Value::String(uri.clone()));
        }
    }
    if let Some(first) = https.first() {
        let chosen = node
            .get("id")
            .and_then(Value::as_str)
            .filter(|id| https.iter().any(|item| item == id))
            .unwrap_or(first.as_str());
        node.insert("url".to_string(), Value::String(chosen.to_string()));
        return;
    }
    if let Some(id) = node.get("id").and_then(Value::as_str) {
        if is_http_url(id) {
            node.insert("url".to_string(), Value::String(id.to_string()));
            return;
        }
    }
    node.remove("url");
}

fn collect_urls(value: &Value, https: &mut Vec<String>, at: &mut Vec<String>) {
    match value {
        Value::String(text) => push_url(text, https, at),
        Value::Array(items) => {
            for item in items {
                collect_urls(item, https, at);
            }
        }
        Value::Object(map) => {
            if let Some(href) = map.get("href").and_then(Value::as_str) {
                push_url(href, https, at);
            }
        }
        _ => {}
    }
}

fn push_url(text: &str, https: &mut Vec<String>, at: &mut Vec<String>) {
    if text.starts_with("at://") {
        if !at.iter().any(|item| item == text) {
            at.push(text.to_string());
        }
        return;
    }
    if is_http_url(text) && !https.iter().any(|item| item == text) {
        https.push(text.to_string());
    }
}

fn is_http_url(value: &str) -> bool {
    let lower = value.to_ascii_lowercase();
    (lower.starts_with("https://") || lower.starts_with("http://"))
        && !value.chars().any(char::is_whitespace)
        && value.len() > if lower.starts_with("https://") { 8 } else { 7 }
}

fn apply_media_content(node: &mut Map<String, Value>) {
    let kind = node.get("type").and_then(Value::as_str).unwrap_or("");
    if !matches!(kind, "Note" | "Article" | "Page" | "Question") || !has_attachment(node) {
        return;
    }
    if let Some(content) = node.get("content") {
        if !content.is_string() {
            return;
        }
    }
    if let Some(Value::Object(map)) = node.get("contentMap") {
        if map.values().any(|value| value.as_str().is_some_and(|text| !visible_text(text).is_empty())) {
            return;
        }
    }
    let content = node.get("content").and_then(Value::as_str).unwrap_or("");
    if !visible_text(content).is_empty() {
        return;
    }
    node.insert("content".to_string(), Value::String(MEDIA_CONTENT.to_string()));
}

fn has_attachment(node: &Map<String, Value>) -> bool {
    match node.get("attachment") {
        Some(Value::Object(map)) => map.contains_key("type") || map.contains_key("url") || map.contains_key("href"),
        Some(Value::Array(items)) => items.iter().any(Value::is_object),
        _ => false,
    }
}

fn visible_text(html: &str) -> String {
    let mut out = String::new();
    let mut tag = false;
    for ch in html.chars() {
        match ch {
            '<' => tag = true,
            '>' => tag = false,
            _ if !tag => out.push(ch),
            _ => {}
        }
    }
    let decoded = decode_entities(&out);
    decoded
        .replace(['\u{200B}', '\u{FEFF}', '\u{00A0}'], "")
        .trim()
        .to_string()
}

fn decode_entities(text: &str) -> String {
    let mut out = String::new();
    let mut rest = text;
    while let Some(start) = rest.find('&') {
        out.push_str(&rest[..start]);
        let after = &rest[start + 1..];
        if let Some(end) = after.find(';') {
            let name = &after[..end];
            let replacement = if let Some(hex) = name.strip_prefix("#x").or_else(|| name.strip_prefix("#X")) {
                u32::from_str_radix(hex, 16).ok().and_then(char::from_u32)
            } else if let Some(dec) = name.strip_prefix('#') {
                dec.parse::<u32>().ok().and_then(char::from_u32)
            } else {
                match name {
                    "amp" => Some('&'),
                    "lt" => Some('<'),
                    "gt" => Some('>'),
                    "quot" => Some('"'),
                    "apos" => Some('\''),
                    "nbsp" => Some('\u{00A0}'),
                    _ => None,
                }
            };
            if let Some(ch) = replacement {
                out.push(ch);
                rest = &after[end + 1..];
                continue;
            }
        }
        out.push('&');
        rest = after;
    }
    out.push_str(rest);
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn pinned_empty_video_drops_at_url_and_keeps_bluesky_uri() {
        let note = json!({
            "type": "Note",
            "id": "https://mkultra.monster/users/cmdr_nova/notes/bc15ca933555c8aa",
            "content": "<p></p>",
            "url": [
                "https://mkultra.monster/users/cmdr_nova/notes/bc15ca933555c8aa",
                {"type": "Link", "rel": "alternate", "href": "at://did:plc:example/app.bsky.feed.post/abc"}
            ],
            "blueskyUri": "at://did:plc:example/app.bsky.feed.post/abc",
            "blueskyCid": "bafyrei",
            "attachment": [{"type": "Document", "mediaType": "video/mp4", "url": "https://cdn.example/video.mp4"}],
            "alsoKnownAs": ["at://did:plc:example"]
        });
        let out = pleroma_document(&note);
        assert_eq!(out["url"], "https://mkultra.monster/users/cmdr_nova/notes/bc15ca933555c8aa");
        assert_eq!(out["blueskyUri"], "at://did:plc:example/app.bsky.feed.post/abc");
        assert_eq!(out["blueskyCid"], "bafyrei");
        assert_eq!(out["content"], MEDIA_CONTENT);
        assert_eq!(out["alsoKnownAs"][0], "at://did:plc:example");
        assert_eq!(out["attachment"][0]["url"], "https://cdn.example/video.mp4");
    }
}
