-- Read-only Nostr follows in Home (opt-in per user, Settings > Profile).
-- deploy/remote/migrate.php applies this file as the schema owner before
-- the release goes live. Re-running is a no-op once the tables exist.
--
-- Settings writes nostr_links (a public key only; VAAK never stores a Nostr
-- private key). `vaak-worker nostr-sync` (deploy/vaak-nostr-sync.timer) is
-- the only writer of the other tables. Home reads nostr_events for the
-- pubkeys in nostr_follows. The sync deletes notes older than
-- VAAK_NOSTR_RETENTION_DAYS (default 30) and anything nobody follows.
-- Times are unix seconds except created_at/updated_at on nostr_links.
CREATE TABLE IF NOT EXISTS nostr_links (
    owner_user_id BIGINT PRIMARY KEY,
    pubkey TEXT NOT NULL,
    show_in_home BOOLEAN NOT NULL DEFAULT TRUE,
    follow_count INTEGER NOT NULL DEFAULT 0,
    follow_list_at BIGINT NOT NULL DEFAULT 0,
    synced_at BIGINT NOT NULL DEFAULT 0,
    next_sync_at BIGINT NOT NULL DEFAULT 0,
    failures INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS nostr_follows (
    owner_user_id BIGINT NOT NULL,
    pubkey TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, pubkey)
);
CREATE INDEX IF NOT EXISTS idx_nostr_follows_pubkey ON nostr_follows (pubkey);

-- Kind 0 profile and kind 10002 write relays of linked users and the
-- people they follow.
CREATE TABLE IF NOT EXISTS nostr_profiles (
    pubkey TEXT PRIMARY KEY,
    name TEXT NOT NULL DEFAULT '',
    display_name TEXT NOT NULL DEFAULT '',
    picture TEXT NOT NULL DEFAULT '',
    nip05 TEXT NOT NULL DEFAULT '',
    profile_at BIGINT NOT NULL DEFAULT 0,
    write_relays JSONB NOT NULL DEFAULT '[]'::jsonb,
    relays_at BIGINT NOT NULL DEFAULT 0,
    meta_fetched_at BIGINT NOT NULL DEFAULT 0,
    notes_synced_at BIGINT NOT NULL DEFAULT 0
);

-- Signature-checked kind 1 notes. reply_pubkeys holds the note's p tags
-- when it is a reply and reply_to the parent note id, so Home can keep
-- replies inside the follow graph.
CREATE TABLE IF NOT EXISTS nostr_events (
    id TEXT PRIMARY KEY,
    pubkey TEXT NOT NULL,
    kind INTEGER NOT NULL,
    created_at BIGINT NOT NULL,
    content TEXT NOT NULL,
    tags JSONB NOT NULL DEFAULT '[]'::jsonb,
    is_reply BOOLEAN NOT NULL DEFAULT FALSE,
    reply_pubkeys TEXT[] NOT NULL DEFAULT '{}',
    reply_to TEXT NOT NULL DEFAULT '',
    relay TEXT NOT NULL DEFAULT '',
    ingested_at BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_nostr_events_pubkey_created
    ON nostr_events (pubkey, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_nostr_events_created ON nostr_events (created_at);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_links TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_follows TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_profiles TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_events TO "www-data";
