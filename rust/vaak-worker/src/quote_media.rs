//! Keep quoted attachment copies out of the quoting post's media row.
use serde_json::Value;
use std::collections::HashSet;

fn attachment_url(media: &Value) -> Option<&str> {
    media.get("url").and_then(Value::as_str).filter(|url| url.starts_with("https://"))
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
    outer_attachments_for_quote(status, quoted)
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
}
