#!/usr/bin/env bash
# Cron templates that start a PHP worker under flock must bound the run.
# A hung PHP process otherwise holds the lock and every later run is skipped
# (ListenBrainz, 2026-11-18). Long-lived daemons stay unbounded.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/.." && pwd)"

fail() { echo "FAIL: $*" >&2; exit 1; }

# timeout must wrap php, and when flock is present it must wrap timeout so
# the lock is released as soon as timeout's child exits.
check_bounded() {
  local file="$1" script="$2" want_flock="$3"
  local line
  line="$(grep -F "$script" "$file" | head -n 1 || true)"
  [[ -n "$line" ]] || fail "$file has no line for $script"
  [[ "$line" == *"/usr/bin/timeout -k 30 10m"*"/usr/bin/php"* ]] || fail "$file does not run timeout before php"
  if [[ "$want_flock" == flock ]]; then
    [[ "$line" == *"/usr/bin/flock -n "*"/usr/bin/timeout -k 30 10m"* ]] || fail "$file holds the flock around an unbounded php"
  fi
}

check_bounded "$root/deploy/mkultra-ap-listenbrainz" "ap-listenbrainz-worker.php" flock
check_bounded "$root/deploy/mkultra-ap-actor-refresh" "ap-actor-refresh-worker.php" flock
check_bounded "$root/deploy/mkultra-ap-phyrian-decay" "phyrian-decay.php" flock
check_bounded "$root/ops/mkultra-phyrian-sync.cron" "phyrian-sync.php" noflock

# Daemons and maintenance that are supposed to run for a long time.
for unit in \
  deploy/vaak-redis-queue-worker.service \
  deploy/vaak-worker-notif-list.service \
  deploy/vaak-inbox-spool-drain.service
do
  if grep -q '/usr/bin/timeout' "$root/$unit"; then
    fail "$unit must not be wrapped in timeout"
  fi
done

# The drain restarts every --budget; RuntimeMaxSec kills a run that hangs
# past it (2026-11-18: one run polled a dead Redis socket for 18 hours).
grep -qE '^RuntimeMaxSec=[0-9]+' "$root/deploy/vaak-inbox-spool-drain.service" \
  || fail "vaak-inbox-spool-drain.service needs RuntimeMaxSec"
grep -q "ap_worker_cli_max_runtime" "$root/api/bin/inbox-spool-drain.php" \
  || fail "inbox-spool-drain.php must arm ap_worker_cli_max_runtime"

echo "php worker timeout cron: ok"
