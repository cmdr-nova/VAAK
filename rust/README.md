# VAAK Rust workers (shadow + live cutover)

| Command | Mode | Role |
|---|---|---|
| `notif-badge --live --loop --owner-id 0` | **LIVE** | Owns production notif Redis + file cache for **all** local `ap_users` (multi-user) |
| `notif-list --loop --owner-id 0` | **LIVE** | Mentions list warm — **native projection→Redis** first (`ap_notification_projection`); PHP `notif-list-warm.php` only when projection cannot fill (10.5) |
| `ranked-warm --loop --owner-id 0` | **LIVE** | Home / Local / Federated ranked ID-cache warm — orchestrates PHP `bin/ranked-warm.php` into `vaak:timeline:ranked:v2:{sha256}` |
| `thin-media-warm --loop` | **LIVE** | Drains `vaak:queue:bsky_post_warm` (Redis DB1); AppView upsert |
| `actor-warm --loop` | **LIVE** | Drains `vaak:queue:bsky_actor_warm` (Redis DB1); `getProfiles` → `bsky_actor_profiles` + flat DID Redis |
| `notif-badge` / `ranked-newer` / … | shadow | Parity / soak helpers |
| `serve` | shadow HTTP | Localhost `/shadow/*` + Mastodon-shaped `/api/v1/timelines/home`, `/api/v1/notifications` (Redis-read only) |

PHP remains fallback: notif badge/list rebuild on Redis miss; warm enqueue falls back to `ap-bsky-post-warm.php` if queue push fails. Actor warm is best-effort Redis LPUSH from thin-author enrich; PG `bsky_actor_refresh_queue` still has the PHP worker for sync jobs. Mentions list-warm and ranked-warm keep entity/lean hydrate in PHP (freeze contract) while Rust owns the multi-owner loop — interim bridges until native Rust + Axum cutover, then those PHP parts drop.

## Live systemd units

```bash
systemctl enable --now vaak-worker-notif.service
systemctl enable --now vaak-worker-notif-list.service
systemctl enable --now vaak-worker-ranked-warm.service
systemctl enable --now vaak-worker-thin-warm.service
systemctl enable --now vaak-worker-actor-warm.service
systemctl enable --now vaak-worker-shadow-http.service   # localhost:8787 Axum shadow
```

Units: `deploy/vaak-worker-notif.service`, `deploy/vaak-worker-notif-list.service`, `deploy/vaak-worker-ranked-warm.service`, `deploy/vaak-worker-thin-warm.service`, `deploy/vaak-worker-actor-warm.service`, `deploy/vaak-worker-shadow-http.service`

## Env

| Variable | Default | Notes |
|---|---|---|
| `VAAK_DATABASE_URL` | peer `novalandia` | www-data |
| `VAAK_REDIS_URL` | `redis://127.0.0.1/0` | cache DB |
| `VAAK_REDIS_QUEUE_URL` | `redis://127.0.0.1/1` | queue DB (PHP `ap_redis_client('queue')`) |
| `VAAK_NOTIF_RUST_PRIMARY` | `1` (PHP) | longer stampede wait + stale file preference for Rust-covered owners |
| `VAAK_NOTIF_RUST_OWNER_ID` | `0` (PHP) | `0` = all local users covered by Rust loop; positive = single-owner long-stale shortcut only |
| `VAAK_NOTIF_LIST_RUST_PRIMARY` | `1` (PHP) | Mentions list Redis fresh 120s / stale 600s; skip request-path stale rebuild + look-ahead on cache hit |
| `VAAK_NOTIF_AXUM_PRIMARY` | `1` (PHP) | Mentions M5: `admin_notifications_page` reads localhost Axum `/api/v1/notifications` first; PHP Redis/hydrate fallback on miss |
| `VAAK_NOTIF_NATIVE_PROJECTION` | `1` | notif-list writes Redis from `ap_notification_projection` before PHP spawn |
| `VAAK_NOTIF_LIST_REFRESH_SECS` | `90` | skip-if-fresh window for list keys |
| `VAAK_SHADOW_HTTP` | `http://127.0.0.1:8787` | Axum shadow base for Mentions M5 proxy (loopback only) |
| `VAAK_RANKED_RUST_PRIMARY` | `1` (default in materializer) | ranked-warm tags Redis `source=vaak-worker-live` with longer TTL trust |
| `VAAK_API_ROOT` | `/srv/mkultra/html/api` | notif-list / ranked-warm PHP materializer path |
| `VAAK_PHP_BIN` | `/usr/bin/php` | materializer spawn |
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
- Notif list key: `vaak:notifications:v1:{owner}:{sha256(limit,max,since,types,exclude)}` envelope `{ts,items,source}` TTL 600s when list-warm primary; source `vaak-worker-live` from `notif-list-warm.php` (hash JSON key order must match PHP: `limit,max,since,types,exclude`)
- Ranked key: `vaak:timeline:ranked:v2:{sha256(logical)}` + owner index `vaak:timeline:owner-index:v1:{owner}`; source `vaak-worker-live` from `ranked-warm.php`
- Shadow HTTP (127.0.0.1:8787): `/shadow/notifications`, `/api/v1/notifications` (Redis-read; 404 on miss), `/shadow/timelines/home`, `/api/v1/timelines/home`
- Warm queue: `vaak:queue:bsky_post_warm` JSON `{uri,owner,ts,source}`
- Actor warm queue: `vaak:queue:bsky_actor_warm` JSON `{did,owner,ts,source}`; flat key `vaak:actor:v1:bsky:{did}` TTL ~2700s
- Rollback: `systemctl disable --now vaak-worker-notif vaak-worker-notif-list vaak-worker-ranked-warm vaak-worker-thin-warm vaak-worker-actor-warm vaak-worker-shadow-http` and set `VAAK_NOTIF_RUST_PRIMARY=0` / `VAAK_NOTIF_LIST_RUST_PRIMARY=0` / `VAAK_THIN_MEDIA_RUST_PRIMARY=0` / `VAAK_ACTOR_WARM_RUST_PRIMARY=0` in `/etc/mkultra/vaak.env`
