ALTER TABLE mentions ADD COLUMN IF NOT EXISTS ask_actor TEXT NOT NULL DEFAULT '';
ALTER TABLE mentions ADD COLUMN IF NOT EXISTS ask_question TEXT NOT NULL DEFAULT '';
ALTER TABLE mentions ADD COLUMN IF NOT EXISTS ask_answer TEXT NOT NULL DEFAULT '';
CREATE INDEX IF NOT EXISTS idx_mentions_ask_actor ON mentions(owner_user_id, ask_actor, created_at DESC);
