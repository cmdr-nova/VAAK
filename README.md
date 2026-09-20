# VAAK

**Status:** alpha  
**Version:** `0.2.13` · `2026-09-20`

VAAK is the multi-user ActivityPub/Mastodon-compatible web application used by
the Vaak instance. This repository contains only the VAAK application and its
PHP API libraries; the surrounding NovaLandia website is intentionally excluded.

While VAAK is in **alpha**, expect breaking changes, incomplete surfaces, and
frequent polish as federation, Bluesky integration, and multi-user flows settle.
The in-app login page and sidebar show the same release label.

### Version numbering

| Field | Source of truth | Notes |
|--------|------------------|--------|
| Channel | `api/ap-version.php` → `VAAK_CHANNEL` | Currently `alpha` |
| Semver | `VAAK_VERSION` | `0.x` while alpha; bump when shipping a meaningful batch |
| Date stamp | `VAAK_VERSION_DATE` | Calendar date of the labeled release (`YYYY-MM-DD`) |

UI helper: `vaak_version_label()` → e.g. `VAAK alpha 0.2.13 · 2026-09-20`.

Bump those three constants together when cutting a public sync. Prefer one
version bump per published batch rather than per tiny commit. While in alpha,
increment the patch number (`0.1.0` → `0.1.1`) for normal fix/feature batches;
only bump the minor (`0.1.x` → `0.2.0`) for a particularly large milestone.

## Configuration

Production configuration is supplied outside the web root by the deployment
environment. The application expects PostgreSQL settings through `AP_DB_DSN`,
`AP_DB_USER`, and `AP_DB_PASSWORD`, plus deployment-managed files under
`/etc/mkultra/` for session secrets, federation keys, object storage, Web Push,
and other integrations. Do not commit those files or their contents.

The public repository must never contain real passwords, access tokens, private
keys, bearer tokens, database URLs, or user/runtime data.

## Layout

- `vaak/` - login and front-controller pages
- `api/` - ActivityPub, Mastodon compatibility, authentication, federation, and
  application libraries
- `api/assets/` - notification sounds used by the VAAK UI

Deployment routing and service configuration remain environment-specific and are
not included here.

### Required background workers

Local posts and quote boosts are committed before federation delivery. The
delivery queue is retried by `api/ap-publish-delivery-worker.php`; production
deployments must run it as the `www-data` service account. The web request also
attempts a short-lived async wake-up, but that is best-effort and must not be
the only scheduler (PHP-FPM may have `exec` disabled).

For a simple deployment, add a cron entry that loads the same environment file
used by PHP-FPM:

```cron
* * * * * www-data . /etc/mkultra/vaak.env; export VAAK_SECRET AP_DB_DSN AP_DB_USER AP_DB_PASSWORD; /usr/bin/php /srv/mkultra/html/api/ap-publish-delivery-worker.php --limit=25 >>/var/log/vaak-publish-delivery.log 2>&1
```

Use a systemd timer instead when the host already manages application workers.
The worker is idempotent and uses a lease, so overlapping timer invocations are
safe; keep only one active timer on a host to avoid unnecessary database work.
Deploy checks can query queue state without claiming work with
`api/ap-publish-delivery-worker.php --health`.

## Public Feeds

Local actor profiles expose public outbox feeds at `/users/{username}/feed.xml`
(RSS 2.0) and `/users/{username}/feed.atom` (Atom 1.0). Private and
followers-only posts are excluded.

Public profiles advertise the Webmention endpoint at
`/api/ap-webmention.php`. Verified mentions can be read as JSON with a
`target` query parameter; unverified or blocked sources are rejected.

Clients that need instance-wide moderation policy can read the public JSON
feed at `/api/ap-moderation-feed.php`. It contains only active global actor and
domain blocks; personal mutes, report details, and moderation notes are never
included. Responses support ETags and short-lived caching.

The admin Import/Export screen supports Mastodon-compatible CSV portability
for follows, mutes, blocks, blocked domains, bookmarks, lists, and followed
hashtags (`followed_tags.csv`). Imports merge into the current account and do
not delete existing relationships.
