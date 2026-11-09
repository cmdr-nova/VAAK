-- Which world each mirrored grid lineage avatar plays in: opensim (a
-- NovaLandia account), hypergrid (a visitor from another grid), sl (Second
-- Life) or both (a grid account linked to a Second Life avatar). Kept beside
-- phyrian_grid_lineage because deploy migrations only create tables.
-- api/bin/phyrian-sync.php replaces the rows with the lineage every run.
-- Idempotent.
CREATE TABLE IF NOT EXISTS phyrian_grid_lineage_worlds (
    avatar_uuid TEXT PRIMARY KEY,
    world TEXT NOT NULL DEFAULT 'opensim'
);

GRANT SELECT, INSERT, UPDATE, DELETE ON phyrian_grid_lineage_worlds TO "www-data";
