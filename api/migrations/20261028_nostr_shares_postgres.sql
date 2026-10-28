-- Nostr reposts and quotes from Home cards. deploy/remote/migrate.php
-- applies this file as the schema owner before the release goes live.
-- Re-running is a no-op once the table exists.
--
-- Each row is one signed event the owner published through VAAK: a kind 6
-- repost of note_id, or the owner's own kind 1 quote (event_id) of note_id.
-- On VAAK the boost or quote is a masto_reblogs row keyed nostr:{note};
-- this table keeps the event id an undo (NIP-09) has to name, and keeps
-- the notes in nostr_events past clean-up while they are shared.
CREATE TABLE IF NOT EXISTS nostr_shares (
    owner_user_id BIGINT NOT NULL,
    event_id TEXT NOT NULL,
    note_id TEXT NOT NULL,
    kind INTEGER NOT NULL,
    created_at BIGINT NOT NULL,
    PRIMARY KEY (owner_user_id, event_id)
);
CREATE INDEX IF NOT EXISTS idx_nostr_shares_note
    ON nostr_shares (note_id, owner_user_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_shares TO "www-data";
