//! One hashtag reader for Home ranking, followed tags and the ML shadow ranker.
//!
//! Rules match PHP `admin_home_extract_hashtags` and the search indexer
//! (`ap_search_fts_normalize_body`): strip tags, decode entities, then a `#`
//! that does not follow a letter, number or `_`, followed by 2 to 64 Unicode
//! letters, numbers or `_`, lowercased with full Unicode case folding. The
//! cases in the tests come from twitter-text's `conformance/extract.yml`
//! (Apache-2.0, https://github.com/twitter/twitter-text) where VAAK agrees
//! with it, so `#café` and `#日本語` count and `foo#bar` does not.

use std::collections::HashSet;

/// A Unicode letter or number (PHP `\p{L}\p{N}`) or `_`.
pub(crate) fn is_tag_char(c: char) -> bool {
    c == '_' || c.is_alphanumeric()
}

fn strip_tags(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut in_tag = false;
    for ch in text.chars() {
        match ch {
            '<' => in_tag = true,
            '>' => {
                in_tag = false;
                // Keep words from adjacent elements apart: "<p>a</p><p>#b</p>".
                out.push(' ');
            }
            _ if !in_tag => out.push(ch),
            _ => {}
        }
    }
    out
}

/// Lowercased hashtag names in first-seen order, duplicates dropped.
pub(crate) fn extract(html_or_text: &str) -> Vec<String> {
    let plain = crate::html_entities::decode(&strip_tags(html_or_text));
    let chars: Vec<char> = plain.chars().collect();
    let mut tags = Vec::new();
    let mut seen = HashSet::new();
    let mut i = 0usize;
    while i < chars.len() {
        if chars[i] != '#' || (i > 0 && is_tag_char(chars[i - 1])) {
            i += 1;
            continue;
        }
        let start = i + 1;
        let mut end = start;
        while end < chars.len() && is_tag_char(chars[end]) {
            end += 1;
        }
        let len = end - start;
        if (2..=64).contains(&len) {
            let tag: String = chars[start..end].iter().collect::<String>().to_lowercase();
            if seen.insert(tag.clone()) {
                tags.push(tag);
            }
        }
        i = end.max(i + 1);
    }
    tags
}

/// A stored or followed tag name in the same form [`extract`] returns:
/// no leading `#`, Unicode-lowercased, letters/numbers/`_` only
/// (PHP `ap_masto_normalize_tag_name`).
pub(crate) fn normalize_name(name: &str) -> String {
    name.trim()
        .trim_start_matches('#')
        .to_lowercase()
        .chars()
        .filter(|c| is_tag_char(*c))
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ascii_tags_still_work() {
        assert_eq!(extract("Hello #Fediverse and #Art!"), vec!["fediverse", "art"]);
        assert_eq!(extract("<b>#Code</b> #code"), vec!["code"]);
        assert_eq!(extract("#a #ok_tag"), vec!["ok_tag"]);
        assert!(extract("no tags here").is_empty());
    }

    #[test]
    fn unicode_tags_are_whole() {
        // twitter-text extract.yml: "Extract hashtags with Latin extended
        // characters", "Japanese", "Korean", "Cyrillic", "Arabic".
        assert_eq!(extract("#café time"), vec!["café"]);
        assert_eq!(extract("#Müll"), vec!["müll"]);
        assert_eq!(extract("#Ärger"), vec!["ärger"]);
        assert_eq!(extract("今日は #日本語 です"), vec!["日本語"]);
        assert_eq!(extract("#한국어"), vec!["한국어"]);
        assert_eq!(extract("#русский"), vec!["русский"]);
        assert_eq!(extract("#العربية"), vec!["العربية"]);
    }

    #[test]
    fn hash_inside_a_word_is_not_a_tag() {
        // twitter-text: "DO NOT extract hashtags preceded by a letter/number".
        assert!(extract("foo#bar").is_empty());
        assert!(extract("café#bar").is_empty());
        assert!(extract("2#bar").is_empty());
        assert_eq!(extract("(#bar)"), vec!["bar"]);
        assert_eq!(extract("x.#bar"), vec!["bar"]);
    }

    #[test]
    fn entities_are_decoded_first() {
        // An escaped apostrophe is not hashtag #039.
        assert!(extract("it&#039;s fine").is_empty());
        assert_eq!(extract("&quot;#tag&quot;"), vec!["tag"]);
    }

    #[test]
    fn adjacent_elements_do_not_merge_words() {
        assert_eq!(extract("<p>word</p><p>#tag</p>"), vec!["tag"]);
    }

    #[test]
    fn length_limits_count_characters() {
        assert!(extract("#é").is_empty());
        assert_eq!(extract("#日本"), vec!["日本"]);
        let long = format!("#{}", "é".repeat(65));
        assert!(extract(&long).is_empty());
    }

    #[test]
    fn normalize_matches_extract() {
        assert_eq!(normalize_name("#Café"), "café");
        assert_eq!(normalize_name("  Ärger "), "ärger");
        assert_eq!(normalize_name("a-b"), "ab");
        for tag in ["#Café", "#日本語", "#Müll", "#ok_Tag"] {
            assert_eq!(extract(tag), vec![normalize_name(tag)]);
        }
    }
}
