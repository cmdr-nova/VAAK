-- Grid-side Phyrian lineage mirrored from the NovaLandia OpenSim grid.
-- api/bin/phyrian-sync.php replaces the rows from the grid's
-- vaak_lineage_export action every run; the family tree only reads them.
-- No player stats live here. deploy/remote/migrate.php applies this file as
-- the schema owner before the release goes live. Idempotent.
CREATE TABLE IF NOT EXISTS phyrian_grid_lineage (
    avatar_uuid TEXT PRIMARY KEY,
    avatar_name TEXT NOT NULL DEFAULT '',
    parent_uuid TEXT,
    strain TEXT NOT NULL DEFAULT '',
    generation INTEGER NOT NULL DEFAULT 1,
    imprinted_at TIMESTAMPTZ,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

GRANT SELECT, INSERT, UPDATE, DELETE ON phyrian_grid_lineage TO "www-data";
