-- Inbox deliveries the Rust worker accepted (Rust cutover F2). The worker
-- inserts verified, actor-signed activities here and answers 202;
-- api/bin/inbox-spool-drain.php routes each row once through the same PHP
-- code the inbox uses. deploy/remote/migrate.php applies this file as the
-- schema owner. Re-running is a no-op once the table exists.
CREATE TABLE IF NOT EXISTS ap_inbox_spool (
    id BIGSERIAL PRIMARY KEY,
    received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    client_ip TEXT NOT NULL DEFAULT '',
    key_id TEXT NOT NULL,
    request_path TEXT NOT NULL,
    signature_path TEXT NOT NULL,
    activity_type TEXT NOT NULL,
    body TEXT NOT NULL,
    state TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    claimed_at TIMESTAMPTZ,
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ,
    result TEXT
);
CREATE INDEX IF NOT EXISTS idx_ap_inbox_spool_pending
    ON ap_inbox_spool (next_attempt_at, id) WHERE state IN ('pending', 'processing');
CREATE INDEX IF NOT EXISTS idx_ap_inbox_spool_finished
    ON ap_inbox_spool (finished_at) WHERE finished_at IS NOT NULL;
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_inbox_spool TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE ap_inbox_spool_id_seq TO "www-data";
