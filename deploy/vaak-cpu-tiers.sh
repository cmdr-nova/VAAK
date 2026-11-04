#!/usr/bin/env bash
# CPU priority tiers for VAAK background services.
#
#   vaak-cpu-tiers.sh status    show Nice / CPUWeight / IOWeight per unit
#   vaak-cpu-tiers.sh apply     write the drop-ins and daemon-reload (root)
#   vaak-cpu-tiers.sh remove    delete the drop-ins and daemon-reload (root)
#
# The box is CPU-bound and shared with other services. Without this, the
# warmers and fan-out compete on equal terms with php-fpm, Caddy and the Axum
# server that answer page loads. Weights only matter under contention, so an
# idle box runs every job exactly as fast as before.
#
# Tiers (systemd default is CPUWeight=100, IOWeight=100, Nice=0):
#   interactive  untouched: php8.3-fpm, caddy, postgresql, redis,
#                vaak-worker-shadow-http (Axum)
#   near-live    CPUWeight=50 IOWeight=50: inbox drain, fan-out, queue
#                worker, live badge, Jetstream spooler/worker
#   warm         CPUWeight=20 IOWeight=20 Nice=10: cache warmers and refresh
#                jobs that only make later reads faster
#   bulk         CPUWeight=5 IOWeight=10 Nice=19: backups and training
#
# Limit: SQL a worker sends runs in a postgres backend, outside this unit's
# cgroup, so the tiers cover the worker's own CPU, not its queries.
#
# CPUWeight/IOWeight apply to running units on daemon-reload. Nice applies on
# the unit's next start, so `apply` prints the restarts that pick it up.
# One drop-in file per unit, so `remove` never touches anything else.
set -euo pipefail

SYSTEMD_DIR="${VAAK_CPU_TIERS_SYSTEMD_DIR:-/etc/systemd/system}"
SYSTEMCTL="${VAAK_CPU_TIERS_SYSTEMCTL:-systemctl}"
DROPIN_NAME="50-vaak-cpu-tier.conf"

NEAR_LIVE=(
  vaak-inbox-spool-drain.service
  vaak-worker-timeline-fanout.service
  vaak-redis-queue-worker.service
  vaak-worker-notif.service
  ap-bsky-jetstream@.service
  ap-bsky-jetstream-worker.service
)
WARM=(
  vaak-worker-ranked-warm.service
  vaak-worker-thin-warm.service
  vaak-worker-actor-warm.service
  vaak-worker-ap-actor-warm.service
  vaak-worker-notif-list.service
  vaak-worker-shadow.service
  ap-bsky-jetstream-wanted.service
  ap-wafrn-friend-refresh.service
  ap-follow-fallback.service
  vaak-nostr-sync.service
)
BULK=(
  vaak-backup-restore-drill.service
  vaak-ml-shadow.service
  vaak-trends-service.service
)

tier_body() {
  case "$1" in
    near-live) printf '[Service]\nCPUWeight=50\nIOWeight=50\n' ;;
    warm) printf '[Service]\nCPUWeight=20\nIOWeight=20\nNice=10\n' ;;
    bulk) printf '[Service]\nCPUWeight=5\nIOWeight=10\nNice=19\n' ;;
    *) echo "unknown tier $1" >&2; return 1 ;;
  esac
}

each_unit() {
  local u
  for u in "${NEAR_LIVE[@]}"; do printf '%s near-live\n' "$u"; done
  for u in "${WARM[@]}"; do printf '%s warm\n' "$u"; done
  for u in "${BULK[@]}"; do printf '%s bulk\n' "$u"; done
}

cmd_apply() {
  local unit tier dir restart=()
  while read -r unit tier; do
    dir="$SYSTEMD_DIR/$unit.d"
    mkdir -p "$dir"
    {
      printf '# Written by vaak-cpu-tiers.sh (%s tier). Remove with: vaak-cpu-tiers.sh remove\n' "$tier"
      tier_body "$tier"
    } >"$dir/$DROPIN_NAME.tmp"
    mv "$dir/$DROPIN_NAME.tmp" "$dir/$DROPIN_NAME"
    if [ "$tier" != near-live ] && [[ "$unit" != *@.service ]]; then
      restart+=("$unit")
    fi
  done < <(each_unit)
  "$SYSTEMCTL" daemon-reload
  echo "Drop-ins written to $SYSTEMD_DIR/<unit>.d/$DROPIN_NAME and daemon reloaded."
  echo "CPUWeight/IOWeight are live now. Nice applies on next start; oneshot timers pick it up on their next run."
  echo "Long-running warmers to restart for Nice (safe, they resume their loop):"
  local u
  for u in "${restart[@]}"; do
    if "$SYSTEMCTL" is-active --quiet "$u" 2>/dev/null; then
      echo "  systemctl restart $u"
    fi
  done
}

cmd_remove() {
  local unit tier
  while read -r unit tier; do
    rm -f "$SYSTEMD_DIR/$unit.d/$DROPIN_NAME"
    rmdir "$SYSTEMD_DIR/$unit.d" 2>/dev/null || true
  done < <(each_unit)
  "$SYSTEMCTL" daemon-reload
  echo "Drop-ins removed and daemon reloaded."
}

cmd_status() {
  local unit tier name
  printf '%-40s %-10s %s\n' UNIT TIER 'Nice / CPUWeight / IOWeight (live)'
  while read -r unit tier; do
    name="$unit"
    [[ "$unit" == *@.service ]] && name="${unit/@.service/@0.service}"
    printf '%-40s %-10s %s\n' "$name" "$tier" \
      "$("$SYSTEMCTL" show -p Nice -p CPUWeight -p IOWeight --value "$name" 2>/dev/null | tr '\n' ' ')"
  done < <(each_unit)
  for name in php8.3-fpm.service caddy.service vaak-worker-shadow-http.service; do
    printf '%-40s %-10s %s\n' "$name" interactive \
      "$("$SYSTEMCTL" show -p Nice -p CPUWeight -p IOWeight --value "$name" 2>/dev/null | tr '\n' ' ')"
  done
}

case "${1:-status}" in
  apply) cmd_apply ;;
  remove) cmd_remove ;;
  status) cmd_status ;;
  *) echo "usage: $0 status|apply|remove" >&2; exit 2 ;;
esac
