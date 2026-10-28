-- Nostr relays VAAK talks to, for Admin > Relays > Nostr. deploy/remote/
-- migrate.php applies this file as the schema owner before the release
-- goes live. Re-running is a no-op once the table exists.
--
-- is_index: asked for profiles, follow lists and relay lists (kinds 0, 3,
-- 10002). is_fallback: read for notes of keys without a usable relay list,
-- and published to. A row with neither is a relay some followed author
-- lists, recorded by the sync so its status shows. removed_at set means
-- an admin removed it: VAAK never connects to it again, and the env lists
-- (VAAK_NOSTR_INDEX_RELAYS / FALLBACK_RELAYS) cannot bring it back.
-- Times are unix seconds.
CREATE TABLE IF NOT EXISTS nostr_relays (
    url TEXT PRIMARY KEY,
    is_index BOOLEAN NOT NULL DEFAULT FALSE,
    is_fallback BOOLEAN NOT NULL DEFAULT FALSE,
    added_at BIGINT NOT NULL,
    removed_at BIGINT,
    checked_at BIGINT NOT NULL DEFAULT 0,
    ok BOOLEAN,
    last_ok_at BIGINT NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    fail_streak INTEGER NOT NULL DEFAULT 0
);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_relays TO "www-data";
