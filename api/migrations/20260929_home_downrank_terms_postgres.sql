-- Admin-managed phrase vocabulary for temporary, viewer-specific Home soft-downranking.
CREATE TABLE IF NOT EXISTS ap_home_downrank_terms (
    id BIGSERIAL PRIMARY KEY,
    phrase TEXT NOT NULL UNIQUE,
    category TEXT NOT NULL DEFAULT 'custom',
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ap_home_downrank_terms_enabled
    ON ap_home_downrank_terms(enabled, category, phrase);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_home_downrank_terms TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE ap_home_downrank_terms_id_seq TO "www-data";
