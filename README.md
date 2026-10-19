# VAAK

<p align="center">
  <img src="docs/assets/vaak-readme-mascot.png" alt="VAAK mascot" width="480">
</p>

**Current release:** alpha **0.8.75** · 2026-10-19

Notes: [0.8.75](docs/RELEASE-0.8.75.md).

Hi, I'm Nova. VAAK is the social server I built so I could stay on the Fediverse and still have the rest of the internet I actually use in the same window.

It's pronounced "vaak". The live copy is [https://mkultra.monster/vaak/](https://mkultra.monster/vaak/). `vaak.monster` redirects there. Addresses stay `@name@mkultra.monster`. This repository is the public source. The accounts and the posts belong to the host that runs them.

<p>
  <a href="https://mkultra.monster/vaak/users/cmdr_nova"><img src="docs/assets/cmdr-nova-avatar.webp" width="28" height="28" alt="cmdr_nova on VAAK"></a>
  <a href="https://mkultra.monster/vaak/users/cmdr_nova"><strong>cmdr_nova</strong> on VAAK</a>
</p>

## What it is

An ActivityPub server. PHP runs the app, PostgreSQL holds the durable data, and a Mastodon-compatible API lets clients such as Ice Cubes and Tusky log in and post. Connect a Bluesky app password and the same Home, search, profiles, favourites, and bookmarks pick up that network too. A cross-post carries a dual-publish marker so the two copies stay linked.

There's a Rust and Axum backend in progress. It already paints Home, Local, Federated, profile, and notification cards on the paths that have caught up. PHP still writes, and it still draws bookmarks, favourites, and the surfaces that are waiting their turn. The worker map, queues, and environment variables are in [rust/README.md](rust/README.md).

It is **alpha**. Screens move between releases. If something feels half-finished, it is, and I'm in the middle of it on purpose.

## What you can do with it

Home mixes people you follow on the Fediverse, people you follow on Bluesky, hashtags you follow, RSS subscriptions, and a small set of recommendations you can turn off. Local and the Federated feed stay their own timelines. Your blocks and mutes, and the server's, apply on Home, Local, Federated, notifications, search, and Trending before a card is shown. The feed scrolls, and Home has a New posts control when something lands above you.

You can reply, boost, quote-boost, favourite, and run a poll. Polls stay on the Fediverse side. Content warnings, emoji, and sensitive-media marks are there, along with images, animated GIFs, video, audio, and link previews. A YouTube link can play in the post. The audience is public, silent public, local only, or followers-only. Drafts and a queue live under You, for the post you are still writing and the one you want later.

Asks are the Wafrn kind: a question you send to a profile that advertises them, and an answer that comes back as a post. Outbound Asks are always from the signed-in account.

Trending sits beside the timeline: tags, posts, and links, with the same hides as everything else.

Library is the shelf. Favourites, bookmarks with folders, followers and following on both networks, followed hashtags, collections, and lists.

The blog composer is for the long one. You write Markdown, with buttons for bold, italic, headings, lists, and links, save a draft, and publish. The full post lives on your profile's Blog tab. The timeline gets the title as a content warning and a short excerpt, so the essay stays on the blog.

Profiles are at `/vaak/users/{name}`, including a view for people who are logged out. The ActivityPub actor document stays at `https://mkultra.monster/users/{name}`. Tabs cover posts, replies, boosts, media, Asks, pins, featured accounts, and the blog.

There are notifications, direct messages, two-factor authentication, and an account switcher. Import and export covers follows, mutes, blocks, bookmarks, lists, and followed hashtags. RSS lives under You and mixes into Home. An item that is only video is skipped. Notices, a local Discuss board, and the Phyrian Strains hub sit alongside the usual account stuff.

**Pluraldawn:** when an avatar already carries a member list, and a post uses exactly one of those indicators at the start or end of a paragraph, signed-in readers see that member's name and picture. The account address, the stored post, and Mastodon API clients stay on the account. VAAK reads the list out of the picture. It leaves the avatar, and the member list, as they were published.

Federation is the usual set: actors, inbox and outbox, WebFinger, NodeInfo, HTTP signatures, and webmentions. Registration stays closed until it is opened. When it is open, an invite is required, or an approval when that gate is on.

Moderation is reports, mod lists, server blocks, and domain blocks. A hide is applied before the card is shown.

## Why I made it

I run [mkultra.monster](https://mkultra.monster), and I wanted a server I could live in. One Home for the Fediverse and for Bluesky. Asks the way Wafrn does them. A Library for the posts I meant to come back to. A blog that publishes next to the short posts. Trending that still respects a block. A Mastodon API, because the apps people already like should keep working. VAAK is that project, still rough in the corners I'm moving.

## Running and deploying

The app I actually use is the link above. If you want to see how a copy is wired:

- How a production deploy actually ships: [docs/DEPLOY.md](docs/DEPLOY.md)
- Worker build, environment, and the commands that stay up: [rust/README.md](rust/README.md)
- systemd units and backup scripts: [deploy/](deploy/)
- Backups and the integrity check: [docs/vaak-integrity-policy.md](docs/vaak-integrity-policy.md)
- This release: [docs/RELEASE-0.8.45.md](docs/RELEASE-0.8.45.md)

PostgreSQL is the database (`AP_DB_DSN` as a `pgsql:` DSN). SQLite is available when `AP_ALLOW_SQLITE=1` is set, for local work. Redis is a cache and a queue. If Redis is down, the app still serves. It just has more to do on the way.

A small explainable ranker can train beside the live Home ordering and compare the two. People see the production heuristic. The comparison lives in [`rust/vaak-worker/src/ml_ranker.rs`](rust/vaak-worker/src/ml_ranker.rs).

## Status

Active alpha. **0.8.75** is the deployed version in `api/ap-version.php`.

The label in the app comes from that file: channel, number, and date. The counter steps the last component, and `0.8.99` rolls to `0.9.00`. How that bump is applied is written up in [docs/DEPLOY.md](docs/DEPLOY.md). Federation and Bluesky still depend on the other server. I would rather keep a cache warm and a queue bounded than make a page wait on somebody else's API.
