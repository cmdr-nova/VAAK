//! Attach cached Open Graph / YouTube cards onto Mastodon-shaped statuses.
//!
//! PHP paints via `ap_link_preview_card_for_status_text` (link_preview_cards).
//! Lean Axum hydrate historically left `card: null`, so timelines lost YouTube
//! click-to-play previews. This batch-loads the PHP cache before lean paint.

use std::collections::{HashMap, HashSet};

use anyhow::Result;
use serde_json::{json, Value};
use tokio_postgres::Client;

fn strip_tags_lite(html: &str) -> String {
    let mut out = String::with_capacity(html.len());
    let mut in_tag = false;
    for c in html.chars() {
        match c {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ if !in_tag => out.push(c),
            _ => {}
        }
    }
    out.replace("&amp;", "&")
        .replace("&quot;", "\"")
        .replace("&#39;", "'")
        .replace("&nbsp;", " ")
}

fn first_https_urls(text_or_html: &str) -> Vec<String> {
    let plain = strip_tags_lite(text_or_html);
    let mut out = Vec::new();
    let mut search_from = 0usize;
    while let Some(rel) = plain[search_from..].find("https://") {
        let start = search_from + rel;
        let tail = &plain[start..];
        let end_rel = tail
            .find(|c: char| c.is_whitespace() || "<>\"'".contains(c))
            .unwrap_or(tail.len());
        let mut url = tail[..end_rel].trim_end_matches(|c: char| ".,);]!?'\"".contains(c));
        if let Some(second) = url[8..].find("https://") {
            url = &url[..8 + second];
        }
        url = url.trim_end_matches(|c: char| ".,);]!?'\"/".contains(c));
        if url.starts_with("https://") && !out.iter().any(|u| u == url) {
            out.push(url.to_string());
        }
        search_from = start + end_rel.max(1);
        if search_from >= plain.len() {
            break;
        }
    }
    out
}

/// Canonicalize YouTube URLs the way PHP `ap_link_preview_normalize_url` does.
pub fn canonicalize_preview_url(url: &str) -> String {
    let url = url.trim();
    let lower = url.to_ascii_lowercase();
    if let Some(pos) = lower.find("youtu.be/") {
        let rest = &url[pos + "youtu.be/".len()..];
        let id = rest.split(['?', '#', '/']).next().unwrap_or("");
        if id.len() >= 6 {
            return format!("https://www.youtube.com/watch?v={id}");
        }
    }
    if let Some(pos) = lower.find("youtube.com/shorts/") {
        let rest = &url[pos + "youtube.com/shorts/".len()..];
        let id = rest.split(['?', '#', '/']).next().unwrap_or("");
        if id.len() >= 6 {
            return format!("https://www.youtube.com/watch?v={id}");
        }
    }
    if lower.contains("youtube.com/watch") {
        if let Some(vpos) = lower.find("v=") {
            let rest = &url[vpos + 2..];
            let id = rest.split(['&', '#', '/', '?']).next().unwrap_or("");
            if id.len() >= 6 {
                return format!("https://www.youtube.com/watch?v={id}");
            }
        }
    }
    url.trim_end_matches('/').to_string()
}

fn status_needs_card(st: &Value) -> bool {
    if st
        .get("media_attachments")
        .and_then(|v| v.as_array())
        .map(|a| !a.is_empty())
        .unwrap_or(false)
    {
        return false;
    }
    if let Some(card) = st.get("card").filter(|v| v.is_object()) {
        let url = card.get("url").and_then(|v| v.as_str()).unwrap_or("");
        if url.starts_with("https://") || url.starts_with("http://") {
            return false;
        }
    }
    true
}

fn collect_candidate_urls(st: &Value, into: &mut HashSet<String>) {
    if !status_needs_card(st) {
        return;
    }
    let content = st.get("content").and_then(|v| v.as_str()).unwrap_or("");
    for url in first_https_urls(content) {
        let canon = canonicalize_preview_url(&url);
        if canon.starts_with("https://") {
            into.insert(canon);
            into.insert(url);
        }
    }
}

fn card_json_from_row(
    url: &str,
    title: Option<String>,
    description: Option<String>,
    image: Option<String>,
    provider_name: Option<String>,
) -> Value {
    json!({
        "url": url,
        "title": title.unwrap_or_default(),
        "description": description.unwrap_or_default(),
        "image": image.filter(|s| s.starts_with("https://")).unwrap_or_default(),
        "type": "link",
        "author_name": "",
        "author_url": "",
        "provider_name": provider_name.unwrap_or_default(),
        "provider_url": "",
        "html": "",
        "width": 0,
        "height": 0,
        "embed_url": "",
        "blurhash": Value::Null
    })
}

fn attach_card_if_missing(st: &mut Value, by_url: &HashMap<String, Value>) {
    if !status_needs_card(st) {
        return;
    }
    let content = st
        .get("content")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_string();
    for url in first_https_urls(&content) {
        let canon = canonicalize_preview_url(&url);
        if let Some(card) = by_url.get(&canon).or_else(|| by_url.get(&url)) {
            st["card"] = card.clone();
            return;
        }
        // YouTube without a cache row still gets a usable card for lean paint.
        if canon.contains("youtube.com/watch?v=") {
            if let Some(id) = canon.rsplit("v=").next() {
                let id = id.split('&').next().unwrap_or("");
                if id.len() >= 6 {
                    st["card"] = card_json_from_row(
                        &canon,
                        Some("YouTube video".into()),
                        None,
                        Some(format!("https://i.ytimg.com/vi/{id}/hqdefault.jpg")),
                        Some("YouTube".into()),
                    );
                    return;
                }
            }
        }
    }
}

/// Fill missing `status.card` from `link_preview_cards` (and YouTube fallback).
pub async fn attach_cached_cards(db: &Client, statuses: &mut [Value]) -> Result<()> {
    let mut urls: HashSet<String> = HashSet::new();
    for st in statuses.iter() {
        collect_candidate_urls(st, &mut urls);
        if let Some(reblog) = st.get("reblog").filter(|v| v.is_object()) {
            collect_candidate_urls(reblog, &mut urls);
        }
    }
    if urls.is_empty() {
        return Ok(());
    }
    let list: Vec<String> = urls.into_iter().collect();
    let rows = db
        .query(
            "SELECT url, title, description, image, provider_name
             FROM link_preview_cards
             WHERE url = ANY($1) AND COALESCE(status, 'ok') IN ('ok', '')",
            &[&list],
        )
        .await
        .unwrap_or_default();
    let mut by_url: HashMap<String, Value> = HashMap::new();
    for row in rows {
        let url: String = row.get(0);
        let title: Option<String> = row.get(1);
        let description: Option<String> = row.get(2);
        let image: Option<String> = row.get(3);
        let provider_name: Option<String> = row.get(4);
        let canon = canonicalize_preview_url(&url);
        let card = card_json_from_row(&canon, title, description, image, provider_name);
        by_url.insert(canon, card.clone());
        by_url.insert(url, card);
    }
    for st in statuses.iter_mut() {
        attach_card_if_missing(st, &by_url);
        if let Some(reblog) = st.get_mut("reblog").filter(|v| v.is_object()) {
            attach_card_if_missing(reblog, &by_url);
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn canonicalizes_youtu_be() {
        assert_eq!(
            canonicalize_preview_url("https://youtu.be/z6hGTNMgl28?si=abc"),
            "https://www.youtube.com/watch?v=z6hGTNMgl28"
        );
    }
}
