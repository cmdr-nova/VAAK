//! Library quote cards and the quote-preview budget.
//!
//! PHP still owns signed quote fetch, AppView misses, OG HTTP, and async warm.
//! This module paints the cards PHP would paint from text, a cached preview,
//! or a cold/deleted/non-status stub, and it counts the same quote-budget
//! attempts. The whole batch falls back when a quoted URL still needs a fetch.
use anyhow::{bail, Result};
use serde::Serialize;
use serde_json::{json, Value};
use std::collections::HashMap;

use super::card::php_trim;

#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
pub(crate) struct QuotePreviewState {
    pub initial: i64,
    pub remaining: i64,
    pub attempts: usize,
}

impl QuotePreviewState {
    pub(crate) fn new(initial: i64) -> Self {
        Self { initial, remaining: initial, attempts: 0 }
    }

    fn consume_plain(&mut self) {
        if self.remaining > 0 {
            self.remaining -= 1;
            self.attempts += 1;
        }
    }
}

pub(crate) fn quote_target_is_bluesky(url: &str) -> bool {
    let url = url.trim();
    url.starts_with("https://bsky.app/") || url.starts_with("at://") || url.contains("bsky.brid.gy")
}

/// PHP `ap_url_looks_like_status_object`. Ordinary article links are not
/// status targets, so a miss is "unavailable" rather than "not cached yet".
pub(crate) fn looks_like_status_object(url: &str) -> bool {
    let url = url.trim().trim_end_matches('/');
    if !url.starts_with("https://") {
        return false;
    }
    if quote_target_is_bluesky(url) || url.starts_with("at://") {
        return true;
    }
    let Ok(parsed) = reqwest::Url::parse(url) else { return false };
    let host = parsed.host_str().unwrap_or("").to_ascii_lowercase();
    let path = parsed.path();
    if host.is_empty() || path.is_empty() || path == "/" {
        return false;
    }
    if (host == "bsky.app" || host.ends_with(".bsky.app"))
        && regex::Regex::new(r"/profile/[^/]+/post/[A-Za-z0-9_-]+").unwrap().is_match(path)
    {
        return true;
    }
    if regex::Regex::new(r"(?:^|\.)(youtu\.be|youtube\.com|wikipedia\.org|bloomberg\.com|twitter\.com|x\.com|tiktok\.com|instagram\.com|facebook\.com|reddit\.com|twitch\.tv)$")
        .unwrap()
        .is_match(&host)
    {
        return false;
    }
    if regex::Regex::new(r"(?i)\.(?:jpe?g|png|gif|webp|mp4|webm|mp3|pdf|svg)(?:$|\?)").unwrap().is_match(path) {
        return false;
    }
    if regex::Regex::new(r"(?i)/(?:statuses|posts|notes|objects|notice|activity|videos/watch|w)/[A-Za-z0-9_-]+").unwrap().is_match(path) {
        return true;
    }
    if regex::Regex::new(r"/@[^/]+/[0-9A-Za-z_-]+$").unwrap().is_match(path) {
        return true;
    }
    if regex::Regex::new(r"/post/[0-9]+").unwrap().is_match(path) {
        return true;
    }
    host.contains("threads.net") && path.contains("/post/")
}

fn clean_url(raw: &str) -> String {
    raw.trim().trim_end_matches(['.', ',', ')', ';', ']', '!', '?', '\'', '"']).trim_end_matches('/').to_owned()
}

/// Best quote-target URL from an event summary, using PHP's priority order.
pub(crate) fn quote_target_url(summary: &str) -> Option<String> {
    let summary = summary.trim();
    if summary.is_empty() {
        return None;
    }
    let mut candidates: Vec<(String, i32)> = Vec::new();
    let mut push = |url: &str, pri: i32| {
        let url = clean_url(url);
        if url.starts_with("https://") {
            candidates.push((url, pri));
        }
    };
    for cap in regex::Regex::new(r"(?:^|\n)RE:\s*(https://[^\s<>]+)").unwrap().captures_iter(summary) {
        push(&cap[1], 40);
    }
    if let Some(cap) = regex::Regex::new(r"^(https://[^\s<>]+)").unwrap().captures(summary) {
        push(&cap[1], 35);
    }
    for cap in regex::Regex::new(r"↪\s*QT:\s*(https://[^\s<>]+)").unwrap().captures_iter(summary) {
        push(&cap[1], 30);
    }
    for cap in regex::Regex::new(r"↪\s*QT\s+(https://[^\s<>]+)").unwrap().captures_iter(summary) {
        push(&cap[1], 28);
    }
    for cap in regex::Regex::new(r"↪\s*QT[^\n]*?(https://[^\s<>]+)").unwrap().captures_iter(summary) {
        push(&cap[1], 10);
    }
    candidates.into_iter().max_by_key(|(url, pri)| pri + if looks_like_status_object(url) { 100 } else { 0 }).map(|(url, _)| url)
}

fn qt_fallback(summary: &str) -> Option<(String, String, String)> {
    let pattern = regex::Regex::new(r"(?s)^(.*?)(?:\n\n|\n)?↪\s*QT(?:\s+@(\S+))?\s*:\s*(.*)$").unwrap();
    let caps = pattern.captures(summary.trim())?;
    let acct = caps.get(2).map(|m| m.as_str().trim().trim_start_matches('@').to_owned()).unwrap_or_default();
    let mut text = php_trim(caps.get(3).map(|m| m.as_str()).unwrap_or("")).to_owned();
    if text == "(quoted post unavailable)" || text == "(quoted post)" {
        text.clear();
    }
    let mut url = String::new();
    if text.starts_with("https://") && !text.contains(' ') {
        url = clean_url(&text);
        text.clear();
    }
    if text.is_empty() && acct.is_empty() && url.is_empty() {
        return None;
    }
    Some((acct, text, url))
}

pub(crate) fn saved_quote_supported(summary: &str) -> bool {
    quote_target_url(summary).is_some() || qt_fallback(summary).is_some()
}

pub(crate) fn synthetic_quote(quoted: &Value) -> bool {
    let plain = super::body::scrub_as2(&super::body::html_to_plain(quoted.get("content").and_then(Value::as_str).unwrap_or("")));
    let acct = quoted.get("account").and_then(|account| account.get("acct")).and_then(Value::as_str).unwrap_or("").trim().trim_start_matches('@');
    let uri = quoted.get("uri").or_else(|| quoted.get("url")).and_then(Value::as_str).unwrap_or("");
    php_trim(&plain).is_empty() && (acct.is_empty() || acct.eq_ignore_ascii_case("quoted")) && !uri.is_empty()
}

pub(crate) fn paintable_preview(preview: &Value) -> bool {
    let text = php_trim(preview.get("text").and_then(Value::as_str).unwrap_or(""));
    let acct = php_trim(preview.get("acct").or_else(|| preview.get("handle")).and_then(Value::as_str).unwrap_or(""));
    let media = preview.get("media").and_then(Value::as_array).is_some_and(|items| !items.is_empty());
    let card = preview.get("card").is_some_and(Value::is_object) || preview.get("external").is_some_and(Value::is_object);
    !text.is_empty() || !acct.is_empty() || media || card
}

fn commentary_of(summary: &str) -> String {
    super::body::strip_raw_quotes(summary, None)
}

fn stub_status(url: &str, unavailable: bool) -> Value {
    if unavailable {
        json!({
            "uri": url,
            "url": url,
            "content": "Quoted post unavailable",
            "account": {"acct": ""},
            "media_attachments": [],
            "visibility": "public",
            "vaak_quote_open_label": "Open original",
            "vaak_quote_open_external": true
        })
    } else {
        json!({
            "uri": url,
            "url": url,
            "content": "",
            "account": {"acct": ""},
            "media_attachments": [],
            "visibility": "public",
            "vaak_quote_open_label": "Quoted post (not cached yet) — open",
            "vaak_quote_open_external": false
        })
    }
}

fn preview_status(preview: &Value, fallback_url: &str) -> Value {
    let handle = php_trim(preview.get("handle").or_else(|| preview.get("acct")).and_then(Value::as_str).unwrap_or("")).trim_start_matches('@').to_owned();
    let text = php_trim(preview.get("text").and_then(Value::as_str).unwrap_or(""));
    let mut url = php_trim(preview.get("url").and_then(Value::as_str).unwrap_or("")).to_owned();
    if url.is_empty() {
        url = fallback_url.to_owned();
    }
    let media = preview.get("media").and_then(Value::as_array).cloned().unwrap_or_default();
    let mut card = preview.get("card").filter(|card| card.is_object()).cloned();
    if card.is_none() {
        if let Some(ext) = preview.get("external").filter(|ext| ext.is_object()) {
            card = Some(json!({
                "url": ext.get("uri").or_else(|| ext.get("url")).cloned().unwrap_or(Value::Null),
                "title": ext.get("title").cloned().unwrap_or(Value::Null),
                "description": ext.get("description").cloned().unwrap_or(Value::Null),
                "image": ext.get("thumb").or_else(|| ext.get("image")).cloned().unwrap_or(Value::Null),
                "provider_name": ""
            }));
        }
    }
    let sensitive = preview.get("sensitive").and_then(Value::as_bool).unwrap_or(false);
    let mut status = json!({
        "uri": url,
        "url": url,
        "content": text,
        "account": {"acct": handle},
        "media_attachments": media,
        "visibility": "public",
        "sensitive": sensitive,
        "spoiler_text": if sensitive { "Sensitive content" } else { "" },
        "vaak_quote_open_label": if quote_target_is_bluesky(&url) || preview.get("handle").is_some() { "Open original" } else { "Open quoted" },
        "vaak_quote_open_external": quote_target_is_bluesky(&url) || preview.get("handle").is_some()
    });
    if let Some(card) = card {
        status["card"] = card;
    }
    status
}

pub(crate) struct SavedQuote {
    pub commentary: String,
    pub quote: Value,
    pub quote_url: String,
}

/// Build the quote object a saved ActivityPub row can paint without a fetch.
/// `target` is the cached quoted status. `deleted` means the target row is a
/// Delete/Tombstone and its body must not be revived.
pub(crate) fn assemble_saved_quote(summary: &str, target: Option<&Value>, deleted: bool, bsky: Option<&Value>) -> Result<SavedQuote> {
    let url = quote_target_url(summary).unwrap_or_default();
    let fallback = qt_fallback(summary);
    if url.is_empty() && fallback.is_none() {
        bail!("PHP fallback: quote bookmark needs canonical quote assembly");
    }
    let commentary = commentary_of(summary);
    if deleted {
        return Ok(SavedQuote { commentary, quote: Value::Null, quote_url: url });
    }
    if let Some(target) = target {
        let mut quoted = target.clone();
        if let Some(object) = quoted.as_object_mut() {
            object.remove("quote");
        }
        return Ok(SavedQuote {
            commentary,
            quote: json!({"state": "accepted", "quoted_status": quoted}),
            quote_url: url,
        });
    }
    if let Some(preview) = bsky.filter(|preview| paintable_preview(preview)) {
        return Ok(SavedQuote {
            commentary,
            quote: json!({"state": "accepted", "quoted_status": preview_status(preview, &url)}),
            quote_url: url,
        });
    }
    if let Some((acct, text, fallback_url)) = fallback.clone() {
        if url.is_empty() && !fallback_url.is_empty() {
            // The QT line itself is the target.
        }
        let target_url = if !url.is_empty() { url.clone() } else { fallback_url.clone() };
        if target_url.is_empty() && (!text.is_empty() || !acct.is_empty()) {
            let mut quoted = json!({
                "uri": "",
                "url": "",
                "content": text,
                "account": {"acct": acct},
                "media_attachments": [],
                "visibility": "public"
            });
            if quoted["uri"].as_str().unwrap_or("").is_empty() {
                quoted.as_object_mut().unwrap().remove("uri");
                quoted.as_object_mut().unwrap().remove("url");
            }
            return Ok(SavedQuote {
                commentary,
                quote: json!({"state": "accepted", "quoted_status": quoted}),
                quote_url: String::new(),
            });
        }
    }
    let target_url = if !url.is_empty() { url.clone() } else { fallback.as_ref().map(|(_, _, url)| url.clone()).unwrap_or_default() };
    if target_url.is_empty() {
        bail!("PHP fallback: quote bookmark needs canonical quote assembly");
    }
    let unavailable = !looks_like_status_object(&target_url);
    Ok(SavedQuote {
        commentary,
        quote: json!({"state": "accepted", "quoted_status": stub_status(&target_url, unavailable)}),
        quote_url: target_url,
    })
}

fn quoted_plain(quoted: &Value) -> String {
    super::body::scrub_as2(&super::body::html_to_plain(quoted.get("content").and_then(Value::as_str).unwrap_or("")))
}

fn has_media(quoted: &Value) -> bool {
    quoted.get("media_attachments").and_then(Value::as_array).is_some_and(|items| !items.is_empty())
}

/// Attach a cached link card or count one plain-text quote attempt.
/// A URL with no cached card and no YouTube fallback bails the batch so PHP
/// still performs the fetch and warm. Nothing here opens a socket.
pub(crate) fn apply_quoted_card(quoted: &mut Value, state: &mut QuotePreviewState, cards: &HashMap<String, Value>) -> Result<()> {
    if has_media(quoted) || quoted.get("card").is_some_and(Value::is_object) {
        return Ok(());
    }
    if synthetic_quote(quoted) {
        bail!("PHP fallback: synthetic quote needs canonical status lookup");
    }
    let plain = quoted_plain(quoted);
    if php_trim(&plain).is_empty() {
        return Ok(());
    }
    if let Some(url) = crate::direct_preview::extract_url(&plain)? {
        if let Some(card) = cards.get(&url) {
            quoted["card"] = card.clone();
            return Ok(());
        }
        let anchor = format!("<a href=\"{}\"></a>", super::actions::esc(&url));
        if let Some(card) = crate::direct_preview::youtube_from_linked_html(&anchor)? {
            quoted["card"] = card;
            return Ok(());
        }
        // The raw URL may still be a YouTube watch URL before canonicalization.
        if let Some(found) = regex::Regex::new(r#"https://[^\s<>"']+"#).unwrap().find(&plain) {
            let raw = clean_url(found.as_str());
            let anchor = format!("<a href=\"{}\"></a>", super::actions::esc(&raw));
            if let Some(card) = crate::direct_preview::youtube_from_linked_html(&anchor)? {
                quoted["card"] = card;
                return Ok(());
            }
        }
        bail!("PHP fallback: quoted cold URL requires exact fetch/warm effects");
    }
    state.consume_plain();
    Ok(())
}

fn displayed_mut(status: &mut Value) -> &mut Value {
    let inner = status.get("content").and_then(Value::as_str) == Some("") && status.get("reblog").is_some_and(Value::is_object);
    if inner { status.get_mut("reblog").unwrap() } else { status }
}

pub(crate) fn prepare_presentation(status: &mut Value, state: &mut QuotePreviewState, cards: &HashMap<String, Value>) -> Result<()> {
    prepare_tree(displayed_mut(status), state, cards, 0)
}

fn prepare_tree(status: &mut Value, state: &mut QuotePreviewState, cards: &HashMap<String, Value>, depth: usize) -> Result<()> {
    if depth > 16 {
        bail!("PHP fallback: quote preview depth");
    }
    if let Some(preview) = status.get("vaak_quote_preview").filter(|preview| preview.is_object()).cloned() {
        let pending = status.get("quote").and_then(|quote| quote.get("quoted_status")).is_none_or(|child| !child.is_object());
        if pending && paintable_preview(&preview) {
            let url = status.get("quote_url").and_then(Value::as_str).unwrap_or("");
            let quoted = preview_status(&preview, url);
            let quote = status.as_object_mut().unwrap().entry("quote").or_insert_with(|| json!({"state": "pending"}));
            if quote.is_null() {
                *quote = json!({"state": "pending"});
            }
            quote["quoted_status"] = quoted;
        }
    }
    if let Some(quote) = status.get_mut("quote").filter(|quote| quote.is_object()) {
        if let Some(quoted) = quote.get_mut("quoted_status").filter(|quoted| quoted.is_object()) {
            apply_quoted_card(quoted, state, cards)?;
            prepare_tree(quoted, state, cards, depth + 1)?;
        }
    }
    Ok(())
}

/// Cache-only quoted URL cards. A miss that is not a YouTube fallback returns
/// the batch to PHP, which still fetches and warms.
pub(crate) async fn prepare_admitted(
    db: &tokio_postgres::Client,
    dns: &dyn crate::direct_preview::PreviewDns,
    status: &mut Value,
    state: &mut QuotePreviewState,
    now: chrono::DateTime<chrono::Utc>,
) -> Result<()> {
    let mut cards = HashMap::new();
    collect_quote_cards(db, dns, displayed_mut(status), &mut cards, now, 0).await?;
    prepare_presentation(status, state, &cards)
}

fn collect_quote_cards<'a>(
    db: &'a tokio_postgres::Client,
    dns: &'a dyn crate::direct_preview::PreviewDns,
    status: &'a Value,
    cards: &'a mut HashMap<String, Value>,
    now: chrono::DateTime<chrono::Utc>,
    depth: usize,
) -> std::pin::Pin<Box<dyn std::future::Future<Output = Result<()>> + Send + 'a>> {
    Box::pin(async move {
        if depth > 16 {
            return Ok(());
        }
        let quoted = status.get("quote").and_then(|quote| quote.get("quoted_status")).filter(|quoted| quoted.is_object());
        if let Some(quoted) = quoted {
            if !has_media(quoted) && !quoted.get("card").is_some_and(Value::is_object) {
                let plain = quoted_plain(quoted);
                if let Some(url) = crate::direct_preview::extract_url(&plain)? {
                    // YouTube thumbs are deterministic. PHP paints them on the
                    // cache-only pass even when DNS fails, and that pass does
                    // not spend the quote budget.
                    let anchor = format!("<a href=\"{}\"></a>", super::actions::esc(&url));
                    if let Some(card) = crate::direct_preview::youtube_from_linked_html(&anchor)? {
                        cards.insert(url, card);
                    } else if let Some(card) = crate::direct_preview::resolve_cache_only(db, dns, &plain, now).await? {
                        cards.insert(url, card);
                    }
                }
            }
            collect_quote_cards(db, dns, quoted, cards, now, depth + 1).await?;
        }
        let inner = status.get("content").and_then(Value::as_str) == Some("") && status.get("reblog").is_some_and(Value::is_object);
        if inner {
            if let Some(reblog) = status.get("reblog") {
                collect_quote_cards(db, dns, reblog, cards, now, depth + 1).await?;
            }
        }
        Ok(())
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::audience::{replay_decision, ReplayRecord, Surface};
    use crate::library::bookmark::BookmarkCache;
    use crate::library::card::{parity_quote_card, render, CardFacts};
    use crate::library::favourite::project_saved_favourite;

    fn tokenize(html: &str) -> Vec<String> {
        regex::Regex::new(r"<[^>]+>|[^<]+")
            .unwrap()
            .find_iter(html)
            .map(|token| php_trim(token.as_str()).to_owned())
            .filter(|token| !token.is_empty())
            .collect()
    }

    fn facts(status: &Value) -> CardFacts {
        CardFacts {
            current_action_counts: None,
            bsky: None,
            owner_id: 1,
            owner_actor: "https://mkultra.monster/users/fixture".into(),
            return_view: "favourites".into(),
            auto_unblur_sensitive: false,
            resolved_avatar: "https://mkultra.monster/img/avatar/default.webp".into(),
            csrf: "fixture-csrf-token".into(),
            favourited: status.get("favourited").and_then(Value::as_bool) == Some(true),
            bookmarked: status.get("bookmarked").and_then(Value::as_bool) == Some(true),
            boosted: false,
            own: None,
            rss_row: None,
            rss_quote_row: None,
            rss_quoted: false,
            emoji_by_actor: Default::default(),
            reply_mentions: Vec::new(),
            moderation: Default::default(),
            poll_row: None,
            ask_row: None,
            now: chrono::DateTime::parse_from_rfc3339("2024-07-01T00:00:00Z").unwrap().with_timezone(&chrono::Utc),
            lazy_media: false,
        }
    }

    fn quote_html(status: &Value) -> String {
        parity_quote_card(status, &facts(status)).unwrap()
    }

    #[test]
    fn unresolved_glued_qt_is_not_a_card_and_status_urls_are() {
        assert!(!saved_quote_supported("hello ↪ QT there"));
        assert!(quote_target_url("hello ↪ QT there").is_none());
        let url = "https://quoted.test/users/bob/statuses/9";
        assert_eq!(quote_target_url(&format!("Comment\n↪ QT: {url}")).as_deref(), Some(url));
        assert!(looks_like_status_object(url));
        assert!(!looks_like_status_object("https://www.youtube.com/watch?v=abcdefghijk"));
        assert!(quote_target_is_bluesky("https://bsky.app/profile/alice.test/post/abc"));
        assert!(assemble_saved_quote("hello ↪ QT there", None, false, None).is_err());
    }

    #[test]
    fn plain_quote_budget_matches_the_php_second_call() {
        let quoted = json!({"content": "Actual current quote body", "media_attachments": [], "account": {"acct": "bob"}});
        for (initial, remaining, attempts) in [(-1, -1, 0), (0, 0, 0), (1, 0, 1), (2, 1, 1), (3, 2, 1)] {
            let mut state = QuotePreviewState::new(initial);
            let mut quoted = quoted.clone();
            apply_quoted_card(&mut quoted, &mut state, &HashMap::new()).unwrap();
            assert_eq!((state.remaining, state.attempts), (remaining, attempts));
            assert!(quoted.get("card").is_none());
        }
        let mut state = QuotePreviewState::new(3);
        let mut media = quoted.clone();
        media["media_attachments"] = json!([{"url": "https://media.test/q.jpg", "type": "image"}]);
        apply_quoted_card(&mut media, &mut state, &HashMap::new()).unwrap();
        assert_eq!(state.remaining, 3);
        let mut supplied = quoted.clone();
        supplied["card"] = json!({"url": "https://article.test/story", "title": "Story"});
        apply_quoted_card(&mut supplied, &mut state, &HashMap::new()).unwrap();
        assert_eq!(state.remaining, 3);
    }

    #[test]
    fn cold_non_youtube_url_keeps_the_batch_on_php_without_spending_budget() {
        let mut state = QuotePreviewState::new(2);
        let mut quoted = json!({
            "content": "Read https://article.test/missing today",
            "account": {"acct": "bob"},
            "media_attachments": []
        });
        let err = apply_quoted_card(&mut quoted, &mut state, &HashMap::new()).unwrap_err();
        assert!(err.to_string().contains("fetch/warm"), "{err}");
        assert_eq!((state.remaining, state.attempts), (2, 0));
        assert!(quoted.get("card").is_none());
    }

    #[test]
    fn quote_cards_match_actual_php_capture() {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/library-quote-preview-capture.php");
        let output = std::process::Command::new(file.parent().unwrap().join("php-policy-test")).arg(&file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        let captured: Value = serde_json::from_slice(&output.stdout).unwrap();
        let mut seen = Vec::new();
        for case in captured["cases"].as_array().unwrap() {
            let id = case["id"].as_str().unwrap();
            seen.push(id.to_owned());
            let php = case["html"].as_str().unwrap();
            assert!(!php.is_empty(), "{id} PHP quote HTML was empty");
            let native = match case["kind"].as_str().unwrap() {
                "quoted" => {
                    let mut status = case["status"].clone();
                    let mut cards = HashMap::new();
                    if id == "cached_article" {
                        let plain = quoted_plain(&status);
                        let url = crate::direct_preview::extract_url(&plain).unwrap().unwrap();
                        cards.insert(url, json!({
                            "url": "https://article.test/story",
                            "title": "Cached story",
                            "description": "A cached article",
                            "image": "https://article.test/story.jpg",
                            "provider_name": "Article Test",
                            "provider_url": "https://article.test",
                            "type": "link",
                            "status": "ok"
                        }));
                    }
                    let initial = case.get("budget_before").and_then(Value::as_i64).unwrap_or(2);
                    let mut state = QuotePreviewState::new(initial);
                    apply_quoted_card(&mut status, &mut state, &cards).unwrap();
                    let after = case["budget_after"].as_i64().unwrap();
                    assert_eq!(state.remaining, after, "{id} quote budget");
                    if initial > 0 {
                        assert_eq!(state.attempts as i64, initial - after, "{id} quote attempts");
                    } else {
                        assert_eq!(state.attempts, 0, "{id} exhausted budget");
                    }
                    quote_html(&status)
                }
                "bsky" => quote_html(&preview_status(&case["preview"], "")),
                "opts" => {
                    let saved = match id {
                        "cold_status" => assemble_saved_quote(
                            "Waiting\n↪ QT: https://quoted.test/users/bob/statuses/missing",
                            None, false, None,
                        ).unwrap(),
                        "unavailable_link" => assemble_saved_quote(
                            "Note\n↪ QT: https://www.youtube.com/watch?v=abcdefghijk",
                            None, false, None,
                        ).unwrap(),
                        "qt_words" => assemble_saved_quote("Comment\n↪ QT @carol: quoted words", None, false, None).unwrap(),
                        other => panic!("unexpected opts case {other}"),
                    };
                    quote_html(&saved.quote["quoted_status"])
                }
                other => panic!("unexpected kind {other}"),
            };
            let actual = tokenize(&native);
            let expected = tokenize(php);
            if actual != expected {
                let index = actual.iter().zip(expected.iter()).position(|(a, b)| a != b).unwrap_or(actual.len().min(expected.len()));
                panic!("actual PHP quote card {id} differs at token {index}: native={:?}, PHP={:?}", actual.get(index), expected.get(index));
            }
        }
        for id in ["plain_no_url", "plain_unset_budget", "plain_budget_zero", "supplied_media", "supplied_card", "cached_article", "youtube_quote", "cold_status", "unavailable_link", "bsky_preview", "qt_words"] {
            assert!(seen.iter().any(|seen| seen == id), "required PHP quote case {id}");
        }
    }

    fn paths() -> Value {
        serde_json::from_str(include_str!("../fixtures/library/quote-preview-paths.json")).unwrap()
    }

    fn now() -> chrono::DateTime<chrono::Utc> {
        chrono::DateTime::parse_from_rfc3339("2024-07-01T00:00:00Z").unwrap().with_timezone(&chrono::Utc)
    }

    fn cache_for(case: &Value) -> BookmarkCache {
        let mut cache = BookmarkCache::default();
        let mut put_event = |event: &Value| {
            let object = event.get("object_id").and_then(Value::as_str).unwrap_or("").trim_end_matches('/').to_owned();
            let id = event.get("id").and_then(Value::as_i64).unwrap_or(0);
            cache.events_by_object.insert(object, event.clone());
            if id > 0 {
                cache.events_by_id.insert(id, event.clone());
            }
        };
        if let Some(parent) = case.get("parent").filter(|value| value.is_object()) {
            put_event(parent);
        }
        if let Some(target) = case.get("target").filter(|value| value.is_object()) {
            put_event(target);
        }
        for actor in case.get("actors").and_then(Value::as_array).into_iter().flatten() {
            let id = actor.get("actor_id").and_then(Value::as_str).unwrap_or("").trim_end_matches('/').to_owned();
            cache.actors.insert(id, actor.clone());
        }
        if let Some(local) = case.get("local").filter(|value| value.is_object()) {
            let note = local.get("note_id").and_then(Value::as_str).unwrap_or("").trim_end_matches('/').to_owned();
            let id = local.get("local_id").and_then(Value::as_i64).unwrap_or(0);
            cache.local_by_note.insert(note, local.clone());
            if id > 0 {
                cache.local_by_id.insert(id, local.clone());
            }
        }
        if let Some(media) = case.get("media").and_then(Value::as_array) {
            let id = case["local"]["local_id"].as_i64().unwrap_or(0);
            cache.media_by_local.insert(id, media.clone());
        }
        if let Some(preview) = case.get("bsky").filter(|value| value.is_object()) {
            let url = preview.get("url").and_then(Value::as_str).unwrap_or("").to_owned();
            cache.bsky_by_uri.insert(url, preview.clone());
        }
        cache
    }

    fn project(case: &Value) -> Value {
        project_saved_favourite(&case["row"], &cache_for(case), now()).unwrap().unwrap()
    }

    fn audience(visibility: &str, author: &str, recipients: &[&str], denied: bool) -> ReplayRecord {
        ReplayRecord {
            denied,
            visibility: visibility.into(),
            author: author.into(),
            recipients: recipients.iter().map(|item| (*item).to_owned()).collect(),
        }
    }

    #[test]
    fn saved_quotes_paint_mixed_network_cold_deleted_and_private_targets() {
        let fixture = paths();
        let cached = project(&fixture["cached_ap"]);
        assert_eq!(cached["favourited"], true);
        assert_eq!(cached["bookmarked"], false);
        assert!(!cached["content"].as_str().unwrap().contains('↪'), "QT line stays out of the parent body");
        let cached_html = render(&cached, &facts(&cached)).unwrap();
        assert!(cached_html.contains("See this"));
        assert!(cached_html.contains("Actual current quote body"));
        assert!(cached_html.contains("quote-block"));
        assert!(cached_html.contains("unfavourite_status"));

        let pictured = project(&fixture["local_media"]);
        let pictured_html = render(&pictured, &facts(&pictured)).unwrap();
        assert!(pictured_html.contains("Quoted with a picture"));
        assert!(pictured_html.contains("https://media.test/quoted.jpg"));
        assert!(pictured_html.contains("quote-media"));
        assert!(!pictured_html.contains('↪'));

        let bsky = project(&fixture["bluesky"]);
        let bsky_html = render(&bsky, &facts(&bsky)).unwrap();
        assert!(bsky_html.contains("Bluesky quoted line"));
        assert!(bsky_html.contains("https://cdn.test/bsky-quote.jpg"));
        assert!(bsky_html.contains("cw-gate"));
        assert!(bsky_html.contains("Sensitive content"));
        assert!(bsky_html.contains("Open original"));
        assert!(bsky_html.contains("target=\"_blank\""));

        let cold = project(&fixture["cold_status"]);
        let cold_html = render(&cold, &facts(&cold)).unwrap();
        assert!(cold_html.contains("Quoted post (not cached yet) — open"));
        assert!(cold_html.contains("Still waiting"));
        assert!(!cold_html.contains("Quoted post unavailable"));

        let unavailable = project(&fixture["unavailable"]);
        let unavailable_html = render(&unavailable, &facts(&unavailable)).unwrap();
        assert!(unavailable_html.contains("Quoted post unavailable"));
        assert!(unavailable_html.contains("Open original"));
        assert!(unavailable_html.contains("https://www.youtube.com/watch?v=abcdefghijk"));

        let deleted = project(&fixture["deleted_target"]);
        assert!(deleted.get("quote").is_none_or(Value::is_null));
        assert!(!deleted["content"].as_str().unwrap().contains("must not be revived"));
        let deleted_html = render(&deleted, &facts(&deleted)).unwrap();
        assert!(deleted_html.contains("Keep this commentary"));
        assert!(!deleted_html.contains("must not be revived"));
        assert!(!deleted_html.contains("quote-block"));

        let glued = fixture["glued"]["parent"].clone();
        let mut cache = BookmarkCache::default();
        let object = glued["object_id"].as_str().unwrap().trim_end_matches('/').to_owned();
        cache.events_by_object.insert(object, glued);
        let err = project_saved_favourite(&fixture["glued"]["row"], &cache, now()).unwrap_err();
        assert!(err.to_string().contains("quote"), "{err}");

        let private_status = project(&fixture["private_quote"]);
        let quoted_uri = private_status["quote"]["quoted_status"]["uri"].as_str().unwrap().to_owned();
        let author = private_status["quote"]["quoted_status"]["account"]["uri"].as_str().unwrap().to_owned();
        let viewer = "https://mkultra.monster/users/viewer";
        let stranger = HashMap::new();
        assert!(!replay_decision(&private_status, &stranger, viewer, &[], Surface::Library), "a private quoted post is not public");
        let mut grant = HashMap::new();
        grant.insert(quoted_uri.clone(), audience("private", &author, &[], false));
        assert!(!replay_decision(&private_status, &grant, viewer, &[], Surface::Library));
        assert!(replay_decision(&private_status, &grant, viewer, &[author.clone()], Surface::Library), "a follower may read the quoted post");
        let mut revoked = HashMap::new();
        revoked.insert(quoted_uri.clone(), audience("private", &author, &[], true));
        assert!(!replay_decision(&private_status, &revoked, viewer, &[author.clone()], Surface::Library), "a revoked grant hides the whole card");

        let direct = project(&fixture["direct_quote"]);
        let direct_uri = direct["quote"]["quoted_status"]["uri"].as_str().unwrap().to_owned();
        let direct_author = direct["quote"]["quoted_status"]["account"]["uri"].as_str().unwrap().to_owned();
        let mut mention = HashMap::new();
        mention.insert(direct_uri, audience("direct", &direct_author, &[viewer], false));
        assert!(replay_decision(&direct, &mention, viewer, &[], Surface::Library), "the mentioned recipient may read the quoted post");
        assert!(!replay_decision(&direct, &mention, "https://mkultra.monster/users/stranger", &[direct_author], Surface::Library));

        let root = json!({"id": "1", "uri": "https://bsky.app/profile/alice.test/post/abc123", "content": "root"});
        assert!(super::super::context::require_ap_preview_protocol(&root, 0).is_err());
        let nested = json!({
            "id": "2",
            "uri": "https://remote.test/users/alice/statuses/parent",
            "content": "See this",
            "quote": {"quoted_status": {"id": "3", "uri": "https://bsky.app/profile/alice.test/post/abc123", "content": "Bluesky quoted line", "visibility": "public"}}
        });
        assert!(super::super::context::require_ap_preview_protocol(&nested, 0).is_ok());

        let mut state = QuotePreviewState::new(2);
        let mut plain = json!({"content": "Actual current quote body", "quote": {"quoted_status": {"content": "Actual current quote body", "account": {"acct": "bob"}, "media_attachments": []}}});
        prepare_presentation(&mut plain, &mut state, &HashMap::new()).unwrap();
        assert_eq!((state.remaining, state.attempts), (1, 1));
        let cached_card = json!({"content": "Read https://article.test/story today", "account": {"acct": "bob"}, "media_attachments": []});
        let url = crate::direct_preview::extract_url("Read https://article.test/story today").unwrap().unwrap();
        let mut cards = HashMap::new();
        cards.insert(url, json!({"url": "https://article.test/story", "title": "Cached story", "description": "A cached article", "image": "https://article.test/story.jpg", "provider_name": "Article Test", "type": "link", "status": "ok"}));
        let mut with_card = json!({"content": "outer", "quote": {"quoted_status": cached_card}});
        prepare_presentation(&mut with_card, &mut state, &cards).unwrap();
        assert_eq!((state.remaining, state.attempts), (1, 1), "a cached quote card does not spend another attempt");
    }
}
