-- Opt-in for carrying local VAAK follows over to Bluesky.
-- A row means: when someone this user follows on VAAK creates a VAAK
-- Bluesky account, queue a Bluesky follow of it from this user's own repo
-- (ap_bsky_follow_carry_enqueue in api/ap-bsky.php). No row means off.
-- deploy/remote/migrate.php applies this file as the schema owner before the
-- release goes live. Re-running is a no-op once the table exists.
CREATE TABLE IF NOT EXISTS bsky_follow_carry_optin (
    owner_user_id BIGINT PRIMARY KEY,
    enabled_at TEXT NOT NULL
);

GRANT SELECT, INSERT, UPDATE, DELETE ON bsky_follow_carry_optin TO "www-data";
