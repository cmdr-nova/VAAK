-- Same statements as vaak_migrate_cpu_indexes() in deploy/remote/migrate.php.
-- Deploy runs those statements automatically, outside a transaction, with
-- CREATE INDEX CONCURRENTLY. This file is NOT listed in
-- vaak_migrate_deploy_sql_files(): that loop refuses indexes on existing
-- tables and would build them with a write-blocking CREATE INDEX.
-- CONCURRENTLY cannot run inside a transaction. Re-running is a no-op when
-- the index is already valid. An invalid leftover from a failed concurrent
-- build is dropped and created again by the deploy helper.
--
-- idx_bsky_posts_lower_author_did matches
--   SELECT DISTINCT lower(author_handle) FROM bsky_posts
--   WHERE lower(author_did) IN (...)
-- idx_bsky_posts_seen matches the observation sync's seen_at window.
-- idx_bsky_post_observations_last_seen matches the fresh-window predicate
-- (last_seen_at older than about 10 minutes).
-- uniq_bsky_post_observations_uri_owner is not in this file. Deploy creates
-- it only when (bsky_uri, owner_user_id) has no valid unique index or
-- primary key, so a table that already has that primary key does not gain
-- a second unique index.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_lower_author_did
    ON bsky_posts (lower(author_did));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_seen
    ON bsky_posts (seen_at);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_post_observations_last_seen
    ON bsky_post_observations (last_seen_at);
