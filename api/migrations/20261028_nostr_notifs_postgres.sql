-- Nostr notifications. deploy/remote/migrate.php applies this file as the
-- schema owner before the release goes live. Re-running is a no-op.
--
-- `vaak-worker nostr-sync` reads replies, mentions, reposts and likes that
-- tag a linked npub (`#p`) and stages the ones the user should see here,
-- with the mentions row already built. api/bin/nostr-notif-ingest.php
-- stores each one in mentions (so the Notifications tab, the unread badge
-- and push treat it like any other notification) and stamps ingested_at.
-- Times are unix seconds.
CREATE TABLE IF NOT EXISTS nostr_notifs (
    owner_user_id BIGINT NOT NULL,
    event_id TEXT NOT NULL,
    kind INTEGER NOT NULL,
    actor TEXT NOT NULL,
    target_id TEXT NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL,
    mention JSONB NOT NULL,
    push BOOLEAN NOT NULL DEFAULT FALSE,
    ingested_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (owner_user_id, event_id)
);
CREATE INDEX IF NOT EXISTS idx_nostr_notifs_pending ON nostr_notifs (ingested_at, created_at);
CREATE INDEX IF NOT EXISTS idx_nostr_notifs_target ON nostr_notifs (target_id);
CREATE INDEX IF NOT EXISTS idx_nostr_notifs_actor ON nostr_notifs (actor);

-- How far back the next notification read starts, per linked user.
CREATE TABLE IF NOT EXISTS nostr_notif_state (
    owner_user_id BIGINT PRIMARY KEY,
    since_at BIGINT NOT NULL DEFAULT 0
);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_notifs TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_notif_state TO "www-data";
