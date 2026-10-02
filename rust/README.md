# VAAK Rust workers (wave 1–3 — shadow mode)

Side-by-side Rust binaries that **mirror** hot worker paths without replacing PHP/Python.

| Command | Live owner today | Rust shadow does |
|---|---|---|
| `notif-badge` | PHP `ajax=notif_unread` | Unread badge with PHP snowflake + mention types + mute/block + Update-of-favourite |
| `ranked-newer` | PHP `partial=1&newer=1` | Newer Home candidate dump |
| `action-queue` | PHP `ap-action-queue-worker.php` | List pending jobs only (never claims) |
| `jetstream-hydrate` | Python spooler + PHP ingest | Spool scan + cursors + wanted-dids + thin-media DB |
| `thin-media-repair` | PHP `ap_bsky_repair_thin_media_embed` | Dry-run candidates; optional AppView `--fetch` (never writes) |
| `timeline-home` | PHP Home ranked cache / hydrate | Read ranked Redis IDs + light enrich; DB fallback if cache cold |
| `serve` | — | Localhost Axum: `/healthz` + `/shadow/*` + `/api/v1/timelines/home` alias |

Shared freeze-contract crate: **`vaak-types`** (deserializes `api/fixtures/normalize/*/expected.json`).

## Build

```bash
cd rust
cargo test -p vaak-types --tests
cargo test -p vaak-worker --bins
cargo build --release -p vaak-worker
```

Binary: `target/release/vaak-worker`

## Config

Reads `/etc/mkultra/vaak.env` when present (override with `VAAK_ENV_FILE`).

| Variable | Default | Notes |
|---|---|---|
| `VAAK_DATABASE_URL` | `postgresql:///novalandia?host=/var/run/postgresql` | Peer auth as `www-data` on prod |
| `VAAK_REDIS_URL` | `redis://127.0.0.1/` | Cache DB |
| `VAAK_JETSTREAM_STATE` | `/var/lib/mkultra/ap/jetstream` | Spool directory |
| `VAAK_SHADOW_OWNER_ID` | `1` | Owner used by CLI shadows |
| `VAAK_BSKY_PUBLIC_API` | `https://public.api.bsky.app` | AppView for thin-media `--fetch` |

## Prod install (shadow only)

```bash
scp target/release/vaak-worker root@144.91.124.35:/usr/local/bin/vaak-worker
# Optional units (leave disabled):
#   deploy/vaak-worker-shadow.service
#   deploy/vaak-worker-shadow-http.service
```

Run as `www-data`:

```bash
sudo -u www-data env RUST_LOG=error /usr/local/bin/vaak-worker <command> …
```

## Soak examples

```bash
sudo -u www-data env RUST_LOG=error /usr/local/bin/vaak-worker notif-badge --once --owner-id 1 --compare
sudo -u www-data env RUST_LOG=error /usr/local/bin/vaak-worker thin-media-repair --dry-run --limit 10
sudo -u www-data env RUST_LOG=error /usr/local/bin/vaak-worker thin-media-repair --dry-run --fetch --limit 3
sudo -u www-data env RUST_LOG=error /usr/local/bin/vaak-worker timeline-home --owner-id 1 --limit 10

sudo -u www-data /usr/local/bin/vaak-worker serve --bind 127.0.0.1:8787
curl -s 'http://127.0.0.1:8787/shadow/timelines/home?owner_id=1&limit=10'
curl -s 'http://127.0.0.1:8787/api/v1/timelines/home?owner_id=1&limit=5'   # alias, still shadow JSON
curl -s 'http://127.0.0.1:8787/shadow/thin-media?limit=5&fetch=1'
```

## Cutover rule

No Rust command writes production timeline/action/Jetstream/`bsky_posts` state.
Shadow Redis keys use the `vaak:shadow:` prefix only.
`serve` refuses non-loopback binds.
`/api/v1/timelines/home` on this port is a **shadow alias**, not a PHP replacement.

## Wave notes

- **W1**: four CLI shadows + disabled systemd unit
- **W2**: notif snowflake parity, jetstream depth, Axum stub, `vaak-types`
- **W3**: mute/block + Update-of-favourite notif edges; thin-media dry-run/`--fetch`; Home timeline shadow from ranked cache (DB cold fallback)
