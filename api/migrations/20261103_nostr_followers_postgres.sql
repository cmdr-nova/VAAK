-- Nostr follow notifications. deploy/remote/migrate.php applies this file as
-- the schema owner before the release goes live. Re-running is a no-op.
--
-- Nostr has no follow activity: following someone means publishing a new
-- kind 3 contact list that lists them, and every later edit of that list
-- lists them again. `vaak-worker nostr-sync` (nostr/notif.rs) reads kind 3
-- lists that tag a linked npub and remembers each author here, so only the
-- first list that names the user notifies ("followed you"), not every edit.
-- The first read for a linked key fills this table without notifying, so
-- people who already followed don't show up as new.
-- Times are unix seconds.
CREATE TABLE IF NOT EXISTS nostr_followers (
    owner_user_id BIGINT NOT NULL,
    pubkey TEXT NOT NULL,
    seen_at BIGINT NOT NULL,
    PRIMARY KEY (owner_user_id, pubkey)
);

-- Which linked key the silent first read was done for (a new key reads again).
CREATE TABLE IF NOT EXISTS nostr_follower_seed (
    owner_user_id BIGINT PRIMARY KEY,
    pubkey TEXT NOT NULL,
    seeded_at BIGINT NOT NULL
);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_followers TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_follower_seed TO "www-data";
