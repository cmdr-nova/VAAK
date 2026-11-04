# Changelog

Every production deploy is a release. Each section below is written by the
deploy workflow from the pull requests merged since the previous release,
grouped by label (see `.github/release.yml`), and the same notes go on the
matching [GitHub Release](https://github.com/cmdr-nova/VAAK/releases).

VAAK follows [Semantic Versioning](https://semver.org). While it is 0.x, a
release with a feature or a breaking change bumps the minor number and
anything else bumps the patch number.

Releases up to **0.9.83** used a two-digit deploy counter (0.9.05, 0.9.83)
and have no notes here; their tags and GitHub Releases are unchanged.

## [0.33.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.33.0) - 2026-11-04

### ✨ Features
* Mark trending-topic feeds as temporary, with a close and back link by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/370
* Show Wafrn self-quotes with the real author and drop the mid-body RE: line by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/371

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.32.0...v0.33.0

## [0.32.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.32.0) - 2026-11-04

### ✨ Features
* Always pick a Home feed chip, even for feeds outside the pinned row by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/369
### 🐛 Fixes
* Fix follow suggestions offering accounts you already follow by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/367
### ⚡ Performance
* Speed up first page load: send the shell before fetching Home by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/368

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.31.0...v0.32.0

## [0.31.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.31.0) - 2026-11-04

### ✨ Features
* Trends: drop Trending posts from Across networks by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/365
* Nostr: sign through the bunker when the browser's extension can't by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/366

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.30.0...v0.31.0

## [0.30.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.30.0) - 2026-11-04

### ✨ Features
* Trends sidebar: posts inside Trending tags, Who to follow, Bluesky topic feeds by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/364

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.29.0...v0.30.0

## [0.29.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.29.0) - 2026-11-04

### ✨ Features
* Background trending service + Across networks trends by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/363

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.28.0...v0.29.0

## [0.28.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.28.0) - 2026-11-03

### ✨ Features
* Nostr in Search, Trending tags and Trending posts (linked npubs only) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/362

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.27.0...v0.28.0

## [0.27.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.27.0) - 2026-11-03

### ✨ Features
* Keep trending posts fresh: 48h window, steeper age decay by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/361

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.26.0...v0.27.0

## [0.26.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.26.0) - 2026-11-03

### ✨ Features
* Bluesky: paint posts quoted inside reply and mention notifications by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/357
* Nostr: show quoted notes as cards instead of a "Quoted note" link by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/358
* Bluesky status: say a muted quote closed the post, still load its thread by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/359
* Nostr crosspost: drop "(media)" placeholder from media-only notes by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/360

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.25.0...v0.26.0

## [0.25.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.25.0) - 2026-11-03

### ✨ Features
* Nostr: "followed you" notifications with Follow back by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/356

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.24.0...v0.25.0

## [0.24.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.24.0) - 2026-11-03

### ✨ Features
* Nostr: keep the follow list in step with your real kind 3 by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/354
* Push notification ids resolve to the pushed DM, never a borrowed boost by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/355

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.23.0...v0.24.0

## [0.23.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.23.0) - 2026-11-03

### ✨ Features
* Paint VAAK boosts of Nostr notes with Nostr actions and real links by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/351
* Render Bluemoji custom emoji on Bluesky posts by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/352
* Inbox: Rust signs and fetches unknown sender keys itself by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/353

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.22.0...v0.23.0

## [0.22.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.22.0) - 2026-11-03

### ✨ Features
* Rank Nostr notes on Home like Bluesky posts by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/349
* Nostr follow packs: open a pack in Collections, follow picked members with one signature by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/350

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.21.0...v0.22.0

## [0.21.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.21.0) - 2026-11-03

### ✨ Features
* Search: flip Look up remote in place by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/348

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.20.0...v0.21.0

## [0.20.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.20.0) - 2026-11-03

### ✨ Features
* Resolve own posts quoted with reply text glued to the link by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/346
* Nostr boosts: clickable booster, booster follow in menu, hover cards, visible follow toast by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/347

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.19.0...v0.20.0

## [0.19.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.19.0) - 2026-11-02

### ✨ Features
* Let sampled feed posts reach Home recommendations by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/345

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.18.0...v0.19.0

## [0.18.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.18.0) - 2026-11-02

### ✨ Features
* Close the zap pay panel once paid; drop the stuck Saving pill by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/342
* Notifications: paint grouped like/boost stacks in Rust instead of PHP by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/343
* Remove "Bluesky for @handle · Open profile" line from Bluesky feed view by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/344

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.17.0...v0.18.0

## [0.17.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.17.0) - 2026-11-02

### ✨ Features
* Nostr zaps: fill Lightning addresses for already-read profiles now by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/341

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.16.0...v0.17.0

## [0.16.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.16.0) - 2026-11-02

### ✨ Features
* Opt-in backfill of past Bluesky likes, reposts and bookmarks for Home ranking by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/340

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.15.0...v0.16.0

## [0.15.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.15.0) - 2026-11-02

### ✨ Features
* Nostr zaps: ⚡ button, Lightning invoice, zap notifications by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/339

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.14.0...v0.15.0

## [0.14.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.14.0) - 2026-11-02

### ✨ Features
* Bluesky feeds: background sampling for Home, scroll shimmer, back-to-top by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/337
### 🐛 Fixes
* Fix "List update failed" when adding to Bluesky-linked block lists by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/338

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.13.0...v0.14.0

## [0.13.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.13.0) - 2026-11-02

### ✨ Features
* Keep the selected Home feed chip in view by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/334
* Open and edit Library > Feeds without a page reload by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/335
* Switch DM conversations and actions without a page load by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/336

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.12.0...v0.13.0

## [0.12.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.12.0) - 2026-11-02

### ✨ Features
* Keep the page Saving pill off DM sends by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/329
* Add + "Add to list" button on posts; moderation lists block/mute any account by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/331
* Count Bluesky likes, reposts and bookmarks toward Home ranking by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/332
* Inbox: Rust spools signer/actor mismatches; the drain forwards or recovers them by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/333
### 🐛 Fixes
* Fix "Invalid feed" on pin; add Library > Feeds page by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/330

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.11.0...v0.12.0

## [0.11.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.11.0) - 2026-11-02

### ✨ Features
* Bluesky DMs on the DM screen (behind VAAK_BSKY_DMS=1) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/324
* DM screen: no underlines when hovering conversations by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/325
* Home feed chips (VAAK first), Bluesky feed search, smoother feed switching by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/326
* Bluesky DMs: requests, reactions, mute/leave, DM on Bluesky profiles by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/327
### 🐛 Fixes
* Keep Bluesky posting working through AppView takedowns by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/328

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.10.0...v0.11.0

## [0.10.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.10.0) - 2026-11-02

### ✨ Features
* Releases: SemVer versions, generated notes, CHANGELOG.md by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/323
### Other changes
* Bluesky custom feeds: soft-nav chips, Redis page cache, no background polling by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/318
* Home and trends: Unicode hashtags, signal caps, people-based trends, burst spreading by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/319
* Rework the DM screen and keep local DMs on this server by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/320
* Explain VAAK to remote admins in NodeInfo and User-Agents by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/321
* Redesign the Search page by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/322

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.9.83...v0.10.0

