# VAAK integrity policy

- PostgreSQL and protected deployment configuration are backed up daily. The
  local backup directory is only the first tier; it must be copied to an
  independent host or object store and periodically restored in a disposable
  PostgreSQL instance.
- Remote firehose events and Bluesky cache rows are retained for 30 days in the
  hot database and then moved to the compact `ap_cold_archive` tier. The cold
  tier is retained for 365 days by maintenance. Local outbox notes, accounts,
  moderation state, DMs, and durable action/publication records are not subject
  to this remote-cache retention window.
- Delivery failures are not silently replayed. 4xx/405/404 and exhausted
  circuit-breaker rows remain reviewable, while pending rows and transient
  failures continue through the worker. Use `api/ap-queue-replay.php` only
  after confirming the remote host is healthy.
- `vaak-integrity-check.sh` checks services, backup age, queue growth, and disk
  pressure every 15 minutes and logs warnings/critical failures to journald.

The workstation pulls completed VPS backups to `/mnt/disc3/Backups/VAAK` with
the user-level `vaak-disc3-backup.timer`. It runs daily at 03:15 (and on the
next wake/boot when a scheduled run was missed), verifies the PostgreSQL
checksum manifest, and retains 180 days. This is a local second tier, not a
replacement for an independent off-box copy.
