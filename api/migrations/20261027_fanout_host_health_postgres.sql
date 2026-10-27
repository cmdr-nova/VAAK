-- Per-host ActivityPub fan-out health. One row per remote host, not per delivery.
-- deploy/remote/migrate.php applies this file as the schema owner before
-- the release goes live. Re-running is a no-op once the table exists.
-- Suspension itself is computed from these columns plus VAAK_FANOUT_DEAD_DAYS
-- (default 7) and VAAK_FANOUT_DEAD_FAILURES (default 20). Nothing here sends
-- a request, and nothing here is a Bluesky record.
CREATE TABLE IF NOT EXISTS ap_fanout_host_health (
    host TEXT PRIMARY KEY,
    last_success_at TEXT,
    streak_started_at TEXT,
    consecutive_terminal_failures INTEGER NOT NULL DEFAULT 0,
    last_failure_at TEXT,
    last_error TEXT,
    last_live_status INTEGER,
    last_live_at TEXT,
    last_probe_at TEXT,
    probe_inbox_url TEXT,
    probe_activity_json TEXT,
    probe_key_id TEXT,
    probe_priv_path TEXT,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ap_fanout_host_health_failures
    ON ap_fanout_host_health (consecutive_terminal_failures, last_probe_at);
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_fanout_host_health TO "www-data";
