-- Shared Bluesky post bodies are canonical; this relation records which
-- connected VAAK accounts have observed each post.
CREATE TABLE IF NOT EXISTS bsky_post_observations (
    bsky_uri TEXT NOT NULL,
    owner_user_id BIGINT NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    PRIMARY KEY (bsky_uri, owner_user_id)
);
CREATE INDEX IF NOT EXISTS idx_bsky_post_observations_owner_time
    ON bsky_post_observations(owner_user_id, last_seen_at DESC);
INSERT INTO bsky_post_observations (bsky_uri, owner_user_id, first_seen_at, last_seen_at)
SELECT bsky_uri, owner_user_id, COALESCE(seen_at, NOW()::text), COALESCE(seen_at, NOW()::text)
FROM bsky_posts
WHERE owner_user_id IS NOT NULL
ON CONFLICT (bsky_uri, owner_user_id) DO NOTHING;
GRANT SELECT, INSERT, UPDATE, DELETE ON bsky_post_observations TO "www-data";
