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

## [0.59.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.59.0) - 2026-11-08

### ✨ Features
* Post view: drop focus outline and earlier-posts labels by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/413

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.58.0...v0.59.0

## [0.58.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.58.0) - 2026-11-08

### ✨ Features
* Show the same like/boost/reply/quote counts on every card by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/412

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.57.0...v0.58.0

## [0.57.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.57.0) - 2026-11-08

### ✨ Features
* Blog composer v2: native editor, richer Markdown, edit/delete, scheduling by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/410
* Open the quoted post's thread when a quote card is clicked or tapped by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/411

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.56.0...v0.57.0

## [0.56.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.56.0) - 2026-11-08

### ✨ Features
* Share likes, boosts and bookmarks between a post's fediverse and Bluesky copies; drop stray Profile button on notification posts by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/408
* Show Home likes on Bluesky mentions in Notifications by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/409

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.55.0...v0.56.0

## [0.55.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.55.0) - 2026-11-08

### ✨ Features
* Publish delivery: reap a hung worker and alert when the queue stalls by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/407

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.54.0...v0.55.0

## [0.54.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.54.0) - 2026-11-08

### ✨ Features
* F5 shadow: check "no notification" against this delivery's recipient by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/406
### 🐛 Fixes
* Quote cards: fill quoted posts on older timeline pages, fix placeholder fields by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/405

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.53.0...v0.54.0

## [0.53.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.53.0) - 2026-11-07

### ✨ Features
* Inbox shadow: predict likes and boosts of local posts (cutover F5) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/404

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.52.0...v0.53.0

## [0.52.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.52.0) - 2026-11-07

### ✨ Features
* Show crossposted parents for Nostr replies by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/403

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.51.0...v0.52.0

## [0.51.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.51.0) - 2026-11-07

### ✨ Features
* Inbox routing: match actor blocks by actor, not by host by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/401
* Bluesky: blocked or deleted quotes no longer paint as a DID author by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/402

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.50.0...v0.51.0

## [0.50.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.50.0) - 2026-11-06

### ✨ Features
* Inbox: Rust routes Undo Like/Announce and mention Deletes (F4) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/400

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.49.0...v0.50.0

## [0.49.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.49.0) - 2026-11-06

### ✨ Features
* Unboost: give the embedded Announce its audience (GoToSocial 400) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/399

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.48.0...v0.49.0

## [0.48.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.48.0) - 2026-11-06

### ✨ Features
* Title local profiles "Local profile" everywhere, not "Remote profile" by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/397
* Mobile pull to refresh: start anywhere, no gap under top bar by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/398

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.47.0...v0.48.0

## [0.47.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.47.0) - 2026-11-06

### ✨ Features
* Link preview cards for shared VAAK post links by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/396

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.46.0...v0.47.0

## [0.46.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.46.0) - 2026-11-06

### ✨ Features
* Fan-out: answered 4xx no longer opens a host's circuit; gone inboxes give up at once by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/395

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.45.0...v0.46.0

## [0.45.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.45.0) - 2026-11-06

### ✨ Features
* Quote cards use the best-known author profile everywhere by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/394

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.44.0...v0.45.0

## [0.44.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.44.0) - 2026-11-05

### ✨ Features
* Keep one "followed you" notification per follower by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/393

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.43.0...v0.44.0

## [0.43.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.43.0) - 2026-11-05

### ✨ Features
* Delivery: Rust sends outgoing likes, unlikes and unboosts (A1) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/392

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.42.0...v0.43.0

## [0.42.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.42.0) - 2026-11-05

### ✨ Features
* Show Bluesky-native posts of local accounts in Local by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/391

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.41.0...v0.42.0

## [0.41.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.41.0) - 2026-11-05

### ✨ Features
* Inbox: Rust routes author-signed Deletes itself (F3 switch) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/390

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.40.0...v0.41.0

## [0.40.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.40.0) - 2026-11-05

### ✨ Features
* Paint Nostr boosts and quotes in the PHP boost card by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/388
* Show nothing for boosts VAAK can't display by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/389
### 🧹 Maintenance
* Bump redis from 1.8.0 to 1.8.1 in /rust in the cargo-minor-patch group by @dependabot[bot] in https://github.com/cmdr-nova/VAAK/pull/385
* Bump actions/labeler from 5 to 7 by @dependabot[bot] in https://github.com/cmdr-nova/VAAK/pull/386
* Bump actions/github-script from 7 to 9 by @dependabot[bot] in https://github.com/cmdr-nova/VAAK/pull/387

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.39.0...v0.40.0

## [0.39.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.39.0) - 2026-11-04

### ✨ Features
* Remote avatars: Refresh profile re-downloads them; use known icon URLs by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/384

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.38.0...v0.39.0

## [0.38.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.38.0) - 2026-11-04

### ✨ Features
* Notifications: drop Wafrn Bluesky twins; sync likes/boosts/bookmarks across views by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/383

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.37.1...v0.38.0

## [0.37.1](https://github.com/cmdr-nova/VAAK/releases/tag/v0.37.1) - 2026-11-04

### 🐛 Fixes
* Profiles: fix the 503 on every /vaak/users/{key} page by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/382

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.37.0...v0.37.1

## [0.37.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.37.0) - 2026-11-04

### ✨ Features
* Home: Klipy GIFs without a Bluesky thumbnail show the MP4's first frame by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/380
* Inbox: Rust shadow-predicts how the router handles Deletes (F3) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/381

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.36.0...v0.37.0

## [0.36.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.36.0) - 2026-11-04

### ✨ Features
* Delivery: Rust sends PHP's inline fan-out too (cutover D3) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/378
* Home: play Bluesky Klipy GIFs instead of a dead video box by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/379

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.35.1...v0.36.0

## [0.35.1](https://github.com/cmdr-nova/VAAK/releases/tag/v0.35.1) - 2026-11-04

### ⚡ Performance
* Speed up page loads: serve the shell's CSS and scripts as cached files by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/377

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.35.0...v0.35.1

## [0.35.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.35.0) - 2026-11-04

### ✨ Features
* Delivery: Rust works the fan-out delivery queue (D2) by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/376

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.34.0...v0.35.0

## [0.34.0](https://github.com/cmdr-nova/VAAK/releases/tag/v0.34.0) - 2026-11-04

### ✨ Features
* Temporary feed chip: drop the Temporary tag, put ✕ inside the pill by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/375
### 🐛 Fixes
* Nostr: say which step failed instead of a bare "VAAK did not answer" by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/374
### ⚡ Performance
* Speed up first Home load: share the worker's ranked cache key by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/373

**Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.33.0...v0.34.0

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

