# VAAK alpha 0.8.41 · 2026-10-12

Home fanout could reinsert a connected account's Bluesky copy after the warmer excluded it. Rust and PHP now enforce the connected-owner exclusion and current local-mirror provenance during fanout, ranked merging, hydration, newer polling and cached Home responses. Deduplication no longer depends on the local original appearing on the same page.

Existing publication retains its crosspost markers and owned link mappings for text, media, replies and split posts. The Home classifier preserves independently authored Bluesky posts, profile-only Bluesky imports, peer quotes and unrelated replies. Trusted ownership and complete segment boundaries prevent stale split copies from resurfacing after local deletion. These checks grant no audience access and apply only to Home.

Verification used isolated real SQL and Redis fixtures: 132 PHP classifier controls across SQLite and both PostgreSQL ID widths; 88 native SQL cases; 60 actual worker fanout assertions; 88 cached return controls and two late-map controls; 60 PHP queue-fallback assertions; and 1,056 actual PHP Home caller checks. Default Rust regression passed 254 tests, with 89 optional integration tests ignored. Separate actual publisher and importer references passed 56 and 17 controls. Owner/authentication and external transport seams are declared in the evidence; these fixtures do not establish every authenticated browser session or full Rust parity.

Home hydration checks added about 1.3 ms at the median in the isolated fixture. No render-time network requests, schema changes, global cache flushes or migration switch changes are required. Current registration approvals, paid Supporter behavior, and Settings/privacy actions retain their existing configuration. Library, Search, profile and Admin migration completion remains separate.

Production publication and health verification are recorded in the operator log after deployment.
