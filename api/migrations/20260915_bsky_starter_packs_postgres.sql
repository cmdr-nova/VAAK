-- Durable per-account cache for Bluesky Starter Packs shown on Library → Collections.
-- Run as the PostgreSQL schema owner. The web role must not provision tables.
CREATE TABLE IF NOT EXISTS bsky_starter_pack_cache (
    owner_user_id BIGINT NOT NULL,
    pack_uri TEXT NOT NULL,
    list_uri TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    members_json TEXT NOT NULL DEFAULT '[]',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, pack_uri)
);
CREATE INDEX IF NOT EXISTS idx_bsky_starter_pack_cache_owner
    ON bsky_starter_pack_cache (owner_user_id, updated_at DESC);

CREATE TABLE IF NOT EXISTS bsky_starter_pack_sync_state (
    owner_user_id BIGINT PRIMARY KEY,
    synced_at TEXT,
    updated_at TEXT NOT NULL
);

GRANT SELECT, INSERT, UPDATE, DELETE ON bsky_starter_pack_cache TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON bsky_starter_pack_sync_state TO "www-data";
