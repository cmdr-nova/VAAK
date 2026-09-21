-- Apply as the PostgreSQL schema owner before deploying.
CREATE TABLE IF NOT EXISTS ap_fanout_delivery_queue (
    id BIGSERIAL PRIMARY KEY,
    activity_key TEXT NOT NULL,
    inbox_url TEXT NOT NULL,
    activity_json TEXT NOT NULL,
    key_id TEXT NOT NULL,
    priv_path TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL,
    claimed_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(activity_key, inbox_url)
);
CREATE INDEX IF NOT EXISTS idx_ap_fanout_delivery_due ON ap_fanout_delivery_queue(status, next_attempt_at, id);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_fanout_delivery_queue TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE ap_fanout_delivery_queue_id_seq TO "www-data";
