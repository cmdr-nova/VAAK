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
-- idx_remote_actors_lower_host_username matches the actor alias lookup in
-- ap_masto_actor_id_aliases() and the Rust search alias lookup:
--   WHERE lower(COALESCE(host,'')) = ? AND lower(COALESCE(username,'')) = ?
-- idx_events_local_timeline_created matches the newest-local-posts reads in
-- the Rust Home warm (cached recommendations, local_only) and the
-- newer-items check, which filter on
--   action_taken IN ('log','local_observe')
--   AND actor_id LIKE 'https://mkultra.monster/users/%'
-- and sort by created_at DESC, id DESC. Without it Postgres walks every event
-- in the window to find the few local ones.
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
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_remote_actors_lower_host_username
    ON remote_actors (lower(COALESCE(host, '')), lower(COALESCE(username, '')));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_local_timeline_created
    ON events (created_at DESC, id DESC)
    WHERE action_taken IN ('log', 'local_observe') AND actor_id LIKE 'https://mkultra.monster/users/%';
