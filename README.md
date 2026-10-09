# VAAK

<p align="center">
  <img src="docs/assets/vaak-readme-mascot.png" alt="VAAK mascot" width="480">
</p>

**Current release:** alpha **0.8.17** · 2026-10-06

VAAK (pronounced “vaak”) is a multi-user social web client for the Fediverse, with an optional Bluesky / AT Protocol connection. One interface for reading, posting, following, moderating, and carrying a conversation across both networks.

It is **alpha** software: surfaces and behavior can still change between releases. The app for this project is [https://mkultra.monster/vaak/](https://mkultra.monster/vaak/). `vaak.monster` redirects there. Account addresses stay `@name@mkultra.monster`.

## What it is

A browser app and a Mastodon-compatible API that speak **ActivityPub**, and that can link an account to **Bluesky** with an app password. Timelines, notifications, search, profiles, DMs, Asks, and moderation apply across both sides where the feature exists. Reads are cache-first so a page does not wait on a remote server.

This repository is the public source mirror. Live accounts and profiles belong to the host that runs it.

## Highlights

### Timelines

- **Home**, **Local**, and **Federated**, with those scopes kept distinct
- Home draws from followed Fediverse accounts, followed Bluesky accounts, followed hashtags, RSS subscriptions, and a bounded set of recommendations
- Turning recommendations off keeps followed sources and moderation in place
- Personal and server mutes and blocks apply on Home, Local, Federated, notifications, search, and trending
- Infinite scroll, plus a New posts control on Home

### Posting

- Replies, boosts, quote boosts, favourites, and polls
- Content warnings, emoji, and sensitive-media marks
- Images, animated GIFs, video, and audio, plus link previews (YouTube can play in the post)
- Audience: public, silent public, local only, or followers-only
- Drafts and a queue
- Long-form blog posts next to short posts

### Profiles and people

- Canonical profiles at `/vaak/users/{name}`, including a logged-out guest view
- ActivityPub actor documents stay at `https://mkultra.monster/users/{name}`
- Tabs for posts, replies, boosts, media, Asks, pins, featured accounts, and blog
- Followers and following for both networks, plus lists, collections, and followed hashtags
- Notifications, Wafrn-compatible Asks, and direct messages
- Bookmarks with folders, and favourites split between Fediverse and Bluesky
- **Pluraldawn:** when an avatar already carries a member list, and a post uses exactly one of those indicators at the start or end of a paragraph, signed-in readers see that member’s name and picture. The account address, the stored post, and Mastodon-API clients stay on the account. VAAK does not build the avatar or store its own member list.

### Bluesky

- Optional per-account connection with an app password
- The same Home, search, profiles, favourites, and bookmarks — not a separate Bluesky app
- Cross-posting when connected, with dedupe markers so the two copies are not counted twice

### RSS

- Subscriptions under You → RSS, mixed into Home
- An item that is only video is skipped

### Account

- Profile settings, color accents, muted words, and personal blocks and mutes
- Two-factor authentication and an account switcher
- Import and export for follows, mutes, blocks, bookmarks, lists, and followed hashtags
- Notices, a local Discuss board, and the Phyrian Strains hub

### Clients and federation

- ActivityPub actors, inbox/outbox, WebFinger, NodeInfo, and HTTP signatures
- Mastodon-compatible API for clients such as Ice Cubes
- Webmentions
- Invite-only registration

### Moderation

- Reports, moderation lists, server blocks, and domain blocks
- Hides are applied before a card is shown

## Design notes

- **PostgreSQL** holds durable state (accounts, posts, queues, moderation)
- **Redis** accelerates caches and wake-ups; the app still works if Redis is down
- Remote AppView / PDS work is pushed to workers so page loads enqueue and return quickly

### Current runtime model

- PostgreSQL is the only durable application database. Redis is a cache and queue layer, never the source of truth.
- Rust/Axum paints Home, Local, Federated, profile, and notification cards where parity is in place. PHP paints bookmarks, favourites, and the remaining surfaces, and it remains the write owner.
- Home ranking uses freshness, follows, favourites, boosts, replies, bookmarks, and moderation signals. Personal and server blocks and mutes are applied before rendering.
- A small explainable ML ranker runs in **shadow mode** only. It compares an ordering with the production heuristic and does not choose what people see. See `rust/vaak-worker/src/ml_ranker.rs`.

### Background ingestion

- Bluesky ingestion uses shared Jetstream hydration and cached profile and media resolution.
- Timeline workers warm ranked caches and newer-post polling without blocking the page request.
- ActivityPub delivery, Asks, Bluesky mirroring, media queues, and moderation stay on bounded queues, with PHP still able to serve a surface the worker does not own.

## Status

Active alpha. **0.8.17** is the version in `api/ap-version.php`. Federation and Bluesky behavior vary by remote software. Performance work favors warm caches, bounded queues, and safe fallbacks over making a remote API a hard dependency for every screen.

Version labels in the app come from `api/ap-version.php` (channel, semver, date).
