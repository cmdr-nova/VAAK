-- Wafrn actor ↔ Bluesky DID links.
-- Wafrn publishes the DID on the actor document as alsoKnownAs "at://did:..."
-- (packages/backend/utils/activitypub/userToJSONLD.ts). The DID lookup index
-- is created CONCURRENTLY by deploy/remote/migrate.php, outside this file,
-- so a request never builds it and this loop stays additive.
-- Re-running is a no-op once the table exists.
CREATE TABLE IF NOT EXISTS wafrn_bsky_actors (
    actor_id TEXT NOT NULL,
    bsky_did TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (actor_id, bsky_did)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON wafrn_bsky_actors TO "www-data";
