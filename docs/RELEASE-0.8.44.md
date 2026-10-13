# VAAK alpha 0.8.44 · 2026-10-13

Search admission now batches current source, audience, mute/block, follow and owner/admin action checks. Account and status cards stay bound to the PHP collector window, with fresh checks before a batch is returned. Ordinary Fediverse account batches can proceed when unused Bluesky profile storage is absent; graph identities that cannot preserve PHP profile-key behavior use whole-batch PHP fallback.

This batch also fixes notification state and authored reply text, quote presentation CSS, and the “Pinned!” confirmation toast.

**Production Search native rendering remains off.** PHP continues to collect, render and write Search results, including cursors and remote resolution. Activation and fallback settings stay unchanged; this release does not enable a Search or Library cutover. Five unchanged live PHP dependencies are preserved, including live Ask profile navigation and Phyrian extensions. Native Ask navigation requires separate compatibility work before activation.

Verification passed 266 active Rust tests; 105 ignored fixtures are not claimed as coverage. The PHP reply-preview smoke passed 29 checks. Owned fixtures passed eight genuine PHP follow controls, four account/admin paint race controls, six caller gates using the actual optimized production worker, and five full/soft/append performance routes. Privacy withdrawal, malformed and missing collectors, protected DOM/forms/actions/CSRF/cursors, and fixture restoration and cleanup were checked within those finite scopes.

The final performance fixture used 42 genuinely posted, naturally minute-old local notes. Its 40-card native handler completed in **1.484 seconds**, inside the original three-second limit. The complete PHP request using native rendering took **1.691 seconds**, versus **0.454 seconds** for PHP-only rendering. This establishes finite parity and deadline coverage, **not a speed improvement** or universal provider performance.

Fixture results do not establish browser execution, remote cold-media transport, authenticated live-user sessions, or a full Rust migration. Production installation and service health are recorded separately in the operator release receipt.
