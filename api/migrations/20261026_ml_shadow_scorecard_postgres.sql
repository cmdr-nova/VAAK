-- Nightly shadow-ranker scorecard. One row per owner per US Eastern day.
-- The JSON is personal ranking metrics, not a post: it is not federated
-- and it is not exposed by a public API.
-- deploy/remote/migrate.php applies this file as the schema owner before
-- the release goes live. Re-running is a no-op once the table exists.
CREATE TABLE IF NOT EXISTS ap_ml_shadow_scorecard (
    id BIGSERIAL PRIMARY KEY,
    owner_user_id BIGINT NOT NULL,
    run_on TEXT NOT NULL,
    model_version INTEGER NOT NULL,
    summary_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (owner_user_id, run_on)
);
CREATE INDEX IF NOT EXISTS idx_ap_ml_shadow_scorecard_owner
    ON ap_ml_shadow_scorecard (owner_user_id, run_on DESC);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_ml_shadow_scorecard TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE ap_ml_shadow_scorecard_id_seq TO "www-data";
