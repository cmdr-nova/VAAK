-- Like and repost counts for recent Nostr notes, for Trending posts.
-- deploy/remote/migrate.php applies this file as the schema owner before
-- the release goes live. Re-running is a no-op.
--
-- `vaak-worker nostr-sync` (nostr/counts.rs) asks the fallback relays for
-- kind 7 reactions and kind 6 reposts of the most active recent notes from
-- linked users' follows (at most VAAK_NOSTR_COUNT_NOTES per run, each note
-- at most every 30 minutes), counting each key once. VAAK's own stored
-- reposts and replies are counted separately at read time. Rows for notes
-- older than a week are dropped. Times are unix seconds.
CREATE TABLE IF NOT EXISTS nostr_note_counts (
    note_id TEXT PRIMARY KEY,
    likes INTEGER NOT NULL DEFAULT 0,
    reposts INTEGER NOT NULL DEFAULT 0,
    counted_at BIGINT NOT NULL
);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_note_counts TO "www-data";
