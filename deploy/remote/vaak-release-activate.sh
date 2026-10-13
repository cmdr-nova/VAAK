#!/usr/bin/env bash
# Activate a VAAK release on the production host, or roll back to one already there.
#
# The deploy user owns /srv/mkultra/vaak-releases and swaps only the "current"
# symlink. /srv/mkultra/html/api, /srv/mkultra/html/vaak, and
# /usr/local/bin/vaak-worker must already be symlinks into that tree. This
# script never writes the Jekyll web root, clearance, or backup paths.
set -euo pipefail

ROOT="${VAAK_RELEASE_ROOT:-/srv/mkultra/vaak-releases}"
HTML_API="${VAAK_HTML_API:-/srv/mkultra/html/api}"
HTML_VAAK="${VAAK_HTML_VAAK:-/srv/mkultra/html/vaak}"
WORKER_LINK="${VAAK_WORKER_BIN:-/usr/local/bin/vaak-worker}"
PHP_BIN="${VAAK_PHP:-/usr/bin/php}"
CURL_BIN="${VAAK_CURL:-/usr/bin/curl}"
KEEP="${VAAK_RELEASE_KEEP:-5}"

REQUIRED_UNITS=(
  php8.3-fpm.service
  vaak-redis-queue-worker.service
  vaak-worker-notif.service
  vaak-worker-notif-list.service
  vaak-worker-ranked-warm.service
  vaak-worker-thin-warm.service
  vaak-worker-actor-warm.service
  vaak-worker-ap-actor-warm.service
  vaak-worker-timeline-fanout.service
  vaak-worker-shadow-http.service
)
OPTIONAL_UNITS=(
  vaak-worker-shadow.service
  ap-bsky-jetstream.service
)

PRESERVE_API_FILES=(
  network-status.php
  discord-presence.php
  .gts-config.php
  sl_visitors.json
)

die() {
  echo "vaak-release: $*" >&2
  exit 1
}

systemctl_bin() {
  if [[ -n "${VAAK_SYSTEMCTL:-}" ]]; then
    "${VAAK_SYSTEMCTL}" "$@"
  else
    systemctl "$@"
  fi
}

sudo_restart() {
  local unit="$1"
  if [[ -n "${VAAK_SYSTEMCTL:-}" ]]; then
    "${VAAK_SYSTEMCTL}" restart "$unit"
  else
    sudo -n /usr/bin/systemctl restart "$unit"
  fi
}

unit_installed() {
  local unit="$1" out
  out="$(systemctl_bin list-unit-files --no-legend --type=service "$unit" 2>/dev/null || true)"
  [[ "$out" == "$unit "* || "$out" == "$unit" ]]
}

require_layout() {
  [[ -d "$ROOT/releases" && -d "$ROOT/incoming" ]] || die "missing ${ROOT}/{releases,incoming}; run the one-time setup"
  [[ -L "$ROOT/current" ]] || die "${ROOT}/current is not a symlink"
  case "$ROOT" in
    /srv/mkultra/html|/srv/mkultra/html/*) die "release root must stay outside the web root" ;;
  esac
  [[ "$(readlink "$HTML_API")" == "${ROOT}/current/api" ]] || die "${HTML_API} is not a symlink to ${ROOT}/current/api"
  [[ "$(readlink "$HTML_VAAK")" == "${ROOT}/current/vaak" ]] || die "${HTML_VAAK} is not a symlink to ${ROOT}/current/vaak"
  [[ "$(readlink "$WORKER_LINK")" == "${ROOT}/current/bin/vaak-worker" ]] || die "${WORKER_LINK} is not a symlink to the current worker"
  if [[ "${VAAK_DEPLOY_RELAX_PERMS:-0}" != "1" ]]; then
    local group
    group="$(stat -c %G "$ROOT")"
    [[ "$group" == "www-data" ]] || die "${ROOT} group is ${group}; expected www-data"
    [[ -g "$ROOT" ]] || die "${ROOT} is not setgid, so new files would not stay readable by www-data"
  fi
}

swap_current() {
  local relative="$1"
  [[ "$relative" == releases/* && -d "${ROOT}/${relative}" ]] || die "refusing to swap to ${relative}"
  ln -s "$relative" "${ROOT}/current.next"
  mv -Tf "${ROOT}/current.next" "${ROOT}/current"
}

remember_previous() {
  local relative="$1"
  ln -s "$relative" "${ROOT}/previous.next"
  mv -Tf "${ROOT}/previous.next" "${ROOT}/previous"
}

restart_units() {
  local unit
  for unit in "${REQUIRED_UNITS[@]}"; do
    if ! unit_installed "$unit"; then
      echo "vaak-release: required unit is not installed: ${unit}" >&2
      return 1
    fi
    sudo_restart "$unit" || return 1
  done
  for unit in "${OPTIONAL_UNITS[@]}"; do
    if unit_installed "$unit"; then
      sudo_restart "$unit" || return 1
    else
      echo "vaak-release: skip optional ${unit}"
    fi
  done
}

health_check() {
  local expected actual i payload
  expected="$(<"${ROOT}/current/VERSION")"
  actual="$("$PHP_BIN" -r 'require $argv[1]; echo VAAK_VERSION;' "${ROOT}/current/api/ap-version.php")"
  if [[ "$actual" != "$expected" ]]; then
    echo "vaak-release: live version ${actual} != ${expected}" >&2
    return 1
  fi
  local attempts="${VAAK_HEALTH_ATTEMPTS:-30}" pause="${VAAK_HEALTH_SLEEP:-1}"
  payload=""
  for i in $(seq 1 "$attempts"); do
    if payload="$("$CURL_BIN" -fsS --max-time 3 http://127.0.0.1:8787/healthz 2>/dev/null)"; then
      if [[ "$payload" == *'"ok":true'* || "$payload" == *'"ok": true'* ]]; then
        break
      fi
    fi
    if [[ "$i" -eq "$attempts" ]]; then
      echo "vaak-release: Axum /healthz did not report ok" >&2
      return 1
    fi
    sleep "$pause"
  done
  for unit in "${REQUIRED_UNITS[@]}"; do
    systemctl_bin is-active --quiet "$unit" || {
      echo "vaak-release: ${unit} is not active" >&2
      return 1
    }
  done
}

lint_tree() {
  local dir="$1" file
  while IFS= read -r -d '' file; do
    "$PHP_BIN" -l "$file" >/dev/null
  done < <(find "$dir/api" "$dir/vaak" -name '*.php' -print0)
}

preserve_server_files() {
  local previous="$1" dest="$2" rel
  [[ -d "${previous}/api" ]] || return 0
  for rel in "${PRESERVE_API_FILES[@]}"; do
    if [[ -f "${previous}/api/${rel}" && ! -e "${dest}/api/${rel}" ]]; then
      cp -a "${previous}/api/${rel}" "${dest}/api/${rel}"
      echo "vaak-release: preserved api/${rel}"
    fi
  done
}

assert_safe_tar() {
  local tarball="$1" entry
  while IFS= read -r entry; do
    case "$entry" in
      /*|*../*|../*|*/..|..) die "tarball path is unsafe: ${entry}" ;;
    esac
  done < <(tar -tzf "$tarball")
}

prune_releases() {
  local current previous base dir
  current="$(readlink "${ROOT}/current")"
  previous="$(readlink "${ROOT}/previous" 2>/dev/null || true)"
  mapfile -t dirs < <(find "${ROOT}/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | awk '{print $2}')
  local kept=0
  for dir in "${dirs[@]}"; do
    base="releases/$(basename "$dir")"
    if [[ "$base" == "$current" || "$base" == "$previous" ]]; then
      continue
    fi
    kept=$((kept + 1))
    if (( kept > KEEP )); then
      rm -rf "$dir"
      echo "vaak-release: pruned ${base}"
    fi
  done
}

deploy_tarball() {
  local tarball="$1" tmp sha dest previous relative version
  [[ -f "$tarball" ]] || die "missing tarball ${tarball}"
  assert_safe_tar "$tarball"
  tmp="$(mktemp -d "${ROOT}/incoming/extract.XXXXXX")"
  tar -xzf "$tarball" -C "$tmp"
  [[ -f "${tmp}/REVISION" && -f "${tmp}/VERSION" && -f "${tmp}/bin/vaak-worker" && -f "${tmp}/api/ap-version.php" ]] || die "tarball is not a VAAK release"
  sha="$(<"${tmp}/REVISION")"
  version="$(<"${tmp}/VERSION")"
  [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || die "REVISION is not a git sha"
  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]{2}$ ]] || die "VERSION is not a VAAK counter"
  dest="${ROOT}/releases/${sha}"
  previous="$(readlink "${ROOT}/current")"
  [[ -d "${ROOT}/${previous}" ]] || die "current release ${previous} is missing"
  if [[ -d "$dest" && "$previous" != "releases/${sha}" ]]; then
    rm -rf "$dest"
  fi
  if [[ ! -d "$dest" ]]; then
    mv "$tmp" "$dest"
  else
    rm -rf "$tmp"
  fi
  chmod 0755 "${dest}/bin/vaak-worker"
  if [[ "$previous" != "releases/${sha}" ]]; then
    preserve_server_files "${ROOT}/${previous}" "$dest"
  fi
  lint_tree "$dest"
  local php_version
  php_version="$("$PHP_BIN" -r 'require $argv[1]; echo VAAK_VERSION;' "${dest}/api/ap-version.php")"
  [[ "$php_version" == "$version" ]] || die "ap-version.php is ${php_version}, tarball says ${version}"
  "$PHP_BIN" "${dest}/migrate.php" --api-root="${dest}/api"
  if [[ "$previous" != "releases/${sha}" ]]; then
    swap_current "releases/${sha}"
    remember_previous "$previous"
    if ! restart_units || ! health_check; then
      echo "vaak-release: health failed; restoring ${previous}" >&2
      swap_current "$previous"
      restart_units || true
      health_check || echo "vaak-release: restored release is also unhealthy" >&2
      exit 1
    fi
  else
    restart_units
    health_check || die "already-current release failed health"
  fi
  prune_releases
  echo "deploy_ok version=${version} revision=${sha}"
}

rollback_to() {
  local target="$1" dest="" dir version previous
  [[ -n "$target" ]] || die "rollback needs a sha or version"
  if [[ -d "${ROOT}/releases/${target}" ]]; then
    dest="releases/${target}"
  else
    for dir in "${ROOT}/releases"/*; do
      [[ -f "${dir}/VERSION" ]] || continue
      version="$(<"${dir}/VERSION")"
      if [[ "$version" == "$target" || "v${version}" == "$target" ]]; then
        dest="releases/$(basename "$dir")"
        break
      fi
    done
  fi
  [[ -n "$dest" ]] || die "no installed release matches ${target}"
  previous="$(readlink "${ROOT}/current")"
  [[ "$previous" != "$dest" ]] || { echo "rollback_ok already ${dest}"; return 0; }
  swap_current "$dest"
  if ! restart_units || ! health_check; then
    echo "vaak-release: rollback health failed; restoring ${previous}" >&2
    swap_current "$previous"
    restart_units || true
    health_check || true
    exit 1
  fi
  echo "rollback_ok ${dest}"
}

self_test() {
  local tmp sha_old sha_new blog api_sum
  tmp="$(mktemp -d)"
  sha_old="$(printf 'b%.0s' {1..40})"
  sha_new="$(printf 'c%.0s' {1..40})"
  install -d \
    "${tmp}/root/releases/${sha_old}/api" \
    "${tmp}/root/releases/${sha_old}/vaak" \
    "${tmp}/root/releases/${sha_old}/bin" \
    "${tmp}/root/incoming" \
    "${tmp}/html/clearance" \
    "${tmp}/bin"
  printf 'old\n' > "${tmp}/root/releases/${sha_old}/api/ap-version.php"
  printf '0.8.45\n' > "${tmp}/root/releases/${sha_old}/VERSION"
  printf '%s\n' "$sha_old" > "${tmp}/root/releases/${sha_old}/REVISION"
  printf '#!/bin/sh\n' > "${tmp}/root/releases/${sha_old}/bin/vaak-worker"
  chmod 0755 "${tmp}/root/releases/${sha_old}/bin/vaak-worker"
  printf 'blog\n' > "${tmp}/html/index.html"
  printf 'clearance\n' > "${tmp}/html/clearance/config.local.php"
  ln -s "releases/${sha_old}" "${tmp}/root/current"
  ln -s "${tmp}/root/current/api" "${tmp}/html/api"
  ln -s "${tmp}/root/current/vaak" "${tmp}/html/vaak"
  ln -s "${tmp}/root/current/bin/vaak-worker" "${tmp}/bin/vaak-worker"
  printf 'kept\n' > "${tmp}/root/releases/${sha_old}/api/network-status.php"

  cat > "${tmp}/php" <<'PHP'
#!/bin/sh
if [ "$1" = "-l" ]; then
  exit 0
fi
if [ "$1" = "-r" ]; then
  release="$(dirname "$(dirname "$3")")"
  tr -d '\n' < "${release}/VERSION"
  exit 0
fi
echo migrate_ok
exit 0
PHP
  cat > "${tmp}/curl" <<'CURL'
#!/bin/sh
echo '{"ok":true}'
CURL
  cat > "${tmp}/systemctl" <<'CTL'
#!/bin/sh
printf '%s\n' "$*" >> "${SYSTEMCTL_LOG}"
if [ "$1" = "list-unit-files" ]; then
  echo "$4 enabled enabled"
  exit 0
fi
if [ "$1" = "is-active" ]; then
  exit 0
fi
exit 0
CTL
  chmod 0755 "${tmp}/php" "${tmp}/curl" "${tmp}/systemctl"

  install -d "${tmp}/new/api/bin" "${tmp}/new/vaak" "${tmp}/new/bin"
  cat > "${tmp}/new/api/ap-version.php" <<'PHP'
<?php
const VAAK_VERSION = '0.8.46';
PHP
  printf '<?php echo "ok";\n' > "${tmp}/new/vaak/index.php"
  printf '#!/bin/sh\necho worker\n' > "${tmp}/new/bin/vaak-worker"
  chmod 0755 "${tmp}/new/bin/vaak-worker"
  printf '0.8.46\n' > "${tmp}/new/VERSION"
  printf '%s\n' "$sha_new" > "${tmp}/new/REVISION"
  printf '<?php echo "migrate";\n' > "${tmp}/new/migrate.php"
  tar -C "${tmp}/new" -czf "${tmp}/release.tar.gz" .
  # A poisoned tarball must be rejected before it is extracted over a release.
  tar -C "${tmp}" -czf "${tmp}/bad.tar.gz" html/index.html
  # Rename the entry by rebuilding with an absolute path is hard; plant ".." via a copy.
  install -d "${tmp}/poison"
  printf 'x\n' > "${tmp}/poison/x"
  tar -C "${tmp}/poison" -czf "${tmp}/dotdot.tar.gz" x
  python3 - "${tmp}/dotdot.tar.gz" <<'PY'
import sys, tarfile
path = sys.argv[1]
with tarfile.open(path, "w:gz") as tar:
    import io
    data = b"nope\n"
    info = tarfile.TarInfo("../html/index.html")
    info.size = len(data)
    tar.addfile(info, io.BytesIO(data))
PY

  export VAAK_RELEASE_ROOT="${tmp}/root"
  export VAAK_HTML_API="${tmp}/html/api"
  export VAAK_HTML_VAAK="${tmp}/html/vaak"
  export VAAK_WORKER_BIN="${tmp}/bin/vaak-worker"
  export VAAK_PHP="${tmp}/php"
  export VAAK_CURL="${tmp}/curl"
  export VAAK_SYSTEMCTL="${tmp}/systemctl"
  export SYSTEMCTL_LOG="${tmp}/systemctl.log"
  export VAAK_DEPLOY_RELAX_PERMS=1
  export VAAK_RELEASE_KEEP=1
  export VAAK_HEALTH_ATTEMPTS=2
  export VAAK_HEALTH_SLEEP=0
  : > "${SYSTEMCTL_LOG}"

  if bash "$0" deploy "${tmp}/dotdot.tar.gz"; then
    echo "unsafe tarball was accepted" >&2
    exit 1
  fi
  [[ "$(cat "${tmp}/html/index.html")" == "blog" ]]

  bash "$0" deploy "${tmp}/release.tar.gz"
  [[ "$(readlink "${tmp}/root/current")" == "releases/${sha_new}" ]]
  [[ -f "${tmp}/root/releases/${sha_new}/api/network-status.php" ]]
  [[ "$(cat "${tmp}/html/index.html")" == "blog" ]]
  [[ "$(cat "${tmp}/html/clearance/config.local.php")" == "clearance" ]]
  grep -q "restart php8.3-fpm.service" "${SYSTEMCTL_LOG}"
  grep -q "restart vaak-worker-shadow-http.service" "${SYSTEMCTL_LOG}"

  # Health failure rolls the symlink back and does not leave the new release live.
  cat > "${tmp}/curl" <<'CURL'
#!/bin/sh
echo '{"ok":false}'
exit 1
CURL
  chmod 0755 "${tmp}/curl"
  sha_fail="$(printf 'd%.0s' {1..40})"
  install -d "${tmp}/fail/api" "${tmp}/fail/vaak" "${tmp}/fail/bin"
  cp "${tmp}/new/api/ap-version.php" "${tmp}/fail/api/ap-version.php"
  cp "${tmp}/new/vaak/index.php" "${tmp}/fail/vaak/index.php"
  cp "${tmp}/new/bin/vaak-worker" "${tmp}/fail/bin/vaak-worker"
  cp "${tmp}/new/migrate.php" "${tmp}/fail/migrate.php"
  printf '0.8.47\n' > "${tmp}/fail/VERSION"
  printf '%s\n' "$sha_fail" > "${tmp}/fail/REVISION"
  # The stub php always prints 0.8.46, so force VERSION to match that print.
  printf '0.8.46\n' > "${tmp}/fail/VERSION"
  tar -C "${tmp}/fail" -czf "${tmp}/fail.tar.gz" .
  if bash "$0" deploy "${tmp}/fail.tar.gz"; then
    echo "failed health was treated as success" >&2
    exit 1
  fi
  [[ "$(readlink "${tmp}/root/current")" == "releases/${sha_new}" ]]
  [[ "$(cat "${tmp}/html/index.html")" == "blog" ]]

  cat > "${tmp}/curl" <<'CURL'
#!/bin/sh
echo '{"ok":true}'
CURL
  chmod 0755 "${tmp}/curl"
  bash "$0" rollback "$sha_old"
  [[ "$(readlink "${tmp}/root/current")" == "releases/${sha_old}" ]]
  api_sum="$(sha256sum "${tmp}/html/index.html" | awk '{print $1}')"
  [[ -n "$api_sum" ]]
  echo "activate_self_test_ok"
  rm -rf "$tmp"
}

if [[ "${1:-}" == "--self-test" ]]; then
  self_test
  exit 0
fi

[[ $# -ge 1 ]] || die "usage: vaak-release-activate.sh deploy TARBALL | rollback SHA_OR_VERSION"
exec 9>"${ROOT}/.deploy.lock"
flock -n 9 || die "another deploy holds ${ROOT}/.deploy.lock"
require_layout
case "$1" in
  deploy) deploy_tarball "${2:-}" ;;
  rollback) rollback_to "${2:-}" ;;
  *) die "unknown command $1" ;;
esac
