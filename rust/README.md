# VAAK Rust workers (shadow + live cutover)

| Command | Mode | Role |
|---|---|---|
| `notif-badge --live --loop --owner-id 0` | **LIVE** | Owns production notif Redis + file cache for **all** local `ap_users` (multi-user) |
| `notif-list --loop --owner-id 0` | **LIVE** | Mentions list warm — **native projection→Redis** first; quiet confirmed-empty envelopes skip/restamp (0.6.52); PHP `notif-list-warm.php` only on true cold-start / incomplete projection |
| `ranked-warm --loop --owner-id 0` | **LIVE** | Home + Local + Federated ranked rebuild **native-only** (`source=vaak-worker-native`, FoF/recs on Home); after non-empty Home write, fire-and-forget `bin/home-timeline-warm.php --limits=15,40` (0.6.67); PHP `bin/ranked-warm.php` retired → `vaak:timeline:ranked:v2:{sha256}` |
| `thin-media-warm --loop` | **LIVE** | Drains `vaak:queue:bsky_post_warm` (Redis DB1); AppView upsert |
| `actor-warm --loop` | **LIVE** | Drains `vaak:queue:bsky_actor_warm` (Redis DB1); `getProfiles` → `bsky_actor_profiles` + flat DID Redis |
| `ap-actor-warm --loop` | **LIVE** | Drains `vaak:queue:ap_actor_warm` (Redis DB1); spawns PHP signed AS2 fetch → `remote_actors` + flat AP Redis |
| `timeline-fanout --loop` | **LIVE** | Drains `vaak:queue:timeline_fanout` (Redis DB1); ranked prepend + Home hydrate invalidate (0.6.73); PHP enqueue with in-process fallback |
| `notif-badge` / `ranked-newer` / … | shadow | Parity / soak helpers |
| `serve` | shadow HTTP | Localhost `/shadow/*` + Mastodon-shaped `/api/v1/timelines/home` (hydrate Redis), `/api/v1/notifications` (Redis-read) |

PHP remains fallback: notif badge/list rebuild on Redis miss; warm enqueue falls back to `ap-bsky-post-warm.php` if queue push fails. Timeline fan-out (0.6.73) enqueues to Rust first; PHP Redis prepend runs only if queue push fails or `VAAK_TIMELINE_FANOUT_RUST=0`. Actor warm is best-effort Redis LPUSH from thin-author enrich; PG `bsky_actor_refresh_queue` still has the PHP worker for sync jobs. Mentions list-warm keeps PHP hydrate as cold fallback; ranked Home/Local/Federated are native-only (empty soft-skips; `bin/ranked-warm.php` retired). Interim bridges drop as each surface moves fully to Rust + Axum.

## Live systemd units

```bash
systemctl enable --now vaak-worker-notif.service
systemctl enable --now vaak-worker-notif-list.service
systemctl enable --now vaak-worker-ranked-warm.service
systemctl enable --now vaak-worker-thin-warm.service
systemctl enable --now vaak-worker-actor-warm.service
systemctl enable --now vaak-worker-ap-actor-warm.service
systemctl enable --now vaak-worker-timeline-fanout.service
systemctl enable --now vaak-worker-shadow-http.service   # localhost:8787 Axum shadow
```

Units: `deploy/vaak-worker-notif.service`, `deploy/vaak-worker-notif-list.service`, `deploy/vaak-worker-ranked-warm.service`, `deploy/vaak-worker-thin-warm.service`, `deploy/vaak-worker-actor-warm.service`, `deploy/vaak-worker-ap-actor-warm.service`, `deploy/vaak-worker-timeline-fanout.service`, `deploy/vaak-worker-shadow-http.service`

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
| `VAAK_HOME_AXUM_PRIMARY` | `1` (PHP) | Home Mastodon API: `/api/v1/timelines/home` tries localhost Axum hydrate first; PHP Redis/file + cold merge on miss. Head-only (no max_id). Rollback: `0` |
| `VAAK_NOTIF_NATIVE_PROJECTION` | `1` | notif-list writes Redis from `ap_notification_projection` before PHP spawn; empty thin windows fall through to PHP (0.6.43) |
| `VAAK_NOTIF_LIST_REFRESH_SECS` | `90` | skip-if-fresh window for list keys |
| `VAAK_SHADOW_HTTP` | `http://127.0.0.1:8787` | Axum shadow base for Mentions M5 + Home Axum-primary (loopback only) |
| `VAAK_RANKED_NATIVE_HOME` | `1` (default) | Home ranked rebuild in Rust (incl. FoF/cold-start recommendations); `0` skips Home warm |
| `VAAK_RANKED_NATIVE_LOCAL_FEED` | `1` (default) | Local + Federated ranked rebuild in Rust; `0` skips those views |
| `VAAK_HOME_HYDRATE_WARM` | `1` (default) | After non-empty Home ranked write, spawn `bin/home-timeline-warm.php --limits=15,40` (Axum hydrate); `0` disables |
| `VAAK_HOME_HYDRATE_WARM_COOLDOWN_SECS` | `60` | Redis cooldown between Home hydrate spawns per owner (30–600) |
| `VAAK_TIMELINE_FANOUT_RUST` | `1` (default) | PHP enqueues timeline fan-out to Rust; `0` forces in-process PHP Redis prepend |
| `VAAK_HOME_FANOUT_INGEST` | `1` (default) | Master enable for Home/Federated/Local ingest fan-out (PHP + Rust) |
| `VAAK_API_ROOT` | `/srv/mkultra/html/api` | notif-list / home-timeline-warm PHP path |
| `VAAK_PHP_BIN` | `/usr/bin/php` | materializer spawn |
| `VAAK_THIN_MEDIA_RUST_PRIMARY` | `1` (PHP) | enqueue via Redis queue first |
| `VAAK_ACTOR_WARM_RUST_PRIMARY` | `1` (PHP) | thin-author LPUSH to `bsky_actor_warm` |
| `VAAK_AP_ACTOR_WARM_RUST_PRIMARY` | `1` (PHP) | Fediverse warm_async LPUSH to `ap_actor_warm` |
| `VAAK_BSKY_PUBLIC_API` | `https://public.api.bsky.app` | warm fetch |

## Build / install

```bash
cd rust && cargo build --release -p vaak-worker
scp target/release/vaak-worker root@144.91.124.35:/usr/local/bin/vaak-worker
# stop units before replacing a running binary
```

## Cutover notes (0.5.64)

- Notif live key: `vaak:notifications:v1:unread:{owner}:{scan}:{sha256(last_read)}` TTL 45s; source `vaak-worker-live`
- Notif list key: `vaak:notifications:v1:{owner}:{sha256(limit,max,since,types,exclude)}` envelope `{ts,items,source}` TTL 600s when list-warm primary; source `vaak-worker-projection` (native); `bin/notif-list-warm.php` retired (0.6.63); hash JSON key order must match PHP: `limit,max,since,types,exclude`
- Ranked key: `vaak:timeline:ranked:v2:{sha256(logical)}` + owner index `vaak:timeline:owner-index:v1:{owner}`; Home/Local/Federated source `vaak-worker-native` (0.6.48–0.6.60); `bin/ranked-warm.php` retired (0.6.60); HTML soft-nav still uses in-process `admin_tl_lean_ranked_warm`
- Shadow HTTP (127.0.0.1:8787): `/shadow/notifications`, `/api/v1/notifications` (Redis-read; 404 on miss), `/shadow/timelines/home` (ranked IDs + hydrate probe), `/api/v1/timelines/home` (Mastodon status array from `vaak:timeline:v1:*`; 404 on miss). Prime via ranked-warm post-Home spawn (0.6.67, TTL 300s), `bin/home-timeline-warm.php`, or Ice Cubes head polls.
- Home Axum-primary (0.6.51): PHP `ap_masto_timeline_home_axum_try` → localhost Axum when `VAAK_HOME_AXUM_PRIMARY=1`; header `X-VAAK-TL-Cache: axum-shadow` on hit.
- HTML Home Axum assist (0.6.62): soft-nav shell + full-page first paint prefer `ap_masto_timeline_home_axum_fetch` → masto cards; miss schedules `bin/home-timeline-warm.php`; `X-TL-Cache: axum-shadow` / `data-tl-cache=axum-shadow`.
- Warm queue: `vaak:queue:bsky_post_warm` JSON `{uri,owner,ts,source}`
- Actor warm queue: `vaak:queue:bsky_actor_warm` JSON `{did,owner,ts,source}`; flat key `vaak:actor:v1:bsky:{did}` TTL ~2700s
- AP actor warm queue: `vaak:queue:ap_actor_warm` JSON `{actor_id,ts,source}`; flat key `vaak:actor:v1:ap:{sha256}` TTL ~2700s; PHP `ap-actor-warm.php` does signed fetch
- Timeline fan-out queue (0.6.73): `vaak:queue:timeline_fanout` JSON `{op,k,id,s,actor?,owner?,views?,visibility?,type?,hydrate?,ts}` — ops `home_followers` / `home_bsky` / `public_feed` / `owner_status`; stage_meta `fanout_src=vaak-worker`
- Rollback: `systemctl disable --now vaak-worker-notif vaak-worker-notif-list vaak-worker-ranked-warm vaak-worker-thin-warm vaak-worker-actor-warm vaak-worker-ap-actor-warm vaak-worker-timeline-fanout vaak-worker-shadow-http` and set `VAAK_NOTIF_RUST_PRIMARY=0` / `VAAK_NOTIF_LIST_RUST_PRIMARY=0` / `VAAK_HOME_AXUM_PRIMARY=0` / `VAAK_THIN_MEDIA_RUST_PRIMARY=0` / `VAAK_ACTOR_WARM_RUST_PRIMARY=0` / `VAAK_AP_ACTOR_WARM_RUST_PRIMARY=0` / `VAAK_TIMELINE_FANOUT_RUST=0` in `/etc/mkultra/vaak.env`
