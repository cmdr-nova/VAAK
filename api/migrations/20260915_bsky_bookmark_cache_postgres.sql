-- Durable, per-account cache for connected Bluesky bookmark views.
-- Run as the PostgreSQL schema owner; the web role must not provision tables.
CREATE TABLE IF NOT EXISTS bsky_bookmark_cache (
    owner_user_id BIGINT NOT NULL,
    bookmark_uri TEXT NOT NULL,
    bookmarked_at TEXT NOT NULL,
    post_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, bookmark_uri)
);

CREATE INDEX IF NOT EXISTS idx_bsky_bookmark_cache_owner_time
    ON bsky_bookmark_cache (owner_user_id, bookmarked_at DESC);

CREATE TABLE IF NOT EXISTS bsky_bookmark_sync_state (
    owner_user_id BIGINT PRIMARY KEY,
    head_checked_at TEXT,
    full_synced_at TEXT,
    updated_at TEXT NOT NULL
);
