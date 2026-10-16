-- Same statement as vaak_migrate_events_action_actor_indexes() in
-- deploy/remote/migrate.php. Deploy runs it automatically, outside a
-- transaction, with CREATE INDEX CONCURRENTLY. This file is NOT listed in
-- vaak_migrate_deploy_sql_files(): that loop refuses indexes on existing
-- tables and would build them with a write-blocking CREATE INDEX.
-- CONCURRENTLY cannot run inside a transaction. Re-running is a no-op when
-- the index is already valid. An invalid leftover from a failed concurrent
-- build is dropped and created again by the deploy helper.
--
-- Home suggestions loads every distinct actor_id for action_taken =
-- 'bsky_follow'. The partial index matches
--   WHERE action_taken = ? AND actor_id IS NOT NULL
--   ORDER BY actor_id
-- A page load must not create this index.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_action_actor
    ON events (action_taken, actor_id)
    WHERE actor_id IS NOT NULL;
