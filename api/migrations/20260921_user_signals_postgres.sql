CREATE TABLE IF NOT EXISTS ap_user_signals (
    id BIGSERIAL PRIMARY KEY,
    owner_user_id BIGINT NOT NULL,
    platform TEXT NOT NULL,
    signal_type TEXT NOT NULL,
    target_key TEXT NOT NULL,
    weight DOUBLE PRECISION NOT NULL DEFAULT 1,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ap_user_signals_owner_time
    ON ap_user_signals(owner_user_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_ap_user_signals_target
    ON ap_user_signals(owner_user_id, signal_type, target_key, created_at DESC);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_user_signals TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE ap_user_signals_id_seq TO "www-data";
