-- Apply as the PostgreSQL schema owner before deploying the PHP changes.
CREATE TABLE IF NOT EXISTS ap_publish_delivery_queue (
    id BIGSERIAL PRIMARY KEY,
    owner_user_id BIGINT NOT NULL,
    note_id TEXT NOT NULL UNIQUE,
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL,
    claimed_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ap_publish_delivery_due
    ON ap_publish_delivery_queue(status, next_attempt_at, id);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_publish_delivery_queue TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE ap_publish_delivery_queue_id_seq TO "www-data";
