#!/usr/bin/env bash
# Activate a VAAK release on the production host, or roll one already there back.
#
# /srv/mkultra/html/api and /srv/mkultra/html/vaak stay real directories. The
# blog publisher's rsync --delete excludes those paths only when they are
# directories, and would remove them if they were symlinks. Releases live
# outside the web root. Activation rsyncs a release into the real directories
# with --delay-updates, then restarts units.
#
# Four untracked live files under api/ are canonical on the server. Deploy and
# rollback both exclude them, so they are neither replaced nor deleted.
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

# Live files the release tarball does not own. update_site.sh no longer copies
# the first two; the copies already on the server are canonical.
PRESERVE_API_FILES=(
  network-status.php
  discord-presence.php
  .gts-config.php
  sl_visitors.json
)

JETSTREAM_RELEASE_FILES=(
  api/deploy/ap-bsky-jetstream.py
  api/deploy/ap-bsky-jetstream-requirements.txt
  api/deploy/wafrn-friend-hosts.txt
)

# Production runs the template instances. There is no ap-bsky-jetstream.service.
JETSTREAM_UNITS=(
  ap-bsky-jetstream@0.service
  ap-bsky-jetstream@1.service
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

unit_enabled() {
  systemctl_bin is-enabled --quiet "$1" 2>/dev/null
}

require_real_dir() {
  local path="$1"
  [[ -d "$path" && ! -L "$path" ]] || die "${path} must stay a real directory, not a symlink"
}

require_layout() {
  [[ -d "$ROOT/releases" && -d "$ROOT/incoming" && -d "$ROOT/bin" ]] || die "missing ${ROOT}/{releases,incoming,bin}; run the one-time setup"
  [[ -f "$ROOT/live-revision" ]] || die "missing ${ROOT}/live-revision"
  case "$ROOT" in
    /srv/mkultra/html|/srv/mkultra/html/*) die "release root must stay outside the web root" ;;
  esac
  require_real_dir "$HTML_API"
  require_real_dir "$HTML_VAAK"
  [[ -L "$WORKER_LINK" ]] || die "${WORKER_LINK} must be a symlink to ${ROOT}/bin/vaak-worker"
  [[ "$(readlink "$WORKER_LINK")" == "${ROOT}/bin/vaak-worker" ]] || die "${WORKER_LINK} does not point at ${ROOT}/bin/vaak-worker"
  local live
  live="$(<"$ROOT/live-revision")"
  [[ "$live" != */* && -d "${ROOT}/releases/${live}" ]] || die "live revision ${live} is not an installed release"
  if [[ "${VAAK_DEPLOY_RELAX_PERMS:-0}" != "1" ]]; then
    local group
    group="$(stat -c %G "$ROOT")"
    [[ "$group" == "www-data" ]] || die "${ROOT} group is ${group}; expected www-data"
    [[ -g "$ROOT" ]] || die "${ROOT} is not setgid, so new files would not stay readable by www-data"
    [[ -g "$HTML_API" && -g "$HTML_VAAK" ]] || die "api and vaak directories must be setgid so vaak-deploy can replace files"
  fi
}

file_sha() {
  if [[ -f "$1" ]]; then
    sha256sum "$1" | awk '{print $1}'
  else
    echo missing
  fi
}

jetstream_differs() {
  local old="$1" new="$2" rel
  for rel in "${JETSTREAM_RELEASE_FILES[@]}"; do
    [[ "$(file_sha "${old}/${rel}")" == "$(file_sha "${new}/${rel}")" ]] || return 0
  done
  return 1
}

shadow_differs() {
  local old="$1" new="$2"
  [[ "$(file_sha "${old}/bin/vaak-worker")" != "$(file_sha "${new}/bin/vaak-worker")" ]]
}

want_optional() {
  local which="$1" old="$2" new="$3" flag
  case "$which" in
    jetstream) flag="${VAAK_RESTART_JETSTREAM:-auto}" ;;
    shadow) flag="${VAAK_RESTART_SHADOW:-auto}" ;;
    *) die "unknown optional unit ${which}" ;;
  esac
  case "$flag" in
    1|true|yes) return 0 ;;
    0|false|no) return 1 ;;
    auto)
      [[ -d "$old" ]] || return 0
      if [[ "$which" == jetstream ]]; then
        jetstream_differs "$old" "$new"
      else
        shadow_differs "$old" "$new"
      fi
      ;;
    *) die "bad restart flag for ${which}: ${flag}" ;;
  esac
}

restart_if_installed() {
  local unit="$1"
  if unit_installed "$unit"; then
    sudo_restart "$unit" || return 1
  else
    echo "vaak-release: skip optional ${unit} (not installed)"
  fi
}

restart_units() {
  local old="$1" new="$2" unit
  for unit in "${REQUIRED_UNITS[@]}"; do
    if ! unit_installed "$unit"; then
      echo "vaak-release: required unit is not installed: ${unit}" >&2
      return 1
    fi
    sudo_restart "$unit" || return 1
  done
  if ! unit_enabled vaak-worker-shadow.service; then
    echo "vaak-release: skip vaak-worker-shadow.service (unit is not enabled)"
  elif want_optional shadow "$old" "$new"; then
    restart_if_installed vaak-worker-shadow.service || return 1
  else
    echo "vaak-release: skip vaak-worker-shadow.service (diff does not touch the shadow worker)"
  fi
  if want_optional jetstream "$old" "$new"; then
    for unit in "${JETSTREAM_UNITS[@]}"; do
      restart_if_installed "$unit" || return 1
    done
  else
    for unit in "${JETSTREAM_UNITS[@]}"; do
      echo "vaak-release: skip ${unit} (diff does not touch Jetstream)"
    done
  fi
}

health_check() {
  local release="$1" expected actual i payload
  expected="$(<"${release}/VERSION")"
  actual="$("$PHP_BIN" -r 'require $argv[1]; echo VAAK_VERSION;' "${HTML_API}/ap-version.php")"
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

assert_safe_tar() {
  local tarball="$1" entry
  while IFS= read -r entry; do
    case "$entry" in
      /*|*../*|../*|*/..|..) die "tarball path is unsafe: ${entry}" ;;
    esac
  done < <(tar -tzf "$tarball")
}

sync_into() {
  local src="$1" dest="$2"
  shift 2
  require_real_dir "$dest"
  [[ -d "$src" && ! -L "$src" ]] || die "release source ${src} is not a real directory"
  # --checksum so a same-size edit is not skipped when mtimes match.
  # --delay-updates renames each new file into place at the end of the transfer.
  rsync -a --checksum --delete-delay --delay-updates "$@" "${src}/" "${dest}/"
}

preserve_excludes() {
  local rel
  for rel in "${PRESERVE_API_FILES[@]}"; do
    printf '%s\n' "--exclude" "/${rel}"
  done
}

publish_release() {
  local release="$1"
  local -a excludes=()
  mapfile -t excludes < <(preserve_excludes)
  sync_into "${release}/api" "$HTML_API" "${excludes[@]}"
  sync_into "${release}/vaak" "$HTML_VAAK"
  install -m 0755 "${release}/bin/vaak-worker" "${ROOT}/bin/vaak-worker.next"
  mv -f "${ROOT}/bin/vaak-worker.next" "${ROOT}/bin/vaak-worker"
}

note_live() {
  local previous="$1" sha="$2"
  if [[ "$previous" != "$sha" ]]; then
    printf '%s\n' "$previous" > "${ROOT}/previous-revision"
  fi
  printf '%s\n' "$sha" > "${ROOT}/live-revision"
}

prune_releases() {
  local live prev base dir kept=0
  live="$(<"${ROOT}/live-revision")"
  prev=""
  [[ -f "${ROOT}/previous-revision" ]] && prev="$(<"${ROOT}/previous-revision")"
  mapfile -t dirs < <(find "${ROOT}/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | awk '{print $2}')
  for dir in "${dirs[@]}"; do
    base="$(basename "$dir")"
    if [[ "$base" == "$live" || "$base" == "$prev" ]]; then
      continue
    fi
    kept=$((kept + 1))
    if (( kept > KEEP )); then
      rm -rf "$dir"
      echo "vaak-release: pruned ${base}"
    fi
  done
}

switch_to() {
  local release="$1" previous_sha previous_dir sha
  sha="$(basename "$release")"
  previous_sha="$(<"${ROOT}/live-revision")"
  previous_dir="${ROOT}/releases/${previous_sha}"
  [[ -d "$previous_dir" ]] || die "live release ${previous_sha} is missing"
  publish_release "$release"
  if ! restart_units "$previous_dir" "$release" || ! health_check "$release"; then
    echo "vaak-release: health failed; restoring ${previous_sha}" >&2
    publish_release "$previous_dir"
    restart_units "$release" "$previous_dir" || true
    health_check "$previous_dir" || echo "vaak-release: restored release is also unhealthy" >&2
    exit 1
  fi
  note_live "$previous_sha" "$sha"
}

deploy_tarball() {
  local tarball="$1" tmp sha dest version previous_sha
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
  previous_sha="$(<"${ROOT}/live-revision")"
  if [[ -d "$dest" && "$previous_sha" != "$sha" ]]; then
    rm -rf "$dest"
  fi
  if [[ ! -d "$dest" ]]; then
    mv "$tmp" "$dest"
  else
    rm -rf "$tmp"
  fi
  chmod 0755 "${dest}/bin/vaak-worker"
  lint_tree "$dest"
  local php_version
  php_version="$("$PHP_BIN" -r 'require $argv[1]; echo VAAK_VERSION;' "${dest}/api/ap-version.php")"
  [[ "$php_version" == "$version" ]] || die "ap-version.php is ${php_version}, tarball says ${version}"
  "$PHP_BIN" "${dest}/migrate.php" --api-root="${dest}/api"
  switch_to "$dest"
  prune_releases
  echo "deploy_ok version=${version} revision=${sha}"
}

rollback_to() {
  local target="$1" dest="" dir version
  [[ -n "$target" ]] || die "rollback needs a sha or version"
  if [[ -d "${ROOT}/releases/${target}" ]]; then
    dest="${ROOT}/releases/${target}"
  else
    for dir in "${ROOT}/releases"/*; do
      [[ -f "${dir}/VERSION" ]] || continue
      version="$(<"${dir}/VERSION")"
      if [[ "$version" == "$target" || "v${version}" == "$target" ]]; then
        dest="$dir"
        break
      fi
    done
  fi
  [[ -n "$dest" && -d "$dest" ]] || die "no installed release matches ${target}"
  local sha
  sha="$(basename "$dest")"
  [[ "$(<"${ROOT}/live-revision")" == "$sha" ]] && { echo "rollback_ok already ${sha}"; return 0; }
  switch_to "$dest"
  echo "rollback_ok ${sha}"
}

self_test() {
  local tmp sha_old sha_new sha_skip sha_fail
  tmp="$(mktemp -d)"
  sha_old="$(printf 'b%.0s' {1..40})"
  sha_new="$(printf 'c%.0s' {1..40})"
  sha_skip="$(printf 'd%.0s' {1..40})"
  sha_fail="$(printf 'e%.0s' {1..40})"
  install -d \
    "${tmp}/root/releases/${sha_old}/api" \
    "${tmp}/root/releases/${sha_old}/vaak" \
    "${tmp}/root/releases/${sha_old}/bin" \
    "${tmp}/root/incoming" \
    "${tmp}/root/bin" \
    "${tmp}/html/api" \
    "${tmp}/html/vaak" \
    "${tmp}/html/clearance" \
    "${tmp}/bin"
  cat > "${tmp}/root/releases/${sha_old}/api/ap-version.php" <<'PHP'
<?php
const VAAK_VERSION = '0.8.45';
PHP
  printf 'only-old\n' > "${tmp}/root/releases/${sha_old}/api/stale.php"
  printf 'vaak-old\n' > "${tmp}/root/releases/${sha_old}/vaak/index.php"
  printf '#!/bin/sh\necho worker-old\n' > "${tmp}/root/releases/${sha_old}/bin/vaak-worker"
  chmod 0755 "${tmp}/root/releases/${sha_old}/bin/vaak-worker"
  printf '0.8.45\n' > "${tmp}/root/releases/${sha_old}/VERSION"
  printf '%s\n' "$sha_old" > "${tmp}/root/releases/${sha_old}/REVISION"
  cp -a "${tmp}/root/releases/${sha_old}/api/." "${tmp}/html/api/"
  cp -a "${tmp}/root/releases/${sha_old}/vaak/." "${tmp}/html/vaak/"
  printf 'canonical-status\n' > "${tmp}/html/api/network-status.php"
  printf 'canonical-discord\n' > "${tmp}/html/api/discord-presence.php"
  printf 'canonical-gts\n' > "${tmp}/html/api/.gts-config.php"
  printf 'canonical-visitors\n' > "${tmp}/html/api/sl_visitors.json"
  printf 'scratch\n' > "${tmp}/html/api/scratch.php"
  printf 'blog\n' > "${tmp}/html/index.html"
  printf 'clearance\n' > "${tmp}/html/clearance/config.local.php"
  cp -a "${tmp}/root/releases/${sha_old}/bin/vaak-worker" "${tmp}/root/bin/vaak-worker"
  ln -s "${tmp}/root/bin/vaak-worker" "${tmp}/bin/vaak-worker"
  printf '%s\n' "$sha_old" > "${tmp}/root/live-revision"

  cat > "${tmp}/php" <<'PHP'
#!/bin/sh
if [ "$1" = "-l" ]; then
  exit 0
fi
if [ "$1" = "-r" ]; then
  sed -n "s/.*'\\([0-9][0-9.]*\\)'.*/\\1/p" "$3"
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
if [ "$1" = "is-enabled" ] && [ "$3" = "vaak-worker-shadow.service" ] && [ -e "${SHADOW_DISABLED_FLAG}" ]; then
  exit 1
fi
exit 0
CTL
  chmod 0755 "${tmp}/php" "${tmp}/curl" "${tmp}/systemctl"

  make_release() {
    local sha="$1" version="$2" worker="$3" vaak="$4" jet="$5"
    local dest="${tmp}/stage-${sha}"
    rm -rf "$dest"
    install -d "${dest}/api/deploy" "${dest}/vaak" "${dest}/bin"
    cat > "${dest}/api/ap-version.php" <<PHP
<?php
const VAAK_VERSION = '${version}';
PHP
    printf '%s\n' "$vaak" > "${dest}/vaak/index.php"
    printf '%s\n' "$worker" > "${dest}/bin/vaak-worker"
    chmod 0755 "${dest}/bin/vaak-worker"
    if [[ "$jet" == "yes" ]]; then
      printf 'jetstream\n' > "${dest}/api/deploy/ap-bsky-jetstream.py"
    fi
    printf '%s\n' "$version" > "${dest}/VERSION"
    printf '%s\n' "$sha" > "${dest}/REVISION"
    printf '<?php echo "migrate";\n' > "${dest}/migrate.php"
    tar -C "$dest" -czf "${tmp}/${sha}.tar.gz" .
  }

  make_release "$sha_new" 0.8.46 $'#!/bin/sh\necho worker-new\n' vaak-new yes
  make_release "$sha_skip" 0.8.47 $'#!/bin/sh\necho worker-new\n' vaak-skip yes
  make_release "$sha_fail" 0.8.48 $'#!/bin/sh\necho worker-fail\n' vaak-fail yes

  python3 - "${tmp}/dotdot.tar.gz" <<'PY'
import io, sys, tarfile
path = sys.argv[1]
with tarfile.open(path, "w:gz") as tar:
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
  export SHADOW_DISABLED_FLAG="${tmp}/shadow-disabled"
  export VAAK_DEPLOY_RELAX_PERMS=1
  export VAAK_RELEASE_KEEP=5
  export VAAK_HEALTH_ATTEMPTS=2
  export VAAK_HEALTH_SLEEP=0
  unset VAAK_RESTART_JETSTREAM VAAK_RESTART_SHADOW
  : > "${SYSTEMCTL_LOG}"

  if bash "$0" deploy "${tmp}/dotdot.tar.gz"; then
    echo "unsafe tarball was accepted" >&2
    exit 1
  fi
  [[ "$(cat "${tmp}/html/index.html")" == "blog" ]]

  mv "${tmp}/html/api" "${tmp}/html/api.real"
  ln -s "${tmp}/html/api.real" "${tmp}/html/api"
  if bash "$0" deploy "${tmp}/${sha_new}.tar.gz"; then
    echo "symlink api directory was accepted" >&2
    exit 1
  fi
  rm "${tmp}/html/api"
  mv "${tmp}/html/api.real" "${tmp}/html/api"
  [[ ! -L "${tmp}/html/api" && ! -L "${tmp}/html/vaak" ]]
  [[ "$(cat "${tmp}/html/api/stale.php")" == "only-old" ]]

  bash "$0" deploy "${tmp}/${sha_new}.tar.gz"
  [[ ! -L "${tmp}/html/api" && ! -L "${tmp}/html/vaak" ]]
  [[ "$(sed -n "s/.*'\\([0-9.]*\\)'.*/\\1/p" "${tmp}/html/api/ap-version.php")" == "0.8.46" ]]
  [[ ! -e "${tmp}/html/api/stale.php" && ! -e "${tmp}/html/api/scratch.php" ]]
  [[ "$(cat "${tmp}/html/api/network-status.php")" == "canonical-status" ]]
  [[ "$(cat "${tmp}/html/api/discord-presence.php")" == "canonical-discord" ]]
  [[ "$(cat "${tmp}/html/api/.gts-config.php")" == "canonical-gts" ]]
  [[ "$(cat "${tmp}/html/api/sl_visitors.json")" == "canonical-visitors" ]]
  [[ "$(cat "${tmp}/html/vaak/index.php")" == "vaak-new" ]]
  [[ "$(cat "${tmp}/html/index.html")" == "blog" ]]
  [[ "$(cat "${tmp}/html/clearance/config.local.php")" == "clearance" ]]
  [[ "$(readlink "${tmp}/bin/vaak-worker")" == "${tmp}/root/bin/vaak-worker" ]]
  grep -q -F "restart php8.3-fpm.service" "${SYSTEMCTL_LOG}"
  grep -q -F "restart vaak-worker-shadow-http.service" "${SYSTEMCTL_LOG}"
  grep -q -F "restart vaak-worker-shadow.service" "${SYSTEMCTL_LOG}"
  grep -q -F "restart ap-bsky-jetstream@0.service" "${SYSTEMCTL_LOG}"
  grep -q -F "restart ap-bsky-jetstream@1.service" "${SYSTEMCTL_LOG}"
  if grep -E 'restart ap-bsky-jetstream\.service( |$)' "${SYSTEMCTL_LOG}"; then
    echo "bare ap-bsky-jetstream.service was restarted" >&2
    exit 1
  fi

  local jet0_before jet1_before shadow_before
  jet0_before="$(grep -c -F 'restart ap-bsky-jetstream@0.service' "${SYSTEMCTL_LOG}")"
  jet1_before="$(grep -c -F 'restart ap-bsky-jetstream@1.service' "${SYSTEMCTL_LOG}")"
  shadow_before="$(grep -c -F 'restart vaak-worker-shadow.service' "${SYSTEMCTL_LOG}")"
  bash "$0" deploy "${tmp}/${sha_skip}.tar.gz"
  [[ "$(grep -c -F 'restart ap-bsky-jetstream@0.service' "${SYSTEMCTL_LOG}")" == "$jet0_before" ]]
  [[ "$(grep -c -F 'restart ap-bsky-jetstream@1.service' "${SYSTEMCTL_LOG}")" == "$jet1_before" ]]
  [[ "$(grep -c -F 'restart vaak-worker-shadow.service' "${SYSTEMCTL_LOG}")" == "$shadow_before" ]]
  [[ "$(sed -n "s/.*'\\([0-9.]*\\)'.*/\\1/p" "${tmp}/html/api/ap-version.php")" == "0.8.47" ]]
  [[ "$(cat "${tmp}/html/api/network-status.php")" == "canonical-status" ]]

  cat > "${tmp}/curl" <<'CURL'
#!/bin/sh
echo '{"ok":false}'
exit 1
CURL
  chmod 0755 "${tmp}/curl"
  if bash "$0" deploy "${tmp}/${sha_fail}.tar.gz"; then
    echo "failed health was treated as success" >&2
    exit 1
  fi
  [[ "$(sed -n "s/.*'\\([0-9.]*\\)'.*/\\1/p" "${tmp}/html/api/ap-version.php")" == "0.8.47" ]]
  [[ "$(cat "${tmp}/html/vaak/index.php")" == "vaak-skip" ]]
  [[ "$(cat "${tmp}/html/api/network-status.php")" == "canonical-status" ]]
  [[ "$(cat "${tmp}/html/api/discord-presence.php")" == "canonical-discord" ]]
  [[ "$(cat "${tmp}/html/index.html")" == "blog" ]]
  [[ ! -L "${tmp}/html/api" ]]

  cat > "${tmp}/curl" <<'CURL'
#!/bin/sh
echo '{"ok":true}'
CURL
  chmod 0755 "${tmp}/curl"
  : > "${SHADOW_DISABLED_FLAG}"
  shadow_before="$(grep -c -F 'restart vaak-worker-shadow.service' "${SYSTEMCTL_LOG}")"
  VAAK_RESTART_SHADOW=1 bash "$0" rollback "$sha_old"
  [[ "$(grep -c -F 'restart vaak-worker-shadow.service' "${SYSTEMCTL_LOG}")" == "$shadow_before" ]]
  rm -f "${SHADOW_DISABLED_FLAG}"
  [[ "$(cat "${tmp}/root/live-revision")" == "$sha_old" ]]
  [[ "$(cat "${tmp}/html/api/stale.php")" == "only-old" ]]
  [[ "$(cat "${tmp}/html/vaak/index.php")" == "vaak-old" ]]
  [[ "$(cat "${tmp}/html/api/network-status.php")" == "canonical-status" ]]
  [[ "$(cat "${tmp}/html/api/discord-presence.php")" == "canonical-discord" ]]
  [[ "$(cat "${tmp}/html/api/.gts-config.php")" == "canonical-gts" ]]
  [[ "$(cat "${tmp}/html/api/sl_visitors.json")" == "canonical-visitors" ]]
  [[ ! -e "${tmp}/html/api/scratch.php" ]]
  [[ "$(cat "${tmp}/html/index.html")" == "blog" ]]
  [[ ! -L "${tmp}/html/api" && ! -L "${tmp}/html/vaak" ]]
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
