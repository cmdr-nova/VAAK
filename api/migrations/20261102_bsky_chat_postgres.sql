-- Bluesky DM cache (chat.bsky.convo.*), filled by api/ap-bsky-chat.php only
-- while a signed-in user has VAAK open (DM screen or the unread badge poll).
-- deploy/remote/migrate.php applies this file as the schema owner before the
-- release goes live. Re-running is a no-op once the tables exist.
-- Nothing reads these tables unless VAAK_BSKY_DMS=1.
CREATE TABLE IF NOT EXISTS bsky_chat_convos (
    owner_user_id BIGINT NOT NULL,
    convo_id TEXT NOT NULL,
    rev TEXT NOT NULL DEFAULT '',
    peer_did TEXT NOT NULL DEFAULT '',
    peer_handle TEXT NOT NULL DEFAULT '',
    peer_name TEXT NOT NULL DEFAULT '',
    peer_avatar TEXT NOT NULL DEFAULT '',
    peer_chat_disabled INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'accepted',
    muted INTEGER NOT NULL DEFAULT 0,
    unread_count INTEGER NOT NULL DEFAULT 0,
    last_message_id TEXT NOT NULL DEFAULT '',
    last_sender_did TEXT NOT NULL DEFAULT '',
    last_text TEXT NOT NULL DEFAULT '',
    last_at TEXT NOT NULL DEFAULT '',
    history_loaded INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, convo_id)
);
CREATE INDEX IF NOT EXISTS idx_bsky_chat_convos_owner_last
    ON bsky_chat_convos (owner_user_id, last_at);
CREATE TABLE IF NOT EXISTS bsky_chat_messages (
    owner_user_id BIGINT NOT NULL,
    convo_id TEXT NOT NULL,
    message_id TEXT NOT NULL,
    rev TEXT NOT NULL DEFAULT '',
    sender_did TEXT NOT NULL DEFAULT '',
    text TEXT NOT NULL DEFAULT '',
    facets_json TEXT,
    embed_json TEXT,
    sent_at TEXT NOT NULL DEFAULT '',
    deleted INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (owner_user_id, message_id)
);
CREATE INDEX IF NOT EXISTS idx_bsky_chat_messages_convo
    ON bsky_chat_messages (owner_user_id, convo_id, rev);
CREATE TABLE IF NOT EXISTS bsky_chat_state (
    owner_user_id BIGINT PRIMARY KEY,
    did TEXT NOT NULL DEFAULT '',
    log_cursor TEXT NOT NULL DEFAULT '',
    synced_at BIGINT NOT NULL DEFAULT 0,
    next_sync_at BIGINT NOT NULL DEFAULT 0,
    failures INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    scope_ok INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
GRANT SELECT, INSERT, UPDATE, DELETE ON bsky_chat_convos TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON bsky_chat_messages TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON bsky_chat_state TO "www-data";
