-- Bluesky lookup indexes already created on production.
-- Idempotent. Run this file on its own: CONCURRENTLY cannot run inside a
-- transaction. Deploy also ensures the same indexes from ap_db_migrate_postgres()
-- with CREATE INDEX IF NOT EXISTS after a cached existence check, which is a
-- no-op when these indexes are already present.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_lower_handle
    ON bsky_posts (lower(author_handle));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_reply_root
    ON bsky_posts (reply_root);
