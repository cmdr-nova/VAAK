//! Projected Mastodon-status subset matching PHP normalize fixtures.

use serde::{Deserialize, Serialize};

/// Where a status originated in VAAK's dual pipeline.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize, Default)]
#[serde(rename_all = "lowercase")]
pub enum StatusSource {
    #[default]
    #[serde(alias = "")]
    ActivityPub,
    Bluesky,
    Rss,
    #[serde(other)]
    Other,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize, Default)]
pub struct NormalizedAccount {
    pub acct: String,
    #[serde(default)]
    pub username: String,
    #[serde(default)]
    pub display_name: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize, Default)]
pub struct MediaProjection {
    #[serde(default)]
    pub r#type: String,
    #[serde(default)]
    pub url: String,
}

/// Freeze-contract projection of a normalized status.
/// Matches `api/fixtures/normalize/*/expected.json`.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize, Default)]
pub struct NormalizedStatusProjection {
    pub uri: String,
    #[serde(default)]
    pub content_plain: String,
    #[serde(default)]
    pub sensitive: bool,
    #[serde(default)]
    pub spoiler_text: String,
    /// Empty string in some AP fixtures; Bluesky/RSS set explicitly.
    #[serde(default)]
    pub source: String,
    #[serde(default)]
    pub account: NormalizedAccount,
    #[serde(default)]
    pub media: Vec<MediaProjection>,
    #[serde(default)]
    pub has_visible_body: bool,
    #[serde(default)]
    pub has_ask: bool,
    #[serde(default)]
    pub ask_question: String,
    #[serde(default)]
    pub has_quote: bool,
    #[serde(default)]
    pub has_reblog: bool,
    #[serde(default)]
    pub vaak_rss_feed_id: Option<i64>,
    #[serde(default)]
    pub vaak_degraded: bool,
    #[serde(default)]
    pub vaak_degraded_reason: String,
}

impl NormalizedStatusProjection {
    pub fn is_bluesky(&self) -> bool {
        self.source.eq_ignore_ascii_case("bluesky")
    }

    pub fn is_rss(&self) -> bool {
        self.source.eq_ignore_ascii_case("rss") || self.vaak_rss_feed_id.is_some()
    }

    pub fn has_media(&self) -> bool {
        self.media.iter().any(|m| !m.url.is_empty())
    }
}
