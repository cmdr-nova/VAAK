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
- `vaak-integrity-check.sh` checks services, backup age, restore drill age,
  queue growth, and disk pressure every 15 minutes and logs warnings/critical
  failures to journald. Terminal fan-out failures on suspended dead hosts are
  counted apart from failures on hosts that are still healthy, and so are
  "dying" hosts: a streak of at least `VAAK_FANOUT_DEAD_FAILURES` terminal
  failures with no success since it began, still waiting out the
  `VAAK_FANOUT_DEAD_DAYS` window before suspension. Only the healthy count
  warns, and only above 1000; the dying count is printed as `dying_fanout`.
  Rows whose last error is a live rejection (HTTP 401, 403, 429 or 500: the
  host answered and refused) are printed as `rejected_fanout` and
  `rejected_fanout_hosts` and left out of the healthy count. They warn only
  when `VAAK_INTEGRITY_REJECTED_HOSTS_WARN` (default 25) or more hosts refuse
  at once, which points at VAAK's own signing rather than one remote server.
  `VAAK_FANOUT_DEAD_DAYS` (default 7) and
  `VAAK_FANOUT_DEAD_FAILURES` (default 20) are the same knobs the delivery
  worker uses. A recent success, or a recent HTTP 401, 403, 429, or 500, keeps a
  host out of the suspended count. It also checks Caddy and every long-running
  VAAK unit that is enabled on the box (an enabled unit that is not active
  warns; the Rust HTTP unit going down is critical).
- Phone alerts: when `/etc/mkultra/vaak-alert.env` names an ntfy topic, warn
  and crit results are pushed by `vaak-alert-notify.sh`. The same set of
  problems is sent once and then at most every 6 hours (counts are ignored
  when comparing, so a growing queue does not re-alert every 15 minutes); a
  new problem sends at once, and the first clean run after an alert sends one
  "recovered" push. Without the file nothing is sent.

### Phone alerts setup

1. Install the ntfy app (iOS/Android) and subscribe to a long random topic,
   for example the output of `openssl rand -hex 16`. Anyone who knows the
   topic name can read it on ntfy.sh, so treat it like a password, or use a
   self-hosted ntfy with an access token.
2. As root:

```bash
install -m 0755 deploy/vaak-alert-notify.sh /usr/local/sbin/vaak-alert-notify.sh
install -m 0755 deploy/vaak-integrity-check.sh /usr/local/sbin/vaak-integrity-check.sh
install -m 0600 /dev/null /etc/mkultra/vaak-alert.env
echo 'VAAK_ALERT_NTFY_URL=https://ntfy.sh/<your topic>' >> /etc/mkultra/vaak-alert.env
# optional: VAAK_ALERT_NTFY_TOKEN=tk_...   VAAK_ALERT_REPEAT_HOURS=6
/usr/local/sbin/vaak-alert-notify.sh test
```

`bash deploy/test_alert_notify.sh` exercises the notifier with a fake curl.

The workstation pulls completed VPS backups to `/mnt/disc3/Backups/VAAK` with
the user-level `vaak-disc3-backup.timer`. It runs daily at 03:15 (and on the
next wake/boot when a scheduled run was missed), verifies the PostgreSQL
checksum manifest, and retains 180 days. This is a local second tier, not a
replacement for an independent off-box copy.

The pull connects as the `vaak-backup` user, whose only key is restricted on the
VPS with `restrict,command="/usr/bin/rrsync -ro /var/backups/mkultra/vaak/"`.
It cannot get a shell or write. `vaak-postgres-backup.sh` writes dumps as
`root:vaak-backup 0640` in a `0750` directory so that user can read them. Note
the config archive includes `/etc/mkultra`, `/etc/caddy`, and the
admin-uploaded profile media in `/srv/mkultra/html/img/profile`
(`ap_profile_media_dir()`), which is not in git and is never written by the
deploy.

## Restore drill

`deploy/vaak-backup-restore-drill.sh` runs monthly from
`vaak-backup-restore-drill.timer` (the 3rd, 04:30 local time). It:

1. Picks the newest `novalandia-*.dump` in `/var/backups/mkultra/vaak` and
   verifies it against its `.sha256` manifest.
2. Checks that the PostgreSQL data directory has at least four times the dump
   size free (`VAAK_DRILL_SPACE_FACTOR`).
3. Restores it into the scratch database `novalandia_restore_drill` with
   `pg_restore --no-owner --no-privileges --exit-on-error --jobs=1`, with
   `maintenance_work_mem=64MB`, no parallel index builds, and
   `synchronous_commit=off`, under `nice` and idle I/O.
4. Compares row counts of `ap_users`, `following`, `followers`,
   `outbox_notes`, `masto_statuses`, and `events` with the live database.
   Each must be within a percentage of live (5% for users, 10% for follows
   and own posts, 50% for `events`, which is pruned daily) or within 5 rows.
5. Drops the scratch database on every exit path, prints
   `restore_drill_ok`, and writes `<epoch> <dump name>` to
   `/var/lib/mkultra/vaak-restore-drill/last-ok`.

Any failure prints `restore_drill_failed <reason>` and exits non-zero, so the
unit shows as failed. `vaak-integrity-check.sh` warns when `last-ok` is
missing or older than 40 days. The drill takes `/run/vaak-lock/vaak-backup-restore-drill.lock`.

Install as root:

```bash
install -m 0755 deploy/vaak-backup-restore-drill.sh /usr/local/sbin/vaak-backup-restore-drill.sh
install -m 0755 deploy/vaak-postgres-backup.sh /usr/local/sbin/vaak-postgres-backup.sh
install -m 0755 deploy/vaak-integrity-check.sh /usr/local/sbin/vaak-integrity-check.sh
install -m 0644 deploy/vaak-backup-restore-drill.service deploy/vaak-backup-restore-drill.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now vaak-backup-restore-drill.timer
# Run it once now so the integrity check has a stamp:
systemctl start vaak-backup-restore-drill.service
journalctl -u vaak-backup-restore-drill.service -n 20 --no-pager
```

`bash deploy/test_backup_restore_drill.sh` exercises the script with fake
PostgreSQL tools.

Root cron jobs take their `flock` in `/run/vaak-lock` (root-owned, created by
`deploy/vaak-lock.tmpfiles.conf` -> `/etc/tmpfiles.d/vaak-lock.conf`), not
`/tmp`, where `www-data` could hold the lock and block backups.
