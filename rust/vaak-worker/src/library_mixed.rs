//! Native source-order projection for the *actual* mixed Bookmarks page.
//!
//! This is a shadow building block, not a card renderer or permission grant.
//! Source rows remain private to their owner. PHP still resolves/enriches their
//! content, checks capabilities, renders cards and executes authenticated writes.
//! In particular, do not use public Profile visibility policy for saved posts.

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use serde_json::Value;

/// Matches admin_bookmark_mixed_fragment, including uncached Bluesky stubs.
/// The third parameter is the canonical folder URI set, not all cache rows.
pub const MIXED_KEYS_SQL: &str = r#"
SELECT kind, ref FROM (
 SELECT 'fedi' AS kind, b.created_at::timestamptz AS ts, b.id::text AS ref
 FROM masto_bookmarks b
 WHERE b.owner_user_id::bigint = $1
 AND b.status_id NOT LIKE 'bsky:%'
 AND b.object_id NOT LIKE 'at://%'
 AND b.object_id NOT LIKE '%bsky.app/%'
 AND ($2::bigint = 0 OR EXISTS (
   SELECT 1 FROM vaak_bookmark_folder_items fi
   WHERE fi.folder_id::bigint = $2 AND fi.owner_user_id = b.owner_user_id
   AND (fi.status_id = b.status_id OR fi.status_id = b.object_id)))
 UNION ALL
 SELECT 'fedi' AS kind, b.created_at::timestamptz AS ts, b.id::text AS ref
 FROM masto_bookmarks b
 WHERE b.owner_user_id::bigint = $1
 AND (b.status_id LIKE 'bsky:%' OR b.object_id LIKE 'at://%' OR b.object_id LIKE '%bsky.app/%')
 AND ($2::bigint = 0 OR EXISTS (
   SELECT 1 FROM vaak_bookmark_folder_items fi
   WHERE fi.folder_id::bigint = $2 AND fi.owner_user_id = b.owner_user_id
   AND (fi.status_id = b.status_id OR fi.status_id = b.object_id)))
 AND NOT EXISTS (
   SELECT 1 FROM bsky_bookmark_cache c
   WHERE c.owner_user_id = b.owner_user_id
   AND (c.bookmark_uri = b.object_id OR c.bookmark_uri = regexp_replace(
     b.object_id, '^https://bsky\.app/profile/(did:[^/]+)/post/([A-Za-z0-9._~:-]+).*$',
     'at://\1/app.bsky.feed.post/\2')))
 UNION ALL
 SELECT 'bsky' AS kind, c.bookmarked_at::timestamptz AS ts, c.bookmark_uri AS ref
 FROM bsky_bookmark_cache c
 WHERE c.owner_user_id::bigint = $1
 AND ($2::bigint = 0 OR c.bookmark_uri = ANY($3::text[]))
) mixed ORDER BY ts DESC, ref DESC OFFSET $4 LIMIT $5
"#;

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct MixedKey {
    pub kind: String,
    pub reference: String,
}

#[derive(Debug, Serialize)]
pub struct MixedProjection {
    pub owner_id: i64,
    pub folder_id: i64,
    pub keys: Vec<MixedKey>,
    /// Raw rows for canonical PHP interaction normalization; never public statuses.
    pub fedi_rows: Vec<Value>,
    pub bsky_rows: Vec<Value>,
    pub offset: i64,
    pub next_offset: i64,
    pub limit: i64,
    pub has_more: bool,
    pub source: &'static str,
    pub promotion_allowed: bool,
    pub remaining_boundaries: &'static [&'static str],
}

/// PHP advances by selected source keys, even when a missing/malformed status
/// produces no card. Advancing by painted cards repeats or skips rows.
pub fn slice_source_keys(mut keys: Vec<MixedKey>, offset: i64, limit: i64) -> (Vec<MixedKey>, i64, bool) {
    let offset = offset.max(0);
    let limit = limit.clamp(10, 40) as usize;
    let has_more = keys.len() > limit;
    keys.truncate(limit);
    let next_offset = offset + keys.len() as i64;
    (keys, next_offset, has_more)
}

/// Same DID-only HTTPS conversion as admin_bookmark_bsky_at_uri. Handle URLs
/// intentionally require PHP's existing resolution boundary, not guessed DIDs.
pub fn bsky_at_uri(reference: &str) -> Option<String> {
    let reference = reference.trim();
    if reference.starts_with("at://") {
        return Some(reference.to_owned());
    }
    let re = regex::Regex::new(r"^https://bsky\.app/profile/(did:[^/]+)/post/([A-Za-z0-9._~:-]+)").unwrap();
    let c = re.captures(reference)?;
    Some(format!("at://{}/app.bsky.feed.post/{}", &c[1], &c[2]))
}

pub async fn project(cfg: &crate::config::Config, owner_id: i64, folder_id: i64, offset: i64, limit: i64) -> Result<MixedProjection> {
    if owner_id < 1 { anyhow::bail!("owner_id must be positive"); }
    let folder_id = folder_id.max(0);
    let offset = offset.max(0);
    let limit = limit.clamp(10, 40);
    let db = crate::db::connect(&cfg.database_url).await.context("connect mixed Library projection")?;
    let mut folder_uris = Vec::<String>::new();
    if folder_id > 0 {
        let rows = db.query(
            "SELECT mb.object_id FROM vaak_bookmark_folder_items fi
             JOIN masto_bookmarks mb ON mb.owner_user_id = fi.owner_user_id AND mb.status_id = fi.status_id
             WHERE fi.folder_id::bigint = $1 AND fi.owner_user_id::bigint = $2 AND mb.status_id LIKE 'bsky:%'",
            &[&folder_id, &owner_id],
        ).await.context("read owner folder Bluesky aliases")?;
        for row in rows {
            if let Some(uri) = bsky_at_uri(&row.get::<_, Option<String>>(0).unwrap_or_default()) {
                if !folder_uris.contains(&uri) { folder_uris.push(uri); }
            }
        }
    }
    let keys = db.query(MIXED_KEYS_SQL, &[&owner_id, &folder_id, &folder_uris, &offset, &(limit + 1)])
        .await.context("read chronological mixed bookmark keys")?
        .into_iter().map(|row| MixedKey { kind: row.get(0), reference: row.get(1) }).collect();
    let (keys, next_offset, has_more) = slice_source_keys(keys, offset, limit);
    let fedi_ids: Vec<i64> = keys.iter().filter(|k| k.kind == "fedi").filter_map(|k| k.reference.parse().ok()).collect();
    let bsky_uris: Vec<String> = keys.iter().filter(|k| k.kind == "bsky").map(|k| k.reference.clone()).collect();
    let fedi_rows = db.query(
        "SELECT to_jsonb(b) FROM masto_bookmarks b WHERE b.owner_user_id::bigint = $1 AND b.id::bigint = ANY($2::bigint[])",
        &[&owner_id, &fedi_ids],
    ).await.context("read owner mixed bookmark rows")?.into_iter().map(|row| row.get(0)).collect();
    let mut bsky_rows = Vec::new();
    for row in db.query(
        "SELECT bookmark_uri, post_json FROM bsky_bookmark_cache WHERE owner_user_id::bigint = $1 AND bookmark_uri = ANY($2::text[])",
        &[&owner_id, &bsky_uris],
    ).await.context("read owner mixed Bluesky bookmark rows")? {
        let reference: String = row.get(0);
        let raw: Option<String> = row.get(1);
        if let Some(item) = bookmarked_bsky_item(raw.as_deref().unwrap_or("")) {
            bsky_rows.push(serde_json::json!({"reference": reference, "item": item}));
        }
    }
    Ok(MixedProjection { owner_id, folder_id, keys, fedi_rows, bsky_rows, offset, next_offset, limit, has_more,
        source: "rust-mixed-library-shadow", promotion_allowed: false,
        remaining_boundaries: &["signed thin-status enrichment", "owner-aware saved-status access", "RSS and Bluesky dedicated card renderers", "settings/capabilities and action authorization/effects", "native card differential verification"] })
}

// json_decode(..., true) considers both JSON lists and objects PHP arrays.
// Adding a named key to a list turns its JSON encoding into a keyed object.
fn php_array_object(value: &mut Value) -> Option<&mut serde_json::Map<String, Value>> {
    if let Value::Array(items) = value {
        let map = std::mem::take(items).into_iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect();
        *value = Value::Object(map);
    }
    value.as_object_mut()
}

pub fn bookmarked_bsky_item(raw: &str) -> Option<Value> {
    let mut item: Value = serde_json::from_str(raw).ok()?;
    let post = item.get_mut("post")?;
    let post = php_array_object(post)?;
    let viewer = post.entry("viewer").or_insert_with(|| serde_json::json!({}));
    if !viewer.is_object() && !viewer.is_array() { *viewer = serde_json::json!({}); }
    php_array_object(viewer)?.insert("bookmarked".into(), Value::Bool(true));
    Some(item)
}

/// Native mixed-fragment chrome. Cards are deliberately supplied by a separate
/// renderer contract. This function alone makes no native-card parity claim.
pub fn mixed_markup(cards: &[String], next_offset: i64, limit: i64, has_more: bool, folder_id: i64, first_page: bool) -> String {
    let mut html = format!("<div data-bookmark-fragment data-offset=\"{next_offset}\" data-limit=\"{limit}\" data-has-more=\"{}\">", u8::from(has_more));
    if cards.is_empty() && first_page {
        let message = if folder_id > 0 { "No bookmarks in this folder." } else { "No bookmarks yet." };
        html.push_str(&format!("<div class=\"empty\" data-bookmark-empty>{message}</div>"));
    } else {
        for card in cards { html.push_str(card); }
    }
    html.push_str("</div>");
    html
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Network { Fedi, Bsky }

/// Exact network Bookmarks chrome from admin_bookmark_fragment_markup. The
/// caller preserves one card slot for each selected valid item, even if the
/// eventual card is empty. Empty-state decisions concern items, not HTML size.
pub fn bookmark_network_markup(network: Network, cards: &[String], next_offset: i64, limit: i64, has_more: bool, folder_id: i64, first_page: bool, refreshing: bool) -> String {
    let bsky = matches!(network, Network::Bsky);
    let prefix = if bsky { "bsky" } else { "fedi" };
    let mut html = format!("<div data-{prefix}-bookmark-fragment data-offset=\"{next_offset}\" data-limit=\"{limit}\" data-has-more=\"{}\"{}>", u8::from(has_more), if bsky { " data-loaded=\"1\"" } else { "" });
    if cards.is_empty() {
        if first_page {
            let message = match (bsky, folder_id > 0, refreshing) {
                (true, true, _) => "No Bluesky bookmarks in this folder.",
                (false, true, _) => "No Fediverse bookmarks in this folder.",
                (true, false, true) => "Bluesky bookmarks are refreshing in the background. Reload shortly.",
                (true, false, false) => "No cached Bluesky bookmarks yet. They will appear after the background sync completes.",
                (false, false, _) => "No Fediverse bookmarks yet.",
            };
            html.push_str(&format!("<div class=\"empty\" data-{prefix}-bookmark-empty>{message}</div>"));
        }
    } else {
        if first_page {
            if bsky && refreshing { html.push_str("<p class=\"meta\">Showing cached Bluesky bookmarks while they refresh in the background.</p>"); }
            let network_name = if bsky { "Bluesky" } else { "Fediverse" };
            html.push_str(&format!("<h3 style=\"font-size:.95rem;color:var(--muted);margin:0 0 .5rem\">{network_name} bookmarks</h3>"));
        }
        for card in cards { html.push_str(card); }
    }
    html.push_str("</div>");
    html
}

/// Exact AJAX Favourites chrome. Unlike network Bookmarks, Fediverse
/// Favourites renders neither a heading nor a first-page empty explanation.
pub fn favourite_network_markup(network: Network, cards: &[String], next_offset: i64, limit: i64, has_more: bool, first_page: bool, refreshing: bool) -> String {
    let bsky = matches!(network, Network::Bsky);
    let prefix = if bsky { "bsky" } else { "fedi" };
    let mut html = format!("<div data-{prefix}-favourites-fragment data-offset=\"{next_offset}\" data-limit=\"{limit}\" data-has-more=\"{}\"{}>", u8::from(has_more), if bsky { " data-loaded=\"1\"" } else { "" });
    if bsky && cards.is_empty() && first_page {
        let message = if refreshing { "Bluesky favourites are refreshing in the background. Reload shortly." } else { "No cached Bluesky favourites yet. They will appear after the background sync completes." };
        html.push_str(&format!("<div class=\"empty\" data-bsky-favourite-empty>{message}</div>"));
    } else {
        if bsky && first_page {
            if refreshing { html.push_str("<p class=\"meta\">Showing cached Bluesky favourites while they refresh in the background.</p>"); }
            html.push_str("<h3 style=\"font-size:.95rem;color:var(--muted);margin:0 0 .5rem\">Bluesky favourites</h3>");
        }
        for card in cards { html.push_str(card); }
    }
    html.push_str("</div>");
    html
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn mixed_cursor_counts_source_keys_not_cards() {
        let keys = (0..11).map(|i| MixedKey { kind: "bsky".into(), reference: format!("missing-{i}") }).collect();
        let (keys, next, more) = slice_source_keys(keys, 20, 10);
        assert_eq!(keys.len(), 10); assert_eq!(next, 30); assert!(more);
        assert_eq!(mixed_markup(&[], next, 10, more, 0, false), "<div data-bookmark-fragment data-offset=\"30\" data-limit=\"10\" data-has-more=\"1\"></div>");
    }
    #[test]
    fn empty_first_page_and_folder_match_php() {
        assert_eq!(mixed_markup(&[], 0, 20, false, 0, true), "<div data-bookmark-fragment data-offset=\"0\" data-limit=\"20\" data-has-more=\"0\"><div class=\"empty\" data-bookmark-empty>No bookmarks yet.</div></div>");
        assert!(mixed_markup(&[], 0, 20, false, 8, true).contains("No bookmarks in this folder."));
        assert!(!mixed_markup(&[], 40, 20, false, 8, false).contains("empty"));
    }
    #[test]
    fn preserves_order_and_action_markup_without_rewriting() {
        let cards = vec!["<form data-owner=\"2\">csrf-sensitive</form>".into(), "<article>second</article>".into()];
        let html = mixed_markup(&cards, 2, 20, false, 0, true);
        assert!(html.contains("csrf-sensitive</form><article>second</article>"));
    }
    #[test]
    fn bsky_alias_conversion_is_did_only_and_matches_php_prefix() {
        assert_eq!(bsky_at_uri(" https://bsky.app/profile/did:plc:alice/post/key?x=1 ").as_deref(), Some("at://did:plc:alice/app.bsky.feed.post/key"));
        assert_eq!(bsky_at_uri("at://did:plc:a/app.bsky.feed.post/k ").as_deref(), Some("at://did:plc:a/app.bsky.feed.post/k"));
        assert_eq!(bsky_at_uri("https://bsky.app/profile/alice.example/post/key"), None);
        assert_eq!(bsky_at_uri("https://other.example/profile/did:plc:alice/post/key"), None);
    }
    #[test]
    fn malformed_bsky_is_skipped_but_php_array_shapes_are_preserved() {
        assert!(bookmarked_bsky_item("").is_none());
        assert!(bookmarked_bsky_item("null").is_none());
        assert!(bookmarked_bsky_item("{\"post\":\"invalid\"}").is_none());
        assert_eq!(bookmarked_bsky_item("{\"post\":[]}").unwrap(), serde_json::json!({"post":{"viewer":{"bookmarked":true}}}));
        assert_eq!(bookmarked_bsky_item("{\"post\":{\"viewer\":[\"keep\"]}}").unwrap(), serde_json::json!({"post":{"viewer":{"0":"keep","bookmarked":true}}}));
    }
    #[test]
    fn network_and_mixed_envelopes_match_actual_php_reference_functions() {
        let reference = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/migration-php-reference-smoke.php");
        let output = std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).arg(reference).arg("--json").output().expect("PHP required for differential Library test");
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        let fixture: Value = serde_json::from_slice(&output.stdout).unwrap();
        let envelopes = fixture["bookmark_envelopes"].as_object().unwrap();
        for (name, expected) in envelopes {
            let actual = if name == "mixed:populated" {
                mixed_markup(&["<fixture-fedi id=\"remote-id\" view=\"bookmarks\"></fixture-fedi>".into(), "<fixture-bsky></fixture-bsky>".into()], 23, 20, true, 0, false)
            } else {
                let parts: Vec<_> = name.split(':').collect();
                let network = if parts[0] == "bsky" { Network::Bsky } else { Network::Fedi };
                let folder_id: i64 = parts[1].strip_prefix("folder=").unwrap().parse().unwrap();
                let refreshing = parts[2] == "refresh=1";
                bookmark_network_markup(network, &[], 20, 20, false, folder_id, true, refreshing)
            };
            assert_eq!(actual, expected.as_str().unwrap(), "PHP envelope {name}");
        }
    }
}
