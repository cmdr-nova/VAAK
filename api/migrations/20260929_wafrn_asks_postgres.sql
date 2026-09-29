CREATE TABLE IF NOT EXISTS ap_asks (
 id BIGSERIAL PRIMARY KEY, ask_id TEXT NOT NULL UNIQUE, question TEXT NOT NULL,
 asker_actor TEXT, asked_actor TEXT NOT NULL, owner_user_id BIGINT NOT NULL DEFAULT 1,
 answered INTEGER NOT NULL DEFAULT 0, ap_object TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ap_asks_owner ON ap_asks(owner_user_id, answered, created_at DESC);
