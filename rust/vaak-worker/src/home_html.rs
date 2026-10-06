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
}

async fn load_viewer_actor(cfg: &Config, owner_user_id: i64) -> String {
    let Ok(db) = db::connect(&cfg.database_url).await else {
        return String::new();
    };
    let Ok(row) = db
        .query_opt(
            "SELECT COALESCE(NULLIF(trim(actor_id), ''), ''), COALESCE(username, '')
             FROM ap_users WHERE id = $1 LIMIT 1",
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
pub async fn tl_html_fill(
    cfg: &Config,
    owner_user_id: i64,
    view: &str,
    limit: i64,
    offset: i64,
) -> Result<Option<HomeHtmlReport>> {
    let view = normalize_view(view);
    let limit = limit.clamp(1, 40) as usize;
    let offset = offset.max(0) as usize;
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
    let mut extra_parents = std::collections::HashMap::new();
    // Attach cached OG/YouTube cards (PHP paint parity) before lean HTML.
    if let Ok(db) = db::connect(&cfg.database_url).await {
        let _ = crate::link_preview::attach_cached_cards(&db, &mut slice).await;
        // Mute/block labels for lean ⋯ menus (PHP block_quick_actions parity).
        if let Ok(moderation) = crate::hidden::load_viewer_moderation(&db, owner_user_id).await {
            crate::notif_embed::stamp_viewer_moderation(&mut slice, &moderation);
        }
        // Self-thread parents not in this hydrate window (common on Home).
        let need = missing_self_reply_parent_uris(&slice);
        if !need.is_empty() {
            if let Ok(fetched) =
                crate::profile_html::fetch_outbox_statuses_by_uris(&db, &need).await
            {
                extra_parents = fetched;
            }
        }
        crate::home_hydrate_ranked::link_outbox_reply_ids(&mut slice);
    }
    let viewer_actor = load_viewer_actor(cfg, owner_user_id).await;

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
    const WARM_HEAD_SIZES: [usize; 6] = [15, 40, 50, 80, 160, 240];
    let exhausted = end >= report.items.len();
    let looks_like_warm_head = WARM_HEAD_SIZES.contains(&report.items.len());
    Ok(Some(HomeHtmlReport {
        html,
        count: painted,
        has_more: painted >= limit || !exhausted || looks_like_warm_head || report.filtered,
        next_offset: offset + painted,
        source: format!("axum-{view}-html:{}", report.source),
        hydrate_key: report.redis_key,
    }))
}

/// Build Home feed HTML from hydrate Redis (`vaak:timeline:v1:*`).
pub async fn home_html_fill(
    cfg: &Config,
    owner_user_id: i64,
    limit: i64,
    offset: i64,
) -> Result<Option<HomeHtmlReport>> {
    tl_html_fill(cfg, owner_user_id, "home", limit, offset).await
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
    let mut slice = report.items;
    let mut extra_parents = std::collections::HashMap::new();
    if let Ok(db) = db::connect(&cfg.database_url).await {
        let _ = crate::link_preview::attach_cached_cards(&db, &mut slice).await;
        if let Ok(moderation) = crate::hidden::load_viewer_moderation(&db, owner_user_id).await {
            crate::notif_embed::stamp_viewer_moderation(&mut slice, &moderation);
        }
        let need = missing_self_reply_parent_uris(&slice);
        if !need.is_empty() {
            if let Ok(fetched) = crate::profile_html::fetch_outbox_statuses_by_uris(&db, &need).await {
                extra_parents = fetched;
            }
        }
        crate::home_hydrate_ranked::link_outbox_reply_ids(&mut slice);
    }
    let viewer_actor = load_viewer_actor(cfg, owner_user_id).await;
    let units = plan_feed_paint_units(&slice, &extra_parents);
    let paint = |st: &serde_json::Value, from: &str, viewer: &str| {
        paint_lean_feed_card_opts(st, from, viewer)
    };
    let (html, painted) = paint_feed_units(&units, "home", &viewer_actor, &paint);
    Ok(Some(HomeHtmlReport {
        html,
        count: painted,
        has_more: false,
        next_offset: painted,
        source: "axum-home-since-html".to_string(),
        hydrate_key: report.hydrate_key.unwrap_or_default(),
    }))
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
}
