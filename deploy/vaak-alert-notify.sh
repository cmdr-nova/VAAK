#!/usr/bin/env bash
# Push integrity alerts to a phone through ntfy (https://ntfy.sh or a
# self-hosted ntfy server).
#
#   vaak-alert-notify.sh <ok|warn|crit> <file with one alert per line>
#   vaak-alert-notify.sh test        (sends one test push, ignores state)
#
# Config comes from VAAK_ALERT_ENV_FILE (default /etc/mkultra/vaak-alert.env,
# root 0600). The file is parsed, never sourced. Keys:
#   VAAK_ALERT_NTFY_URL=https://ntfy.sh/<long random topic>
#   VAAK_ALERT_NTFY_TOKEN=tk_...        (optional, for a protected topic)
#   VAAK_ALERT_REPEAT_HOURS=6           (optional, resend an unchanged alert)
# No URL means alerts stay in the journal only, exactly as before.
#
# Spam guard: the same set of problems (numbers ignored, so a growing queue
# count is still "the same" problem) is sent once, then again only every
# VAAK_ALERT_REPEAT_HOURS. A change in the set sends at once. Going back to ok
# after an alert sends one "recovered" message. A failed send is retried on the
# next run because the state is only written after ntfy accepts it.
set -uo pipefail

level="${1:-}"
alerts_file="${2:-/dev/null}"
ENV_FILE="${VAAK_ALERT_ENV_FILE:-/etc/mkultra/vaak-alert.env}"
STATE_DIR="${VAAK_ALERT_STATE_DIR:-/var/lib/mkultra/vaak-integrity}"
STATE="${STATE_DIR}/last-alert"
HOST_LABEL="${VAAK_ALERT_HOST_LABEL:-VAAK}"

case "${level}" in ok|warn|crit|test) ;; *) echo "usage: $0 <ok|warn|crit|test> [alerts file]" >&2; exit 64 ;; esac

conf() {
  [[ -r "${ENV_FILE}" ]] || return 0
  # Last KEY=value wins; surrounding quotes are stripped.
  sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "${ENV_FILE}" | tail -1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

url="$(conf VAAK_ALERT_NTFY_URL)"
token="$(conf VAAK_ALERT_NTFY_TOKEN)"
repeat_hours="$(conf VAAK_ALERT_REPEAT_HOURS)"
[[ "${repeat_hours}" =~ ^[0-9]+$ ]] && (( repeat_hours >= 1 )) || repeat_hours=6

if [[ -z "${url}" ]]; then
  [[ "${level}" == test ]] && { echo "alert_not_configured (no VAAK_ALERT_NTFY_URL in ${ENV_FILE})" >&2; exit 1; }
  exit 0
fi
if [[ ! "${url}" =~ ^https://[^[:space:]]+$ ]]; then
  echo "alert_notify_bad_url (must be https://)" >&2
  [[ "${level}" == test ]] && exit 1
  exit 0
fi

now="$(date +%s)"
prev_level="ok"; prev_fp=""; prev_ts=0
if [[ -r "${STATE}" ]]; then
  read -r prev_level prev_fp prev_ts <"${STATE}" || true
  [[ "${prev_ts}" =~ ^[0-9]+$ ]] || prev_ts=0
fi

if [[ "${level}" == test ]]; then
  title="${HOST_LABEL} test alert"
  body="If this reached your phone, integrity alerts are wired up."
  priority=3
  tags="test_tube"
elif [[ "${level}" == ok ]]; then
  [[ "${prev_level}" == ok ]] && exit 0
  title="${HOST_LABEL} recovered"
  body="All integrity checks pass again."
  priority=3
  tags="white_check_mark"
  fp="-"
else
  body="$(grep -v '^[[:space:]]*$' "${alerts_file}" 2>/dev/null | head -20)"
  [[ -n "${body}" ]] || body="integrity check reported ${level} without details"
  fp="$(printf '%s\n%s' "${level}" "${body}" | sed 's/[0-9]\+/N/g' | sort | sha256sum | cut -c1-16)"
  if [[ "${fp}" == "${prev_fp}" ]] && (( now - prev_ts < repeat_hours * 3600 )); then
    exit 0
  fi
  if [[ "${level}" == crit ]]; then
    title="${HOST_LABEL} CRITICAL"; priority=5; tags="rotating_light"
  else
    title="${HOST_LABEL} warning"; priority=4; tags="warning"
  fi
fi

args=(-fsS --max-time 15 --retry 2 -o /dev/null
  -H "Title: ${title}" -H "Priority: ${priority}" -H "Tags: ${tags}"
  --data-binary @-)
[[ -n "${token}" ]] && args+=(-H "Authorization: Bearer ${token}")

if printf '%s\n' "${body}" | curl "${args[@]}" "${url}"; then
  if [[ "${level}" == test ]]; then echo "alert_sent test"; exit 0; fi
  mkdir -p "${STATE_DIR}" 2>/dev/null || true
  tmp="${STATE}.tmp.$$"
  if printf '%s %s %s\n' "${level}" "${fp}" "${now}" >"${tmp}" 2>/dev/null; then
    mv -f "${tmp}" "${STATE}"
  fi
  echo "alert_sent ${level}"
else
  logger -t vaak-integrity -p user.warning -- "ntfy alert send failed" 2>/dev/null || true
  echo "alert_send_failed ${level}" >&2
  [[ "${level}" == test ]] && exit 1
fi
exit 0
