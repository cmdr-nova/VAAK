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

