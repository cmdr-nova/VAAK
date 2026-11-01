//! Home/Local/Federated fetch quoted posts that are not cached yet.
//!
//! The Rust timeline painters read quotes from cache only. When a quoted post
//! was never fetched, the card shows "Quoted post (not cached yet)" and stays
//! that way, because nothing on these paths asked for it. PHP's card painter
//! called `ap_quote_target_warm_async` for the same case. This module does the
//! same after a page is assembled, through the existing
//! [`crate::profile_quote_warm::QuoteWarmContext::warm`]: it skips non-status
//! URLs and targets marked missing or deleted, routes Bluesky targets to the
//! Bluesky post warm queue, and holds a 90 s per-URL cooldown before spawning
//! `ap-quote-target-warm.php`. The work runs in the background so the paint
//! never waits on it, and at most [`MAX_PER_PAGE`] targets go per page.

use serde_json::Value;

/// Upper bound on warm requests one painted page can start.
pub(crate) const MAX_PER_PAGE: usize = 4;

/// Unresolved quote targets on a page, first seen first, deduplicated.
pub(crate) fn targets(statuses: &[Value]) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    for status in statuses {
        let shown = status.get("reblog").filter(|v| v.is_object()).unwrap_or(status);
        for st in [shown, status] {
            if let Some(url) = crate::notif_embed::unresolved_quote_target(st) {
                if !out.contains(&url) {
                    out.push(url);
                }
            }
        }
        if out.len() >= MAX_PER_PAGE {
            break;
        }
    }
    out.truncate(MAX_PER_PAGE);
    out
}

struct Always;
impl crate::profile_quote_warm::CurrentQuoteGrant for Always {
    fn require_current(&self) -> crate::profile_quote_warm::GrantFuture<'_> {
        Box::pin(async { Ok(()) })
    }
}

/// Ask for the page's missing quote targets in the background.
pub(crate) fn kick(cfg: &crate::config::Config, owner_user_id: i64, statuses: &[Value]) {
    let urls = targets(statuses);
    if urls.is_empty() || std::env::var("VAAK_HOME_QUOTE_WARM").is_ok_and(|v| matches!(v.trim(), "0" | "off" | "false" | "no")) {
        return;
    }
    let cfg = cfg.clone();
    tokio::spawn(async move {
        let Ok(db) = crate::db::timeline_reused(&cfg.database_url).await else { return };
        let transport = crate::profile_quote_warm::ProcessQuoteTransport::configured(cfg.clone());
        let ctx = crate::profile_quote_warm::QuoteWarmContext::new(std::sync::Arc::new(transport));
        let now = chrono::Utc::now().timestamp();
        for url in urls {
            if let Err(e) = ctx.warm(&db, &url, owner_user_id, now, &Always).await {
                tracing::debug!(error = %e, url = %url, "home quote warm");
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    #[test]
    fn pending_quotes_on_posts_and_boosts_are_collected_once() {
        let pending = |url: &str| json!({"id": "1", "quote_url": url, "quote": {"state": "pending", "quoted_status": null}});
        let a = "https://misskey.test/notes/a";
        let b = "https://mastodon.test/@x/2";
        let statuses = vec![
            pending(a),
            json!({"id": "2", "reblog": pending(b)}),
            pending(a),
            json!({"id": "3", "content": "<p>no quote</p>"}),
        ];
        assert_eq!(super::targets(&statuses), vec![a.to_string(), b.to_string()]);
    }

    #[test]
    fn cached_quotes_are_left_alone_and_pages_are_capped() {
        let cached = json!({"id": "1", "quote_url": "https://m.test/@a/1", "quote": {"state": "accepted", "quoted_status": {"account": {"acct": "a@m.test"}, "content": "<p>hi</p>"}}});
        assert!(super::targets(&[cached]).is_empty());
        let deleted = json!({"id": "2", "quote_url": "https://m.test/@a/9", "quote": {"state": "deleted", "quoted_status": null}});
        assert!(super::targets(&[deleted]).is_empty(), "final quote states are not warmed");
        let many: Vec<_> = (0..10)
            .map(|i| json!({"id": i.to_string(), "quote_url": format!("https://m.test/@a/{i}"), "quote": {"state": "pending", "quoted_status": null}}))
            .collect();
        assert_eq!(super::targets(&many).len(), super::MAX_PER_PAGE);
    }
}
