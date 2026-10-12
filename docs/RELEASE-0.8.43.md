# VAAK alpha 0.8.43 · 2026-10-12

Bluesky timeline and Open-post cards now keep navigation and moderation actions in one overflow menu, across initial AT identifiers and hydrated web URLs. The same correction is included in PHP and the Rust shared card renderers. Protocol and guest/minimal-card Remote links permit HTTP(S) targets.

Opening a Bluesky post incrementally loads its earlier posts and replies into the existing thread shells. Public AppView discovery validates the requested post and author, rejects blocked or missing thread records, preserves current block, mute, word-filter and audience rules, and uses current local post associations to avoid reviving withdrawn bridged copies. Explicit Open keeps the established root mute behavior; discovered replies and nested quotes retain strict filtering. Thread discovery is bounded to 12 earlier posts, reply depth four and 40 discovered cards. The existing loader preserves the focus position and supports cancellation without refreshing the page or sidebar.

Direct-message cards no longer offer the broken Open remote link. The SQLite per-account mentions migration preserves Ask actor, question and answer fields instead of losing them during the legacy table rebuild. Production PostgreSQL schema is unchanged.

Verification includes 76 actual PHP card checks, 68 thread helper controls, 25 isolated PHP SAPI controller checks and actual browser loader, scroll-anchor and cancellation checks. Fixtures do not establish authenticated production session or Settings POST parity. The normal Rust regression artifact passed 256 active tests; 90 ignored tests are not claimed as parity coverage. Production worker compilation and installation are recorded separately in the operator log.

This release ships the reviewed fixes and retains the current fallback and activation settings. Library, Search, full-profile and remaining backend conversion are unfinished; this release does not enable a full Rust cutover. Registration continues using its existing approval, closure and paid Supporter code controls.
