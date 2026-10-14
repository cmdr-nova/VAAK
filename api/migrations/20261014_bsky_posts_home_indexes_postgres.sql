-- Same statements as vaak_migrate_home_bsky_indexes() in
-- deploy/remote/migrate.php. Deploy runs those statements automatically,
-- outside a transaction, with CREATE INDEX CONCURRENTLY. This file is NOT
-- listed in vaak_migrate_deploy_sql_files(): that loop refuses indexes on
-- existing tables and would build them with a write-blocking CREATE INDEX.
-- CONCURRENTLY cannot run inside a transaction. Re-running is a no-op when
-- the index is already valid. An invalid leftover from a failed concurrent
-- build is dropped and created again by the deploy helper.
--
-- idx_bsky_posts_post_rkey uses the same split_part expression as the Home
-- rkey lookup. idx_bsky_posts_embed_indexed is the partial index for the
-- thin-media scan (embed_json IS NOT NULL, newest indexed_at first).
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_post_rkey
    ON bsky_posts (split_part(bsky_uri, '/app.bsky.feed.post/', 2));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_embed_indexed
    ON bsky_posts (indexed_at DESC NULLS LAST) WHERE embed_json IS NOT NULL;
