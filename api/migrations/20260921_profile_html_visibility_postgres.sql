-- Per-account controls for hiding replies and boosts from public HTML profiles.
-- Apply as the PostgreSQL schema owner before or alongside the PHP deployment.
ALTER TABLE actor_profile
    ADD COLUMN IF NOT EXISTS hide_profile_replies INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS hide_profile_boosts INTEGER NOT NULL DEFAULT 0;

GRANT SELECT, INSERT, UPDATE ON actor_profile TO "www-data";
