//! Typed Mastodon-shaped status that every Rust timeline paints from.
//!
//! Home, Local, Federated and profiles each build statuses from different
//! tables. When one builder forgot a field (Bluesky media, a remote poll, the
//! media inside a quote) nothing failed: the card just painted without it.
//! The content-bearing fields are typed here so a builder has to fill them,
//! and `TimelineStatus::contract_violations` lets tests check what a builder
//! produced. VAAK-specific keys (`vaak_*`, `source`, …) ride in `extra`.

use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct MediaAttachment {
    pub id: String,
    /// image, gifv, video, audio or unknown.
    #[serde(rename = "type")]
    pub kind: String,
    pub url: String,
    #[serde(default)]
    pub preview_url: Option<String>,
    #[serde(default)]
    pub remote_url: Option<String>,
    #[serde(default)]
    pub description: Option<String>,
    #[serde(default)]
    pub blurhash: Option<String>,
    #[serde(flatten)]
    pub extra: Map<String, Value>,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct PollOption {
    #[serde(alias = "name")]
    pub title: String,
    #[serde(default)]
    pub votes_count: i64,
}

/// Remote polls (remote_polls.id) share the poll id space with masto_polls
/// local ids, offset by this base; PHP `ap_remote_poll_id_base` matches.
pub const REMOTE_POLL_ID_BASE: i64 = 900_000_000_000;

/// `id` is the local masto_polls id, or REMOTE_POLL_ID_BASE + remote_polls.id
/// for a remote poll, as a string; "0" marks a display-only poll (results
/// shown, no vote form). `own_votes` are the viewer's option indexes.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Poll {
    pub id: String,
    pub expired: bool,
    pub multiple: bool,
    pub votes_count: i64,
    pub voters_count: i64,
    pub voted: bool,
    #[serde(default, skip_serializing_if = "Vec::is_empty")]
    pub own_votes: Vec<i64>,
    #[serde(default)]
    pub expires_label: String,
    pub options: Vec<PollOption>,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct PreviewCard {
    pub url: String,
    #[serde(default)]
    pub title: String,
    #[serde(default)]
    pub description: String,
    #[serde(flatten)]
    pub extra: Map<String, Value>,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Quote {
    #[serde(default)]
    pub quoted_status: Option<Box<TimelineStatus>>,
    #[serde(flatten)]
    pub extra: Map<String, Value>,
}

/// No `Default`: a builder names every field, so adding one here is a compile
/// error in every builder until it decides what to put there.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct TimelineStatus {
    pub id: String,
    pub created_at: String,
    pub in_reply_to_id: Option<String>,
    pub in_reply_to_account_id: Option<String>,
    pub sensitive: bool,
    pub spoiler_text: String,
    pub visibility: String,
    pub language: Option<String>,
    pub uri: String,
    pub url: String,
    pub replies_count: i64,
    pub reblogs_count: i64,
    pub favourites_count: i64,
    pub edited_at: Option<String>,
    pub favourited: bool,
    pub reblogged: bool,
    pub muted: bool,
    pub bookmarked: bool,
    pub pinned: bool,
    pub content: String,
    pub reblog: Option<Box<TimelineStatus>>,
    pub application: Option<Value>,
    pub account: Value,
    pub media_attachments: Vec<MediaAttachment>,
    pub mentions: Vec<Value>,
    pub tags: Vec<Value>,
    pub emojis: Vec<Value>,
    pub card: Option<PreviewCard>,
    pub poll: Option<Poll>,
    pub quote: Option<Quote>,
    pub quote_approval: Value,
    #[serde(flatten)]
    pub extra: Map<String, Value>,
}

impl TimelineStatus {
    /// Public, unreplied, uninteracted status. The one place a status is
    /// built field by field; every timeline builder starts here.
    pub fn base(id: &str, created_at: &str, content: &str, uri: &str, url: &str, account: Value) -> Self {
        TimelineStatus {
            id: id.to_string(),
            created_at: created_at.to_string(),
            in_reply_to_id: None,
            in_reply_to_account_id: None,
            sensitive: false,
            spoiler_text: String::new(),
            visibility: "public".into(),
            language: None,
            uri: uri.to_string(),
            url: url.to_string(),
            replies_count: 0,
            reblogs_count: 0,
            favourites_count: 0,
            edited_at: None,
            favourited: false,
            reblogged: false,
            muted: false,
            bookmarked: false,
            pinned: false,
            content: content.to_string(),
            reblog: None,
            application: None,
            account,
            media_attachments: Vec::new(),
            mentions: Vec::new(),
            tags: Vec::new(),
            emojis: Vec::new(),
            card: None,
            poll: None,
            quote: None,
            quote_approval: serde_json::json!({
                "automatic": ["public"],
                "manual": [],
                "current_user": "automatic"
            }),
            extra: Map::new(),
        }
    }

    /// Typed media from builder JSON; entries that are not a media shape
    /// are returned as errors instead of vanishing.
    pub fn media_from_values(values: Vec<Value>) -> (Vec<MediaAttachment>, Vec<serde_json::Error>) {
        let mut ok = Vec::new();
        let mut bad = Vec::new();
        for v in values {
            match serde_json::from_value(v) {
                Ok(m) => ok.push(m),
                Err(e) => bad.push(e),
            }
        }
        (ok, bad)
    }

    /// Typed view of a status a builder produced as JSON.
    pub fn from_value(value: &Value) -> Result<Self, serde_json::Error> {
        serde_json::from_value(value.clone())
    }

    pub fn to_value(&self) -> Value {
        serde_json::to_value(self).unwrap_or(Value::Null)
    }

    /// Shape problems a painter would silently drop, including nested
    /// reblogs and quotes. Empty means the status is safe to paint.
    pub fn contract_violations(&self) -> Vec<String> {
        check_value(&self.to_value())
    }
}

/// The same contract for a status JSON any builder produced, typed or not.
/// Library, search and notifications build partial shapes, so only the
/// fields a status carries are checked: media, poll, card, reblog, quote.
pub fn check_value(status: &Value) -> Vec<String> {
    let mut out = Vec::new();
    collect_violations(status, "", &mut out);
    out
}

fn collect_violations(st: &Value, path: &str, out: &mut Vec<String>) {
    let at = |field: &str| format!("{path}{field}");
    let id_empty = match st.get("id") {
        Some(Value::String(id)) => id.trim().is_empty(),
        Some(Value::Number(_)) => false,
        _ => true,
    };
    if id_empty {
        out.push(at("id is empty"));
    }
    match st.get("media_attachments") {
        None | Some(Value::Null) => {}
        Some(Value::Array(media)) => {
            for (i, m) in media.iter().enumerate() {
                let url = m.get("url").and_then(Value::as_str).unwrap_or("");
                if !url.starts_with("https://") {
                    out.push(at(&format!("media_attachments[{i}].url is not https: {url}")));
                }
                let kind = m.get("type").and_then(Value::as_str).unwrap_or("");
                if !matches!(kind, "image" | "gifv" | "video" | "audio" | "unknown") {
                    out.push(at(&format!("media_attachments[{i}].type is {kind}")));
                }
            }
        }
        Some(_) => out.push(at("media_attachments is not a list")),
    }
    if let Some(poll) = st.get("poll").filter(|p| !p.is_null()) {
        let titled = poll.get("options").and_then(Value::as_array).is_some_and(|opts| {
            opts.iter().any(|o| {
                o.get("title").or_else(|| o.get("name")).and_then(Value::as_str).is_some_and(|t| !t.trim().is_empty())
            })
        });
        if !titled {
            out.push(at("poll has no options"));
        }
    }
    if let Some(card) = st.get("card").filter(|c| !c.is_null()) {
        let url = card.get("url").and_then(Value::as_str).unwrap_or("");
        if !url.starts_with("http") && card.get("vaak_collection_kind").is_none() {
            out.push(at(&format!("card.url is not a link: {url}")));
        }
    }
    if let Some(reblog) = st.get("reblog").filter(|r| r.is_object()) {
        collect_violations(reblog, &format!("{path}reblog."), out);
    }
    if let Some(quoted) = st.get("quote").and_then(|q| q.get("quoted_status")).filter(|q| q.is_object()) {
        collect_violations(quoted, &format!("{path}quote."), out);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn sample() -> Value {
        json!({
            "id": "1", "created_at": "2026-10-19T05:00:00.000Z",
            "in_reply_to_id": null, "in_reply_to_account_id": null,
            "sensitive": false, "spoiler_text": "", "visibility": "public", "language": null,
            "uri": "https://social.example/notes/1", "url": "https://social.example/notes/1",
            "replies_count": 0, "reblogs_count": 0, "favourites_count": 0, "edited_at": null,
            "favourited": false, "reblogged": false, "muted": false, "bookmarked": false, "pinned": false,
            "content": "<p>hi</p>", "reblog": null, "application": null, "account": {"id": "a"},
            "media_attachments": [{"id": "1#media-1", "type": "image", "url": "https://cdn.example/a.jpg",
                "preview_url": "https://cdn.example/a.jpg", "remote_url": "https://cdn.example/a.jpg",
                "description": "", "blurhash": null}],
            "mentions": [], "tags": [], "emojis": [], "card": null,
            "poll": {"id": "0", "expired": false, "multiple": false, "votes_count": 3, "voters_count": 3,
                "voted": false, "expires_label": "", "options": [{"title": "a", "votes_count": 3}]},
            "quote": null, "quote_approval": {"automatic": ["public"]},
            "source": "activitypub", "vaak_tl_cursor": "x"
        })
    }

    #[test]
    fn round_trips_without_losing_vaak_keys() {
        let v = sample();
        let st = TimelineStatus::from_value(&v).expect("parses");
        assert_eq!(st.to_value(), v);
        assert!(st.contract_violations().is_empty());
    }

    #[test]
    fn flags_shapes_a_painter_would_drop() {
        let mut v = sample();
        v["media_attachments"][0]["url"] = json!("at://did:plc:x/blob");
        v["poll"]["options"] = json!([{"title": " "}]);
        v["quote"] = json!({"quoted_status": sample()});
        v["quote"]["quoted_status"]["media_attachments"][0]["type"] = json!("picture");
        let st = TimelineStatus::from_value(&v).expect("parses");
        let bad = st.contract_violations();
        assert_eq!(bad.len(), 3, "{bad:?}");
        assert!(bad[2].starts_with("quote.media_attachments[0].type"), "{bad:?}");
    }

    #[test]
    fn checks_partial_shapes_from_other_builders() {
        let partial = json!({"id": 7, "content": "x",
            "media_attachments": [{"type": "image", "url": "https://cdn.example/a.jpg"}],
            "quote": {"quoted_status": {"id": "q", "media_attachments": [{"type": "image", "url": ""}]}}});
        let bad = check_value(&partial);
        assert_eq!(bad, vec!["quote.media_attachments[0].url is not https: ".to_string()]);
        assert!(check_value(&json!({"id": "1", "media_attachments": {}})).len() == 1);
    }

    #[test]
    fn rejects_a_poll_without_options() {
        let mut v = sample();
        v["poll"] = json!({"id": "0", "expired": false});
        assert!(TimelineStatus::from_value(&v).is_err());
    }
}
