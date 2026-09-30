#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${VAAK_DB_NAME:-novalandia}"
BACKUP_DIR="${VAAK_BACKUP_DIR:-/var/backups/mkultra/vaak}"
LOG_TAG="vaak-integrity"
WARN=0
CRIT=0

warn() { WARN=1; logger -t "${LOG_TAG}" -p user.warning -- "$*" 2>/dev/null || true; echo "WARN $*"; }
crit() { CRIT=1; logger -t "${LOG_TAG}" -p user.err -- "$*" 2>/dev/null || true; echo "CRIT $*"; }

if ! systemctl is-active --quiet postgresql; then crit "postgresql inactive"; fi
if ! systemctl is-active --quiet php8.3-fpm; then crit "php8.3-fpm inactive"; fi
if ! systemctl is-active --quiet redis-server; then warn "redis-server inactive (optional cache/queue accelerator)"; fi

if [[ ! -d "${BACKUP_DIR}" ]]; then
  crit "backup directory missing: ${BACKUP_DIR}"
else
  latest="$(find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'novalandia-*.dump' -printf '%T@ %p\n' 2>/dev/null | sort -nr | head -1 || true)"
  if [[ -z "${latest}" ]]; then
    crit "no PostgreSQL backup found"
  else
    latest_ts="${latest%% *}"
    now_ts="$(date +%s)"
    age=$(( now_ts - ${latest_ts%.*} ))
    (( age > 36 * 3600 )) && crit "latest PostgreSQL backup is older than 36h"
  fi
fi

q="$(runuser -u postgres -- psql -d "${DB_NAME}" -At -F '|' -c "SELECT 'pending_fanout',count(*) FROM ap_fanout_delivery_queue WHERE status='pending' UNION ALL SELECT 'failed_fanout',count(*) FROM ap_fanout_delivery_queue WHERE status='failed' UNION ALL SELECT 'pending_action',count(*) FROM ap_action_queue WHERE status='pending' UNION ALL SELECT 'failed_action',count(*) FROM ap_action_queue WHERE status='failed';")"
while IFS='|' read -r kind count; do
  [[ -z "${kind:-}" ]] && continue
  if [[ "${kind}" == failed_fanout && "${count}" -gt 1000 ]]; then warn "${count} terminal fan-out failures require review"; fi
  if [[ "${kind}" == pending_fanout && "${count}" -gt 500 ]]; then crit "${count} pending fan-out deliveries"; fi
  if [[ "${kind}" == failed_action && "${count}" -gt 25 ]]; then warn "${count} failed user actions require review"; fi
done <<<"${q}"

if ! df -P / | awk 'NR==2 {gsub(/%/,"",$5); exit ($5 >= 85 ? 1 : 0)}'; then warn "root filesystem is at or above 85%"; fi

if (( CRIT > 0 )); then exit 2; fi
if (( WARN > 0 )); then exit 1; fi
echo "integrity_ok"
