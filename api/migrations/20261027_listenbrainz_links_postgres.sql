-- Opt-in ListenBrainz "now playing" snapshot, one row per local user.
-- deploy/remote/migrate.php applies this file as the schema owner before
-- the release goes live. Re-running is a no-op once the table exists.
-- api/ap-listenbrainz-worker.php (cron) is the only writer of the snapshot
-- columns; Settings > Profile writes lb_username. Profile pages only read.
-- Times are unix seconds except created_at/updated_at.
CREATE TABLE IF NOT EXISTS ap_listenbrainz_links (
    owner_user_id BIGINT PRIMARY KEY,
    lb_username TEXT NOT NULL,
    playing_track TEXT NOT NULL DEFAULT '',
    playing_artist TEXT NOT NULL DEFAULT '',
    playing_release TEXT NOT NULL DEFAULT '',
    playing_cover TEXT NOT NULL DEFAULT '',
    last_track TEXT NOT NULL DEFAULT '',
    last_artist TEXT NOT NULL DEFAULT '',
    last_release TEXT NOT NULL DEFAULT '',
    last_cover TEXT NOT NULL DEFAULT '',
    last_listened_at BIGINT NOT NULL DEFAULT 0,
    fetched_at BIGINT NOT NULL DEFAULT 0,
    next_fetch_at BIGINT NOT NULL DEFAULT 0,
    failures INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ap_listenbrainz_links_due
    ON ap_listenbrainz_links (next_fetch_at);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_listenbrainz_links TO "www-data";
