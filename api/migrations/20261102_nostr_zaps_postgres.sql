-- Nostr zaps (NIP-57). deploy/remote/migrate.php applies this file as the
-- schema owner before the release goes live. Re-running is a no-op.
--
-- One row per Nostr key whose kind 0 profile names a Lightning address
-- (lud16) or LNURL (lud06). `vaak-worker nostr-sync` fills pay_url from the
-- profile; the LNURL-pay answer (callback, sendable range, whether it takes
-- Nostr zaps and the key that signs its receipts) is cached here when a zap
-- is prepared or a receipt for a linked key is checked. VAAK stores no
-- wallet secrets: the payer's own wallet pays the invoice.
-- Amounts are millisatoshis, times unix seconds.
CREATE TABLE IF NOT EXISTS nostr_zap_addrs (
    pubkey TEXT PRIMARY KEY,
    pay_url TEXT NOT NULL DEFAULT '',
    profile_at BIGINT NOT NULL DEFAULT 0,
    callback TEXT NOT NULL DEFAULT '',
    min_msats BIGINT NOT NULL DEFAULT 0,
    max_msats BIGINT NOT NULL DEFAULT 0,
    allows_nostr BOOLEAN NOT NULL DEFAULT FALSE,
    zap_pubkey TEXT NOT NULL DEFAULT '',
    comment_max INTEGER NOT NULL DEFAULT 0,
    checked_at BIGINT NOT NULL DEFAULT 0
);

GRANT SELECT, INSERT, UPDATE, DELETE ON nostr_zap_addrs TO "www-data";
