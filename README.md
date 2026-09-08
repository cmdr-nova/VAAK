# VAAK

VAAK is the multi-user ActivityPub/Mastodon-compatible web application used by
the Vaak instance. This repository contains only the VAAK application and its
PHP API libraries; the surrounding NovaLandia website is intentionally excluded.

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

## Public Feeds

Local actor profiles expose public outbox feeds at `/users/{username}/feed.xml`
(RSS 2.0) and `/users/{username}/feed.atom` (Atom 1.0). Private and
followers-only posts are excluded.

Public profiles advertise the Webmention endpoint at
`/api/ap-webmention.php`. Verified mentions can be read as JSON with a
`target` query parameter; unverified or blocked sources are rejected.
