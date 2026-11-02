#!/usr/bin/env bash
# Tests for deploy/vaak-cpu-tiers.sh against a temp systemd dir and a fake systemctl.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

cat >"$tmp/systemctl" <<'EOF'
#!/usr/bin/env bash
echo "$*" >>"$(dirname "$0")/calls"
case "$1" in
  is-active) [ "$3" = vaak-worker-ranked-warm.service ] ;;
  show) printf '0\n100\n100\n' ;;
  *) exit 0 ;;
esac
EOF
chmod +x "$tmp/systemctl"
export VAAK_CPU_TIERS_SYSTEMD_DIR="$tmp/etc" VAAK_CPU_TIERS_SYSTEMCTL="$tmp/systemctl"

fail() { echo "FAIL: $*" >&2; exit 1; }

# An unrelated drop-in the operator already has must survive apply + remove.
mkdir -p "$tmp/etc/vaak-worker-ranked-warm.service.d"
echo keep >"$tmp/etc/vaak-worker-ranked-warm.service.d/override.conf"

out="$(bash "$here/vaak-cpu-tiers.sh" apply)"
f="$tmp/etc/vaak-worker-ranked-warm.service.d/50-vaak-cpu-tier.conf"
grep -qx 'CPUWeight=20' "$f" || fail "warm CPUWeight"
grep -qx 'Nice=10' "$f" || fail "warm Nice"
g="$tmp/etc/vaak-inbox-spool-drain.service.d/50-vaak-cpu-tier.conf"
grep -qx 'CPUWeight=50' "$g" || fail "near-live CPUWeight"
grep -q 'Nice' "$g" && fail "near-live must not renice"
grep -qx 'CPUWeight=50' "$tmp/etc/ap-bsky-jetstream@.service.d/50-vaak-cpu-tier.conf" || fail "template unit"
grep -qx 'Nice=19' "$tmp/etc/vaak-ml-shadow.service.d/50-vaak-cpu-tier.conf" || fail "bulk Nice"
for u in php8.3-fpm caddy vaak-worker-shadow-http; do
  [ -e "$tmp/etc/$u.service.d" ] && fail "interactive unit $u must stay untouched"
done
grep -qx 'daemon-reload' "$tmp/calls" || fail "daemon-reload not called"
grep -q 'systemctl restart vaak-worker-ranked-warm.service' <<<"$out" || fail "restart hint for active warmer"
grep -q 'restart vaak-worker-thin-warm' <<<"$out" && fail "no restart hint for inactive unit"

# apply is idempotent
bash "$here/vaak-cpu-tiers.sh" apply >/dev/null
[ "$(grep -c CPUWeight "$f")" = 1 ] || fail "apply not idempotent"

st="$(bash "$here/vaak-cpu-tiers.sh" status)"
grep -q "vaak-worker-ranked-warm.service .*warm .*0 100 100" <<<"$st" || fail "status"

bash "$here/vaak-cpu-tiers.sh" remove >/dev/null
[ -e "$f" ] && fail "remove left drop-in"
[ -e "$tmp/etc/vaak-inbox-spool-drain.service.d" ] && fail "remove left empty dir"
[ "$(cat "$tmp/etc/vaak-worker-ranked-warm.service.d/override.conf")" = keep ] || fail "unrelated drop-in touched"

echo "vaak-cpu-tiers tests passed"
