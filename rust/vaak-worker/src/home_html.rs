//! Timeline fill HTML from warm hydrate Redis (0.7.4+ Home; 0.7.20 Local/Federated).
//!
//! Serves soft-nav / first-paint / infinite-scroll cards without PHP
//! `admin_render_masto_status_card` × N. Offset pages (0.7.9) slice a larger warm
//! envelope; 0.7.25 widens to 15/40/50/80/160/240 so deep Home scroll stays on Axum.

use anyhow::Result;

use crate::config::Config;
use crate::db;
use crate::notif_embed::paint_lean_feed_card_opts;
use crate::self_thread::{
    missing_self_reply_parent_uris, paint_feed_units, plan_feed_paint_units,
};
use crate::timeline;

#[derive(Debug, Clone)]
pub struct HomeHtmlReport {
    pub html: String,
    pub count: usize,
    pub has_more: bool,
    pub next_offset: usize,
    pub source: String,
    pub hydrate_key: String,
    /// Max `created_at` the live poll considered. 0 on the fill path.
    pub newest_ts: i64,
    /// Keyset token for the next older page. Empty when this response is not a cursor page.
    pub next_cursor: String,
}

async fn load_self_thread_parents(
    db: &tokio_postgres::Client,
    slice: &mut [serde_json::Value],
    owner: i64,
    view: &str,
) -> anyhow::Result<std::collections::HashMap<String, serde_json::Value>> {
    crate::home_hydrate_ranked::stamp_missing_reply_parents(db, slice).await;
    let need = missing_self_reply_parent_uris(slice);
    let mut extra = std::collections::HashMap::new();
    if need.is_empty() {
        return Ok(extra);
    }
    if let Ok(fetched) = crate::profile_html::fetch_outbox_statuses_by_uris(db, &need).await {
        extra = fetched;
    }
    let missing: Vec<String> = need
        .into_iter()
        .filter(|uri| !extra.contains_key(uri.trim().trim_end_matches('/')))
        .collect();
    if !missing.is_empty() {
        if let Ok(events) =
            crate::home_hydrate_ranked::fetch_event_parent_statuses(db, &missing).await
        {
            for (key, value) in events {
                extra.entry(key).or_insert(value);
            }
        }
    }
    // Bluesky self-replies name an at:// parent that is often off the current
    // page. Load that cached post only; do not fetch it from the network.
    let at_uris: Vec<String> = missing
        .iter()
        .filter(|uri| {
            uri.starts_with("at://") && !extra.contains_key(uri.trim().trim_end_matches('/'))
        })
        .cloned()
        .collect();
    if !at_uris.is_empty() {
        if let Ok(map) = crate::home_hydrate_ranked::fetch_bsky_map(db, &at_uris).await {
            for (uri, row) in map {
                let key = uri.trim().trim_end_matches('/').to_string();
                extra
                    .entry(key)
                    .or_insert_with(|| crate::home_hydrate_ranked::materialize_bsky(&row));
            }
        }
    }
    crate::audience::filter_parents(db, owner, crate::audience::Surface::timeline(view), &mut extra).await?;
    // Parents loaded off-page skip the slice's quote resolver. Run it here so a
    // quoted post stays on the parent card once a self-reply groups under it.
    if !extra.is_empty() {
        let (keys, mut vals): (Vec<String>, Vec<serde_json::Value>) = extra.drain().unzip();
        let _ = crate::home_hydrate_ranked::hydrate_local_quote_targets(db, &mut vals).await;
        extra = keys.into_iter().zip(vals).collect();
    }
    Ok(extra)
}

pub(crate) async fn load_viewer_actor(cfg: &Config, owner_user_id: i64) -> String {
    let Ok(db) = db::connect(&cfg.database_url).await else {
        return String::new();
    };
    let Ok(row) = db
        .query_opt(
            "SELECT COALESCE(NULLIF(trim(actor_id), ''), ''), COALESCE(username, '')
             FROM ap_users WHERE id = $1::bigint LIMIT 1",
            &[&owner_user_id],
        )
        .await
    else {
        return String::new();
    };
    let Some(row) = row else {
        return String::new();
    };
    let actor_id: String = row.get(0);
    if actor_id.starts_with("https://") {
        return actor_id.trim_end_matches('/').to_string();
    }
    let username: String = row.get(1);
    if username.is_empty() {
        return String::new();
    }
    format!(
        "https://mkultra.monster/users/{}",
        username.to_ascii_lowercase()
    )
}

async fn stamp_ask_identities(
    db: &tokio_postgres::Client,
    slice: &mut [serde_json::Value],
    extra: &mut std::collections::HashMap<String, serde_json::Value>,
) {
    let _ = crate::home_hydrate_ranked::attach_ask_identities(db, slice).await;
    let _ = crate::status_emoji::attach_custom_emojis(db, slice).await;
    let _ = crate::wafrn_crosspost::stamp_text_asks(db, slice).await;
    if extra.is_empty() {
        return;
    }
    let keys: Vec<String> = extra.keys().cloned().collect();
    let mut vals = Vec::with_capacity(keys.len());
    for key in &keys {
        if let Some(value) = extra.remove(key) {
            vals.push(value);
        }
    }
    let _ = crate::home_hydrate_ranked::attach_ask_identities(db, &mut vals).await;
    let _ = crate::status_emoji::attach_custom_emojis(db, &mut vals).await;
    let _ = crate::wafrn_crosspost::stamp_text_asks(db, &mut vals).await;
    for (key, value) in keys.into_iter().zip(vals) {
        extra.insert(key, value);
    }
}

async fn attach_visible_polls(
    db: &tokio_postgres::Client,
    slice: &mut [serde_json::Value],
    extra: &mut std::collections::HashMap<String, serde_json::Value>,
    viewer_actor: &str,
) {
    let _ = crate::home_hydrate_ranked::attach_polls(db, slice, viewer_actor).await;
    if extra.is_empty() {
        return;
    }
    let keys: Vec<String> = extra.keys().cloned().collect();
    let mut vals = Vec::with_capacity(keys.len());
    for key in &keys {
        if let Some(value) = extra.remove(key) {
            vals.push(value);
        }
    }
    let _ = crate::home_hydrate_ranked::attach_polls(db, &mut vals, viewer_actor).await;
    for (key, value) in keys.into_iter().zip(vals) {
        extra.insert(key, value);
    }
}

fn normalize_view(view: &str) -> &'static str {
    match view.trim().to_ascii_lowercase().as_str() {
        "local" => "local",
        "feed" => "feed",
        _ => "home",
    }
}

/// Build feed HTML from hydrate Redis for `home` / `local` / `feed`.
///
/// `offset` skips the head of a warm envelope so infinite-scroll pages can hit
/// the same Axum lean paint path as first paint (0.7.9).
/// `before` is a `v1|created_at|source|id` cursor. When set, the page is read
/// with a keyset instead of slicing the warm head.
pub async fn tl_html_fill(
    cfg: &Config,
    owner_user_id: i64,
    view: &str,
    limit: i64,
    offset: i64,
    before: Option<&str>,
) -> Result<Option<HomeHtmlReport>> {
    let view = normalize_view(view);
    let limit = limit.clamp(1, 40) as usize;
    let offset = offset.max(0) as usize;
    if let Some(token) = before.map(str::trim).filter(|token| !token.is_empty()) {
        return tl_html_older(cfg, owner_user_id, view, limit, token).await;
    }
    // Warm heads go to 240 (0.7.25). Past that → miss → PHP extend.
    const MAX_WARM: usize = 240;
    let need = (offset + limit).min(MAX_WARM);

    // Prefer the smallest warm size that covers this window, then larger heads.
    let mut candidates: Vec<usize> = Vec::new();
    for alt in [need, 240usize, 160, 80, 50, 40, 60, 30, 20, 15] {
        if alt >= need && !candidates.contains(&alt) {
            candidates.push(alt);
        }
    }
    // Last resort: any warm head (may be shorter than need; we still slice).
    for alt in [240usize, 160, 80, 50, 40, 15, 30, 20] {
        if !candidates.contains(&alt) {
            candidates.push(alt);
        }
    }

    let mut report =
        timeline::view_hydrate(cfg, owner_user_id, view, candidates[0], None).await?;
    if !report.cache_hit
        || report.items.len() <= offset
        || (report.filtered && report.items.len() < offset + limit)
    {
        for &alt in &candidates[1..] {
            let alt_report =
                timeline::view_hydrate(cfg, owner_user_id, view, alt, None).await?;
            if alt_report.cache_hit && alt_report.items.len() > offset {
                report = alt_report;
                break;
            }
        }
    }
    if !report.cache_hit || report.items.is_empty() || report.items.len() <= offset {
        return Ok(None);
    }

    let end = (offset + limit).min(report.items.len());
    // Flags already overlaid in timeline::view_hydrate (0.7.19).
    let mut slice: Vec<serde_json::Value> = report.items[offset..end].to_vec();
    let viewer_actor = load_viewer_actor(cfg, owner_user_id).await;
    let mut extra_parents = std::collections::HashMap::new();
    // Attach cached OG/YouTube cards (PHP paint parity) before lean HTML.
    if let Ok(db) = db::connect(&cfg.database_url).await {
        let _ = crate::link_preview::attach_cached_cards(&db, &mut slice).await;
        // Mute/block labels for lean ⋯ menus (PHP block_quick_actions parity).
        if let Ok(moderation) = crate::hidden::load_viewer_moderation(&db, owner_user_id).await {
            crate::notif_embed::stamp_viewer_moderation(&mut slice, &moderation);
        }
        // Self-thread parents not in this hydrate window (common on Home).
        extra_parents = load_self_thread_parents(&db, &mut slice, owner_user_id, view).await?;
        let _ = crate::interaction_flags::apply_to_status_map(
            &db,
            owner_user_id,
            &mut extra_parents,
        )
        .await;
        crate::home_hydrate_ranked::link_outbox_reply_ids(&mut slice);
        attach_visible_polls(&db, &mut slice, &mut extra_parents, &viewer_actor).await;
        let _ = crate::home_hydrate_ranked::attach_bsky_link_facets(&db, &mut slice).await;
        if !extra_parents.is_empty() {
            let keys: Vec<String> = extra_parents.keys().cloned().collect();
            let mut vals: Vec<serde_json::Value> = keys
                .iter()
                .filter_map(|key| extra_parents.remove(key))
                .collect();
            let _ = crate::home_hydrate_ranked::attach_bsky_link_facets(&db, &mut vals).await;
            for (key, value) in keys.into_iter().zip(vals) {
                extra_parents.insert(key, value);
            }
        }
        stamp_ask_identities(&db, &mut slice, &mut extra_parents).await;
        crate::post_media_cache::swap_cached_post_media(&db, &mut slice, Some(&mut extra_parents)).await;
        crate::nostr::act::stamp_viewer_flags(&db, owner_user_id, &mut slice).await;
    }

    let units = plan_feed_paint_units(&slice, &extra_parents);
    let paint = |st: &serde_json::Value, from: &str, viewer: &str| {
        paint_lean_feed_card_opts(st, from, viewer)
    };
    let (html, painted) = paint_feed_units(&units, view, &viewer_actor, &paint);
    if painted == 0 {
        return Ok(None);
    }

    // Hydrate envelopes are warm heads, not the full timeline. Claiming
    // end-of-timeline at the tail of a known warm size blocked PHP extend
    // (0.7.25 — early "End of timeline" around ~80–160 posts).
    // The warm envelope is a head, not the stored timeline. A painted page
    // always has an older keyset page until that query comes back short.
    let next_cursor = slice
        .last()
        .and_then(|st| st.get("vaak_tl_cursor"))
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_string();
    Ok(Some(HomeHtmlReport {
        html,
        count: painted,
        has_more: true,
        // A self-reply paints as one card but consumes the parent row too.
        // Advance by source rows. Advancing by painted cards repeats that
        // parent as the first post under the loading placeholder.
        next_offset: end,
        source: format!("axum-{view}-html:{}", report.source),
        hydrate_key: report.redis_key,
        newest_ts: 0,
        next_cursor,
    }))
}

async fn tl_html_older(
    cfg: &Config,
    owner_user_id: i64,
    view: &str,
    limit: usize,
    before: &str,
) -> Result<Option<HomeHtmlReport>> {
    let Some(cursor) = crate::timeline_cursor::unpack(before) else {
        return Ok(None);
    };
    let Ok(db) = db::connect(&cfg.database_url).await else {
        return Ok(None);
    };
    let owner_actor = load_viewer_actor(cfg, owner_user_id).await;
    let actors = if view == "home" {
        crate::timeline_older::followed_actor_ids(&db, &owner_actor).await
    } else {
        Vec::new()
    };
    let dids = if view == "home" {
        crate::timeline_older::followed_bsky_dids(&db, owner_user_id).await
    } else {
        Vec::new()
    };
    let hidden = crate::hidden::load_timeline_hidden_sets(&db, owner_user_id)
        .await
        .unwrap_or_default();
    let fetch_limit = (limit.saturating_mul(3).max(40)) as i64;
    let page = crate::timeline_cursor::collect_async(
        |next| {
            let db = &db;
            let actors = &actors;
            let dids = &dids;
            let hidden = &hidden;
            let owner_actor = owner_actor.clone();
            async move {
                crate::timeline_older::raw_window(
                    db,
                    view,
                    owner_user_id,
                    &owner_actor,
                    actors,
                    dids,
                    hidden,
                    next,
                    fetch_limit,
                )
                .await
            }
        },
        Some(cursor),
        limit,
        crate::timeline_cursor::OLDER_MAX_WINDOWS,
    )
    .await;
    let mut statuses = crate::home_hydrate_ranked::statuses_for_older_hits(
        &db,
        owner_user_id,
        view,
        &page.kept,
    )
    .await?;
    if statuses.is_empty() {
        return Ok(None);
    }
    let _ = crate::wafrn_crosspost::filter_statuses(&db, &mut statuses).await;
    let _ = crate::wafrn_crosspost::filter_bridgy_twins(&db, &mut statuses).await;
    let _ = crate::wafrn_crosspost::stamp_text_asks(&db, &mut statuses).await;
    crate::post_media_cache::swap_cached_post_media(&db, &mut statuses, None).await;
    crate::nostr::act::stamp_viewer_flags(&db, owner_user_id, &mut statuses).await;
    let viewer_actor = owner_actor;
    let units = plan_feed_paint_units(&statuses, &std::collections::HashMap::new());
    let paint = |st: &serde_json::Value, from: &str, viewer: &str| {
        paint_lean_feed_card_opts(st, from, viewer)
    };
    let (html, painted) = paint_feed_units(&units, view, &viewer_actor, &paint);
    if painted == 0 || html.is_empty() {
        return Ok(None);
    }
    let next_cursor = page
        .next
        .as_ref()
        .map(crate::timeline_cursor::pack)
        .unwrap_or_default();
    Ok(Some(HomeHtmlReport {
        html,
        count: painted,
        has_more: page.has_more,
        next_offset: limit,
        source: format!("axum-{view}-html:keyset"),
        hydrate_key: String::new(),
        newest_ts: 0,
        next_cursor,
    }))
}

/// Build Home feed HTML from hydrate Redis (`vaak:timeline:v1:*`).
pub async fn home_html_fill(
    cfg: &Config,
    owner_user_id: i64,
    limit: i64,
    offset: i64,
    before: Option<&str>,
) -> Result<Option<HomeHtmlReport>> {
    tl_html_fill(cfg, owner_user_id, "home", limit, offset, before).await
}

/// Build the Home live-poll fragment with the same lean Rust painter used by
/// the initial and infinite-scroll timeline paths.
pub async fn home_html_since(
    cfg: &Config,
    owner_user_id: i64,
    since_ts: i64,
    limit: i64,
) -> Result<Option<HomeHtmlReport>> {
    let report = timeline::home_since(cfg, owner_user_id, since_ts, limit.clamp(1, 40) as usize).await?;
    if !report.cache_hit {
        return Ok(None);
    }
    // newest_ts is the newest card this response actually returns.
    let newest_ts = report.newest_ts;
    let mut slice = report.items;
    let viewer_actor = load_viewer_actor(cfg, owner_user_id).await;
    let mut extra_parents = std::collections::HashMap::new();
    if let Ok(db) = db::connect(&cfg.database_url).await {
        let _ = crate::link_preview::attach_cached_cards(&db, &mut slice).await;
        if let Ok(moderation) = crate::hidden::load_viewer_moderation(&db, owner_user_id).await {
            crate::notif_embed::stamp_viewer_moderation(&mut slice, &moderation);
        }
        extra_parents = load_self_thread_parents(&db, &mut slice, owner_user_id, "home").await?;
        let _ = crate::interaction_flags::apply_to_status_map(
            &db,
            owner_user_id,
            &mut extra_parents,
        )
        .await;
        crate::home_hydrate_ranked::link_outbox_reply_ids(&mut slice);
        attach_visible_polls(&db, &mut slice, &mut extra_parents, &viewer_actor).await;
        let _ = crate::home_hydrate_ranked::attach_bsky_link_facets(&db, &mut slice).await;
        stamp_ask_identities(&db, &mut slice, &mut extra_parents).await;
        crate::post_media_cache::swap_cached_post_media(&db, &mut slice, Some(&mut extra_parents)).await;
        crate::nostr::act::stamp_viewer_flags(&db, owner_user_id, &mut slice).await;
    }
    let units = plan_feed_paint_units(&slice, &extra_parents);
    let paint = |st: &serde_json::Value, from: &str, viewer: &str| {
        stamp_poll_card(paint_lean_feed_card_opts(st, from, viewer), st)
    };
    let (html, painted) = paint_feed_units(&units, "home", &viewer_actor, &paint);
    // Nothing painted: do not advertise a cursor past posts the browser never received.
    let newest_ts = if painted == 0 { since_ts } else { newest_ts };
    Ok(Some(HomeHtmlReport {
        html,
        count: painted,
        has_more: false,
        next_offset: painted,
        source: "axum-home-since-html".to_string(),
        hydrate_key: report.hydrate_key.unwrap_or_default(),
        newest_ts,
        next_cursor: String::new(),
    }))
}

/// Local / Federated live-poll fragment. Uses the same lean painter as the
/// timeline fill. Returns `None` when the warm head has nothing newer so PHP
/// can still query posts that fan-out has not hydrated yet.
pub async fn tl_html_since(
    cfg: &Config,
    owner_user_id: i64,
    view: &str,
    since_ts: i64,
    limit: i64,
) -> Result<Option<HomeHtmlReport>> {
    let view = normalize_view(view);
    if view == "home" {
        return home_html_since(cfg, owner_user_id, since_ts, limit).await;
    }
    let report = timeline::chrono_since(
        cfg,
        owner_user_id,
        view,
        since_ts,
        limit.clamp(1, 40) as usize,
    )
    .await?;
    if !report.cache_hit || report.items.is_empty() {
        return Ok(None);
    }
    let mut slice = report.items;
    let viewer_actor = load_viewer_actor(cfg, owner_user_id).await;
    let mut extra_parents = std::collections::HashMap::new();
    if let Ok(db) = db::connect(&cfg.database_url).await {
        let _ = crate::link_preview::attach_cached_cards(&db, &mut slice).await;
        if let Ok(moderation) = crate::hidden::load_viewer_moderation(&db, owner_user_id).await {
            crate::notif_embed::stamp_viewer_moderation(&mut slice, &moderation);
        }
        extra_parents = load_self_thread_parents(&db, &mut slice, owner_user_id, view).await?;
        let _ = crate::interaction_flags::apply_to_status_map(
            &db,
            owner_user_id,
            &mut extra_parents,
        )
        .await;
        crate::home_hydrate_ranked::link_outbox_reply_ids(&mut slice);
        attach_visible_polls(&db, &mut slice, &mut extra_parents, &viewer_actor).await;
        let _ = crate::home_hydrate_ranked::attach_bsky_link_facets(&db, &mut slice).await;
        stamp_ask_identities(&db, &mut slice, &mut extra_parents).await;
        crate::post_media_cache::swap_cached_post_media(&db, &mut slice, Some(&mut extra_parents)).await;
        crate::nostr::act::stamp_viewer_flags(&db, owner_user_id, &mut slice).await;
    }
    let units = plan_feed_paint_units(&slice, &extra_parents);
    let paint = |st: &serde_json::Value, from: &str, viewer: &str| {
        stamp_poll_card(paint_lean_feed_card_opts(st, from, viewer), st)
    };
    let (html, painted) = paint_feed_units(&units, view, &viewer_actor, &paint);
    if painted == 0 {
        return Ok(None);
    }
    Ok(Some(HomeHtmlReport {
        html,
        count: painted,
        has_more: false,
        next_offset: painted,
        source: format!("axum-{view}-since-html"),
        hydrate_key: report.hydrate_key.unwrap_or_default(),
        newest_ts: report.newest_ts,
        next_cursor: String::new(),
    }))
}

/// Live-poll identity. A boost must not reuse the original post's key, or the
/// browser treats the boost as already on screen and drops the counter. The
/// outer card's `data-sort` is the cursor the client is allowed to trust.
fn stamp_poll_card(html: String, status: &serde_json::Value) -> String {
    if let Some(boost_id) = poll_boost_id(status) {
        let announce = status
            .get("vaak_announce_event_id")
            .and_then(|v| v.as_str())
            .map(str::trim)
            .filter(|s| !s.is_empty() && *s != "0")
            .map(str::to_string)
            .or_else(|| {
                status
                    .get("vaak_announce_event_id")
                    .and_then(|v| v.as_i64())
                    .filter(|n| *n > 0)
                    .map(|n| n.to_string())
            })
            .unwrap_or_default();
        return crate::notif_embed::retarget_boost_card(
            html,
            &boost_id,
            &announce,
            timeline::status_created_ts(status),
        );
    }
    let Some(gt) = html.find("<article").and_then(|start| {
        html[start..].find('>').map(|rel| start + rel)
    }) else {
        return html;
    };
    let mut head = html[..gt].to_string();
    let rest = &html[gt..];
    let ts = timeline::status_created_ts(status);
    if ts > 0 && !head.contains(" data-sort=\"") {
        head.push_str(&format!(" data-sort=\"{ts}\""));
    }
    let mut out = head;
    out.push_str(rest);
    out
}

fn poll_boost_id(status: &serde_json::Value) -> Option<String> {
    let content = status.get("content").and_then(|v| v.as_str()).unwrap_or("");
    if !content_is_empty(content) {
        return None;
    }
    if !status.get("reblog").is_some_and(serde_json::Value::is_object) {
        return None;
    }
    let id = status
        .get("id")
        .and_then(|v| v.as_str().map(|s| s.trim().trim_end_matches('/').to_string()).filter(|s| !s.is_empty()))
        .or_else(|| status.get("id").and_then(|v| v.as_i64()).map(|n| n.to_string()))
        .or_else(|| status.get("id").and_then(|v| v.as_u64()).map(|n| n.to_string()))
        .unwrap_or_default();
    if id.is_empty() { None } else { Some(id) }
}

fn content_is_empty(content: &str) -> bool {
    let mut text = String::new();
    let mut in_tag = false;
    for ch in content.chars() {
        match ch {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ if !in_tag => text.push(ch),
            _ => {}
        }
    }
    text.trim().is_empty()
}

#[cfg(test)]
mod tests {
    use crate::notif_embed::paint_lean_feed_card;
    use serde_json::json;

    #[test]
    fn paints_boost_wrapper() {
        let st = json!({
            "id": "boost1",
            "created_at": "2026-10-05T00:00:00Z",
            "content": "",
            "account": {
                "acct": "booster",
                "display_name": "Booster",
                "uri": "https://example.com/users/booster",
                "avatar": "https://mkultra.monster/img/avatar/default.webp"
            },
            "reblog": {
                "id": "inner1",
                "created_at": "2026-10-04T00:00:00Z",
                "content": "<p>hello world</p>",
                "uri": "https://example.com/users/orig/statuses/1",
                "url": "https://example.com/users/orig/statuses/1",
                "account": {
                    "acct": "orig",
                    "display_name": "Orig",
                    "uri": "https://example.com/users/orig",
                    "avatar": "https://mkultra.monster/img/avatar/default.webp"
                },
                "media_attachments": []
            }
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("boosted"));
        assert!(html.contains("hello world"));
        assert!(
            html.contains("class=\"tweet tweet-boost"),
            "boost must be article.tweet.tweet-boost for timeline separators: {html}"
        );
        assert!(
            !html.contains("<div class=\"tweet-boost\">"),
            "must not wrap boost in div (breaks #timeline-items > article.tweet borders): {html}"
        );
        // Boost chrome is inside the article, before the original author header.
        let article_at = html.find("<article").expect("article");
        let boost_at = html.find("boosted").expect("boosted");
        let close_at = html.find("</article>").expect("close");
        assert!(article_at < boost_at && boost_at < close_at);
    }

    #[test]
    fn poll_stamp_keeps_a_boost_distinct_from_the_original() {
        let st = serde_json::json!({
            "id": "boost-9",
            "created_at": "2026-10-05T01:57:40.000Z",
            "content": "",
            "account": {"acct": "booster", "display_name": "Booster", "uri": "https://example.com/users/booster"},
            "reblog": {
                "id": "inner1",
                "created_at": "2026-10-04T00:00:00Z",
                "content": "<p>hello world</p>",
                "uri": "https://example.com/users/orig/statuses/1",
                "account": {"acct": "orig", "display_name": "Orig", "uri": "https://example.com/users/orig"}
            }
        });
        let html = super::stamp_poll_card(paint_lean_feed_card(&st), &st);
        let open = html.find('>').expect("open");
        let head = &html[..open];
        assert!(head.contains("data-timeline-key=\"boost:boost-9\""), "{head}");
        assert!(!head.contains("data-timeline-key=\"inner1\""));
        assert!(head.contains("data-status-id=\"boost-9\""), "{head}");
        assert!(!head.contains("data-note-id="), "{head}");
        assert!(!head.contains("data-status-id=\"inner1\""), "{head}");
        assert!(head.contains("data-sort=\"1791165460\""), "{head}");
        let plain = serde_json::json!({
            "id": "note-1",
            "created_at": "2026-10-05T01:57:08.000Z",
            "content": "<p>hello</p>",
            "uri": "https://example.com/users/a/statuses/1",
            "account": {"acct": "a", "display_name": "A", "uri": "https://example.com/users/a"}
        });
        let note = super::stamp_poll_card(paint_lean_feed_card(&plain), &plain);
        assert!(note.contains("data-timeline-key=\"note-1\""), "{note}");
        assert!(note.contains("data-status-id=\"note-1\""), "{note}");
        assert!(note.contains("data-note-id=\"https://example.com/users/a/statuses/1\""), "{note}");
        assert!(note.contains("data-sort=\"1791165428\""), "{note}");
    }
}
