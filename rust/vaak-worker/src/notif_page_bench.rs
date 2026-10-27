//! End-to-end timing for the PHP-called notifications paint (`mentions_html_fill`).
//!
//! Ignored in CI. Needs local Postgres (`notifbench`) and Redis.
//! `cargo test --manifest-path rust/vaak-worker/Cargo.toml --bin vaak-worker notif_page_bench -- --ignored --nocapture --test-threads=1`

use super::mentions_html_fill;
use crate::config::Config;
use crate::notif_list::notifications_list_redis_key;
use serde_json::{json, Value};
use std::time::Instant;

const OWNER: i64 = 7;
const VIEWER: &str = "https://mkultra.monster/users/viewer";

fn cfg(database_url: &str, redis_url: &str) -> Config {
    Config {
        database_url: database_url.to_string(),
        redis_url: redis_url.to_string(),
        redis_queue_url: redis_url.to_string(),
        jetstream_state: std::path::PathBuf::from("/tmp"),
        default_owner_id: OWNER,
        bsky_public_api: "https://public.api.bsky.app".to_string(),
        notif_cache_dir: std::path::PathBuf::from("/tmp"),
        phyrian_mutation_token: None,
        phyrian_bridge_url: "https://strains.example.invalid".to_string(),
        phyrian_bridge_secret: None,
    }
}

fn account(acct: &str, name: &str, uri: &str) -> Value {
    json!({
        "id": acct,
        "acct": acct,
        "display_name": name,
        "username": acct,
        "uri": uri,
        "url": uri,
        "avatar": "https://cdn.example/avatars/a.webp",
        "avatar_static": "https://cdn.example/avatars/a.webp"
    })
}

fn status(id: &str, uri: &str, body: &str, visibility: &str, author: &Value, media: bool) -> Value {
    let mut st = json!({
        "id": id,
        "uri": uri,
        "url": uri,
        "content": format!("<p>{body}</p>"),
        "visibility": visibility,
        "created_at": "2026-10-27T03:00:00Z",
        "account": author,
        "emojis": [],
        "media_attachments": [],
        "mentions": [],
        "tags": [],
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0
    });
    if media {
        st["media_attachments"] = json!([{
            "id": "m1",
            "type": "image",
            "url": "https://cdn.example/media/pic.jpg",
            "preview_url": "https://cdn.example/media/pic-small.jpg",
            "meta": {}
        }]);
    }
    st
}

fn notif(id: i64, kind: &str, who: &Value, st: Option<Value>) -> Value {
    let mut row = json!({
        "id": id.to_string(),
        "type": kind,
        "created_at": "2026-10-27T03:00:00Z",
        "account": who
    });
    if let Some(st) = st {
        row["status"] = st;
    }
    row
}

fn page_items() -> Vec<Value> {
    let alice = account("alice", "Alice", "https://mkultra.monster/users/alice");
    let author = account("author", "Author", "https://remote.test/users/author");
    let stranger = account("stranger", "Stranger", "https://remote.test/users/stranger");
    let muted = account("muted", "Muted", "https://remote.test/users/muted");
    let blocked = account("blocked", "Blocked", "https://remote.test/users/blocked");
    let followed = account("followed", "FOLLOW-NOTIF-GAMMA", "https://remote.test/users/followed");
    let bsky = account("shown.bsky.social", "Shown", "https://bsky.app/profile/did:plc:shownshownshown");
    let evil = account("evil", "Evil", "https://evil.example/users/evil");
    let remote = account("remote", "Remote", "https://remote.test/users/remote");

    let mut items = vec![
        notif(1040, "mention", &alice, Some(status(
            "alpha",
            "https://mkultra.monster/users/alice/notes/alpha",
            "PUBLIC-MENTION-ALPHA :sparkle:",
            "public",
            &alice,
            false,
        ))),
        notif(1039, "favourite", &author, Some(status(
            "fav-1",
            "https://remote.test/users/author/statuses/fav",
            "PUBLIC-FAV-BETA",
            "public",
            &alice,
            false,
        ))),
        notif(1038, "follow", &followed, None),
        notif(1037, "favourite", &bsky, Some(status(
            "at://did:plc:shownshownshown/app.bsky.feed.post/3benchpost",
            "at://did:plc:shownshownshown/app.bsky.feed.post/3benchpost",
            "BSKY-POST-DELTA",
            "public",
            &bsky,
            false,
        ))),
        notif(1036, "quote", &author, Some({
            let mut outer = status(
                "quote-1",
                "https://remote.test/users/author/statuses/quote",
                "QUOTE-BODY-EPSILON",
                "public",
                &author,
                false,
            );
            outer["quote"] = json!({"quoted_status": status(
                "quoted-1",
                "https://remote.test/users/author/statuses/quoted",
                "QUOTED-PUBLIC-INNER",
                "public",
                &alice,
                false,
            )});
            outer
        })),
        notif(1035, "mention", &author, Some(status(
            "fol-ok",
            "https://remote.test/users/author/statuses/private-ok",
            "FOLLOWERS-OK-THETA",
            "private",
            &author,
            false,
        ))),
        notif(1034, "mention", &stranger, Some(status(
            "fol-no",
            "https://remote.test/users/stranger/statuses/private",
            "FOLLOWERS-ONLY-SECRET",
            "private",
            &stranger,
            false,
        ))),
        notif(1033, "mention", &stranger, Some(status(
            "dm-no",
            "https://remote.test/users/stranger/statuses/dm",
            "DM-ONLY-SECRET",
            "direct",
            &stranger,
            false,
        ))),
        notif(1032, "mention", &alice, Some(status(
            "dm-ok",
            "https://mkultra.monster/users/alice/notes/direct-ok",
            "DIRECT-OK-ZETA",
            "public",
            &alice,
            false,
        ))),
        notif(1031, "mention", &muted, Some(status(
            "muted-1",
            "https://remote.test/users/muted/statuses/1",
            "MUTED-ACTOR-SECRET",
            "public",
            &muted,
            false,
        ))),
        notif(1030, "mention", &blocked, Some(status(
            "blocked-1",
            "https://remote.test/users/blocked/statuses/1",
            "BLOCKED-ACTOR-SECRET",
            "public",
            &blocked,
            false,
        ))),
        notif(1029, "mention", &remote, Some(status(
            "media-1",
            "https://remote.test/users/remote/statuses/media",
            "MEDIA-PUBLIC-ETA",
            "public",
            &remote,
            true,
        ))),
        notif(1028, "mention", &remote, Some(status(
            "more-1",
            "https://remote.test/users/remote/statuses/more",
            "LOAD-MORE-PUBLIC",
            "public",
            &remote,
            false,
        ))),
        notif(1027, "mention", &stranger, Some(status(
            "more-dm",
            "https://remote.test/users/stranger/statuses/more-dm",
            "LOAD-MORE-DM-SECRET",
            "direct",
            &stranger,
            false,
        ))),
        notif(1026, "mention", &evil, Some(status(
            "evil-1",
            "https://evil.example/users/evil/statuses/1",
            "DOMAIN-BLOCK-SECRET",
            "public",
            &evil,
            false,
        ))),
    ];
    for n in 0..25 {
        let id = 1025 - n;
        items.push(notif(
            id,
            "mention",
            &remote,
            Some(status(
                &format!("fill-{id}"),
                &format!("https://remote.test/users/remote/statuses/fill-{id}"),
                &format!("FILLER-NOTE-{id}"),
                "public",
                &remote,
                false,
            )),
        ));
    }
    assert_eq!(items.len(), 40);
    items
}

fn clk_tck() -> u64 {
    let out = std::process::Command::new("getconf").arg("CLK_TCK").output().ok();
    out.and_then(|o| String::from_utf8(o.stdout).ok())
        .and_then(|s| s.trim().parse().ok())
        .unwrap_or(100)
}

fn proc_cpu_ticks(stat: &str) -> Option<u64> {
    let rest = stat.split_once(')')?.1;
    let mut fields = rest.split_whitespace();
    // After comm: state is field 3, utime field 14, stime field 15.
    let utime: u64 = fields.nth(11)?.parse().ok()?;
    let stime: u64 = fields.next()?.parse().ok()?;
    Some(utime + stime)
}

fn cpu_ticks_of(pid_prefix: &str) -> u64 {
    let mut total = 0;
    let Ok(entries) = std::fs::read_dir("/proc") else { return 0 };
    for entry in entries.flatten() {
        let name = entry.file_name();
        let Some(pid) = name.to_str() else { continue };
        if !pid.bytes().all(|b| b.is_ascii_digit()) {
            continue;
        }
        let Ok(comm) = std::fs::read_to_string(format!("/proc/{pid}/comm")) else { continue };
        if !comm.starts_with(pid_prefix) {
            continue;
        }
        if let Ok(stat) = std::fs::read_to_string(format!("/proc/{pid}/stat")) {
            total += proc_cpu_ticks(&stat).unwrap_or(0);
        }
    }
    total
}

fn pct(mut samples: Vec<u128>, p: usize) -> u128 {
    samples.sort_unstable();
    let idx = ((samples.len() - 1) * p) / 100;
    samples[idx]
}

#[tokio::test]
#[ignore = "local Postgres and Redis notification page benchmark"]
async fn notif_page_bench() {
    let database_url = std::env::var("NOTIF_BENCH_DATABASE").unwrap_or_else(|_| {
        "host=/var/run/postgresql user=ubuntu dbname=notifbench".into()
    });
    let redis_url = std::env::var("NOTIF_BENCH_REDIS").unwrap_or_else(|_| "redis://127.0.0.1/15".into());
    let db = crate::db::connect(&database_url).await.expect("postgres");
    db.batch_execute(SCHEMA).await.expect("schema");
    db.batch_execute(SEED).await.expect("seed");
    db.batch_execute(PRODUCTION_INDEXES).await.expect("indexes");
    db.batch_execute(NOTIFICATION_READ_INDEXES).await.expect("notification read indexes");
    db.batch_execute("ANALYZE").await.expect("analyze");

    let mut redis = crate::redis_util::connect(&redis_url).await.expect("redis");
    let key = notifications_list_redis_key(OWNER, 40, None, None, &[]);
    let payload = json!({"ts": 1, "items": page_items(), "source": "bench-projection"});
    crate::redis_util::json_set(&mut redis, &key, &payload, 600).await.expect("redis set");

    let cfg = cfg(&database_url, &redis_url);
    let cold_started = Instant::now();
    let first = mentions_html_fill(&cfg, OWNER, 12, &[], None).await.expect("first page").expect("first page miss");
    let cold_ms = cold_started.elapsed().as_millis();
    assert_visible(&first.html, true);
    let cursor = first.next_max_id.clone();
    assert!(!cursor.is_empty(), "first page cursor");
    let more = mentions_html_fill(&cfg, OWNER, 12, &[], Some(&cursor)).await.expect("load more").expect("load more miss");
    assert_visible(&more.html, false);

    // Warm steady state. One discarded pair, then the timed samples.
    let _ = mentions_html_fill(&cfg, OWNER, 12, &[], None).await;
    let _ = mentions_html_fill(&cfg, OWNER, 12, &[], Some(&cursor)).await;

    db.batch_execute("SELECT pg_stat_reset()").await.ok();
    let ticks = clk_tck();
    let self_before = cpu_ticks_of("vaak");
    // The test binary's comm is not vaak. Read this process directly.
    let self_stat_before = std::fs::read_to_string("/proc/self/stat").unwrap_or_default();
    let self_ticks_before = proc_cpu_ticks(&self_stat_before).unwrap_or(0);
    let pg_before = cpu_ticks_of("postgres");
    let wall_started = Instant::now();
    let mut first_samples = Vec::new();
    let mut more_samples = Vec::new();
    for _ in 0..7 {
        let t = Instant::now();
        let page = mentions_html_fill(&cfg, OWNER, 12, &[], None).await.unwrap().unwrap();
        first_samples.push(t.elapsed().as_micros());
        assert_visible(&page.html, true);
        let t = Instant::now();
        let page = mentions_html_fill(&cfg, OWNER, 12, &[], Some(&cursor)).await.unwrap().unwrap();
        more_samples.push(t.elapsed().as_micros());
        assert_visible(&page.html, false);
    }
    let wall_ms = wall_started.elapsed().as_millis();
    let self_stat_after = std::fs::read_to_string("/proc/self/stat").unwrap_or_default();
    let self_ticks = proc_cpu_ticks(&self_stat_after).unwrap_or(0).saturating_sub(self_ticks_before);
    let pg_ticks = cpu_ticks_of("postgres").saturating_sub(pg_before);
    let _ = self_before;
    let stats: Value = db.query_one(
        "SELECT xact_commit, blks_read, blks_hit, tup_returned, tup_fetched FROM pg_stat_database WHERE datname = current_database()",
        &[],
    ).await.map(|row| json!({
        "xact_commit": row.get::<_, i64>(0),
        "blks_read": row.get::<_, i64>(1),
        "blks_hit": row.get::<_, i64>(2),
        "tup_returned": row.get::<_, i64>(3),
        "tup_fetched": row.get::<_, i64>(4),
    })).unwrap_or(json!({"error": "stats"}));

    assert_events_predicate_parity(&db).await;
    let events_plan = explain(&db, EVENTS_EXPLAIN).await;
    let followers_plan = explain(&db, FOLLOWERS_EXPLAIN).await;
    let following_plan = explain(&db, FOLLOWING_EXPLAIN).await;

    let calls = 14.0;
    let report = json!({
        "bench": "notifications-page",
        "cold_first_page_ms": cold_ms,
        "warm_first_page_us": {"min": pct(first_samples.clone(), 0), "p50": pct(first_samples.clone(), 50), "p95": pct(first_samples, 95)},
        "warm_load_more_us": {"min": pct(more_samples.clone(), 0), "p50": pct(more_samples.clone(), 50), "p95": pct(more_samples, 95)},
        "samples": 7,
        "wall_ms_for_14_calls": wall_ms,
        "process_cpu_ms_per_call": (self_ticks * 1000 / ticks) as f64 / calls,
        "postgres_cpu_ms_per_call": (pg_ticks * 1000 / ticks) as f64 / calls,
        "db_stats_for_timed_calls": stats,
        "events_plan_head": events_plan,
        "followers_plan_head": followers_plan,
        "following_plan_head": following_plan,
        "first_html_bytes": first.html.len(),
        "load_more_html_bytes": more.html.len(),
        "first_count": first.count,
        "load_more_count": more.count,
    });
    println!("BENCH {report}");
}

fn assert_visible(html: &str, first_page: bool) {
    // The marker text stays out of the panic message. A failed check reports
    // only that private content leaked, never the content, a token, or an account id.
    for private_text in [
        "FOLLOWERS-ONLY-SECRET",
        "DM-ONLY-SECRET",
        "MUTED-ACTOR-SECRET",
        "BLOCKED-ACTOR-SECRET",
        "DOMAIN-BLOCK-SECRET",
        "LOAD-MORE-DM-SECRET",
    ] {
        assert!(!html.contains(private_text), "private notification content leaked");
    }
    if first_page {
        for marker in [
            "PUBLIC-MENTION-ALPHA",
            "PUBLIC-FAV-BETA",
            "FOLLOW-NOTIF-GAMMA",
            "BSKY-POST-DELTA",
            "QUOTE-BODY-EPSILON",
            "FOLLOWERS-OK-THETA",
            "DIRECT-OK-ZETA",
            "MEDIA-PUBLIC-ETA",
            "https://cdn.example/media/pic.jpg",
        ] {
            assert!(html.contains(marker), "missing {marker}");
        }
    } else {
        assert!(html.contains("LOAD-MORE-PUBLIC"), "missing load-more public note");
        assert!(!html.contains("PUBLIC-MENTION-ALPHA"), "load-more repeated the first page");
    }
}

async fn assert_events_predicate_parity(db: &tokio_postgres::Client) {
    db.batch_execute(
        "BEGIN;
         INSERT INTO events (id, type, object_id, action_taken, visibility, actor_id) VALUES
           (310001, 'Create', 'https://parity.example/notes/exact', 'log', 'public', 'https://parity.example/users/a'),
           (310000, 'Update', 'https://parity.example/notes/exact/', 'log', 'private', 'https://parity.example/users/a'),
           (310002, 'Create', 'https://parity.example/notes/exact//', 'log', 'direct', 'https://parity.example/users/a'),
           (310003, 'Quote', 'https://parity.example/notes/quoteonly', 'log', 'public', 'https://parity.example/users/q'),
           (310004, 'Quote', 'https://parity.example/notes/quoted', 'log', 'direct', 'https://parity.example/users/q'),
           (310005, 'Create', 'https://parity.example/notes/quoted', 'log', 'public', 'https://parity.example/users/q'),
           (310006, 'Quote', 'https://parity.example/notes/multislash', 'log', 'followers', 'https://parity.example/users/q'),
           (310007, 'Create', 'https://parity.example/notes/multislash//', 'log', 'public', 'https://parity.example/users/q');",
    ).await.expect("parity probes");
    let ids = [
        "https://parity.example/notes/exact",
        "https://parity.example/notes/quoteonly",
        "https://parity.example/notes/quoted",
        "https://parity.example/notes/multislash",
    ];
    let slashed: Vec<String> = ids.iter().map(|id| format!("{id}/")).collect();
    let ids: Vec<String> = ids.into_iter().map(str::to_owned).collect();
    let old = db.query(
        "SELECT DISTINCT ON (rtrim(object_id, '/')) rtrim(object_id, '/'), COALESCE(visibility, 'public')
         FROM events e WHERE (e.type IN ('Create','Update') OR (e.type IN ('Quote','QuotePost') AND NOT EXISTS (
              SELECT 1 FROM events note_source WHERE note_source.object_id IN (rtrim(e.object_id, '/'), rtrim(e.object_id, '/') || '/')
                AND note_source.type IN ('Create','Update'))))
           AND action_taken = ANY(ARRAY['log','local_observe','local_fav_update'])
           AND (object_id = ANY($1) OR object_id = ANY($2))
         ORDER BY rtrim(object_id, '/'), id DESC",
        &[&ids, &slashed],
    ).await.expect("old predicate");
    let new = db.query(
        "SELECT DISTINCT ON (rtrim(object_id, '/')) rtrim(object_id, '/'), COALESCE(visibility, 'public')
         FROM events e WHERE (e.type IN ('Create','Update') OR (e.type IN ('Quote','QuotePost') AND NOT EXISTS (
              SELECT 1 FROM events note_source
              WHERE rtrim(note_source.object_id, '/') = rtrim(e.object_id, '/')
                AND note_source.object_id IN (rtrim(e.object_id, '/'), rtrim(e.object_id, '/') || '/')
                AND note_source.type IN ('Create','Update'))))
           AND action_taken = ANY(ARRAY['log','local_observe','local_fav_update'])
           AND rtrim(object_id, '/') = ANY($1)
           AND (object_id = ANY($1) OR object_id = ANY($2))
         ORDER BY rtrim(object_id, '/'), id DESC",
        &[&ids, &slashed],
    ).await.expect("new predicate");
    let pack = |rows: Vec<tokio_postgres::Row>| -> Vec<(String, String)> {
        rows.into_iter().map(|row| (row.get(0), row.get(1))).collect()
    };
    let old = pack(old);
    let new = pack(new);
    assert_eq!(old, new, "events seek predicate changed the match set");
    assert!(old.iter().any(|(id, vis)| id.ends_with("/exact") && vis == "public"), "newer exact row wins over the slash alias");
    assert!(old.iter().any(|(id, vis)| id.ends_with("/quoteonly") && vis == "public"), "quote without a create source stays");
    assert!(old.iter().any(|(id, vis)| id.ends_with("/quoted") && vis == "public"), "create source hides the quote row");
    assert!(old.iter().any(|(id, vis)| id.ends_with("/multislash") && vis == "followers"), "multi-slash create is not a quote source");
    assert!(old.iter().all(|(id, _)| !id.ends_with('/')), "trimmed keys only");
    db.batch_execute("ROLLBACK").await.expect("parity rollback");
}

async fn explain(db: &tokio_postgres::Client, sql: &str) -> String {
    match db.query(&format!("EXPLAIN {sql}"), &[]).await {
        Ok(rows) => rows.iter().take(4).map(|row| row.get::<_, String>(0)).collect::<Vec<_>>().join(" | "),
        Err(e) => format!("explain failed: {e}"),
    }
}

const SCHEMA: &str = r#"
DROP TABLE IF EXISTS events, outbox_notes, mentions, followers, following, ap_users, actor_profile,
  ap_mutes, ap_user_blocks, ap_blocks, ap_muted_words, ap_deprioritized_actors, bsky_sessions,
  bsky_posts, bsky_actor_profiles, bsky_graph_sync, bsky_actor_viewers, bsky_crossposts, bsky_post_links,
  masto_favourites, masto_bookmarks, masto_reblogs, phyrian_requests, remote_custom_emojis, remote_actors,
  remote_media_cache CASCADE;
CREATE TABLE events (
  id BIGINT PRIMARY KEY,
  object_id TEXT,
  type TEXT,
  action_taken TEXT,
  visibility TEXT,
  actor_id TEXT
);
CREATE TABLE outbox_notes (
  id TEXT PRIMARY KEY,
  visibility TEXT,
  to_json TEXT,
  cc_json TEXT
);
CREATE TABLE mentions (
  id BIGINT PRIMARY KEY,
  owner_user_id BIGINT,
  object_id TEXT,
  deleted_at TEXT,
  activity_type TEXT
);
CREATE TABLE followers (owner_actor_id TEXT, actor_id TEXT);
CREATE TABLE following (owner_actor_id TEXT, actor_id TEXT);
CREATE TABLE ap_users (id BIGINT PRIMARY KEY, actor_id TEXT, actor_key TEXT, disabled_at TEXT);
CREATE TABLE actor_profile (
  actor_key TEXT PRIMARY KEY,
  name TEXT,
  icon_url TEXT,
  automated INTEGER,
  discoverable INTEGER,
  indexable INTEGER,
  vanity_verified INTEGER,
  auto_unblur_sensitive INTEGER
);
CREATE TABLE ap_mutes (owner_user_id BIGINT, actor_id TEXT);
CREATE TABLE ap_user_blocks (id BIGINT, owner_user_id BIGINT, scope TEXT, value TEXT);
CREATE TABLE ap_blocks (scope TEXT, value TEXT, kind TEXT);
CREATE TABLE ap_muted_words (owner_user_id BIGINT, phrase TEXT);
CREATE TABLE ap_deprioritized_actors (owner_user_id BIGINT, actor_id TEXT);
CREATE TABLE bsky_sessions (owner_user_id BIGINT, did TEXT, handle TEXT);
CREATE TABLE bsky_posts (
  bsky_uri TEXT PRIMARY KEY,
  author_did TEXT,
  author_handle TEXT,
  text TEXT,
  raw_json TEXT
);
CREATE TABLE bsky_actor_profiles (did TEXT, actor_ref TEXT, profile_json TEXT);
CREATE TABLE bsky_graph_sync (owner_user_id BIGINT, kind TEXT, target_did TEXT);
CREATE TABLE bsky_actor_viewers (owner_user_id BIGINT, actor_ref TEXT, following_uri TEXT);
CREATE TABLE bsky_crossposts (bsky_uri TEXT, note_id TEXT);
CREATE TABLE bsky_post_links (bsky_uri TEXT, fediverse_id TEXT, ap_object_id TEXT);
CREATE TABLE masto_favourites (owner_user_id BIGINT, status_id TEXT, object_id TEXT, created_at TEXT);
CREATE TABLE masto_bookmarks (owner_user_id BIGINT, status_id TEXT, object_id TEXT, created_at TEXT);
CREATE TABLE masto_reblogs (owner_user_id BIGINT, status_id TEXT, object_id TEXT, created_at TEXT);
CREATE TABLE phyrian_requests (to_owner_id BIGINT, status TEXT);
CREATE TABLE remote_custom_emojis (host TEXT, shortcode TEXT, url TEXT, static_url TEXT);
CREATE TABLE remote_actors (actor_id TEXT, username TEXT, host TEXT, display_name TEXT, icon_source_url TEXT, profile_json TEXT);
CREATE TABLE remote_media_cache (actor_id TEXT, kind TEXT, public_url TEXT);
"#;

const SEED: &str = r#"
INSERT INTO ap_users (id, actor_id, actor_key, disabled_at) VALUES
  (7, 'https://mkultra.monster/users/viewer', 'viewer', NULL),
  (8, 'https://mkultra.monster/users/alice', 'alice', NULL);
INSERT INTO actor_profile (actor_key, name, icon_url, automated, discoverable, indexable, vanity_verified, auto_unblur_sensitive) VALUES
  ('viewer', 'Viewer', 'https://cdn.example/avatars/viewer.webp', 0, 1, 1, 0, 0),
  ('alice', 'Alice', 'https://cdn.example/avatars/alice.webp', 0, 1, 1, 0, 0);
INSERT INTO outbox_notes (id, visibility, to_json, cc_json) VALUES
  ('https://mkultra.monster/users/alice/notes/alpha', 'public', '[]', '[]'),
  ('https://mkultra.monster/users/alice/notes/direct-ok', 'direct', '["https://mkultra.monster/users/viewer"]', '[]');
INSERT INTO events (id, object_id, type, action_taken, visibility, actor_id) VALUES
  (1, 'https://mkultra.monster/users/alice/notes/alpha', 'Create', 'log', 'public', 'https://mkultra.monster/users/alice'),
  (2, 'https://mkultra.monster/users/alice/notes/direct-ok', 'Create', 'log', 'direct', 'https://mkultra.monster/users/alice');
INSERT INTO events (id, object_id, type, action_taken, visibility, actor_id)
SELECT n, 'https://filler.invalid/statuses/' || n, 'Create', 'log', 'public', 'https://filler.invalid/users/filler'
FROM generate_series(10000, 210000) AS n;
INSERT INTO followers (owner_actor_id, actor_id)
SELECT 'https://filler.invalid/users/u' || n, 'https://filler.invalid/users/fan' || n
FROM generate_series(1, 400000) AS n;
INSERT INTO followers (owner_actor_id, actor_id) VALUES
  ('https://remote.test/users/author', 'https://mkultra.monster/users/viewer');
INSERT INTO following (owner_actor_id, actor_id)
SELECT 'https://filler.invalid/users/owner' || (n % 1000), 'https://remote.test/users/target' || n
FROM generate_series(1, 100000) AS n;
INSERT INTO following (owner_actor_id, actor_id)
SELECT 'https://mkultra.monster/users/viewer', 'https://remote.test/users/follow' || n
FROM generate_series(1, 400) AS n;
INSERT INTO mentions (id, owner_user_id, object_id, deleted_at, activity_type)
SELECT n, 7, 'https://filler.invalid/statuses/' || n, NULL, 'Create'
FROM generate_series(1, 20000) AS n;
INSERT INTO mentions (id, owner_user_id, object_id, deleted_at, activity_type) VALUES
  (20001, 7, 'https://mkultra.monster/users/alice/notes/direct-ok', NULL, 'Create');
INSERT INTO ap_mutes (owner_user_id, actor_id) VALUES
  (7, 'https://remote.test/users/muted'),
  (7, 'https://bsky.app/profile/did:plc:mutedmutedmuted');
INSERT INTO ap_user_blocks (id, owner_user_id, scope, value) VALUES
  (1, 7, 'actor', 'https://remote.test/users/blocked');
INSERT INTO ap_blocks (scope, value, kind) VALUES ('domain', 'evil.example', 'block');
INSERT INTO ap_muted_words (owner_user_id, phrase) VALUES (7, 'supercalifragilistic');
INSERT INTO bsky_sessions (owner_user_id, did, handle) VALUES
  (7, 'did:plc:viewerviewerviewer', 'viewer.bsky.social');
INSERT INTO bsky_posts (bsky_uri, author_did, author_handle, text, raw_json)
SELECT 'at://did:plc:filler/app.bsky.feed.post/' || n, 'did:plc:filler', 'filler.bsky.social', 'filler', '{}'
FROM generate_series(1, 120000) AS n;
INSERT INTO bsky_posts (bsky_uri, author_did, author_handle, text, raw_json) VALUES
  ('at://did:plc:shownshownshown/app.bsky.feed.post/3benchpost', 'did:plc:shownshownshown', 'shown.bsky.social', 'BSKY-POST-DELTA',
   '{"record":{"text":"BSKY-POST-DELTA","facets":[{"index":{"byteStart":0,"byteEnd":4},"features":[{"$type":"app.bsky.richtext.facet#link","uri":"https://example.com/bench"}]}]}}'),
  ('at://did:plc:mutedmutedmuted/app.bsky.feed.post/3muted', 'did:plc:mutedmutedmuted', 'muted.bsky.social', 'muted', '{}');
INSERT INTO bsky_actor_profiles (did, actor_ref, profile_json) VALUES
  ('did:plc:mutedmutedmuted', 'https://bsky.app/profile/muted.bsky.social', '{"did":"did:plc:mutedmutedmuted","handle":"muted.bsky.social"}');
INSERT INTO bsky_graph_sync (owner_user_id, kind, target_did)
SELECT 7, 'follow', 'did:plc:graph' || n FROM generate_series(1, 200) AS n;
INSERT INTO remote_custom_emojis (host, shortcode, url, static_url) VALUES
  ('mkultra.monster', 'sparkle', 'https://cdn.example/emoji/sparkle.webp', 'https://cdn.example/emoji/sparkle.webp');
INSERT INTO masto_favourites (owner_user_id, status_id, object_id, created_at) VALUES
  (7, 'fav-1', 'https://remote.test/users/author/statuses/fav', '2026-10-27T03:00:00Z');
INSERT INTO masto_favourites (owner_user_id, status_id, object_id, created_at)
SELECT 7, 'noise-' || n, 'https://filler.invalid/statuses/' || n, '2026-10-01T00:00:00Z'
FROM generate_series(1, 30000) AS n;
INSERT INTO remote_actors (actor_id, username, host, display_name, icon_source_url, profile_json) VALUES
  ('https://remote.test/users/blocked', 'blocked', 'remote.test', 'Blocked', '', '{}');
"#;

const PRODUCTION_INDEXES: &str = r#"
CREATE INDEX idx_events_object_key_id ON events (rtrim(object_id, '/'), id DESC);
CREATE INDEX idx_mentions_owner_live_id ON mentions (owner_user_id, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX idx_followers_owner ON followers (owner_actor_id);
CREATE INDEX idx_following_owner ON following (owner_actor_id);
CREATE INDEX idx_bsky_posts_lower_author_did ON bsky_posts (lower(author_did));
CREATE INDEX idx_bsky_posts_lower_handle ON bsky_posts (lower(author_handle));
CREATE INDEX idx_bsky_posts_post_rkey ON bsky_posts (split_part(bsky_uri, '/app.bsky.feed.post/', 2));
CREATE INDEX idx_masto_favourites_owner_created ON masto_favourites (owner_user_id, created_at);
CREATE INDEX idx_masto_bookmarks_owner_created ON masto_bookmarks (owner_user_id, created_at);
CREATE INDEX idx_masto_reblogs_owner_created ON masto_reblogs (owner_user_id, created_at);
"#;

const NOTIFICATION_READ_INDEXES: &str = r#"
CREATE INDEX idx_followers_actor_key ON followers (rtrim(actor_id, '/'));
CREATE INDEX idx_following_owner_actor_key ON following (rtrim(owner_actor_id, '/')) WHERE actor_id NOT LIKE 'https://mkultra.monster/users/%';
CREATE INDEX idx_mentions_owner_object_live ON mentions (owner_user_id, object_id) WHERE deleted_at IS NULL;
"#;

const EVENTS_EXPLAIN: &str = r#"
SELECT DISTINCT ON (rtrim(object_id, '/')) rtrim(object_id, '/'), COALESCE(visibility, 'public'), rtrim(COALESCE(actor_id, ''), '/')
FROM events e
WHERE (e.type IN ('Create','Update') OR (e.type IN ('Quote','QuotePost') AND NOT EXISTS (
        SELECT 1 FROM events note_source
        WHERE rtrim(note_source.object_id, '/') = rtrim(e.object_id, '/')
          AND note_source.object_id IN (rtrim(e.object_id, '/'), rtrim(e.object_id, '/') || '/')
          AND note_source.type IN ('Create','Update'))))
  AND action_taken = ANY(ARRAY['log','local_observe','local_fav_update'])
  AND rtrim(object_id, '/') = ANY(ARRAY['https://mkultra.monster/users/alice/notes/alpha']::text[])
  AND (object_id = ANY(ARRAY['https://mkultra.monster/users/alice/notes/alpha']::text[])
    OR object_id = ANY(ARRAY['https://mkultra.monster/users/alice/notes/alpha/']::text[]))
ORDER BY rtrim(object_id, '/'), id DESC
"#;

const FOLLOWERS_EXPLAIN: &str = r#"
SELECT rtrim(owner_actor_id, '/') FROM followers WHERE rtrim(actor_id, '/') = 'https://mkultra.monster/users/viewer'
"#;

const FOLLOWING_EXPLAIN: &str = r#"
SELECT rtrim(actor_id, '/') FROM following WHERE rtrim(owner_actor_id, '/') = 'https://mkultra.monster/users/viewer'
  AND actor_id NOT LIKE 'https://mkultra.monster/users/%'
"#;
