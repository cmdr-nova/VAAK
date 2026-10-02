# Normalizer snapshot fixtures

JSON cases for `ap_normalize_*` / `ap_masto_status_from_as2_note` → stable Mastodon-status projection.

## Run

```bash
# Local / prod CLI (www-data + Postgres peer DSN on mkultra)
sudo -u www-data env AP_DB_DSN='pgsql:dbname=novalandia' \
  php /srv/mkultra/html/api/bin/normalize-fixture-smoke.php

# Regenerate expected.json after an intentional normalizer change
php api/bin/normalize-fixture-smoke.php --write-expected

# Subset
php api/bin/normalize-fixture-smoke.php --only=mastodon_note,bsky_skeet
```

## Layout

Each directory under here has:

- `meta.json` — `entry` (`as2_note` | `bsky` | `rss` | `event` | `status`) + description
- `input.json` — foreign object / row / status
- `expected.json` — projected subset (uri, content_plain, sensitive, media, ask/quote flags, …)

## Cases

| Case | Intent |
|------|--------|
| `mastodon_note` | HTML body + image |
| `sharkey_note` | contentMap + `_misskey_quote` (quote attach still future work on as2 path) |
| `akkoma_note` | CW via `summary` → spoiler/sensitive |
| `bsky_skeet` | images + adult label → sensitive |
| `bsky_skeet_quote` | record embed → `quote.quoted_status` |
| `ask_answer` | pre-attached `vaak_ask` survives normalize |
| `rss_item` | `source=rss`, `vaak_rss_feed_id` |
| `empty_shell` | Create with empty body → `has_visible_body=false` (needs `_allow_empty_for_context`) |
