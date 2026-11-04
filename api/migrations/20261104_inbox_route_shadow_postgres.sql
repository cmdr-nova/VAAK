-- Rust cutover F3 (shadow). With VAAK_INBOX_ROUTE_SHADOW=1 the Rust front
-- door stores, next to a spooled Delete or Tombstone, the result it predicts
-- ap_route_verified_activity will return (rust/vaak-worker/src/
-- inbox_route_shadow.rs). api/bin/inbox-spool-drain.php compares it with its
-- own result and deletes rows older than 7 days.
-- deploy/remote/migrate.php applies this file as the schema owner. Re-running
-- is a no-op once the table exists.
CREATE TABLE IF NOT EXISTS ap_inbox_route_shadow (
    spool_id BIGINT PRIMARY KEY,
    rust_result TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_ap_inbox_route_shadow_created
    ON ap_inbox_route_shadow (created_at);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_inbox_route_shadow TO "www-data";
