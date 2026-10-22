//! Post audience labels shared by every Rust card painter.
//!
//! PHP twin: `admin_visibility_meta` + `admin_visibility_badge_html` in
//! api/ap-admin.php. Keep the aliases and markup identical so a PHP fallback
//! paints the same header as the Rust worker.

/// Normalized key and human label for a stored or remote `visibility` value.
pub fn visibility_label(raw: &str) -> (&'static str, &'static str) {
    match raw.trim().to_ascii_lowercase().as_str() {
        "unlisted" | "silent" | "silent_public" => ("unlisted", "Silent public"),
        "private" | "followers" | "followers_only" => ("private", "Followers-only"),
        "local" | "local_only" | "instance" | "instance_only" => ("local", "Local only"),
        "direct" => ("direct", "Direct"),
        _ => ("public", "Public"),
    }
}

/// Audience label pinned to the top right of a card header. Every level shows,
/// Public included. Bluesky callers pass `"public"`: the network has no
/// audience levels and every post is world-readable.
pub fn visibility_badge_html(raw: &str) -> String {
    let (key, label) = visibility_label(raw);
    format!("<span class=\"tweet-vis tweet-vis--{key}\" title=\"Audience\">{label}</span>")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_level_has_a_label() {
        assert_eq!(visibility_label("public"), ("public", "Public"));
        assert_eq!(visibility_label(""), ("public", "Public"));
        assert_eq!(visibility_label("unlisted"), ("unlisted", "Silent public"));
        assert_eq!(visibility_label("private"), ("private", "Followers-only"));
        assert_eq!(visibility_label("local_only"), ("local", "Local only"));
        assert_eq!(visibility_label("Direct"), ("direct", "Direct"));
    }

    #[test]
    fn badge_matches_php_markup() {
        assert_eq!(
            visibility_badge_html("local"),
            "<span class=\"tweet-vis tweet-vis--local\" title=\"Audience\">Local only</span>"
        );
    }
}
