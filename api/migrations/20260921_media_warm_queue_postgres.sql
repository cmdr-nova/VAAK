-- Durable remote actor-media warming queue.
-- Run as the PostgreSQL schema owner during deployment.
CREATE TABLE IF NOT EXISTS ap_media_warm_queue (
    id BIGSERIAL PRIMARY KEY,
    job_type TEXT NOT NULL DEFAULT 'actor_media',
    target_key TEXT NOT NULL,
    media_kind TEXT NOT NULL DEFAULT 'avatar_header',
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 8,
    next_attempt_at TEXT NOT NULL,
    claimed_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(job_type, target_key, media_kind)
);
CREATE INDEX IF NOT EXISTS idx_ap_media_warm_due
    ON ap_media_warm_queue(status, next_attempt_at, id);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_media_warm_queue TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE ap_media_warm_queue_id_seq TO "www-data";
