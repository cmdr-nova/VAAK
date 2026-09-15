-- Store fetched ActivityPub account bios for VAAK profile hover cards.
-- Run as the PostgreSQL schema owner; remote_actors already carries the
-- table-level read/write grants used by the web role.
ALTER TABLE remote_actors ADD COLUMN IF NOT EXISTS summary TEXT;
