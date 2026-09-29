CREATE TABLE IF NOT EXISTS ap_home_downrank_audit (
    id BIGSERIAL PRIMARY KEY,
    admin_user_id BIGINT NOT NULL DEFAULT 0,
    admin_actor TEXT NOT NULL DEFAULT '',
    action TEXT NOT NULL,
    actor_id TEXT NOT NULL DEFAULT '',
    term_id BIGINT NOT NULL DEFAULT 0,
    phrase TEXT NOT NULL DEFAULT '',
    details_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ap_home_downrank_audit_created
    ON ap_home_downrank_audit(created_at DESC, id DESC);
GRANT SELECT, INSERT ON ap_home_downrank_audit TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE ap_home_downrank_audit_id_seq TO "www-data";
