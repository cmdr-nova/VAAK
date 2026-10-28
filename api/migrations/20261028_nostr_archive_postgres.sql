-- Nostr backup relay: a permanent copy of linked users' own events.
-- deploy/remote/migrate.php applies this file as the schema owner before
-- the release goes live. Re-running is a no-op.
--
-- Only events signed by an npub in nostr_links (of an active user) land
-- here: from `vaak-worker nostr-sync` (backfill, then incremental pulls from
-- public relays), from VAAK's own signed actions, and from clients writing
-- to `vaak-worker nostr-relay`. Nothing expires by age. A kind 5 from the
-- author removes what it names; an unlinked key is purged 30 days after its
-- link went away. event_json is the signed event exactly as relays serve it.
-- Times are unix seconds.
CREATE TABLE IF NOT EXISTS nostr_archive (
    id TEXT PRIMARY KEY,
    pubkey TEXT NOT NULL,
    kind INTEGER NOT NULL,
    created_at BIGINT NOT NULL,
    -- `d` tag of addressable kinds (30000-39999), else ''.
    d_tag TEXT NOT NULL DEFAULT '',
    tags JSONB NOT NULL DEFAULT '[]'::jsonb,
    event_json TEXT NOT NULL,
    -- An older copy of a replaceable event, kept for restores only; relay
    -- reads skip it.
    superseded BOOLEAN NOT NULL DEFAULT FALSE,
    -- Kind 5 only: the event ids it deletes.
    deletes TEXT[] NOT NULL DEFAULT '{}',
    source TEXT NOT NULL DEFAULT '',
    received_at BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_nostr_archive_author
    ON nostr_archive (pubkey, kind, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_nostr_archive_created ON nostr_archive (created_at DESC);

-- Pull progress per linked key. backfill_until is the oldest created_at
-- read so far (0 = not started); backfill_done once a page came back empty.
CREATE TABLE IF NOT EXISTS nostr_archive_state (
    pubkey TEXT PRIMARY KEY,
    synced_at BIGINT NOT NULL DEFAULT 0,
    next_pull_at BIGINT NOT NULL DEFAULT 0,
    backfill_until BIGINT NOT NULL DEFAULT 0,
    backfill_done BOOLEAN NOT NULL DEFAULT FALSE,
    linked_seen_at BIGINT NOT NULL DEFAULT 0
);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_archive TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_archive_state TO "www-data";
