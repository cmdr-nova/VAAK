# VAAK

<p align="center">
  <img src="docs/assets/vaak-readme-mascot.png" alt="VAAK mascot" width="480">
</p>

**Current release:** alpha **0.8.17** · 2026-10-06

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
- Canonical on-VAAK profiles, a logged-out guest view, RSS/Atom, Webmentions

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

- Canonical on-VAAK profiles with posts, replies, boosts, quotes, asks, media,
  and blog surfaces; legacy HTML profiles remain reference-only during the
  migration
- Accents, muted words, blocks, mutes, and lists — applied before render for both networks
- Search, trends, lists, notifications, and direct messages
- **RSS / Atom** subscriptions under You → RSS, mixed into Home (capped) with favourite / bookmark / boost / quote

## Design notes

- **PostgreSQL** holds durable state (accounts, posts, queues, moderation)
- **Redis** accelerates caches and wake-ups; the app still works if Redis is down
- Remote AppView / PDS work is pushed to workers so page loads enqueue and return quickly
- From **0.6.0**, selected background workers (notification badge, thin-media
  warm, ranked timelines, actor/profile hydration, and relationship/library
  reads) run as Rust primary with PHP fallback; PHP remains the mutation and
  write owner while each Axum surface is parity-tested

### Current runtime model

- PostgreSQL is the only durable application database; Redis is a cache and
  queue layer, never the source of truth.
- Rust/Axum serves the canonical on-VAAK profile and timeline read paths where
  parity is complete. PHP remains available as a behavioral fallback and the
  write owner during the migration.
- Home candidates are assembled from followed Fediverse and local accounts,
  followed Bluesky accounts, followed hashtags, RSS subscriptions, and
  bounded recommendation candidates. Local and Federated retain their
  narrower scopes.
- Home ranking applies freshness, source balance, follows, favourites,
  boosts, replies, bookmarks, clicks, dwell, hashtag affinity, moderation,
  and optional recommendation signals. Personal/server blocks and mutes are
  applied before rendering; disabling the algorithm removes recommendations
  while retaining followed sources and moderation.
- A small explainable ML ranker currently runs in **shadow mode** only. It
  learns per-actor affinity from durable interaction signals, treats VAAK
  favourites as the canonical like signal, and compares its ordering with the
  production heuristic before any activation decision. See
  `rust/vaak-worker/src/ml_ranker.rs`.

### Background ingestion and safety

- Bluesky ingestion uses shared Jetstream hydration and cached profile/media
  resolution; delayed ingestion is handled by overlapping poller watermarks
  plus stable-key deduplication.
- Timeline workers warm ranked caches and newer-post polling without blocking
  the page request. Hollow/unresolved boosts are withheld until their source
  object can be hydrated.
- ActivityPub delivery, Wafrn-compatible Asks, Bluesky mirroring, media
  queues, and moderation remain guarded by bounded queues and PHP fallback
  paths while Rust parity continues.

## Status

Active alpha. **0.8.17** continues the incremental Rust + Axum backend rebuild.
The PHP implementation remains the behavioral reference and safe fallback until
each replacement has response, rendering, privacy, and mobile-client parity.
Federation and Bluesky behavior vary by remote software. Performance work
favors warm caches, bounded queues, and safe fallbacks over making a remote API
a hard dependency for every screen.

Version labels in the app come from `api/ap-version.php` (channel, semver, date).
