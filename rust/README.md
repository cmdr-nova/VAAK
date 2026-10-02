# VAAK Rust workers (wave 1–2 — shadow mode)

Side-by-side Rust binaries that **mirror** hot worker paths without replacing PHP/Python.

| Command | Live owner today | Rust shadow does |
|---|---|---|
| `notif-badge` | PHP `ajax=notif_unread` | Recompute unread (snowflake + mention type filter); write `vaak:shadow:notifications:…`; optional compare |
| `ranked-newer` | PHP `partial=1&newer=1` | Query newer Home candidates; emit JSON for soak/parity |
| `action-queue` | PHP `ap-action-queue-worker.php` | List pending jobs only (never claims) |
| `jetstream-hydrate` | Python spooler + PHP ingest worker | Spool scan + cursors + wanted-dids + thin-media DB candidates |
| `serve` | — | Localhost Axum: `/healthz` + `/shadow/*` |

Shared freeze-contract crate: **`vaak-types`** (deserializes `api/fixtures/normalize/*/expected.json`).

## Build

```bash
cd rust
cargo test -p vaak-types --tests
cargo build --release -p vaak-worker
```

Binary: `target/release/vaak-worker` (~5MB)

## Config

Reads `/etc/mkultra/vaak.env` when present (override with `VAAK_ENV_FILE`).

| Variable | Default | Notes |
|---|---|---|
| `VAAK_DATABASE_URL` | `postgresql:///novalandia?host=/var/run/postgresql` | Peer auth as `www-data` on prod |
| `VAAK_REDIS_URL` | `redis://127.0.0.1/` | Cache DB |
| `VAAK_JETSTREAM_STATE` | `/var/lib/mkultra/ap/jetstream` | Spool directory |
| `VAAK_SHADOW_OWNER_ID` | `1` | Owner used by CLI shadows |

## Prod install (shadow only)

```bash
scp target/release/vaak-worker root@144.91.124.35:/usr/local/bin/vaak-worker
ssh root@144.91.124.35 'chmod 755 /usr/local/bin/vaak-worker'
# Optional units (leave disabled until soak is deliberate):
#   deploy/vaak-worker-shadow.service       — notif loop
#   deploy/vaak-worker-shadow-http.service  — localhost :8787
```

Run as `www-data` so Postgres peer auth matches PHP:

```bash
sudo -u www-data /usr/local/bin/vaak-worker <command> …
```

## Soak-test examples (safe)

```bash
sudo -u www-data /usr/local/bin/vaak-worker notif-badge --once --owner-id 1 --compare
sudo -u www-data /usr/local/bin/vaak-worker ranked-newer --owner-id 1 --since-secs 900
sudo -u www-data /usr/local/bin/vaak-worker action-queue --list
sudo -u www-data /usr/local/bin/vaak-worker jetstream-hydrate --scan-once

# Localhost HTTP (foreground)
sudo -u www-data /usr/local/bin/vaak-worker serve --bind 127.0.0.1:8787
curl -s http://127.0.0.1:8787/healthz
curl -s 'http://127.0.0.1:8787/shadow/notif?owner_id=1&compare=1'
curl -s 'http://127.0.0.1:8787/shadow/ranked-newer?owner_id=1&since_secs=900'
curl -s http://127.0.0.1:8787/shadow/jetstream
```

## Cutover rule

No Rust command in this wave writes production timeline/action/Jetstream state.
Shadow Redis keys use the `vaak:shadow:` prefix only.
`serve` refuses non-loopback binds.

## Wave-2 notes

- **notif-badge**: PHP snowflake (`sec*1e9 + type*1e8 + dbId`) + thin `mention_notif_type` port (likes/reblogs/quotes/bites/mentions; skips Update-of-favourite and hidden-actor lookups). Prod smoke: `latest_id` matched live Redis.
- **jetstream-hydrate**: reports shard cursors, wanted-dids count, spool depths, and `bsky_posts` thin-media DB candidates even when the JSONL spool is drained.
- **serve**: Axum on `127.0.0.1:8787` only.
- **vaak-types**: freeze projection for normalize fixtures; `cargo test -p vaak-types --tests` round-trips all `expected.json` cases.
