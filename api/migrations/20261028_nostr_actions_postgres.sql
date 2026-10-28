-- Nostr likes, replies and follows from Home (Settings > Profile npub link).
-- deploy/remote/migrate.php applies this file as the schema owner before
-- the release goes live. Re-running is a no-op once the tables exist.
--
-- VAAK never holds a Nostr private key: the browser's signer extension
-- (NIP-07) signs every event and the worker only checks and relays it.
-- Times are unix seconds.

-- The owner's own kind 7 likes, so Home can show the filled heart and an
-- unlike can delete the right reaction (NIP-09).
CREATE TABLE IF NOT EXISTS nostr_reactions (
    owner_user_id BIGINT NOT NULL,
    note_id TEXT NOT NULL,
    reaction_id TEXT NOT NULL,
    created_at BIGINT NOT NULL,
    PRIMARY KEY (owner_user_id, note_id)
);

-- The follow list (kind 3) each follow or unfollow replaced, newest kept
-- per owner, so a bad edit can be put back by republishing the old event.
CREATE TABLE IF NOT EXISTS nostr_contact_backups (
    id BIGSERIAL PRIMARY KEY,
    owner_user_id BIGINT NOT NULL,
    event_id TEXT NOT NULL,
    event_json JSONB NOT NULL,
    follow_count INTEGER NOT NULL,
    backed_up_at BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_nostr_contact_backups_owner
    ON nostr_contact_backups (owner_user_id, id DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_reactions TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_contact_backups TO "www-data";
GRANT USAGE, SELECT ON SEQUENCE nostr_contact_backups_id_seq TO "www-data";
