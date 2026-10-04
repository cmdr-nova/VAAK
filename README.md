# VAAK

**Current release:** alpha **0.6.60** · 2026-10-04

VAAK (pronounced “vaak”) is a multi-user social web client for the Fediverse, with optional Bluesky / AT Protocol connection. One interface for reading, posting, following, moderating, and carrying identity across both networks.

It is **alpha** software: surfaces and behavior can still change between releases.

## What it is

A browser app and Mastodon-compatible API that speak **ActivityPub**, while also linking accounts to **Bluesky** (app password or a VAAK PDS). Timelines, notifications, search, profiles, DMs, and moderation apply across both sides where supported — with cache-first reads so pages stay responsive.

Live instances and public profiles are host-specific. This repository is the public source mirror of the application.

## Highlights

### Fediverse

- ActivityPub actors, inbox/outbox, WebFinger, NodeInfo, HTTP signatures
- Mastodon-compatible API for clients such as Ice Cubes
- **Home**, **Local**, and **Federated** timelines with clear scopes
- Follows, boosts, favourites, replies, quotes, polls, and delivery queues
- Public HTML profiles, RSS/Atom, Webmentions

### Bluesky

- Optional per-account Bluesky / PDS link
- Unified views for Bluesky posts, profiles, favourites, and bookmarks (not a separate “Bluesky-only” app)
- Cross-posting from VAAK when connected, with dedupe markers so copies are not double-counted
- Trends and search that can mix Fediverse and Bluesky data from local caches

### Composer & media

- Content warnings, visibility, emoji, media (image / GIF / video / audio), **polls**, drafts, and queueing
- Sensitive-media handling across timelines and profiles
- Long-form blog posts alongside short-form notes

### Profiles, moderation & discovery

- HTML profiles with a **Pinned** tab (up to 5 pins, newest first); Bluesky’s single pin follows the newest mirrored pin
- Accents, muted words, blocks, mutes, and lists — applied before render for both networks
- Search, trends, lists, notifications, and direct messages
- **RSS / Atom** subscriptions under You → RSS, mixed into Home (capped) with favourite / bookmark / boost / quote

## Design notes

- **PostgreSQL** holds durable state (accounts, posts, queues, moderation)
- **Redis** accelerates caches and wake-ups; the app still works if Redis is down
- Remote AppView / PDS work is pushed to workers so page loads enqueue and return quickly
- From **0.6.0**, selected background workers (notification badge, thin-media warm) run as Rust primary with PHP fallback; an Axum stub sits beside PHP while federation and the admin UI remain PHP-owned

## Status

Active alpha. **0.6.0** opens an incremental Rust + Axum backend rebuild track (workers first). Federation and Bluesky behavior vary by remote software. Performance work favors warm caches, bounded queues, and safe fallbacks over making a remote API a hard dependency for every screen.

Version labels in the app come from `api/ap-version.php` (channel, semver, date).
