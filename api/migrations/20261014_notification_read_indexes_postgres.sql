-- Notification read indexes. Idempotent. Run this file on its own:
-- CONCURRENTLY cannot run inside a transaction, so this file is NOT listed
-- in vaak_migrate_deploy_sql_files(). deploy/remote/migrate.php applies the
-- same indexes from ap_db_migrate_postgres() with CREATE INDEX IF NOT EXISTS
-- after a cached existence check, which is a no-op when they are already present.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_object_key_id
    ON events (rtrim(object_id, '/'), id DESC);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_mentions_owner_live_id
    ON mentions (owner_user_id, id DESC) WHERE deleted_at IS NULL;
