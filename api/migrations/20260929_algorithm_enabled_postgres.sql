-- Home recommendation preference. Run as the database owner during deploy.
ALTER TABLE actor_profile
    ADD COLUMN IF NOT EXISTS algorithm_enabled INTEGER NOT NULL DEFAULT 1;

