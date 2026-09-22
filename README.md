# VAAK

VAAK is an open, multi-user social web application for the Fediverse. It
implements ActivityPub and a Mastodon-compatible API while also connecting
accounts to Bluesky and AT Protocol PDSs. The goal is a single, fast interface
for reading, publishing, moderating, and carrying social identity across both
networks.

VAAK is pronounced "vaak" and is currently **alpha** software. Interfaces,
database migrations, and protocol behavior can change between releases. The
repository is useful for people evaluating the project, contributing code, or
running their own instance; it is not a one-command hosted-service installer.

## What VAAK Provides

### Fediverse support

- ActivityPub inbox, outbox, actor, object, WebFinger, NodeInfo, and HTTP
  signature endpoints.
- Mastodon-compatible timelines and API routes for clients such as Ice Cubes
  and other Mastodon applications.
- Home, Local, and Federated timelines with distinct scopes:
  - **Home** combines followed accounts, local activity, and optional
    personalized recommendations.
  - **Local** contains activity from this VAAK instance only.
  - **Federated** contains local activity and ActivityPub remote activity.
- Queued follows, boosts, favourites, replies, quotes, moderation actions, and
  federation delivery.
- RSS and Atom feeds for public local profiles.
- Webmentions and a public moderation-policy feed.

### Bluesky and AT Protocol

- Optional per-account Bluesky/PDS connection using an app password or a VAAK
  PDS account.
- Native Bluesky timeline, profile, post, reply, quote, follow, favourite,
  bookmark, moderation-list, and block/mute integration where supported.
- Optional cross-posting from VAAK to Bluesky, with origin markers used to
  deduplicate the ActivityPub and Bluesky copies.
- Incremental, queued backfills for existing Bluesky posts, replies, boosts,
  and media. Backfilled content is local profile/cache data and is not silently
  re-federated.
- Bluesky-aware search, trends, profiles, HTML profiles, favourites, and
  bookmarks. Cached Bluesky data is used so pages do not wait on AppView/PDS
  requests whenever a warm result is available.

### Publishing and media

- Composer with content warnings, visibility controls, emoji search, media
  uploads, polls, audio recording, drafts, queueing, and drag-and-drop media.
- Automatic textarea growth with a bounded scrollable maximum height.
- Images, GIFs, video, audio, posters/thumbnails, and sensitive-media handling
  across timelines, notifications, profiles, and HTML profiles.
- Long-form Markdown-compatible blog posts with drafts and profile display.
- Optional profile links for external identities such as Second Life and World
  of Warcraft characters.

### Profiles, moderation, and discovery

- HTML profiles with posts, replies, boosts, media, infinite scrolling, and a
  back-to-top control.
- Per-account profile themes, interaction policies, badges, muted words,
  personal blocks, server blocks, mutes, and moderation lists.
- Moderation state is applied to both Fediverse and Bluesky content before it is
  rendered. Cached reads must never bypass those checks.
- Search, trending hashtags, links, posts, recommendations, followed tags,
  lists, collections, notifications, direct messages, and VakkTok video view.

## Architecture

The application is intentionally split into a small front controller and
protocol-focused PHP libraries:

- `vaak/` contains the browser entry point, login page, shell, and UI.
- `api/` contains the ActivityPub implementation, Mastodon compatibility
  routes, authentication, database helpers, media handling, Bluesky/PDS
  integration, profile rendering, search, and workers.
- `deploy/` contains deployment service definitions that are safe to publish;
  host-specific secrets and service overrides live outside the repository.

PostgreSQL is the production source of truth for accounts, posts, relationships,
moderation decisions, migrations, and durable queue rows. SQLite is useful for
local diagnostics only. Redis is an acceleration and coordination layer, not a
replacement for the database. It is used for short-lived read-through caches,
timeline heads, trends, remote metadata, rate counters, duplicate-job locks,
and queue wake-up signals. If Redis is unavailable, the application should
fall back to slower database-backed behavior without weakening moderation or
delivery correctness.

Remote work is deliberately kept out of normal page rendering where possible.
Workers handle federation delivery, fan-out, actions, actor/profile refreshes,
media warming, Bluesky synchronization, backfills, search enrichment, and
other retryable jobs. Requests should enqueue work and return quickly; workers
use leases, retries, bounded batches, and idempotent writes.

## Requirements

- PHP 8.1+ with PDO, cURL, JSON, mbstring, and the extensions required by the
  selected database and Redis client.
- PostgreSQL for a production deployment.
- Redis 8.x recommended for the cache and queue wake-up layer.
- A web server capable of routing HTTPS requests to `vaak/index.php` and the
  public ActivityPub/API endpoints.
- Writable application state and media storage outside the public source tree.
- TLS and a stable public hostname for ActivityPub federation.
- Optional object storage (such as S3-compatible/R2) for uploaded media.
- Optional Bluesky/PDS credentials and a PDS account when Bluesky integration is
  enabled.

## Configuration

Production configuration is supplied by the deployment environment, not by
committed files. At minimum, a deployment normally provides:

```text
AP_DB_DSN
AP_DB_USER
AP_DB_PASSWORD
VAAK_SECRET
```

Federation keys, session encryption, object storage, Web Push, mail, Redis,
Bluesky/PDS, and other integration settings should be provided through the
host's secret manager or environment file. Never commit passwords, access
tokens, private keys, bearer tokens, database URLs, or runtime/user data.

## Background Workers

A production instance should run the workers appropriate to the enabled
features. The repository includes workers for:

- publication and ActivityPub delivery;
- fan-out and queued user actions;
- actor/profile refresh and WebFinger warming;
- media warming and preview generation;
- Bluesky notifications, timeline warming, post backfills, and retries;
- Redis queue wake-ups.

Workers are safe to run from a systemd timer, supervisor, or cron schedule when
they load the same environment as PHP-FPM. Use one scheduler per worker, keep
batches bounded, and retain the lease/retry behavior. Inspect worker-specific
help output before choosing command-line options; deployment paths and service
users are host-specific.

## Public Protocol Surfaces

For a local account named `username`, a deployment commonly exposes:

```text
https://example.org/users/username
https://example.org/users/username/outbox
https://example.org/users/username/feed.xml
https://example.org/users/username/feed.atom
```

The instance also exposes standard WebFinger, NodeInfo, ActivityPub, and
Mastodon-compatible API surfaces. Public profiles advertise the Webmention
endpoint. The moderation feed contains only active instance-wide actor/domain
blocks; personal mutes, reports, and moderation notes are never published.

## Development and Verification

1. Copy the repository and provide a development environment with the required
   PHP extensions and database settings.
2. Keep secrets and runtime state outside the repository.
3. Apply the application migrations through the configured database role.
4. Run the web entry point behind HTTPS when testing federation behavior.
5. Start only the workers needed for the features under test.

Before a release or deployment, check:

```bash
git diff --check
php -l api/changed-file.php
```

Also smoke-test logged-out pages, authentication, Home, Local, Federated,
notifications, search, profiles, media, moderation, and Bluesky/Fediverse
deduplication when those surfaces changed. Test Local and Federated separately:
Bluesky content belongs in Home/Bluesky surfaces and must not leak into Local.

## Versioning

The release source of truth is `api/ap-version.php`. Keep the channel, semantic
version, and release date synchronized with the visible login/sidebar labels.
VAAK is still alpha, so versions remain below `1.0.0`. Bump the version for a
meaningful release batch rather than every small commit.

## Project Status

VAAK is an active alpha project. Federation compatibility varies across remote
software, and Bluesky/PDS behavior can differ between providers. Performance
work therefore favors cache-first reads, bounded queues, stale-while-refresh
behavior, and conservative fallbacks over making a remote service a hard
dependency for every page.

Issues, reproducible bugs, interoperability reports, and focused pull requests
are welcome. Please do not include credentials, private federation data, or
production database/media snapshots in reports or patches.
