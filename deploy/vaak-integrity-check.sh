#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${VAAK_DB_NAME:-novalandia}"
BACKUP_DIR="${VAAK_BACKUP_DIR:-/var/backups/mkultra/vaak}"
DRILL_STAMP="${VAAK_DRILL_STATE_DIR:-/var/lib/mkultra/vaak-restore-drill}/last-ok"
DRILL_MAX_DAYS="${VAAK_DRILL_MAX_AGE_DAYS:-40}"
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

# vaak-backup-restore-drill.sh writes "<epoch> <dump>" after a good restore.
if [[ ! -f "${DRILL_STAMP}" ]]; then
  warn "no successful backup restore drill recorded (${DRILL_STAMP})"
else
  drill_ts="$(cut -d' ' -f1 "${DRILL_STAMP}" 2>/dev/null || true)"
  if [[ ! "${drill_ts}" =~ ^[0-9]+$ ]]; then
    warn "unreadable restore drill stamp ${DRILL_STAMP}"
  elif (( $(date +%s) - drill_ts > DRILL_MAX_DAYS * 86400 )); then
    warn "last successful backup restore drill is older than ${DRILL_MAX_DAYS} days"
  fi
fi

# Dead-host suspension uses the same knobs as api/ap-fanout-host.php.
# A missing health table keeps the previous total, so this check still runs
# before the migration has been applied.
dead_days="${VAAK_FANOUT_DEAD_DAYS:-7}"
dead_failures="${VAAK_FANOUT_DEAD_FAILURES:-20}"
if [[ ! "${dead_days}" =~ ^[0-9]+$ ]] || (( dead_days < 1 || dead_days > 90 )); then dead_days=7; fi
if [[ ! "${dead_failures}" =~ ^[0-9]+$ ]] || (( dead_failures < 1 || dead_failures > 100000 )); then dead_failures=20; fi
health_table="$(runuser -u postgres -- psql -d "${DB_NAME}" -At -c "SELECT CASE WHEN to_regclass('public.ap_fanout_host_health') IS NULL THEN 0 ELSE 1 END" 2>/dev/null || echo 0)"
cutoff="$(date -u -d "${dead_days} days ago" +%Y-%m-%dT%H:%M:%S+00:00 2>/dev/null || true)"
# Host text matches ap_fanout_host_normalize for ordinary https inboxes:
# lower(split_part(split_part(regexp_replace(inbox_url, '^https://', ''), '/', 1), ':', 1))
if [[ "${health_table}" == "1" && "${cutoff}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00$ ]]; then
  q="$(runuser -u postgres -- psql -d "${DB_NAME}" -At -F '|' -c "SELECT 'pending_fanout',count(*) FROM ap_fanout_delivery_queue WHERE status='pending' UNION ALL SELECT 'failed_fanout_healthy',count(*) FROM ap_fanout_delivery_queue q WHERE q.status='failed' AND NOT EXISTS (SELECT 1 FROM ap_fanout_host_health h WHERE h.host = lower(split_part(split_part(regexp_replace(q.inbox_url, '^https://', ''), '/', 1), ':', 1)) AND h.host <> '*' AND h.consecutive_terminal_failures >= ${dead_failures} AND h.streak_started_at IS NOT NULL AND h.streak_started_at <= '${cutoff}' AND (h.last_success_at IS NULL OR h.last_success_at <= '${cutoff}') AND (h.last_live_at IS NULL OR h.last_live_at <= '${cutoff}')) UNION ALL SELECT 'failed_fanout_suspended',count(*) FROM ap_fanout_delivery_queue q WHERE q.status='failed' AND EXISTS (SELECT 1 FROM ap_fanout_host_health h WHERE h.host = lower(split_part(split_part(regexp_replace(q.inbox_url, '^https://', ''), '/', 1), ':', 1)) AND h.host <> '*' AND h.consecutive_terminal_failures >= ${dead_failures} AND h.streak_started_at IS NOT NULL AND h.streak_started_at <= '${cutoff}' AND (h.last_success_at IS NULL OR h.last_success_at <= '${cutoff}') AND (h.last_live_at IS NULL OR h.last_live_at <= '${cutoff}')) UNION ALL SELECT 'pending_action',count(*) FROM ap_action_queue WHERE status='pending' UNION ALL SELECT 'failed_action',count(*) FROM ap_action_queue WHERE status='failed';")"
else
  q="$(runuser -u postgres -- psql -d "${DB_NAME}" -At -F '|' -c "SELECT 'pending_fanout',count(*) FROM ap_fanout_delivery_queue WHERE status='pending' UNION ALL SELECT 'failed_fanout',count(*) FROM ap_fanout_delivery_queue WHERE status='failed' UNION ALL SELECT 'pending_action',count(*) FROM ap_action_queue WHERE status='pending' UNION ALL SELECT 'failed_action',count(*) FROM ap_action_queue WHERE status='failed';")"
fi
while IFS='|' read -r kind count; do
  [[ -z "${kind:-}" ]] && continue
  if [[ "${kind}" == failed_fanout_healthy && "${count}" -gt 1000 ]]; then warn "${count} terminal fan-out failures on live hosts require review"; fi
  if [[ "${kind}" == failed_fanout && "${count}" -gt 1000 ]]; then warn "${count} terminal fan-out failures require review"; fi
  if [[ "${kind}" == failed_fanout_suspended ]]; then echo "suspended_fanout ${count}"; fi
  if [[ "${kind}" == pending_fanout && "${count}" -gt 500 ]]; then crit "${count} pending fan-out deliveries"; fi
  if [[ "${kind}" == failed_action && "${count}" -gt 25 ]]; then warn "${count} failed user actions require review"; fi
done <<<"${q}"

if ! df -P / | awk 'NR==2 {gsub(/%/,"",$5); exit ($5 >= 85 ? 1 : 0)}'; then warn "root filesystem is at or above 85%"; fi

if (( CRIT > 0 )); then exit 2; fi
if (( WARN > 0 )); then exit 1; fi
echo "integrity_ok"
