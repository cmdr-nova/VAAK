#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${VAAK_DB_NAME:-novalandia}"
BACKUP_DIR="${VAAK_BACKUP_DIR:-/var/backups/mkultra/vaak}"
DRILL_STAMP="${VAAK_DRILL_STATE_DIR:-/var/lib/mkultra/vaak-restore-drill}/last-ok"
DRILL_MAX_DAYS="${VAAK_DRILL_MAX_AGE_DAYS:-40}"
LOG_TAG="vaak-integrity"
NOTIFY="${VAAK_ALERT_NOTIFY:-/usr/local/sbin/vaak-alert-notify.sh}"
WARN=0
CRIT=0
ALERTS="$(mktemp)"
trap 'rm -f "${ALERTS}"' EXIT

warn() { WARN=1; logger -t "${LOG_TAG}" -p user.warning -- "$*" 2>/dev/null || true; echo "WARN $*"; echo "WARN $*" >>"${ALERTS}"; }
crit() { CRIT=1; logger -t "${LOG_TAG}" -p user.err -- "$*" 2>/dev/null || true; echo "CRIT $*"; echo "CRIT $*" >>"${ALERTS}"; }

# Phone push (deploy/vaak-alert-notify.sh). Missing notifier or config is fine.
notify() { [[ -x "${NOTIFY}" ]] && "${NOTIFY}" "$1" "${ALERTS}" || true; }

if ! systemctl is-active --quiet postgresql; then crit "postgresql inactive"; fi
if ! systemctl is-active --quiet php8.3-fpm; then crit "php8.3-fpm inactive"; fi
if ! systemctl is-active --quiet redis-server; then warn "redis-server inactive (optional cache/queue accelerator)"; fi
if ! systemctl is-active --quiet caddy; then crit "caddy inactive"; fi

# Long-running VAAK units: only units that are enabled on this box are checked,
# so an optional unit that was never installed stays quiet.
for unit in vaak-worker-shadow-http vaak-worker-timeline-fanout vaak-worker-ranked-warm \
  vaak-worker-notif vaak-worker-notif-list vaak-redis-queue-worker vaak-nostr-relay \
  ap-bsky-jetstream@0 ap-bsky-jetstream@1; do
  systemctl is-enabled --quiet "${unit}" 2>/dev/null || continue
  if ! systemctl is-active --quiet "${unit}"; then
    if [[ "${unit}" == vaak-worker-shadow-http ]]; then crit "${unit} inactive"; else warn "${unit} inactive"; fi
  fi
done

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
rejected_hosts_warn="${VAAK_INTEGRITY_REJECTED_HOSTS_WARN:-25}"
if [[ ! "${rejected_hosts_warn}" =~ ^[0-9]+$ ]] || (( rejected_hosts_warn < 1 )); then rejected_hosts_warn=25; fi
if [[ ! "${dead_days}" =~ ^[0-9]+$ ]] || (( dead_days < 1 || dead_days > 90 )); then dead_days=7; fi
if [[ ! "${dead_failures}" =~ ^[0-9]+$ ]] || (( dead_failures < 1 || dead_failures > 100000 )); then dead_failures=20; fi
health_table="$(runuser -u postgres -- psql -d "${DB_NAME}" -At -c "SELECT CASE WHEN to_regclass('public.ap_fanout_host_health') IS NULL THEN 0 ELSE 1 END" 2>/dev/null || echo 0)"
cutoff="$(date -u -d "${dead_days} days ago" +%Y-%m-%dT%H:%M:%S+00:00 2>/dev/null || true)"
# Host text matches ap_fanout_host_normalize for ordinary https inboxes:
# lower(split_part(split_part(regexp_replace(inbox_url, '^https://', ''), '/', 1), ':', 1))
if [[ "${health_table}" == "1" && "${cutoff}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00$ ]]; then
  # Each failed row's host is one of:
  #   suspended: meets the dead-host rule, deliveries are already skipped.
  #   dying:     a terminal-failure streak of at least VAAK_FANOUT_DEAD_FAILURES
  #              with no success since it began, still inside the
  #              VAAK_FANOUT_DEAD_DAYS wait. It will be suspended on its own.
  #   rejected:  the row's last error is a live rejection (HTTP 401, 403,
  #              429 or 500): the host answered and refused. That is the
  #              remote side's problem (a stale key record, an overloaded
  #              server), so it warns only when it spans many hosts at once,
  #              which would point at VAAK's own signing.
  #   gone:      the inbox answered 404 or 410 (a deleted account). Nothing
  #              to fix on either side, so it never warns.
  #              A "circuit is open" row on a host whose last live answer was
  #              one of those rejections counts as rejected too: the circuit
  #              hid the host's real answer.
  #   healthy:   everything else. Only these count toward the 1000 warning.
  q="$(runuser -u postgres -- psql -d "${DB_NAME}" -At -F '|' -c "WITH f AS (SELECT lower(split_part(split_part(regexp_replace(q.inbox_url, '^https://', ''), '/', 1), ':', 1)) AS host, coalesce(q.last_error, '') AS err FROM ap_fanout_delivery_queue q WHERE q.status='failed'), c AS (SELECT f.host, CASE WHEN h.consecutive_terminal_failures >= ${dead_failures} AND h.streak_started_at IS NOT NULL AND h.streak_started_at <= '${cutoff}' AND (h.last_success_at IS NULL OR h.last_success_at <= '${cutoff}') AND (h.last_live_at IS NULL OR h.last_live_at <= '${cutoff}') THEN 'suspended' WHEN h.consecutive_terminal_failures >= ${dead_failures} AND h.streak_started_at IS NOT NULL AND (h.last_success_at IS NULL OR h.last_success_at <= h.streak_started_at) THEN 'dying' WHEN f.err ~ '^[[:space:]]*HTTP[[:space:]]+(404|410)([^0-9]|\$)' THEN 'gone' WHEN f.err ~ '(^|[^A-Za-z])HTTP[[:space:]]+(401|403|429|500)([^0-9]|\$)' THEN 'rejected' WHEN f.err ILIKE '%circuit is open%' AND h.last_live_status IN (401,403,429,500) THEN 'rejected' ELSE 'healthy' END AS k FROM f LEFT JOIN ap_fanout_host_health h ON h.host = f.host AND h.host <> '*') SELECT 'failed_fanout_' || k, count(*) FROM c GROUP BY k UNION ALL SELECT 'dying_fanout_hosts', count(DISTINCT host) FROM c WHERE k = 'dying' UNION ALL SELECT 'rejected_fanout_hosts', count(DISTINCT host) FROM c WHERE k = 'rejected' UNION ALL SELECT 'pending_fanout',count(*) FROM ap_fanout_delivery_queue WHERE status='pending' UNION ALL SELECT 'pending_action',count(*) FROM ap_action_queue WHERE status='pending' UNION ALL SELECT 'failed_action',count(*) FROM ap_action_queue WHERE status='failed';")"
else
  q="$(runuser -u postgres -- psql -d "${DB_NAME}" -At -F '|' -c "SELECT 'pending_fanout',count(*) FROM ap_fanout_delivery_queue WHERE status='pending' UNION ALL SELECT 'failed_fanout',count(*) FROM ap_fanout_delivery_queue WHERE status='failed' UNION ALL SELECT 'pending_action',count(*) FROM ap_action_queue WHERE status='pending' UNION ALL SELECT 'failed_action',count(*) FROM ap_action_queue WHERE status='failed';")"
fi
while IFS='|' read -r kind count; do
  [[ -z "${kind:-}" ]] && continue
  if [[ "${kind}" == failed_fanout_healthy && "${count}" -gt 1000 ]]; then warn "${count} terminal fan-out failures on live hosts require review"; fi
  if [[ "${kind}" == failed_fanout && "${count}" -gt 1000 ]]; then warn "${count} terminal fan-out failures require review"; fi
  if [[ "${kind}" == failed_fanout_suspended ]]; then echo "suspended_fanout ${count}"; fi
  if [[ "${kind}" == failed_fanout_dying ]]; then echo "dying_fanout ${count}"; fi
  if [[ "${kind}" == dying_fanout_hosts && "${count}" -gt 0 ]]; then echo "dying_fanout_hosts ${count}"; fi
  if [[ "${kind}" == failed_fanout_rejected ]]; then echo "rejected_fanout ${count}"; fi
  if [[ "${kind}" == failed_fanout_gone ]]; then echo "gone_fanout ${count}"; fi
  if [[ "${kind}" == rejected_fanout_hosts && "${count}" -gt 0 ]]; then
    echo "rejected_fanout_hosts ${count}"
    if (( count >= rejected_hosts_warn )); then warn "fan-out refused (HTTP 401/403/429/500) by ${count} live hosts; check VAAK's signing"; fi
  fi
  if [[ "${kind}" == pending_fanout && "${count}" -gt 500 ]]; then crit "${count} pending fan-out deliveries"; fi
  if [[ "${kind}" == failed_action && "${count}" -gt 25 ]]; then warn "${count} failed user actions require review"; fi
done <<<"${q}"

# Publish queue stall: every new post (Fediverse fan-out and the Bluesky mirror)
# goes through ap_publish_delivery_queue, drained every minute. A post that has
# never been attempted after VAAK_PUBLISH_STALL_MINUTES means the worker is not
# running or a hung run holds its lock (2026-11-05: two days of posts stuck).
stall_min="${VAAK_PUBLISH_STALL_MINUTES:-15}"
if [[ ! "${stall_min}" =~ ^[0-9]+$ ]] || (( stall_min < 5 || stall_min > 1440 )); then stall_min=15; fi
stall_cutoff="$(date -u -d "${stall_min} minutes ago" +%Y-%m-%dT%H:%M:%S+00:00 2>/dev/null || true)"
if [[ "${stall_cutoff}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00$ ]]; then
  stalled="$(runuser -u postgres -- psql -d "${DB_NAME}" -At -F '|' -c "SELECT count(*), coalesce(min(created_at),'') FROM ap_publish_delivery_queue WHERE (status='pending' AND attempts=0 AND created_at < '${stall_cutoff}') OR (status='processing' AND claimed_at < '${stall_cutoff}');" 2>/dev/null || true)"
  stalled_count="${stalled%%|*}"
  if [[ "${stalled_count}" =~ ^[0-9]+$ ]] && (( stalled_count > 0 )); then
    warn "${stalled_count} posts stuck in the publish queue over ${stall_min} min (oldest ${stalled#*|}); Bluesky mirror and Fediverse delivery stalled"
  fi
fi

if ! df -P / | awk 'NR==2 {gsub(/%/,"",$5); exit ($5 >= 85 ? 1 : 0)}'; then warn "root filesystem is at or above 85%"; fi

if (( CRIT > 0 )); then notify crit; exit 2; fi
if (( WARN > 0 )); then notify warn; exit 1; fi
notify ok
echo "integrity_ok"
