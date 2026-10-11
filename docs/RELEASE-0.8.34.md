# VAAK alpha 0.8.34 — 2026-10-11

This release fixes Trending privacy and moderation filtering, improves the mobile Blog composer, and removes redundant post-card navigation links while preserving their overflow-menu destinations. Trending excludes accounts currently downranked on Home or manually deprioritized, alongside the existing personal and server moderation rules. Its browser refresh rechecks current policy instead of replaying stale personalized HTML.

The Blog composer has a working mobile navigation drawer and formatting controls for existing Markdown syntax. Draft fields survive validation failures, and publishing retries retain their saved slug. Post navigation retains ActivityPub, Bluesky and Threads destinations in the overflow menu.

Shared privacy and compatibility fixes include PostgreSQL search escaping, rejection of nonpublic fetched quote documents before shared storage, suppression of denied quote warming, profile boost normalization, and the existing invite-account provisioning path. Current native shared card and worker behavior is checked as part of the integrated release.

Library, Search, complete profiles and Admin native migration remain disabled checkpoints. Registration approvals also remain disabled. No migration or registration feature switch is enabled, and this release does not claim complete Rust parity. PHP remains the canonical fallback and write owner.

The OpenSim Phyrian UTC patch is retained only as an upstream review checkpoint; it is not applied or deployed. Prompt Local/Home publication and immediate self-reply thread reconciliation remain follow-up work.

Verification and exact deployment artifacts are recorded in the integrated release review. Internal documentation, replay tools, fixture databases, credentials and Rust source are excluded from the website payload. No new service secrets or blanket cache flush are required.
