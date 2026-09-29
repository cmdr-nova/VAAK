-- Rebuildable, owner-scoped notification read model.
-- mentions/events remain authoritative; this table must never be used for
-- ownership, privacy, moderation, or deletion decisions.
CREATE TABLE IF NOT EXISTS ap_notification_projection (
    owner_user_id BIGINT NOT NULL,
    notification_id TEXT NOT NULL,
    notification_type TEXT NOT NULL,
    actor_id TEXT NOT NULL DEFAULT '',
    status_id TEXT NOT NULL DEFAULT '',
    payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, notification_id)
);
CREATE INDEX IF NOT EXISTS idx_ap_notif_projection_page
    ON ap_notification_projection(owner_user_id, created_at DESC, notification_id DESC);
