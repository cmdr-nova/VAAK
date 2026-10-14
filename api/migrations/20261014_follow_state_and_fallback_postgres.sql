-- Follow acceptance state (pending / accepted / rejected) and the small
-- tables the silent-host fallback uses. Additive and idempotent.
--
-- ADD COLUMN with a constant default is a catalog-only change on
-- PostgreSQL 11+ and does not rewrite `following`. Indexes are created
-- CONCURRENTLY so a live database is not blocked. Run this file on its
-- own: CONCURRENTLY cannot run inside a transaction.
--
-- Runtime ap_db_migrate_postgres() adds the same columns and the small
-- indexes with IF NOT EXISTS after a cached existence check. It does not
-- build them CONCURRENTLY. `following` is the follow graph, not bsky_posts.
-- Do not scan bsky_posts from this migration.

ALTER TABLE following ADD COLUMN IF NOT EXISTS follow_state TEXT NOT NULL DEFAULT 'unknown';
ALTER TABLE following ADD COLUMN IF NOT EXISTS follow_activity_id TEXT;
ALTER TABLE following ADD COLUMN IF NOT EXISTS state_at TEXT;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_following_host_state
    ON following (host, follow_state);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_following_state_at
    ON following (follow_state, state_at);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_following_follow_activity
    ON following (follow_activity_id);

CREATE TABLE IF NOT EXISTS ap_inbox_host_seen (
    host TEXT PRIMARY KEY,
    last_seen_at TEXT NOT NULL,
    last_type TEXT
);

CREATE TABLE IF NOT EXISTS ap_follow_fallback_state (
    actor_id TEXT PRIMARY KEY,
    host TEXT NOT NULL,
    outbox_url TEXT,
    featured_url TEXT,
    etag TEXT,
    last_modified TEXT,
    featured_etag TEXT,
    featured_last_modified TEXT,
    next_attempt_at TEXT,
    fail_count INTEGER NOT NULL DEFAULT 0,
    last_status INTEGER,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_follow_fallback_next
    ON ap_follow_fallback_state (next_attempt_at);
