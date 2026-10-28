-- Nostr moderation. deploy/remote/migrate.php applies this
-- file as the schema owner before the release goes live. Re-running is a
-- no-op.
--
-- nostr_mutes: people one VAAK user doesn't want to see. on_nostr rows
-- mirror the public p tags of the user's own NIP-51 mute list (kind
-- 10000), synced from relays and edited through the signer extension;
-- hidden_vaak rows are VAAK-only and never published. Times are unix
-- seconds.
CREATE TABLE IF NOT EXISTS nostr_mutes (
    owner_user_id BIGINT NOT NULL,
    pubkey TEXT NOT NULL,
    on_nostr BOOLEAN NOT NULL DEFAULT FALSE,
    hidden_vaak BOOLEAN NOT NULL DEFAULT FALSE,
    created_at BIGINT NOT NULL,
    PRIMARY KEY (owner_user_id, pubkey)
);

-- Keys an admin blocked for everyone on VAAK.
CREATE TABLE IF NOT EXISTS nostr_blocks (
    pubkey TEXT PRIMARY KEY,
    blocked_by BIGINT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL
);

-- When each linked user's stored mute list (kind 10000) was signed, so
-- an older copy from a slow relay never wins over a newer one.
CREATE TABLE IF NOT EXISTS nostr_mute_lists (
    owner_user_id BIGINT PRIMARY KEY,
    list_at BIGINT NOT NULL DEFAULT 0
);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_mutes TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_blocks TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_mute_lists TO "www-data";
