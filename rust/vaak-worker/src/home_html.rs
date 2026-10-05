//! Timeline fill HTML from warm hydrate Redis (0.7.4+ Home; 0.7.20 Local/Federated).
//!
//! Serves soft-nav / first-paint / infinite-scroll cards without PHP
//! `admin_render_masto_status_card` × N. Offset pages (0.7.9) slice a larger warm
//! envelope (15/40/80).

use anyhow::Result;

use crate::config::Config;
use crate::db;
use crate::notif_embed::paint_lean_feed_card_opts;
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
    let need = (offset + limit).min(80);

    // Prefer the smallest warm size that covers this window, then larger heads.
    let mut candidates: Vec<usize> = Vec::new();
    for alt in [need, 80usize, 40, 60, 30, 20, 15] {
        if alt >= need && !candidates.contains(&alt) {
            candidates.push(alt);
        }
    }
    // Last resort: any warm head (may be shorter than need; we still slice).
    for alt in [80usize, 40, 15, 30, 20] {
        if !candidates.contains(&alt) {
            candidates.push(alt);
        }
    }

    let mut report =
        timeline::view_hydrate(cfg, owner_user_id, view, candidates[0], None).await?;
    if !report.cache_hit || report.items.len() <= offset {
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
    let slice = &report.items[offset..end];
    let viewer_actor = load_viewer_actor(cfg, owner_user_id).await;

    let mut html = String::with_capacity(slice.len() * 1200);
    let mut painted = 0usize;
    for item in slice {
        if !item.is_object() {
            continue;
        }
        html.push_str(&paint_lean_feed_card_opts(item, view, &viewer_actor));
        painted += 1;
    }
    if painted == 0 {
        return Ok(None);
    }

    Ok(Some(HomeHtmlReport {
        html,
        count: painted,
        // Full page ⇒ client may ask again; Axum miss falls through to PHP.
        has_more: painted >= limit || end < report.items.len(),
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
