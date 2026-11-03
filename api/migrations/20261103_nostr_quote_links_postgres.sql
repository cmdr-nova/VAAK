-- Quoted Nostr notes. deploy/remote/migrate.php applies this file as the
-- schema owner before the release goes live. Re-running is a no-op.
--
-- A Nostr quote only names the note it quotes (a NIP-18 `q` tag, or a bare
-- `nostr:note1…` / `nostr:nevent1…` reference in the text): the quoted note
-- itself arrives only if someone VAAK follows happens to have written it.
-- Without it Home painted a plain "Quoted note" link instead of a card.
-- `vaak-worker nostr-sync` (rust/vaak-worker/src/nostr/quote.rs) remembers
-- every quote it sees here, fetches the missing notes by id from relays, and
-- gives up on one after a few tries. The rows also keep a quoted note past
-- retention (its author is usually followed by nobody) and get that author a
-- profile, the same way a reposted note is kept.
-- Times are unix seconds.
CREATE TABLE IF NOT EXISTS nostr_quote_links (
    note_id TEXT PRIMARY KEY,
    quoted_id TEXT NOT NULL,
    hint TEXT NOT NULL DEFAULT '',
    found BOOLEAN NOT NULL DEFAULT FALSE,
    tries INTEGER NOT NULL DEFAULT 0,
    tried_at BIGINT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_nostr_quote_links_quoted ON nostr_quote_links (quoted_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_quote_links TO "www-data";
