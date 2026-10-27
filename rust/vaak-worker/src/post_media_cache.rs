//! Timeline paint-time swap of remote post images for their R2 WebP copies.
//!
//! PHP `ap_remote_post_media_resolve` returned the cached `public_url` for a
//! remote image and queued a background warm on a miss. The Rust Home, Local
//! and Federated painters read raw `events.media_urls`, so every card
//! hotlinked the original file from the remote server. This pass restores
//! the PHP behaviour with one batched lookup per page.
use serde_json::Value;
use std::collections::HashSet;
use tokio_postgres::Client;

/// New warm jobs queued per painted page. The PHP worker converts three
/// images per nudge, so a long cold page fills in over a few scrolls instead
/// of piling a burst of ImageMagick work onto the CPU.
const MAX_ENQUEUE_PER_PAGE: usize = 12;

/// Skip the `last_used_at` write when the row was touched this recently.
/// Cleanup evicts after 30 unused days, so a daily touch is plenty.
const TOUCH_AFTER_SECS: i64 = 86_400;

/// Public R2 host from /etc/mkultra/r2.env (same key order as
/// ap_r2_put_object). Only the host is read; credentials are ignored.
fn r2_public_host() -> &'static str {
    static HOST: std::sync::OnceLock<String> = std::sync::OnceLock::new();
    HOST.get_or_init(|| {
        let text = std::fs::read_to_string("/etc/mkultra/r2.env").unwrap_or_default();
        r2_host_from_env(&text)
    })
}

fn r2_host_from_env(text: &str) -> String {
    let mut alias = String::new();
    let mut hostname = String::new();
    for line in text.lines() {
        let Some((k, v)) = line.trim().split_once('=') else {
            continue;
        };
        match k.trim() {
            "S3_ALIAS_HOST" => alias = v.trim().to_ascii_lowercase(),
            "S3_HOSTNAME" => hostname = v.trim().to_ascii_lowercase(),
            _ => {}
        }
    }
    if alias.is_empty() { hostname } else { alias }
}

/// Our own media (local uploads and existing cache copies) and hosts that
/// already serve resized CDN copies. Converting those again costs CPU for
/// no gain.
fn skip_host(host: &str) -> bool {
    let host = host.to_ascii_lowercase();
    if host == "cdn.bsky.app" || host == "video.bsky.app" || crate::actor_warm::own_media_host(&host) {
        return true;
    }
    let r2 = r2_public_host();
    !r2.is_empty() && host == r2
}

fn is_video_path(url: &str) -> bool {
    let path = url.split(['?', '#']).next().unwrap_or(url).to_ascii_lowercase();
    ["mp4", "webm", "mov", "m4v", "m3u8"]
        .iter()
        .any(|ext| path.ends_with(&format!(".{ext}")))
}

/// Cache key for an attachment URL, or None when it is not a cacheable
/// remote still image.
fn cache_key(att: &Value, field: &str) -> Option<String> {
    let kind = att.get("type").and_then(Value::as_str).unwrap_or("image");
    if !kind.eq_ignore_ascii_case("image") {
        return None;
    }
    let raw = att.get(field).and_then(Value::as_str)?;
    let clean = crate::profile_enrichment::sanitize_profile_url(raw).ok()??;
    if is_video_path(&clean) || clean.contains("/cache/post-media/") {
        return None;
    }
    let host = reqwest::Url::parse(&clean).ok()?.host_str()?.to_string();
    if skip_host(&host) {
        return None;
    }
    Some(clean)
}

/// Visit every `media_attachments` list in a status, its boost and its quote.
fn for_each_attachment(st: &mut Value, depth: u8, f: &mut dyn FnMut(&mut Value)) {
    if depth > 4 {
        return;
    }
    let Some(obj) = st.as_object_mut() else {
        return;
    };
    if let Some(Value::Array(atts)) = obj.get_mut("media_attachments") {
        for att in atts.iter_mut() {
            f(att);
        }
    }
    for key in ["reblog", "quote", "quoted_status", "vaak_quote_preview"] {
        if let Some(child) = obj.get_mut(key) {
            if child.is_object() {
                for_each_attachment(child, depth + 1, f);
            }
        }
    }
}

fn collect_keys<'a>(statuses: impl Iterator<Item = &'a mut Value>) -> Vec<String> {
    let mut seen = HashSet::new();
    let mut out = Vec::new();
    for st in statuses {
        for_each_attachment(st, 0, &mut |att| {
            for field in ["url", "preview_url"] {
                if let Some(key) = cache_key(att, field) {
                    if seen.insert(key.clone()) {
                        out.push(key);
                    }
                }
            }
        });
    }
    out
}

/// Point `url` / `preview_url` at the cached copy. `remote_url` keeps the
/// original so "open original" and dedupe still see the source.
fn apply(st: &mut Value, hits: &std::collections::HashMap<String, String>) {
    for_each_attachment(st, 0, &mut |att| {
        for field in ["url", "preview_url"] {
            let Some(key) = cache_key(att, field) else {
                continue;
            };
            if let Some(public) = hits.get(&key) {
                att[field] = Value::String(public.clone());
            }
        }
    });
}

/// Swap cached remote images into `statuses` (and `extra` parents), queue
/// the misses, and nudge the PHP media warm worker once if anything new was
/// queued. Best-effort: any SQL error leaves the original URLs in place.
pub(crate) async fn swap_cached_post_media(
    db: &Client,
    statuses: &mut [Value],
    extra: Option<&mut std::collections::HashMap<String, Value>>,
) {
    let mut extra = extra;
    let keys = {
        let extra_iter = extra.as_deref_mut().into_iter().flat_map(|m| m.values_mut());
        collect_keys(statuses.iter_mut().chain(extra_iter))
    };
    if keys.is_empty() {
        return;
    }
    let Ok(rows) = db
        .query(
            "SELECT source_url, public_url, last_used_at FROM remote_post_media_cache WHERE source_url = ANY($1)",
            &[&keys],
        )
        .await
    else {
        return;
    };
    let now = chrono::Utc::now();
    let touch_cutoff = (now - chrono::Duration::seconds(TOUCH_AFTER_SECS))
        .to_rfc3339_opts(chrono::SecondsFormat::Secs, false);
    let mut hits = std::collections::HashMap::new();
    let mut stale = Vec::new();
    for row in rows {
        let source: String = row.get(0);
        let public: String = row.get(1);
        let used: String = row.get(2);
        if public.is_empty() || public == "0" || !public.starts_with("https://") {
            continue;
        }
        if used.as_str() < touch_cutoff.as_str() {
            stale.push(source.clone());
        }
        hits.insert(source, public);
    }
    if !hits.is_empty() {
        for st in statuses.iter_mut() {
            apply(st, &hits);
        }
        if let Some(map) = extra {
            for st in map.values_mut() {
                apply(st, &hits);
            }
        }
    }
    let now_s = now.to_rfc3339_opts(chrono::SecondsFormat::Secs, false);
    if !stale.is_empty() {
        let _ = db
            .execute(
                "UPDATE remote_post_media_cache SET last_used_at = $1 WHERE source_url = ANY($2)",
                &[&now_s, &stale],
            )
            .await;
    }
    let misses: Vec<String> = keys
        .into_iter()
        .filter(|k| !hits.contains_key(k))
        .take(MAX_ENQUEUE_PER_PAGE)
        .collect();
    if misses.is_empty() {
        return;
    }
    // Same row ap_media_warm_enqueue_post writes. ON CONFLICT keeps one job
    // per URL, so repaints of a cold page do not queue it again.
    let queued = db
        .execute(
            "INSERT INTO ap_media_warm_queue (job_type, target_key, media_kind, status, attempts, max_attempts, next_attempt_at, created_at, updated_at)
             SELECT 'post_media', u, 'image', 'pending', 0, 5, $2, $2, $2 FROM unnest($1::text[]) AS u
             ON CONFLICT (job_type, target_key, media_kind) DO NOTHING",
            &[&misses, &now_s],
        )
        .await
        .unwrap_or(0);
    if queued > 0 {
        use crate::profile_warm::WarmTransport;
        let _ = crate::profile_warm::ProcessWarmTransport::configured()
            .media_process()
            .await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn collects_remote_images_from_status_boost_and_quote() {
        let mut st = json!({
            "media_attachments": [
                {"type": "image", "url": "https://files.example/original/a.png", "preview_url": "https://files.example/original/a.png"},
                {"type": "video", "url": "https://files.example/original/v.mp4"},
                {"type": "image", "url": "https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:x/b@jpeg"}
            ],
            "reblog": {"media_attachments": [{"url": "https://other.example/b.jpg"}]},
            "quote": {"quoted_status": {"media_attachments": [{"type": "image", "url": "https://q.example/c.webp"}]}}
        });
        let keys = collect_keys(std::iter::once(&mut st));
        assert_eq!(
            keys,
            vec![
                "https://files.example/original/a.png".to_string(),
                "https://other.example/b.jpg".to_string(),
                "https://q.example/c.webp".to_string(),
            ]
        );
    }

    #[test]
    fn apply_swaps_url_and_preview_but_keeps_remote_url() {
        let mut st = json!({
            "reblog": {"media_attachments": [{
                "type": "image",
                "url": "https://files.example/a.png",
                "preview_url": "https://files.example/a.png",
                "remote_url": "https://files.example/a.png"
            }]}
        });
        let hits = std::collections::HashMap::from([(
            "https://files.example/a.png".to_string(),
            "https://media.example/cache/post-media/x.webp".to_string(),
        )]);
        apply(&mut st, &hits);
        let att = &st["reblog"]["media_attachments"][0];
        assert_eq!(att["url"], "https://media.example/cache/post-media/x.webp");
        assert_eq!(att["preview_url"], "https://media.example/cache/post-media/x.webp");
        assert_eq!(att["remote_url"], "https://files.example/a.png");
    }

    #[test]
    fn r2_alias_host_wins_over_hostname() {
        assert_eq!(r2_host_from_env("S3_HOSTNAME=a.example\nAWS_SECRET_ACCESS_KEY=x\n"), "a.example");
        assert_eq!(r2_host_from_env("S3_HOSTNAME=a.example\nS3_ALIAS_HOST=Media.Example\n"), "media.example");
    }

    #[test]
    fn video_extensions_are_never_cached() {
        let att = json!({"url": "https://files.example/clip.MP4?x=1"});
        assert!(cache_key(&att, "url").is_none());
    }

    /// Needs a disposable Postgres: VAAK_POST_MEDIA_TEST_DATABASE_URL.
    #[tokio::test]
    async fn swaps_hits_and_queues_misses_once() {
        let Ok(url) = std::env::var("VAAK_POST_MEDIA_TEST_DATABASE_URL") else {
            return;
        };
        let db = crate::db::connect(&url).await.unwrap();
        db.batch_execute(
            "DROP TABLE IF EXISTS remote_post_media_cache, ap_media_warm_queue;
             CREATE TABLE remote_post_media_cache (id BIGSERIAL PRIMARY KEY, source_url TEXT NOT NULL UNIQUE, s3_key TEXT NOT NULL,
               public_url TEXT NOT NULL, content_type TEXT NOT NULL, byte_size INTEGER NOT NULL DEFAULT 0,
               original_byte_size INTEGER NOT NULL DEFAULT 0, fetched_at TEXT NOT NULL, last_used_at TEXT NOT NULL);
             CREATE TABLE ap_media_warm_queue (id BIGSERIAL PRIMARY KEY, job_type TEXT NOT NULL DEFAULT 'actor_media',
               target_key TEXT NOT NULL, media_kind TEXT NOT NULL DEFAULT 'avatar_header', status TEXT NOT NULL DEFAULT 'pending',
               attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 8, next_attempt_at TEXT NOT NULL,
               claimed_at TEXT, last_error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
               UNIQUE(job_type, target_key, media_kind));
             INSERT INTO remote_post_media_cache (source_url, s3_key, public_url, content_type, fetched_at, last_used_at)
               VALUES ('https://hit.example/a.png', 'k', 'https://media.example/cache/post-media/a.webp', 'image/webp',
                       '2026-01-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00');",
        )
        .await
        .unwrap();
        let fresh = || {
            vec![json!({"media_attachments": [
                {"type": "image", "url": "https://hit.example/a.png", "preview_url": "https://hit.example/a.png"},
                {"type": "image", "url": "https://miss.example/b.jpg", "preview_url": "https://miss.example/b.jpg"}
            ]})]
        };
        let mut statuses = fresh();
        swap_cached_post_media(&db, &mut statuses, None).await;
        let atts = &statuses[0]["media_attachments"];
        assert_eq!(atts[0]["url"], "https://media.example/cache/post-media/a.webp");
        assert_eq!(atts[1]["url"], "https://miss.example/b.jpg");
        let queued: Vec<String> = db
            .query("SELECT target_key FROM ap_media_warm_queue WHERE job_type='post_media' AND media_kind='image'", &[])
            .await
            .unwrap()
            .iter()
            .map(|r| r.get(0))
            .collect();
        assert_eq!(queued, vec!["https://miss.example/b.jpg".to_string()]);
        let used: String = db
            .query_one("SELECT last_used_at FROM remote_post_media_cache", &[])
            .await
            .unwrap()
            .get(0);
        assert!(used.as_str() > "2026-01-01T00:00:00+00:00");
        let mut again = fresh();
        swap_cached_post_media(&db, &mut again, None).await;
        let count: i64 = db.query_one("SELECT count(*) FROM ap_media_warm_queue", &[]).await.unwrap().get(0);
        assert_eq!(count, 1);
        db.batch_execute("DROP TABLE remote_post_media_cache, ap_media_warm_queue").await.unwrap();
    }
}
