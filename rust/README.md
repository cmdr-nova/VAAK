# VAAK Rust workers (shadow + live cutover)

| Command | Mode | Role |
|---|---|---|
| `notif-badge --live --loop --owner-id 0` | **LIVE** | Owns production notif Redis + file cache for **all** local `ap_users` (multi-user) |
| `thin-media-warm --loop` | **LIVE** | Drains `vaak:queue:bsky_post_warm` (Redis DB1); AppView upsert |
| `actor-warm --loop` | **LIVE** | Drains `vaak:queue:bsky_actor_warm` (Redis DB1); `getProfiles` → `bsky_actor_profiles` + flat DID Redis |
| `notif-badge` / `ranked-newer` / … | shadow | Parity / soak helpers |
| `serve` | shadow HTTP | Localhost `/shadow/*` only |

PHP remains fallback: notif rebuilds on Redis miss; warm enqueue falls back to `ap-bsky-post-warm.php` if queue push fails. Actor warm is best-effort Redis LPUSH from thin-author enrich; PG `bsky_actor_refresh_queue` still has the PHP worker for sync jobs.

## Live systemd units

```bash
systemctl enable --now vaak-worker-notif.service
systemctl enable --now vaak-worker-thin-warm.service
systemctl enable --now vaak-worker-actor-warm.service
```

Units: `deploy/vaak-worker-notif.service`, `deploy/vaak-worker-thin-warm.service`, `deploy/vaak-worker-actor-warm.service`

## Env

| Variable | Default | Notes |
|---|---|---|
| `VAAK_DATABASE_URL` | peer `novalandia` | www-data |
| `VAAK_REDIS_URL` | `redis://127.0.0.1/0` | cache DB |
| `VAAK_REDIS_QUEUE_URL` | `redis://127.0.0.1/1` | queue DB (PHP `ap_redis_client('queue')`) |
| `VAAK_NOTIF_RUST_PRIMARY` | `1` (PHP) | longer stampede wait + stale file preference for Rust-covered owners |
| `VAAK_NOTIF_RUST_OWNER_ID` | `0` (PHP) | `0` = all local users covered by Rust loop; positive = single-owner long-stale shortcut only |
| `VAAK_THIN_MEDIA_RUST_PRIMARY` | `1` (PHP) | enqueue via Redis queue first |
| `VAAK_ACTOR_WARM_RUST_PRIMARY` | `1` (PHP) | thin-author LPUSH to `bsky_actor_warm` |
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
- Actor warm queue: `vaak:queue:bsky_actor_warm` JSON `{did,owner,ts,source}`; flat key `vaak:actor:v1:bsky:{did}` TTL ~2700s
- Rollback: `systemctl disable --now vaak-worker-notif vaak-worker-thin-warm vaak-worker-actor-warm` and set `VAAK_NOTIF_RUST_PRIMARY=0` / `VAAK_THIN_MEDIA_RUST_PRIMARY=0` / `VAAK_ACTOR_WARM_RUST_PRIMARY=0` in `/etc/mkultra/vaak.env`
