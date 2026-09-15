-- Cross-network account lists. Run with the schema owner before deploying PHP
-- code that uses these fields; the application DB role is intentionally not a
-- schema owner and must not attempt migrations at request time.
ALTER TABLE masto_lists
    ADD COLUMN IF NOT EXISTS list_kind TEXT NOT NULL DEFAULT 'curation',
    ADD COLUMN IF NOT EXISTS bsky_list_uri TEXT,
    ADD COLUMN IF NOT EXISTS bsky_moderation_action TEXT NOT NULL DEFAULT 'none',
    ADD COLUMN IF NOT EXISTS bsky_mod_action_uri TEXT,
    ADD COLUMN IF NOT EXISTS bsky_list_source TEXT NOT NULL DEFAULT 'vaak';

ALTER TABLE masto_list_accounts
    ADD COLUMN IF NOT EXISTS bsky_did TEXT,
    ADD COLUMN IF NOT EXISTS bsky_item_uri TEXT;

CREATE UNIQUE INDEX IF NOT EXISTS idx_masto_lists_owner_bsky_uri
    ON masto_lists(owner_user_id, bsky_list_uri)
    WHERE bsky_list_uri IS NOT NULL;
