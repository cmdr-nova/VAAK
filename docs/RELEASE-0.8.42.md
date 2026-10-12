# VAAK alpha 0.8.42 · 2026-10-12

Administrators can close and reopen registration from Approvals. Closure disables the guest form with a visible “Registration closed” marker and rejects new submissions on the server, including submissions already waiting when closure commits. Pending applications remain reviewable. Reopening preserves account approval requirements and paid Supporter code rules. Public registration metadata reflects the accepting state.

The winning successful account approval attempts an email through the existing VAAK/ Clearance SMTP sender after account provisioning. Replayed approvals, rejected applications and applications without email do not send. A bounded child process makes one best-effort attempt; delivery failure leaves the approval saved and shows an administrator warning. There is no automatic retry or guaranteed delivery.

Verification covered 234 genuine PHP/PDO controls across SQLite and both PostgreSQL ID widths, 12 concurrent decision races, nine held-lock closure/role-withdrawal schedules, verified local STARTTLS delivery and exact child cleanup. Actual PHP-FPM front-controller tests passed 46 controls per PostgreSQL width. Those restricted FPM fixtures verify graceful mail failure; successful mail is separately verified through the actual supervised CLI sender. Six receipt-race controls and 19 independently reviewed admin notice controls also passed.

The registration state requires the explicit private operator migration in `ops/migrations/20261012_registration_control.sql`, with production ownership and SELECT/UPDATE grants verified separately. Internal SQL and documentation must never be installed under the public web root. Installation preserves the existing OPEN state. Approvals retains its PHP renderer until Rust supports these controls; Library, Search and profile conversion remain separate work.

Production installation and health verification are recorded in the operator log after deployment.
