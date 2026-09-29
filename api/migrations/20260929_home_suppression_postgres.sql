-- Temporary, viewer-specific Home soft-downranking state.
CREATE TABLE IF NOT EXISTS ap_home_suppression (
    owner_user_id BIGINT NOT NULL,
    actor_id TEXT NOT NULL,
    score INTEGER NOT NULL DEFAULT 0,
    categories_json TEXT NOT NULL DEFAULT '[]',
    suppressed_until TEXT NOT NULL,
    last_object_id TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, actor_id)
);
CREATE INDEX IF NOT EXISTS idx_ap_home_suppression_until
    ON ap_home_suppression(owner_user_id, suppressed_until);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_home_suppression TO "www-data";
