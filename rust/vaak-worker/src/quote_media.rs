//! Keep quoted attachment copies out of the quoting post's media row.
use serde_json::{json, Value};
use std::collections::HashSet;

fn attachment_identities(media: &Value) -> Vec<String> {
    ["url", "remote_url"]
        .into_iter()
        .filter_map(|key| {
            media.get(key)
                .and_then(Value::as_str)
                .filter(|url| url.starts_with("https://"))
                .map(str::to_string)
        })
        .collect()
}

fn attachment_has_asset(media: &Value) -> bool {
    !attachment_identities(media).is_empty()
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
    let identities: HashSet<String> = quoted
        .get("media_attachments")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .flat_map(attachment_identities)
        .collect();
    outer
        .into_iter()
        .filter(|media| {
            let ids = attachment_identities(media);
            ids.is_empty() || ids.iter().all(|url| !identities.contains(url))
        })
        .collect()
}

fn quoted_has_asset(quoted: &Value) -> bool {
    quoted
        .get("media_attachments")
        .and_then(Value::as_array)
        .is_some_and(|items| items.iter().any(attachment_has_asset))
}

/// A cached status (account, body, or attachment list). A QT preview is only
/// `text` / `acct` / `url` and is not a status.
fn preview_looks_like_status(preview: &Value) -> bool {
    preview.get("account").is_some_and(Value::is_object)
        || preview.get("content").and_then(Value::as_str).is_some()
        || preview.get("media_attachments").and_then(Value::as_array).is_some()
        || preview.get("source").and_then(Value::as_str).is_some()
}

/// Move flattened quoted files off the parent and onto a summary stub.
/// A loaded quote keeps its own attachment list, even when that list is empty.
/// Only url/remote_url copies are removed from the parent. Preview URLs are
/// not identities. An alias stub that is not a quote-summary is left alone.
pub(crate) fn rehome_flattened_quote_media(status: &mut Value) {
    let Some(mut quote) = status.get("quote").filter(|value| value.is_object()).cloned().or_else(|| {
        let preview = status.get("vaak_quote_preview").filter(|value| value.is_object())?;
        if preview_looks_like_status(preview) {
            Some(json!({"state": "accepted", "quoted_status": preview}))
        } else {
            Some(json!({"state": "accepted", "quoted_status": Value::Null}))
        }
    }) else {
        return;
    };
    if let Some(quoted) = quote.get("quoted_status").filter(|value| value.is_object()) {
        quote["quoted_status"] = displayed_quoted_status(quoted);
    }
    let child_has = quote.get("quoted_status").is_some_and(quoted_has_asset);
    let summary = quote
        .get("quoted_status")
        .and_then(|quoted| quoted.get("source"))
        .and_then(Value::as_str)
        == Some("quote-summary");
    let missing = !quote.get("quoted_status").is_some_and(Value::is_object);
    let parent = status.get("media_attachments").and_then(Value::as_array).cloned().unwrap_or_default();
    if (summary || missing) && !child_has && !parent.is_empty() {
        if missing {
            let preview = status.get("vaak_quote_preview").cloned().unwrap_or(Value::Null);
            let text = preview.get("text").and_then(Value::as_str).unwrap_or("").trim().to_string();
            let acct = preview.get("acct").and_then(Value::as_str).unwrap_or("").trim().trim_start_matches('@').to_string();
            let mut url = preview.get("url").and_then(Value::as_str).unwrap_or("").trim().to_string();
            if url.is_empty() {
                url = status.get("quote_url").and_then(Value::as_str).unwrap_or("").trim().to_string();
            }
            let display = preview
                .get("display_name")
                .and_then(Value::as_str)
                .or_else(|| preview.get("display").and_then(Value::as_str))
                .unwrap_or("")
                .trim()
                .to_string();
            let display = if display.is_empty() {
                if acct.is_empty() { "Quoted post".to_string() } else { acct.clone() }
            } else {
                display
            };
            quote["quoted_status"] = json!({
                "source": "quote-summary",
                "id": if url.is_empty() { "quote:summary".to_string() } else { url.clone() },
                "uri": url,
                "url": url,
                "content": text,
                "account": {"acct": acct, "display_name": display, "url": url},
                "media_attachments": parent,
                "sensitive": preview.get("sensitive").cloned().unwrap_or(Value::Bool(false)),
                "spoiler_text": preview.get("spoiler_text").and_then(Value::as_str).unwrap_or(""),
            });
        } else if let Some(obj) = quote.get_mut("quoted_status").and_then(Value::as_object_mut) {
            obj.insert("media_attachments".into(), Value::Array(parent.clone()));
        }
        quote["state"] = json!("accepted");
        if quote.get("quoted_status_id").and_then(Value::as_str).unwrap_or("").is_empty() {
            let id = quote.get("quoted_status").and_then(|q| q.get("id")).cloned().unwrap_or(Value::Null);
            quote["quoted_status_id"] = id;
        }
    }
    status["quote"] = quote;
    let outer = outer_attachments(status);
    status["media_attachments"] = Value::Array(outer);
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

    #[test]
    fn remote_url_identity_strips_the_parent_copy() {
        let status = json!({
            "media_attachments":[{"url":"https://cdn.test/a.jpg","remote_url":"https://origin.test/a.jpg"}],
            "quote":{"quoted_status":{"content":"<p>card</p>","media_attachments":[{"url":"https://origin.test/a.jpg"}]}}
        });
        assert!(outer_attachments(&status).is_empty(), "cdn url and original remote_url are one asset");
    }

    #[test]
    fn preview_url_overlap_does_not_strip_parent_media() {
        let status = json!({
            "media_attachments":[{"url":"https://media.test/parent.jpg","preview_url":"https://media.test/same.jpg"}],
            "quote":{"quoted_status":{"media_attachments":[{"url":"https://media.test/quoted.jpg","preview_url":"https://media.test/same.jpg"}]}}
        });
        assert_eq!(outer_attachments(&status).len(), 1);
    }

    #[test]
    fn quote_summary_claims_flattened_parent_media() {
        let mut status = json!({
            "content":"<p>Paint me like one of your French girls</p>",
            "media_attachments":[{"type":"image","url":"https://media.test/ambipom.jpg"}],
            "quote":{"quoted_status":{
                "source":"quote-summary",
                "content":"<p>Ambipom</p>",
                "account":{"acct":"EveryPkmnCard@mastodon.social","display_name":"EveryPkmnCard"},
                "media_attachments":[]
            }}
        });
        rehome_flattened_quote_media(&mut status);
        assert!(status["media_attachments"].as_array().unwrap().is_empty());
        assert_eq!(status["quote"]["quoted_status"]["media_attachments"][0]["url"], "https://media.test/ambipom.jpg");
        assert_eq!(status["quote"]["quoted_status"]["content"], "<p>Ambipom</p>");
        assert_eq!(status["quote"]["quoted_status"]["source"], "quote-summary");
    }

    #[test]
    fn authoritative_empty_quote_does_not_claim_parent_media() {
        let mut status = json!({
            "media_attachments":[{"url":"https://media.test/parent.jpg"}],
            "quote":{"quoted_status":{"content":"<p>text only</p>","account":{"acct":"b"},"media_attachments":[]}}
        });
        rehome_flattened_quote_media(&mut status);
        assert_eq!(status["media_attachments"][0]["url"], "https://media.test/parent.jpg");
        assert!(status["quote"]["quoted_status"]["media_attachments"].as_array().unwrap().is_empty());
    }

    #[test]
    fn text_preview_without_a_status_gets_the_flattened_file() {
        let mut status = json!({
            "media_attachments":[{"type":"image","url":"https://media.test/card.jpg"}],
            "vaak_quote_preview":{"acct":"@EveryPkmnCard@mastodon.social","text":"Ambipom","url":"https://mastodon.social/@EveryPkmnCard/9"}
        });
        rehome_flattened_quote_media(&mut status);
        assert!(status["media_attachments"].as_array().unwrap().is_empty());
        assert_eq!(status["quote"]["quoted_status"]["content"], "Ambipom");
        assert_eq!(status["quote"]["quoted_status"]["media_attachments"][0]["url"], "https://media.test/card.jpg");
        assert_eq!(status["quote"]["quoted_status"]["account"]["acct"], "EveryPkmnCard@mastodon.social");
    }
}
