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

/// Canonical open.spotify.com item URL (PHP `ap_link_preview_spotify_url`).
/// PHP caches Spotify cards under this too, whatever `?si=` the post carried.
fn spotify_canonical_url(url: &str) -> Option<String> {
    static PATTERN: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    let pattern = PATTERN.get_or_init(|| {
        regex::Regex::new(
            r"(?i)^https://(?:www\.)?open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(album|track|playlist|artist|episode|show)/([A-Za-z0-9]{10,40})(?:[/?#]|$)",
        )
        .expect("spotify url pattern")
    });
    let cap = pattern.captures(url.trim())?;
    Some(format!(
        "https://open.spotify.com/{}/{}",
        cap[1].to_ascii_lowercase(),
        &cap[2]
    ))
}

/// Every `link_preview_cards` key a body URL may be cached under, best first.
/// PHP stores cards under its canonical form (lowercase host, no www, sorted
/// query without trackers), so the raw link alone misses e.g. `?si=…&nd=1`.
fn lookup_keys(url: &str) -> Vec<String> {
    let mut keys = vec![canonicalize_preview_url(url), url.to_string()];
    let ascii_len = url
        .bytes()
        .take_while(|b| (0x21..=0x7e).contains(b))
        .count();
    if let Some(key) = crate::direct_preview::cache_key(&url[..ascii_len]) {
        keys.push(key);
    }
    if let Some(key) = spotify_canonical_url(url) {
        keys.push(key);
    }
    let mut seen = HashSet::new();
    keys.retain(|k| k.starts_with("https://") && seen.insert(k.clone()));
    keys
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
        into.extend(lookup_keys(&url));
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
        if let Some(card) = lookup_keys(&url).iter().find_map(|k| by_url.get(k)) {
            st["card"] = card.clone();
            return;
        }
        let canon = canonicalize_preview_url(&url);
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
    fn spotify_share_link_finds_canonical_and_php_keys() {
        let keys = lookup_keys(
            "https://open.spotify.com/intl-de/track/4cOdK2wGLETKBW3PvgPWqT?si=abc&nd=1",
        );
        assert!(keys.contains(&"https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT".to_string()));
        assert!(keys.contains(
            &"https://open.spotify.com/intl-de/track/4cOdK2wGLETKBW3PvgPWqT?nd=1&si=abc"
                .to_string()
        ));
        assert_eq!(
            spotify_canonical_url("https://open.spotify.com/user/abc"),
            None
        );
    }

    #[test]
    fn attaches_card_cached_under_spotify_canonical_url() {
        let card = card_json_from_row(
            "https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT",
            Some("Song".into()),
            None,
            Some("https://i.scdn.co/image/x".into()),
            Some("Spotify".into()),
        );
        let mut by_url = HashMap::new();
        by_url.insert(
            "https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT".to_string(),
            card,
        );
        let mut st = json!({
            "content": "<p>listen <a href=\"https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT?si=f00&amp;nd=1\">https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT?si=f00&amp;nd=1</a></p>",
            "media_attachments": [],
            "card": null
        });
        attach_card_if_missing(&mut st, &by_url);
        assert_eq!(st["card"]["title"], "Song");
        assert_eq!(st["card"]["provider_name"], "Spotify");
    }

    #[test]
    fn php_sorted_query_key_matches() {
        let card = card_json_from_row(
            "https://example.com/a?b=2&z=1",
            Some("Article".into()),
            None,
            None,
            None,
        );
        let mut by_url = HashMap::new();
        by_url.insert("https://example.com/a?b=2&z=1".to_string(), card);
        let mut st = json!({"content": "<p>https://www.example.com/a?z=1&amp;b=2&amp;utm_source=x</p>", "card": null});
        attach_card_if_missing(&mut st, &by_url);
        assert_eq!(st["card"]["title"], "Article");
    }

    #[test]
    fn canonicalizes_youtu_be() {
        assert_eq!(
            canonicalize_preview_url("https://youtu.be/z6hGTNMgl28?si=abc"),
            "https://www.youtube.com/watch?v=z6hGTNMgl28"
        );
    }
}
