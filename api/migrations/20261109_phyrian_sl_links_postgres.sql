-- Phyrian Strains: a Second Life avatar linked onto the VAAK user's grid avatar.
-- api/ap-phyrian-sl.php reads/writes these; strains holds the matching alias
-- (strain_avatar_alias) so the SL avatar plays the grid strain. No player stats
-- live here. deploy/remote/migrate.php applies this as the schema owner. Idempotent.
CREATE TABLE IF NOT EXISTS phyrian_sl_links (
    owner_user_id INTEGER PRIMARY KEY,
    sl_uuid TEXT NOT NULL UNIQUE,
    sl_name TEXT NOT NULL DEFAULT '',
    grid_avatar_uuid TEXT NOT NULL,
    verified_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS phyrian_sl_challenges (
    id BIGSERIAL PRIMARY KEY,
    owner_user_id INTEGER NOT NULL,
    sl_uuid TEXT NOT NULL,
    sl_name TEXT NOT NULL DEFAULT '',
    grid_avatar_uuid TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS phyrian_sl_challenges_owner_idx
    ON phyrian_sl_challenges (owner_user_id, expires_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON phyrian_sl_links TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON phyrian_sl_challenges TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE phyrian_sl_challenges_id_seq TO "www-data";
