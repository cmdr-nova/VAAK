-- Reactions on Bluesky DMs (chat.bsky.convo addReaction/removeReaction),
-- cached next to the tables from 20261102_bsky_chat_postgres.sql.
-- api/ap-bsky-chat.php replaces a message's rows whenever Bluesky sends that
-- message's full view. deploy/remote/migrate.php applies this file as the
-- schema owner before the release goes live; re-running is a no-op.
CREATE TABLE IF NOT EXISTS bsky_chat_reactions (
    owner_user_id BIGINT NOT NULL,
    convo_id TEXT NOT NULL,
    message_id TEXT NOT NULL,
    sender_did TEXT NOT NULL,
    value TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (owner_user_id, message_id, sender_did, value)
);
CREATE INDEX IF NOT EXISTS idx_bsky_chat_reactions_convo
    ON bsky_chat_reactions (owner_user_id, convo_id);
GRANT SELECT, INSERT, UPDATE, DELETE ON bsky_chat_reactions TO "www-data";
