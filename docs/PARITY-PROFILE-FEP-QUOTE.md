# Raw profile FEP quote-link parity checkpoint

Rust now resolves PHP-supported HTTPS quote targets from FEP Object Link tags after the existing scalar quote fields. Target extraction does not authorize quoted content: the existing canonical child lookup, audience checks, source ledger and final publication checks still apply. Multiple associative tag objects retain PHP fallback because PHP insertion order is not represented by the sorted JSON object used by this path. Embedded, summary-only and Bluesky quote handling remain separate gates.

Verification against the unchanged PHP implementation at `b6f6ee4`:

- 25 actual `ap_quote_target_pack` cases, including precedence, singleton/list tags, relation arrays and invalid inputs. Separate native assertions reject unsupported Bluesky normalization and ambiguous associative tag maps.
- 32 actual PHP raw profile cards compared with native canonical PostgreSQL preparation and rendering across INTEGER and BIGINT schemas. Complete significant DOM, all attributes, forms and text matched, including parent/child content warnings and private/deleted child omissions.
- All 32 parent edits after preparation prevented publication.
- Independent source and output review accepted this bounded change. The exact opt0 test artifact was `22988a69ecfadf2e9fef614679f68b77cde2a09fe442e5f04ecc05c2a25bab01`; all 830 source hashes and modes matched before and after compilation.
- Default regression suite: 255 passed, 90 intentionally ignored. One listener test initially hit the sandbox's localhost restriction and passed when rerun with listener access.

The fixtures use retained genuine local quote-source rows, durable alternative tag encodings, disposable local SQL and explicitly offline transport/lookup seams. They do not prove complete controller/authentication/header behavior, authenticated Settings actions, cold remote providers, performance or every late child mutation schedule. Disposable schemas were removed. No production flags or worker binaries changed; full profile cutover remains disabled.

Local reproducible evidence: `rust/target/parity-evidence/profile-tag-quote-b6f6ee4/`. The two fixture tools are `api/bin/profile-tag-target-reference.php` and `api/bin/profile-tag-raw-reference.php`; explicit non-CLI 404 guards were added during final integration without changing their CLI behavior or compiled Rust source.
