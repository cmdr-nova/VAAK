# Trending service

`vaak-worker trends-service` (unit `deploy/vaak-trends-service.service`) keeps
trend lists fresh in the background, so no page request ever computes them.
Pages read finished data from Redis.

## What it does

| Leg | Every | Source | Writes |
| --- | --- | --- | --- |
| On VAAK | 5 min (`VAAK_TRENDS_LOCAL_SECS`) | `api/bin/trends-warm.php`: the existing PHP recount of what VAAK stores (tags, links, posts) | the usual `vaak:trends:v3:*` caches |
| Bluesky | 5 min (`VAAK_TRENDS_BSKY_SECS`) | `app.bsky.unspecced.getTrends` on `VAAK_BSKY_PUBLIC_API` (public, no auth) | `vaak:trends:net:v1:bsky_topics` |
| Fediverse | 10 min (`VAAK_TRENDS_MASTODON_SECS`) | `/api/v1/trends/tags` from `VAAK_TRENDS_MASTODON_INSTANCES` (default: the 10 largest public Mastodon instances) | `vaak:trends:net:v1:tags` |

Setting a leg's interval to `0` turns that leg off.

Network-wide trending posts are off (the sidebar no longer shows them).
`VAAK_TRENDS_NETWORK_POSTS=1` brings the data back: Mastodon
`/trends/statuses` into `vaak:trends:net:v1:statuses`, and a Nostr leg every
5 min (`VAAK_TRENDS_NOSTR_SECS`) writing nostr.wine's notes, signature
checked, to `vaak:trends:net:v1:nostr` while some account has a linked npub.

When a network list changes the service bumps `vaak:trends:data-gen:v1`. The
notification SSE stream (`notif_stream.rs`) sends `event: trends` at most every
2 minutes, and open tabs on "Across networks" refetch the trends sidebar.

Every 20 s it sets `vaak:trends:service:heartbeat:v1` (3 min TTL). While that key
is fresh, `ap_masto_trends_schedule_refresh` does nothing, so PHP-FPM never spends
a worker on trend recomputes.

## Sidebar

The right rail gets an **On VAAK / Across networks** switch (On VAAK is the
default, remembered per browser). The switch only shows once network data
exists. It changes one card, **Trending tags**, which holds trending posts too:

- On VAAK: VAAK's tags, then a Trending posts section.
- Across networks: merged Mastodon tags (people today, server count, 7-day
  sparkline), then Bluesky's trending topics. No posts section.

Bluesky topics open their feed inside VAAK (`?view=bluesky&feed=at://…`) for
viewers with Bluesky connected, and the topic on bsky.app otherwise. The
service resolves each topic's `bsky.app/profile/…/feed/…` link to an at:// URI
(`feed_uri`) when it writes the snapshot.

Under it, **Who to follow** shows 3 random people from the same follow
suggestions Home uses (`ap_masto_suggestions_v2(12)`, same cache, fetched
after paint via `?ajax=who_to_follow`). The whole pool of up to 12 ships with
the card and the browser swaps in another random 3 every minute, so rotating
costs no requests. Following someone fades their row out and fills the gap.
Home still shows its own suggestion strip.

The viewer's blocks, mutes, muted words, moderation lists and downranks
apply as on VAAK. `VAAK_TRENDS_NETWORK=0` (PHP-FPM env) hides the switch.

## Load

The unit is capped at `CPUQuota=25%`, `CPUWeight=5`, `Nice=19`, `MemoryMax=256M`,
and the PHP child runs inside that cgroup. Network legs are about 14 small
HTTP calls per 10 minutes.

## Install (root, after the release that ships it is deployed)

Releases don't ship `deploy/`, so the unit file comes from the repo:

```
curl -fsSL https://raw.githubusercontent.com/cmdr-nova/VAAK/main/deploy/vaak-trends-service.service \
  -o /etc/systemd/system/vaak-trends-service.service
systemctl daemon-reload
systemctl enable --now vaak-trends-service.service
journalctl -u vaak-trends-service -f       # one "leg done" line per leg
```

Add `vaak-deploy ALL=(root) NOPASSWD: /usr/bin/systemctl restart vaak-trends-service.service`
to `/etc/sudoers.d/vaak-deploy` so deploys restart it.

Turning it off: `systemctl disable --now vaak-trends-service`. Within 3 minutes the
heartbeat expires and PHP refreshes trends itself again, as before.
