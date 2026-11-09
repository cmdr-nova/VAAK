-- Which world each mirrored grid carrier plays in, from the grid's
-- vaak_lineage_export: 'opensim' (NovaLandia), 'hypergrid' (visitor from
-- another grid), 'sl' (Second Life only) or 'both' (grid avatar with a linked
-- Second Life alias). The family tree shows it as a badge. Idempotent.
ALTER TABLE phyrian_grid_lineage ADD COLUMN IF NOT EXISTS world TEXT NOT NULL DEFAULT 'opensim';
