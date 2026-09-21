-- Structured retry context for durable user actions.
ALTER TABLE ap_action_queue ADD COLUMN IF NOT EXISTS last_error_host TEXT;
ALTER TABLE ap_action_queue ADD COLUMN IF NOT EXISTS last_error_code INTEGER;
GRANT SELECT, INSERT, UPDATE, DELETE ON ap_action_queue TO "www-data";
