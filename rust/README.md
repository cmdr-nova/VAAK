# VAAK Rust workers (shadow + live cutover)

| Command | Mode | Role |
|---|---|---|
| `notif-badge --live --loop` | **LIVE** | Owns production notif Redis + file cache |
| `thin-media-warm --loop` | **LIVE** | Drains `vaak:queue:bsky_post_warm` (Redis DB1); AppView upsert |
| `notif-badge` / `ranked-newer` / … | shadow | Parity / soak helpers |
| `serve` | shadow HTTP | Localhost `/shadow/*` only |

PHP remains fallback: notif rebuilds on Redis miss; warm enqueue falls back to `ap-bsky-post-warm.php` if queue push fails.

## Live systemd units

```bash
systemctl enable --now vaak-worker-notif.service
systemctl enable --now vaak-worker-thin-warm.service
```

Units: `deploy/vaak-worker-notif.service`, `deploy/vaak-worker-thin-warm.service`

## Env

| Variable | Default | Notes |
|---|---|---|
| `VAAK_DATABASE_URL` | peer `novalandia` | www-data |
| `VAAK_REDIS_URL` | `redis://127.0.0.1/0` | cache DB |
| `VAAK_REDIS_QUEUE_URL` | `redis://127.0.0.1/1` | queue DB (PHP `ap_redis_client('queue')`) |
| `VAAK_NOTIF_RUST_PRIMARY` | `1` (PHP) | longer stampede wait + stale file preference |
| `VAAK_THIN_MEDIA_RUST_PRIMARY` | `1` (PHP) | enqueue via Redis queue first |
| `VAAK_BSKY_PUBLIC_API` | `https://public.api.bsky.app` | warm fetch |

## Build / install

```bash
cd rust && cargo build --release -p vaak-worker
scp target/release/vaak-worker root@144.91.124.35:/usr/local/bin/vaak-worker
# stop units before replacing a running binary
```

## Cutover notes (0.5.64)

- Notif live key: `vaak:notifications:v1:unread:{owner}:{scan}:{sha256(last_read)}` TTL 45s; source `vaak-worker-live`
- Warm queue: `vaak:queue:bsky_post_warm` JSON `{uri,owner,ts,source}`
- Rollback: `systemctl disable --now vaak-worker-notif vaak-worker-thin-warm` and set `VAAK_NOTIF_RUST_PRIMARY=0` / `VAAK_THIN_MEDIA_RUST_PRIMARY=0` in `/etc/mkultra/vaak.env`
