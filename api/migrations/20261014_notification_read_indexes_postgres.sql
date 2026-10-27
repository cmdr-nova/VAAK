-- Same statements as vaak_migrate_notification_read_indexes() in
-- deploy/remote/migrate.php. Deploy runs those statements automatically,
-- outside a transaction, with CREATE INDEX CONCURRENTLY. This file is NOT
-- listed in vaak_migrate_deploy_sql_files(): that loop refuses indexes on
-- existing tables and would build them with a write-blocking CREATE INDEX.
-- CONCURRENTLY cannot run inside a transaction. Re-running is a no-op when
-- the index is already valid.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_object_key_id
    ON events (rtrim(object_id, '/'), id DESC);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_mentions_owner_live_id
    ON mentions (owner_user_id, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_followers_actor_key
    ON followers (rtrim(actor_id, '/'));
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_following_owner_actor_key
    ON following (rtrim(owner_actor_id, '/')) WHERE actor_id NOT LIKE 'https://mkultra.monster/users/%';
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_mentions_owner_object_live
    ON mentions (owner_user_id, object_id) WHERE deleted_at IS NULL;
