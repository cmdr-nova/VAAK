#!/usr/bin/env bash
# Self-test for deploy/vaak-alert-notify.sh with a fake curl.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
notify="${here}/vaak-alert-notify.sh"
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

mkdir -p "${work}/bin"
cat >"${work}/bin/curl" <<'EOF'
#!/usr/bin/env bash
# Record headers + body; FAKE_CURL_FAIL=1 simulates ntfy being down.
{ printf 'ARGS %s\n' "$*"; cat; printf -- '--\n'; } >>"${FAKE_CURL_LOG}"
[[ "${FAKE_CURL_FAIL:-0}" == 1 ]] && exit 22
exit 0
EOF
chmod +x "${work}/bin/curl"
log="${work}/curl.log"
alerts="${work}/alerts"
env_file="${work}/alert.env"
state_dir="${work}/state"

run() {
  env PATH="${work}/bin:${PATH}" FAKE_CURL_LOG="${log}" \
    VAAK_ALERT_ENV_FILE="${env_file}" VAAK_ALERT_STATE_DIR="${state_dir}" \
    "$@"
}
sends() { [[ -f "${log}" ]] && grep -c '^ARGS ' "${log}" || echo 0; }
expect_sends() {
  local got; got="$(sends)"
  [[ "${got}" == "$1" ]] || { echo "expected $1 sends, got ${got}: $2" >&2; cat "${log}" >&2 2>/dev/null; exit 1; }
}

# Not configured: silent no-op, test mode fails loudly.
echo "WARN disk" >"${alerts}"
run bash "${notify}" warn "${alerts}"
expect_sends 0 "unconfigured warn"
if run bash "${notify}" test 2>/dev/null; then echo "test mode passed without config" >&2; exit 1; fi

# Non-https URL is refused.
echo 'VAAK_ALERT_NTFY_URL=http://ntfy.sh/x' >"${env_file}"
run bash "${notify}" warn "${alerts}" 2>/dev/null
expect_sends 0 "http url"

printf '%s\n' '# comment' "VAAK_ALERT_NTFY_URL='https://ntfy.example/topic-abc'" 'VAAK_ALERT_NTFY_TOKEN="tk_secret"' >"${env_file}"

# First warn sends with the token and the alert body.
printf 'WARN 1200 terminal fan-out failures on live hosts require review\n' >"${alerts}"
run bash "${notify}" warn "${alerts}" >/dev/null
expect_sends 1 "first warn"
grep -q 'https://ntfy.example/topic-abc' "${log}" || { echo "wrong url" >&2; exit 1; }
grep -q 'Authorization: Bearer tk_secret' "${log}" || { echo "token header missing" >&2; exit 1; }
grep -q 'Priority: 4' "${log}" || { echo "warn priority" >&2; exit 1; }
grep -q '^WARN 1200 terminal' "${log}" || { echo "body missing" >&2; exit 1; }

# Same problem with a different count is not resent inside the repeat window.
printf 'WARN 1350 terminal fan-out failures on live hosts require review\n' >"${alerts}"
run bash "${notify}" warn "${alerts}" >/dev/null
expect_sends 1 "same warn, new count"

# A new problem sends at once, crit at top priority.
printf 'CRIT caddy inactive\nWARN 1350 terminal fan-out failures on live hosts require review\n' >"${alerts}"
run bash "${notify}" crit "${alerts}" >/dev/null
expect_sends 2 "new crit"
tail -n 5 "${log}" | grep -q 'CRIT caddy inactive' || { echo "crit body" >&2; exit 1; }
grep -q 'Priority: 5' "${log}" || { echo "crit priority" >&2; exit 1; }

# Unchanged crit resends once the repeat window has passed.
read -r lvl fp ts <"${state_dir}/last-alert"
echo "${lvl} ${fp} $(( ts - 7 * 3600 ))" >"${state_dir}/last-alert"
run bash "${notify}" crit "${alerts}" >/dev/null
expect_sends 3 "repeat after window"

# Recovery sends once, then ok stays quiet.
run bash "${notify}" ok >/dev/null
expect_sends 4 "recovered"
grep -q 'recovered' "${log}" || { echo "recovered title" >&2; exit 1; }
run bash "${notify}" ok >/dev/null
expect_sends 4 "ok after ok"

# A failed send keeps no state, so the next run retries.
echo "WARN redis-server inactive" >"${alerts}"
run env FAKE_CURL_FAIL=1 bash "${notify}" warn "${alerts}" 2>/dev/null
expect_sends 5 "failed send attempt"
run bash "${notify}" warn "${alerts}" >/dev/null
expect_sends 6 "retry after failure"

# Test mode always sends and leaves state alone.
before="$(cat "${state_dir}/last-alert")"
run bash "${notify}" test >/dev/null
expect_sends 7 "test push"
[[ "$(cat "${state_dir}/last-alert")" == "${before}" ]] || { echo "test mode touched state" >&2; exit 1; }

if run bash "${notify}" bogus 2>/dev/null; then echo "bad level accepted" >&2; exit 1; fi
echo "alert_notify_test_ok"
