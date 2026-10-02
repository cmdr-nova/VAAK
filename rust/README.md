# VAAK Rust workers (wave 1 — shadow mode)

Side-by-side Rust binaries that **mirror** hot worker paths without replacing PHP/Python.

| Command | Live owner today | Rust shadow does |
|---|---|---|
| `notif-badge` | PHP `ajax=notif_unread` | Recompute unread state; write `vaak:shadow:notifications:…`; optional compare |
| `ranked-newer` | PHP `partial=1&newer=1` | Query newer Home candidates; emit JSON for soak/parity |
| `action-queue` | PHP `ap-action-queue-worker.php` | List pending jobs only (never claims) |
| `jetstream-hydrate` | Python spooler + PHP ingest worker | Read-only scan of Jetstream JSONL; thin-embed repair candidates |

## Build

```bash
cd rust
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
# Optional unit (leave disabled until soak is deliberate):
# scp deploy/vaak-worker-shadow.service root@…:/etc/systemd/system/
# systemctl daemon-reload   # do NOT enable yet
```

Run as `www-data` so Postgres peer auth matches PHP:

```bash
sudo -u www-data /usr/local/bin/vaak-worker <command> …
```

## Soak-test examples (safe)

```bash
# Notification badge shadow once
sudo -u www-data /usr/local/bin/vaak-worker notif-badge --once --owner-id 1

# Compare against live Redis cache if present
sudo -u www-data /usr/local/bin/vaak-worker notif-badge --once --owner-id 1 --compare

# Home newer-poll candidate dump (last N seconds)
sudo -u www-data /usr/local/bin/vaak-worker ranked-newer --owner-id 1 --since-secs 900

# Pending action-queue rows (no claim)
sudo -u www-data /usr/local/bin/vaak-worker action-queue --list

# Jetstream spool scan (read-only; empty when spool is drained)
sudo -u www-data /usr/local/bin/vaak-worker jetstream-hydrate --scan-once
```

Optional loop (systemd template in `deploy/vaak-worker-shadow.service` — **disabled by default**):

```bash
sudo -u www-data /usr/local/bin/vaak-worker notif-badge --loop --interval-secs 30 --owner-id 1
```

## Cutover rule

No Rust command in this wave writes production timeline/action/Jetstream state.
Shadow Redis keys use the `vaak:shadow:` prefix only.

## Known wave-1 gaps (expected)

- **notif-badge**: unread `count` should match live; `latest_id` may differ because PHP also folds likes/reblogs/quotes into the scan. Mentions + follow accepts are the shadow sources for now.
- **ranked-newer**: candidate IDs only (no HTML/card hydrate). Useful for latency + mix parity, not a drop-in for `newer=1` HTML.
- **jetstream-hydrate**: reports empty when `incoming/` + `processing/` are drained (normal under healthy shards). Does not rewrite cursors or spool files.
- **action-queue**: inspect-only. Claiming stays in PHP.

## First prod smoke (2026-10-02)

- `notif-badge --compare`: count matched live Redis (`c=0`); wrote `vaak:shadow:notifications:…`
- `ranked-newer --since-secs 900`: ~150ms, returned follow-event candidates
- `action-queue --list`: pending/processing counts OK
- `jetstream-hydrate --scan-once`: OK against empty drained spool; wrote `vaak:shadow:jetstream:last_scan`
