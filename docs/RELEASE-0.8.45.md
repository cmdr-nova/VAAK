# VAAK alpha 0.8.45 — 2026-10-13

Verification complete; deployment and production health confirmation are pending the operator receipt.

This release repairs ActivityPub pins: pin and unpin now queue Add/Remove on actual changes, and public featured collections return pins for their explicit profile owner. Public/unlisted visibility is checked against both stored representations; private/direct/local posts remain excluded. Repeated actions do not queue duplicates, the five-pin limit remains, and Bluesky still follows the latest eligible pin. Existing pins are not replayed.

The worker aligns Ask profile navigation with the current PHP contract and removes redundant Search context reads while retaining current source, visibility, follow and action checks and whole-batch fallback. Existing production PHP Ask/profile/phyrian helpers and activation settings are preserved.

Validation: 266 active normal Rust tests passed; 105 ignored tests were not run. The focused pin smoke passed 20 checks using actual extracted PHP functions with captured transport and no network. The actual optimized production worker passed full and append private-withdrawal controls: native 200 changed to 422, visible cards/actions matched fresh PHP omission, and original collected cursors and cache bytes remained unchanged. This does not claim identical post-collection count metadata.

The existing five-route fixture passed for 20/20/10/10/40 selected cards with strict DOM, form, action, CSRF and raw-cursor checks. Its 40-card native handler took 0.718 seconds, compared with 1.484 seconds in the prior run. The complete PHP request through the native bridge still took 0.834 seconds versus 0.262 seconds with native Search off. These finite local measurements do not demonstrate an end-to-end improvement, so native Search remains OFF. The original 3-second native and 180-second whole-case bounds were retained. All three cases restored the complete 104-table journal, preserved the 24 protected resources, and verified helper/process, listener, backend and owned-schema cleanup.

PHP remains the collector and write owner; whole-batch fallback and existing activation settings are preserved. Broader Rust, Library, profile and provider parity remains open. Existing production PHP Ask/profile/phyrian helpers are preserved. Deployment, production health and rollback-backup metadata remain pending the operator receipt.
