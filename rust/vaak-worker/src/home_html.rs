//! Home fill HTML from warm hydrate Redis (0.7.4).
//!
//! Serves soft-nav / first-paint Home cards without PHP
//! `admin_render_masto_status_card` × N (Home partial p99 was multi-second).

use anyhow::Result;

use crate::config::Config;
use crate::notif_embed::paint_lean_feed_card;
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

/// Build Home feed HTML from hydrate Redis (`vaak:timeline:v1:*`).
pub async fn home_html_fill(
    cfg: &Config,
    owner_user_id: i64,
    limit: i64,
) -> Result<Option<HomeHtmlReport>> {
    let limit = limit.clamp(1, 40) as usize;
    let mut report = timeline::home_hydrate(cfg, owner_user_id, limit, None).await?;
    // Warm tick writes limits 15+40; soft-nav may ask for other sizes.
    if !report.cache_hit {
        for alt in [15_usize, 40, 20, 30] {
            if alt == limit {
                continue;
            }
            let alt_report = timeline::home_hydrate(cfg, owner_user_id, alt, None).await?;
            if alt_report.cache_hit && !alt_report.items.is_empty() {
                report = alt_report;
                break;
            }
        }
    }
    if !report.cache_hit || report.items.is_empty() {
        return Ok(None);
    }

    let mut items = report.items;
    if items.len() > limit {
        items.truncate(limit);
    }

    let mut html = String::with_capacity(items.len() * 1200);
    let mut painted = 0usize;
    for item in &items {
        if !item.is_object() {
            continue;
        }
        html.push_str(&paint_lean_feed_card(item));
        painted += 1;
    }
    if painted == 0 {
        return Ok(None);
    }

    Ok(Some(HomeHtmlReport {
        html,
        count: painted,
        has_more: painted >= limit,
        next_offset: painted.min(limit),
        source: format!("axum-home-html:{}", report.source),
        hydrate_key: report.redis_key,
    }))
}

#[cfg(test)]
mod tests {
    use super::*;
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
                "avatar": "https://mkultra.monster/img/avatar/default.jpg"
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
                    "avatar": "https://mkultra.monster/img/avatar/default.jpg"
                },
                "media_attachments": []
            }
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("boosted"));
        assert!(html.contains("hello world"));
        assert!(html.contains("tweet-boost") || html.contains("ph-repeat"));
    }
}
