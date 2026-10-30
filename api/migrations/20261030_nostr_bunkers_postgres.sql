-- Nostr remote signing (NIP-46). deploy/remote/migrate.php applies this file
-- as the schema owner before the release goes live. Re-running is a no-op.
--
-- A user who pastes a bunker:// link in Settings > Profile gets one row:
-- the bunker's signer key and relays, and the client key VAAK made for this
-- pairing. That client key can only ask the bunker to sign; the bunker
-- decides which kinds it signs and can revoke it. The user's own Nostr key
-- is never here (rust/vaak-worker/src/nostr/bunker.rs). Times are unix
-- seconds.
CREATE TABLE IF NOT EXISTS nostr_bunkers (
    owner_user_id BIGINT PRIMARY KEY,
    signer_pubkey TEXT NOT NULL,
    relays JSONB NOT NULL DEFAULT '[]'::jsonb,
    client_secret TEXT NOT NULL,
    user_pubkey TEXT NOT NULL,
    connected_at BIGINT NOT NULL,
    last_used_at BIGINT NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    last_error_at BIGINT NOT NULL DEFAULT 0
);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_bunkers TO "www-data";
