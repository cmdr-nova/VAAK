-- Wafrn-inspired priority lanes for durable VAAK delivery queues.
-- Apply as the PostgreSQL schema owner before deploying the PHP changes.
ALTER TABLE ap_publish_delivery_queue
    ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 50;
ALTER TABLE ap_fanout_delivery_queue
    ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 50;

CREATE INDEX IF NOT EXISTS idx_ap_publish_delivery_priority
    ON ap_publish_delivery_queue(status, next_attempt_at, priority, id);
CREATE INDEX IF NOT EXISTS idx_ap_fanout_delivery_priority
    ON ap_fanout_delivery_queue(status, next_attempt_at, priority, id);

GRANT SELECT, INSERT, UPDATE, DELETE ON ap_publish_delivery_queue TO "www-data";
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_fanout_delivery_queue TO "www-data";
