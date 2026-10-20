//! Custom emoji (`:shortcode:`) shared by every painter.
//!
//! Statuses carry Mastodon-shaped `emojis` (`[{shortcode, url, static_url}]`)
//! and accounts carry the same list for display names. Painters read them
//! here instead of each re-parsing `remote_custom_emojis` rows or AS2 tags.

use std::collections::HashMap;

use serde_json::Value;

/// Same grammar as PHP and the Rust body painter: `[A-Za-z0-9_-]{1,80}`.
pub fn valid_shortcode(code: &str) -> bool {
    !code.is_empty()
        && code.len() <= 80
        && code.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-')
}

/// Every `:shortcode:` token in `text`, in order, without duplicates.
/// Matches the leftmost-first scan of `:([A-Za-z0-9_-]{1,80}):`.
pub fn shortcodes_in(text: &str) -> Vec<String> {
    let bytes = text.as_bytes();
    let mut out: Vec<String> = Vec::new();
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] != b':' {
            i += 1;
            continue;
        }
        let mut j = i + 1;
        while j < bytes.len() && (bytes[j].is_ascii_alphanumeric() || bytes[j] == b'_' || bytes[j] == b'-') {
            j += 1;
        }
        let len = j - i - 1;
        if j < bytes.len() && bytes[j] == b':' && (1..=80).contains(&len) {
            let code = &text[i + 1..j];
            if !out.iter().any(|c| c == code) {
                out.push(code.to_string());
            }
            i = j + 1;
        } else {
            i += 1;
        }
    }
    out
}

/// One Mastodon-shaped emoji entry, or `None` when the URL is not https.
pub fn emoji_entry(shortcode: &str, url: &str, static_url: Option<&str>) -> Option<Value> {
    let code = shortcode.trim().trim_matches(':');
    if !valid_shortcode(code) || !url.starts_with("https://") {
        return None;
    }
    let static_url = static_url.filter(|u| u.starts_with("https://")).unwrap_or(url);
    Some(serde_json::json!({
        "shortcode": code,
        "url": url,
        "static_url": static_url,
        "visible_in_picker": false,
    }))
}

/// shortcode -> https URL from a Mastodon `emojis` list. Bad rows are skipped.
pub fn emoji_map(emojis: Option<&Value>) -> HashMap<String, String> {
    let mut map = HashMap::new();
    for item in emojis.and_then(Value::as_array).into_iter().flatten() {
        let code = item.get("shortcode").and_then(Value::as_str).unwrap_or("").trim().trim_matches(':');
        let url = item
            .get("url")
            .and_then(Value::as_str)
            .filter(|u| u.starts_with("https://"))
            .or_else(|| item.get("static_url").and_then(Value::as_str).filter(|u| u.starts_with("https://")));
        if let Some(url) = url {
            if valid_shortcode(code) {
                map.entry(code.to_string()).or_insert_with(|| url.to_string());
            }
        }
    }
    map
}

/// Emoji for a status body / content warning.
pub fn status_emoji_map(status: &Value) -> HashMap<String, String> {
    emoji_map(status.get("emojis"))
}

/// Emoji for an account display name.
pub fn account_emoji_map(account: &Value) -> HashMap<String, String> {
    emoji_map(account.get("emojis"))
}

/// Append entries to a Mastodon `emojis` list, keeping the first per shortcode.
pub fn merge_emojis(target: &mut Value, extra: impl IntoIterator<Item = Value>) {
    if !target.is_array() {
        *target = Value::Array(Vec::new());
    }
    let list = target.as_array_mut().expect("array");
    for entry in extra {
        let code = entry.get("shortcode").and_then(Value::as_str).unwrap_or("");
        if code.is_empty() || list.iter().any(|e| e.get("shortcode").and_then(Value::as_str) == Some(code)) {
            continue;
        }
        list.push(entry);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn scans_like_the_php_regex() {
        assert_eq!(shortcodes_in("You're all wonderful :menhera_happy_hearts:"), vec!["menhera_happy_hearts"]);
        assert_eq!(shortcodes_in(":a::b: :a: 12:30 :x y: ::"), vec!["a", "b"]);
        assert_eq!(shortcodes_in("https://h/x:y"), Vec::<String>::new());
        assert!(shortcodes_in(&format!(":{}:", "a".repeat(81))).is_empty());
    }

    #[test]
    fn map_rejects_bad_rows() {
        let list = json!([
            {"shortcode": "ok", "url": "https://cdn.test/ok.webp"},
            {"shortcode": ":colon:", "url": "https://cdn.test/c.webp"},
            {"shortcode": "plain", "url": "http://cdn.test/p.png"},
            {"shortcode": "bad code", "url": "https://cdn.test/b.png"}
        ]);
        let map = emoji_map(Some(&list));
        assert_eq!(map.len(), 2);
        assert_eq!(map["colon"], "https://cdn.test/c.webp");
    }

    #[test]
    fn merge_keeps_first() {
        let mut list = json!([{"shortcode": "a", "url": "https://one.test/a.png"}]);
        merge_emojis(&mut list, [emoji_entry("a", "https://two.test/a.png", None).unwrap(), emoji_entry(":b:", "https://two.test/b.png", None).unwrap()]);
        let map = emoji_map(Some(&list));
        assert_eq!(map["a"], "https://one.test/a.png");
        assert_eq!(map["b"], "https://two.test/b.png");
    }
}
