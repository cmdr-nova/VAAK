//! ListenBrainz "now playing" block for local profile headers.
//! PHP oracle: api/ap-listenbrainz.php (ap_listenbrainz_display and
//! ap_listenbrainz_profile_html). Both sides replay
//! api/fixtures/listenbrainz-now-playing.json. This module only reads the
//! stored snapshot; api/ap-listenbrainz-worker.php is the only fetcher.
use serde_json::{json, Value};

use crate::library::actions::{esc, query};
use crate::library::card;

/// Same as AP_LISTENBRAINZ_PLAYING_FRESH.
const PLAYING_FRESH: i64 = 600;

fn text<'a>(row: &'a Value, key: &str) -> &'a str { row.get(key).and_then(Value::as_str).unwrap_or("") }

fn cover_re() -> &'static regex::Regex {
    static RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"^https://coverartarchive\.org/release/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/(?:front|[0-9]{1,20})-250(?:\.jpg)?$").unwrap())
}

pub(crate) fn cover_ok(url: &str) -> bool { cover_re().is_match(url) }

/// ap_listenbrainz_display: what one stored row shows at `now`, or None.
pub(crate) fn display(row: &Value, now: i64) -> Option<Value> {
    if !row.is_object() { return None; }
    let name = card::php_trim(text(row, "lb_username"));
    if name.is_empty() { return None; }
    let fetched = card::integer(row, "fetched_at");
    let (prefix, label) = if !card::php_trim(text(row, "playing_track")).is_empty() && fetched >= now - PLAYING_FRESH {
        ("playing_", "Now playing")
    } else if !card::php_trim(text(row, "last_track")).is_empty() {
        ("last_", "Last played")
    } else {
        return None;
    };
    let field = |name: &str| card::php_trim(text(row, &format!("{prefix}{name}"))).to_owned();
    let cover = field("cover");
    Some(json!({
        "label": label,
        "track": field("track"),
        "artist": field("artist"),
        "release": field("release"),
        "cover": if cover_ok(&cover) { cover } else { String::new() },
        "url": format!("https://listenbrainz.org/user/{}/", query(name)),
    }))
}

/// ap_listenbrainz_profile_html: one line of markup, '' when nothing shows.
pub(crate) fn profile_html(display: Option<&Value>) -> String {
    let Some(d) = display.filter(|d| d.is_object()) else { return String::new() };
    let track = text(d, "track");
    if card::php_trim(track).is_empty() { return String::new(); }
    let playing = text(d, "label") == "Now playing";
    let label = if playing { "Now playing" } else { "Last played" };
    let url = text(d, "url");
    if !url.starts_with("https://listenbrainz.org/user/") { return String::new(); }
    let artist = card::php_trim(text(d, "artist"));
    let release = card::php_trim(text(d, "release"));
    let cover = text(d, "cover");
    let mut by = artist.to_owned();
    if !release.is_empty() && release != track {
        by = if by.is_empty() { release.to_owned() } else { format!("{by} · {release}") };
    }
    let mut out = format!(
        "<div class=\"profile-now-playing\" data-state=\"{}\"><a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\" title=\"{}\">",
        if playing { "playing" } else { "last" }, esc(url), esc(&format!("{label} on ListenBrainz"))
    );
    if !cover.is_empty() && cover_ok(cover) {
        out.push_str(&format!("<img class=\"profile-now-playing-cover\" src=\"{}\" alt=\"\" width=\"44\" height=\"44\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.remove()\">", esc(cover)));
    } else {
        out.push_str("<span class=\"profile-now-playing-cover\" aria-hidden=\"true\">♪</span>");
    }
    out.push_str(&format!("<span class=\"profile-now-playing-text\"><span class=\"profile-now-playing-label\">{}</span><span class=\"profile-now-playing-track\">{}</span>", esc(label), esc(track)));
    if !by.is_empty() { out.push_str(&format!("<span class=\"profile-now-playing-by\">{}</span>", esc(&by))); }
    out.push_str("</span></a></div>");
    out
}

/// Profile headers read the stored row with this (one indexed join, no network).
pub(crate) const ROW_FOR_ACTOR_KEY_SQL: &str = "SELECT to_jsonb(l) FROM ap_listenbrainz_links l INNER JOIN ap_users u ON u.id=l.owner_user_id WHERE u.actor_key=$1 AND u.disabled_at IS NULL LIMIT 1";

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn shared_fixture_matches_php() {
        let raw = include_str!("../../../api/fixtures/listenbrainz-now-playing.json");
        let fixture: Value = serde_json::from_str(raw).unwrap();
        let cases = fixture["cases"].as_array().unwrap();
        assert!(cases.len() >= 6);
        for case in cases {
            let id = case["id"].as_str().unwrap();
            let shown = display(&case["row"], case["now"].as_i64().unwrap());
            assert_eq!(shown.clone().unwrap_or(Value::Null), case["display"], "{id} display");
            assert_eq!(profile_html(shown.as_ref()), case["html"].as_str().unwrap(), "{id} html");
        }
    }

    #[test]
    fn rejects_foreign_urls() {
        assert_eq!(profile_html(Some(&json!({"label":"Now playing","track":"x","url":"https://evil.example/"}))), "");
        assert!(!cover_ok("https://coverartarchive.org/release/../x"));
        assert!(cover_ok("https://coverartarchive.org/release/0a1b2c3d-0000-4000-8000-000000000000/front-250"));
    }
}
