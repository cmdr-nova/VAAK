-- Explicit operator migration: never run by the web controller. PostgreSQL and SQLite.
-- Lock/order and ownership readiness must be reviewed before production installation.
CREATE TABLE IF NOT EXISTS ap_registration_control (
 id INTEGER PRIMARY KEY CHECK (id=1), accepting INTEGER NOT NULL CHECK (accepting IN (0,1)),
 updated_at TEXT NOT NULL, updated_by BIGINT
);
INSERT INTO ap_registration_control(id,accepting,updated_at,updated_by)
 VALUES (1,1,'2026-10-12T00:00:00Z',NULL) ON CONFLICT(id) DO NOTHING;
