-- Indexed quote targets for local note quote counts.
-- deploy/remote/migrate.php applies this file before backfill. The table is
-- empty here, so the file does not build the read index: that index is
-- created CONCURRENTLY after the batched backfill, outside a transaction.
-- Re-running is a no-op once the table exists.
CREATE TABLE IF NOT EXISTS event_quote_targets (
    event_id BIGINT NOT NULL,
    quote_target TEXT NOT NULL,
    object_id TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (event_id, quote_target)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON event_quote_targets TO "www-data";
