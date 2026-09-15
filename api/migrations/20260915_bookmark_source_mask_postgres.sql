-- VAAK stores a unified bookmark row with independent Fediverse / Bluesky
-- source bits. Existing rows predate Bluesky mirroring and are Fediverse saves.
ALTER TABLE masto_bookmarks
    ADD COLUMN IF NOT EXISTS source_mask INTEGER NOT NULL DEFAULT 1;

UPDATE masto_bookmarks
   SET source_mask = 1
 WHERE source_mask IS NULL OR source_mask = 0;
