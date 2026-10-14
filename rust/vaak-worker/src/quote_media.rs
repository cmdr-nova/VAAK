//! Keep quoted attachment copies out of the quoting post's media row.
use serde_json::Value;
use std::collections::HashSet;

fn attachment_url(media: &Value) -> Option<&str> {
    media.get("url").and_then(Value::as_str).filter(|url| url.starts_with("https://"))
}

fn plain_content_empty(status: &Value) -> bool {
    let content = status.get("content").and_then(Value::as_str).unwrap_or("");
    let mut text = String::new();
    let mut in_tag = false;
    for ch in content.chars() {
        if ch == '<' { in_tag = true; continue; }
        if ch == '>' { in_tag = false; continue; }
        if !in_tag { text.push(ch); }
    }
    text.trim().is_empty()
}

fn has_announce_fragment(value: &str) -> bool {
    let trimmed = value.trim();
    crate::library::actions::strip_announce_fragment(trimmed).len() != trimmed.len()
}

/// Display form of a quoted status. Boost wrappers unwrap in memory to the
/// original post so media and links name a status the post view can open.
/// Wrapper media moves onto an empty inner. Parent media is left alone.
pub(crate) fn displayed_quoted_status(quoted: &Value) -> Value {
    let mut current = quoted.clone();
    for _ in 0..4 {
        let Some(mut reblog) = current.get("reblog").filter(|value| value.is_object()).cloned() else { break };
        let uri = current.get("uri").and_then(Value::as_str)
            .or_else(|| current.get("url").and_then(Value::as_str))
            .unwrap_or("");
        if !plain_content_empty(&current) && !has_announce_fragment(uri) { break; }
        let inner_empty = reblog.get("media_attachments").and_then(Value::as_array).map(|items| items.is_empty()).unwrap_or(true);
        if inner_empty {
            if let Some(media) = current.get("media_attachments").filter(|value| value.as_array().map(|items| !items.is_empty()).unwrap_or(false)) {
                reblog["media_attachments"] = media.clone();
            }
        }
        current = reblog;
    }
    if let Some(obj) = current.as_object_mut() {
        for key in ["uri", "url", "id"] {
            if let Some(value) = obj.get(key).and_then(Value::as_str) {
                let trimmed = value.trim();
                let stripped = crate::library::actions::strip_announce_fragment(trimmed);
                if stripped.len() != trimmed.len() {
                    obj.insert(key.to_string(), Value::from(stripped.trim_end_matches('/')));
                }
            }
        }
    }
    current
}

/// Ingestion can flatten quote attachments into the event's media list.
/// Only an exact asset also present on the actual quote is omitted here;
/// absent quote media never establishes ownership of the parent's attachments.
pub(crate) fn outer_attachments(status: &Value) -> Vec<Value> {
    let outer = status.get("media_attachments").and_then(Value::as_array).cloned().unwrap_or_default();
    let Some(envelope) = status.get("quote").filter(|value| value.is_object())
        .or_else(|| status.get("vaak_quote_preview").filter(|value| value.is_object())) else { return outer; };
    let quoted = envelope.get("quoted_status").filter(|value| value.is_object())
        .or_else(|| envelope.get("status").filter(|value| value.is_object())).unwrap_or(envelope);
    outer_attachments_for_quote(status, &displayed_quoted_status(quoted))
}

/// The caller supplies precisely the quoted payload its painter uses.
pub(crate) fn outer_attachments_for_quote(status: &Value, quoted: &Value) -> Vec<Value> {
    let outer = status.get("media_attachments").and_then(Value::as_array).cloned().unwrap_or_default();
    let quote_media = quoted.get("media_attachments").and_then(Value::as_array);
    let identities: HashSet<&str> = quote_media.into_iter().flatten().filter_map(attachment_url).collect();
    outer.into_iter().filter(|media| attachment_url(media).map(|url| !identities.contains(url)).unwrap_or(true)).collect()
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn exact_quoted_assets_only_are_removed_from_parent() {
        let mut status = json!({"media_attachments":[{"url":"https://media.test/parent.jpg"},{"url":"https://media.test/quote.jpg"}],"quote":{"quoted_status":{"sensitive":true,"spoiler_text":"quoted CW","media_attachments":[{"url":"https://media.test/quote.jpg"}]}}});
        assert_eq!(outer_attachments(&status), vec![json!({"url":"https://media.test/parent.jpg"})]);
        assert_eq!(status["quote"]["quoted_status"]["spoiler_text"], "quoted CW");
        status["quote"]["quoted_status"]["media_attachments"] = json!([]);
        assert_eq!(outer_attachments(&status).len(), 2, "empty quote cannot take parent media");
        status["quote"]["media_attachments"] = json!([{"url":"https://media.test/quote.jpg"}]);
        assert_eq!(outer_attachments(&status).len(), 2, "full-card child does not paint envelope media");
        status["media_attachments"][1]["url"] = json!("https://media.test/quote.jpg?own=1");
        assert_eq!(outer_attachments(&status).len(), 2, "different assets are not guessed equivalent");
    }

    #[test]
    fn boost_wrapper_quoted_media_is_owned_without_taking_unrelated_parent_media() {
        let boosted = json!({
            "media_attachments":[{"url":"https://media.test/parent.jpg"},{"url":"https://media.test/quoted.jpg"}],
            "quote":{"quoted_status":{
                "content":"",
                "uri":"https://origin.example/users/author/statuses/1#announce-9",
                "media_attachments":[],
                "reblog":{"uri":"https://origin.example/users/author/statuses/1","content":"<p>original</p>","media_attachments":[{"url":"https://media.test/quoted.jpg"}]}
            }}
        });
        assert_eq!(outer_attachments(&boosted), vec![json!({"url":"https://media.test/parent.jpg"})]);
        let displayed = displayed_quoted_status(&boosted["quote"]["quoted_status"]);
        assert_eq!(displayed["uri"], "https://origin.example/users/author/statuses/1");
        assert_eq!(displayed["media_attachments"][0]["url"], "https://media.test/quoted.jpg");
    }
}
