//! Stored Nostr notes → the shared timeline status shape Home paints.
//!
//! Notes are plain text. Links become anchors, image and video links become
//! attachments (with NIP-92 `imeta` alt text when present), `nostr:` mentions
//! link out to njump.me, and NIP-36 content warnings fold the card. The card
//! is read-only: ids start with `nostr:` and the painter shows only Open and
//! Copy link (notif_embed `is_nostr`).

use serde_json::{json, Value};

use super::bech32;

const DEFAULT_AVATAR: &str = "https://mkultra.monster/img/avatar/default.webp";
const VIEWER: &str = "https://njump.me/";
const MAX_MEDIA: usize = 4;

#[derive(Debug, Clone, Default)]
pub struct NostrRow {
    pub id: String,
    pub pubkey: String,
    pub created_at: i64,
    pub content: String,
    pub tags: Value,
    pub name: String,
    pub display_name: String,
    pub picture: String,
    pub nip05: String,
}

fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            _ => out.push(c),
        }
    }
    out
}

pub fn short_npub(npub: &str) -> String {
    if npub.len() <= 20 {
        return npub.to_string();
    }
    format!("{}…{}", &npub[..10], &npub[npub.len() - 6..])
}

fn media_type(url: &str, mime: &str) -> Option<&'static str> {
    let mime = mime.to_ascii_lowercase();
    if mime.starts_with("image/") {
        return Some("image");
    }
    if mime.starts_with("video/") {
        return Some("video");
    }
    let path = url.split(['?', '#']).next().unwrap_or(url).to_ascii_lowercase();
    if [".png", ".jpg", ".jpeg", ".gif", ".webp", ".avif"].iter().any(|e| path.ends_with(e)) {
        Some("image")
    } else if [".mp4", ".webm", ".mov", ".m4v"].iter().any(|e| path.ends_with(e)) {
        Some("video")
    } else {
        None
    }
}

/// NIP-92 `imeta` entries by URL: (mime, alt, blurhash).
fn imeta(tags: &Value) -> std::collections::HashMap<String, (String, String, String)> {
    let mut out = std::collections::HashMap::new();
    for tag in tags.as_array().into_iter().flatten().filter_map(Value::as_array) {
        if tag.first().and_then(Value::as_str) != Some("imeta") {
            continue;
        }
        let (mut url, mut mime, mut alt, mut hash) = (String::new(), String::new(), String::new(), String::new());
        for part in tag.iter().skip(1).filter_map(Value::as_str) {
            let (k, v) = part.split_once(' ').unwrap_or((part, ""));
            match k {
                "url" => url = v.trim().to_string(),
                "m" => mime = v.trim().to_string(),
                "alt" => alt = v.trim().chars().take(1500).collect(),
                "blurhash" => hash = v.trim().chars().take(100).collect(),
                _ => {}
            }
        }
        if url.starts_with("https://") {
            out.insert(url, (mime, alt, hash));
        }
    }
    out
}

fn trim_url_tail(raw: &str) -> &str {
    raw.trim_end_matches(|c: char| ".,;:!?)]}\"'".contains(c))
}

enum Piece {
    Text(String),
    Link(String),
    Mention { label: String, href: String },
}

/// Split text into plain runs, https links and `nostr:` references.
fn tokenize(text: &str) -> Vec<Piece> {
    let mut out = Vec::new();
    let mut plain = String::new();
    let mut rest = text;
    while !rest.is_empty() {
        let next_http = rest.find("https://").or_else(|| rest.find("http://"));
        let next_nostr = rest.find("nostr:");
        let at = match (next_http, next_nostr) {
            (Some(a), Some(b)) => a.min(b),
            (Some(a), None) | (None, Some(a)) => a,
            (None, None) => {
                plain.push_str(rest);
                break;
            }
        };
        plain.push_str(&rest[..at]);
        rest = &rest[at..];
        let end = rest.find(|c: char| c.is_whitespace() || c == '<' || c == '>').unwrap_or(rest.len());
        let token = trim_url_tail(&rest[..end]);
        let consumed = token.len().max(1);
        if let Some(entity) = token.strip_prefix("nostr:") {
            match bech32::decode_entity(entity) {
                Some((hrp, hex)) => {
                    if !plain.is_empty() {
                        out.push(Piece::Text(std::mem::take(&mut plain)));
                    }
                    let label = match hrp.as_str() {
                        "npub" | "nprofile" => format!("@{}", short_npub(&bech32::npub(&hex))),
                        _ => "note".to_string(),
                    };
                    out.push(Piece::Mention { label, href: format!("{VIEWER}{entity}") });
                }
                None => plain.push_str(token),
            }
        } else if token.len() > 8 && !token.contains(['"', '\'']) {
            if !plain.is_empty() {
                out.push(Piece::Text(std::mem::take(&mut plain)));
            }
            out.push(Piece::Link(token.to_string()));
        } else {
            plain.push_str(&rest[..consumed]);
        }
        rest = &rest[consumed..];
    }
    if !plain.is_empty() {
        out.push(Piece::Text(plain));
    }
    out
}

fn text_html(s: &str) -> String {
    esc(s).replace('\n', "<br>")
}

/// `(content html, media attachments)` for one note.
pub fn content_and_media(status_id: &str, content: &str, tags: &Value) -> (String, Vec<Value>) {
    let meta = imeta(tags);
    let mut media = Vec::new();
    let mut media_urls = std::collections::HashSet::new();
    let mut html = String::new();
    for piece in tokenize(content.trim()) {
        match piece {
            Piece::Text(t) => html.push_str(&text_html(&t)),
            Piece::Mention { label, href } => html.push_str(&format!(
                "<a href=\"{}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\" class=\"u-url mention\">{}</a>",
                esc(&href),
                esc(&label)
            )),
            Piece::Link(url) => {
                let (mime, alt, hash) = meta.get(&url).cloned().unwrap_or_default();
                let kind = if url.starts_with("https://") { media_type(&url, &mime) } else { None };
                if let Some(kind) = kind {
                    if media.len() < MAX_MEDIA && media_urls.insert(url.clone()) {
                        media.push(json!({
                            "id": format!("{status_id}-m{}", media.len() + 1),
                            "type": kind,
                            "url": url,
                            "preview_url": if kind == "image" { Value::String(url.clone()) } else { Value::Null },
                            "remote_url": url,
                            "description": if alt.is_empty() { Value::Null } else { Value::String(alt) },
                            "blurhash": if hash.is_empty() { Value::Null } else { Value::String(hash) },
                        }));
                        continue;
                    }
                }
                let shown = url.trim_start_matches("https://").trim_start_matches("http://");
                let shown: String = if shown.chars().count() > 48 {
                    format!("{}…", shown.chars().take(47).collect::<String>())
                } else {
                    shown.to_string()
                };
                html.push_str(&format!(
                    "<a href=\"{}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">{}</a>",
                    esc(&url),
                    esc(&shown)
                ));
            }
        }
    }
    // Media links usually sit on their own lines; drop the breaks they leave.
    let mut html = html.trim().to_string();
    while html.ends_with("<br>") {
        html.truncate(html.len() - 4);
        html = html.trim_end().to_string();
    }
    while let Some(rest) = html.strip_prefix("<br>") {
        html = rest.trim_start().to_string();
    }
    let html = html.replace("<br><br><br>", "<br><br>");
    let body = if html.is_empty() { String::new() } else { format!("<p>{html}</p>") };
    (body, media)
}

fn content_warning(tags: &Value) -> Option<String> {
    tags.as_array()?
        .iter()
        .filter_map(Value::as_array)
        .find(|t| t.first().and_then(Value::as_str) == Some("content-warning"))
        .map(|t| t.get(1).and_then(Value::as_str).unwrap_or("").trim().chars().take(200).collect())
}

pub fn account(row: &NostrRow) -> Value {
    let npub = bech32::npub(&row.pubkey);
    let short = short_npub(&npub);
    let nip05 = row.nip05.trim().trim_start_matches("_@").to_string();
    let acct = if !nip05.is_empty() && nip05.contains('.') && !nip05.contains(char::is_whitespace) { nip05 } else { short.clone() };
    let display = [row.display_name.trim(), row.name.trim()].into_iter().find(|s| !s.is_empty()).unwrap_or(&short).to_string();
    let username = if row.name.trim().is_empty() { short.clone() } else { row.name.trim().to_string() };
    let avatar = if row.picture.starts_with("https://") { row.picture.as_str() } else { DEFAULT_AVATAR };
    json!({
        "id": format!("nostr:{}", row.pubkey),
        "username": username,
        "acct": acct,
        "display_name": display,
        "url": format!("{VIEWER}{npub}"),
        // Not https on purpose: the card must not open a fediverse profile.
        "uri": format!("nostr:{npub}"),
        "avatar": avatar,
        "avatar_static": avatar,
        "header": "",
        "header_static": "",
        "note": "Nostr",
        "bot": false,
        "locked": false,
        "followers_count": 0,
        "following_count": 0,
        "statuses_count": 0,
        "emojis": [],
        "fields": [],
    })
}

fn iso_time(ts: i64) -> String {
    chrono::DateTime::from_timestamp(ts, 0)
        .unwrap_or_else(chrono::Utc::now)
        .format("%Y-%m-%dT%H:%M:%S.000Z")
        .to_string()
}

pub fn materialize(row: &NostrRow) -> Value {
    let status_id = format!("nostr:{}", row.id);
    let note = bech32::note(&row.id);
    let url = format!("{VIEWER}{note}");
    let (content, media) = content_and_media(&status_id, &row.content, &row.tags);
    let mut st = vaak_types::TimelineStatus::base(&status_id, &iso_time(row.created_at), &content, &format!("nostr:{note}"), &url, account(row));
    let (typed_media, bad) = vaak_types::TimelineStatus::media_from_values(media);
    for e in bad {
        tracing::warn!(id = %row.id, error = %e, "nostr media dropped: not a timeline shape");
    }
    st.media_attachments = typed_media;
    if let Some(reason) = content_warning(&row.tags) {
        st.sensitive = true;
        st.spoiler_text = if reason.is_empty() { "Content warning".into() } else { reason };
    }
    let mut v = st.to_value();
    v["application"] = json!({"name": "Nostr", "website": Value::Null});
    v["source"] = json!("nostr");
    v["vaak_nostr_event_id"] = json!(row.id);
    v["vaak_nostr_pubkey"] = json!(row.pubkey);
    v
}

pub fn is_nostr_status(st: &Value) -> bool {
    st.get("source").and_then(Value::as_str) == Some("nostr")
        || st.get("id").and_then(Value::as_str).is_some_and(|id| id.starts_with("nostr:"))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(content: &str, tags: Value) -> NostrRow {
        NostrRow {
            id: "0".repeat(63) + "1",
            pubkey: "7e7e9c42a91bfef19fa929e5fda1b72e0ebc1a4c1141673e2794234d86addf4e".into(),
            created_at: 1_800_000_000,
            content: content.into(),
            tags,
            ..Default::default()
        }
    }

    #[test]
    fn text_links_media_and_mentions() {
        let content = "gm <b>&\nhttps://example.com/a.jpg\nread https://example.com/post?x=1). and nostr:npub10elfcs4fr0l0r8af98jlmgdh9c8tcxjvz9qkw038js35mp4dma8qzvjptg\nnostr:nsecbogus";
        let tags = json!([["imeta", "url https://example.com/a.jpg", "m image/jpeg", "alt a cat"]]);
        let (html, media) = content_and_media("nostr:x", content, &tags);
        assert!(html.starts_with("<p>gm &lt;b&gt;&amp;<br>"), "{html}");
        assert!(html.contains("<a href=\"https://example.com/post?x=1\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">example.com/post?x=1</a>)."), "{html}");
        assert!(html.contains("href=\"https://njump.me/npub10elfcs4fr0l0r8af98jlmgdh9c8tcxjvz9qkw038js35mp4dma8qzvjptg\""), "{html}");
        assert!(html.contains(">@npub10elfc…zvjptg</a>"), "{html}");
        assert!(html.contains("nostr:nsecbogus"), "{html}");
        assert!(!html.contains("a.jpg"), "{html}");
        assert_eq!(media.len(), 1);
        assert_eq!(media[0]["type"], "image");
        assert_eq!(media[0]["description"], "a cat");
    }

    #[test]
    fn javascript_and_quotes_never_become_links() {
        let (html, media) = content_and_media("s", "x javascript:alert(1) https://a.example/\"onmouseover=\"y", &json!([]));
        assert!(!html.contains("href=\"javascript"), "{html}");
        assert!(!html.contains("\"onmouseover"), "{html}");
        assert!(media.is_empty());
    }

    #[test]
    fn status_shape_and_content_warning() {
        let mut r = row("hello", json!([["content-warning", "spoilers"]]));
        r.display_name = "Nova".into();
        r.nip05 = "_@mkultra.monster".into();
        r.picture = "https://img.example/a.png".into();
        let st = materialize(&r);
        assert_eq!(st["id"], format!("nostr:{}", r.id));
        assert_eq!(st["source"], "nostr");
        assert!(st["url"].as_str().unwrap().starts_with("https://njump.me/note1"));
        assert!(st["uri"].as_str().unwrap().starts_with("nostr:note1"));
        assert_eq!(st["spoiler_text"], "spoilers");
        assert_eq!(st["sensitive"], true);
        assert_eq!(st["account"]["display_name"], "Nova");
        assert_eq!(st["account"]["acct"], "mkultra.monster");
        assert_eq!(st["account"]["avatar"], "https://img.example/a.png");
        assert!(!st["account"]["uri"].as_str().unwrap().starts_with("https://"));
        assert!(is_nostr_status(&st));
        assert!(vaak_types::check_timeline_status(&st).is_empty(), "{:?}", vaak_types::check_timeline_status(&st));
    }

    #[test]
    fn missing_profile_falls_back_to_short_npub() {
        let st = materialize(&row("x", json!([])));
        assert_eq!(st["account"]["display_name"], "npub10elfc…zvjptg");
        assert_eq!(st["account"]["acct"], "npub10elfc…zvjptg");
        assert_eq!(st["account"]["avatar"], DEFAULT_AVATAR);
        assert_eq!(st["created_at"], "2027-01-15T08:00:00.000Z");
    }

    #[test]
    fn home_card_signs_through_the_extension_and_opens_on_nostr() {
        let mut r = row("hello nostr", json!([["content-warning", "spoilers"]]));
        r.display_name = "Alice".into();
        let st = materialize(&r);
        let html = crate::notif_embed::paint_lean_embed_from(&st, false, "home", "", true);
        let njump = st["url"].as_str().unwrap();
        assert!(njump.starts_with("https://njump.me/note1"), "{njump}");
        assert!(html.contains("Open on Nostr"), "{html}");
        assert!(html.contains(&format!("href=\"{njump}\" target=\"_blank\"")), "{html}");
        assert!(!html.contains("data-eng=\"1\""), "no VAAK engagement buttons: {html}");
        assert!(html.contains(&format!("data-nostr-act=\"reply\" data-nostr-target=\"{}\"", r.id)), "{html}");
        assert!(html.contains(&format!("data-nostr-act=\"like\" data-nostr-target=\"{}\"", r.id)), "{html}");
        assert!(html.contains(&format!("data-nostr-act=\"unfollow\" data-nostr-target=\"{}\"", r.pubkey)), "{html}");
        assert!(!html.contains("ph-repeat") && !html.contains("ph-quotes"), "no boost or quote on Nostr: {html}");
        assert!(!html.contains("compose=1"), "no reply or quote links: {html}");
        assert!(!html.contains("view=status"), "links stay off the in-app status view: {html}");
        assert!(html.contains(&format!("data-copy-value=\"{njump}\"")), "copy gives the njump link: {html}");
        assert!(html.contains("spoilers"), "{html}");
        assert!(html.contains("Alice"), "{html}");

        let mut liked = st.clone();
        liked["vaak_nostr_liked"] = json!(true);
        liked["vaak_nostr_following"] = json!(false);
        let html = crate::notif_embed::paint_lean_embed_from(&liked, false, "home", "", true);
        assert!(html.contains("aria-pressed=\"true\" title=\"Unlike on Nostr\""), "{html}");
        assert!(html.contains(">Follow on Nostr</button>"), "{html}");
    }
}
