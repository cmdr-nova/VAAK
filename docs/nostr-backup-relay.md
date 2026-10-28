# Nostr backup relay

A small relay that keeps a permanent copy of the events signed by npubs
linked to VAAK accounts (Settings > Profile > Nostr). It's there so a linked
user's profile, follow list, relay list and notes survive if the public
relays they use drop them or shut down.

- **Anyone can read.** It only ever holds linked users' own events, so
  public reads are cheap, and they're what let other clients find those
  events here.
- **Only linked npubs can write**, and only these kinds: 0 profile, 1 note,
  3 follow list, 5 deletion, 6 repost, 10000 mute list, 10002 relay list,
  30023 long-form post. Reactions and DMs are refused.
- **Nothing expires by age.** A kind 5 from the author removes what it names
  and keeps it from coming back. Replaceable events (0, 3, 10000, 10002)
  serve the newest copy and keep the 5 before it for restores. A key whose
  link is removed (or whose user is disabled) is purged 30 days later.

## How events get in

1. `vaak-worker nostr-sync` (the existing 3-minute timer) pulls each linked
   key's own events from the index relays and the key's write relays. The
   first runs page back through the whole history, 500 events per relay per
   run. After that it pulls new events every 15 minutes.
2. Every signed action VAAK relays for a linked user (crossposts, replies,
   reposts, follow changes, deletes) is stored the moment it is checked.
3. Clients that list the relay in their write relays send events straight
   to it.

The relay pushes events written through it to open subscriptions in real
time. Events from the sync show up on the next REQ.

## Cost

Measured on the cloud sandbox: an id hash plus BIP-340 signature check takes
about 0.12 ms per event. A 10,000-note backfill is 1 to 2 CPU seconds in
total. Storage is about 1 KB per event plus indexes. The relay process
idles at around 10 to 20 MB and is capped by systemd (`MemoryMax=128M`,
`CPUQuota=25%`). It holds one Postgres connection for all clients.

Limits per connection: 20 subscriptions, 10 filters per REQ, 500 results per
filter (300 by default), 512 KiB messages, 60 messages per 10 seconds. At
most 12 connections per address and 200 in total. Idle connections close
after 10 minutes.

## Install (as root, once)

The release ships the migration (`20261028_nostr_archive_postgres.sql`,
applied by `deploy/remote/migrate.php`) and the binary. The unit, sudoers
line and Caddy route are pasted by hand, like the other units.

1. Unit: copy `deploy/vaak-nostr-relay.service` to
   `/etc/systemd/system/`, then
   `systemctl daemon-reload && systemctl enable --now vaak-nostr-relay.service`.
2. Sudoers: add the `vaak-nostr-relay.service` line from
   `deploy/vaak-deploy.sudoers` to `/etc/sudoers.d/vaak-deploy`
   (`visudo -cf /etc/sudoers.d/vaak-deploy` to check). Without it, deploys
   warn and skip restarting the relay. They don't fail.
3. Caddy: proxy one path (or a subdomain) to the loopback port. Caddy
   handles the websocket upgrade and sets `X-Forwarded-For`, which the
   relay uses for its per-address limits. Inside the existing site block:

   ```
   @nostr_relay path /nostr
   handle @nostr_relay {
       reverse_proxy 127.0.0.1:8797
   }
   ```

   Then `caddy validate --config /etc/caddy/Caddyfile && systemctl reload caddy`.
4. `/etc/mkultra/vaak.env`: set `VAAK_NOSTR_RELAY_URL=wss://<host>/nostr`
   so the sync never pulls from VAAK's own relay. Optional:
   `VAAK_NOSTR_RELAY_NAME`, `VAAK_NOSTR_RELAY_DESCRIPTION`,
   `VAAK_NOSTR_RELAY_CONTACT`, `VAAK_NOSTR_RELAY_PUBKEY` (hex), all shown in
   the NIP-11 document.
5. Optional, per user: add the relay URL to your relay list (Damus:
   Settings > Relays) so your client writes there directly and other
   clients can find your notes there.

## Check it

```
curl -s -H 'Accept: application/nostr+json' https://<host>/nostr
journalctl -u vaak-nostr-sync.service --since -10min | grep -o 'archive keys=.*purged=[0-9]*'
sudo -u postgres psql -AtP pager=off -d novalandia -c \
  "SELECT pubkey, kind, count(*) FROM nostr_archive GROUP BY 1, 2 ORDER BY 1, 2"
sudo -u postgres psql -AtP pager=off -d novalandia -c \
  "SELECT pubkey, backfill_done, to_timestamp(backfill_until) FROM nostr_archive_state"
```

`backfilling=0` in the sync line means every linked key's history is in.

## Turn it off

`systemctl disable --now vaak-nostr-relay.service` stops serving. The sync
keeps filling the archive. `VAAK_NOSTR=0` stops both, along with the rest
of Nostr. To drop the data: `TRUNCATE nostr_archive, nostr_archive_state`.
