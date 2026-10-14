-- Local Phyrian clans. deploy/remote/migrate.php applies this file as the
-- schema owner before the release goes live. Idempotent. The game page does
-- not create or alter tables on request.
CREATE TABLE IF NOT EXISTS phyrian_clans (
    id BIGSERIAL PRIMARY KEY,
    owner_user_id BIGINT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    emblem_media_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_phyrian_clans_owner ON phyrian_clans (owner_user_id);

CREATE TABLE IF NOT EXISTS phyrian_clan_members (
    clan_id BIGINT NOT NULL,
    owner_user_id BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (clan_id, owner_user_id)
);
CREATE INDEX IF NOT EXISTS idx_phyrian_clan_members_owner ON phyrian_clan_members (owner_user_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON phyrian_clans TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON phyrian_clan_members TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE phyrian_clans_id_seq TO "www-data";
