ALTER TABLE actor_profile
    ADD COLUMN IF NOT EXISTS webmentions_enabled INTEGER NOT NULL DEFAULT 1;
GRANT SELECT, INSERT, UPDATE ON actor_profile TO "www-data";
