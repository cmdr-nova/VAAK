#!/usr/bin/env bash
# Build a VAAK release tree from this checkout plus a CI-built worker binary.
# The tree is what production swaps in. It contains PHP api/, the vaak front
# end, the worker binary, and the migration CLI. It does not contain docs,
# Rust sources, replay fixtures, or the Jekyll blog.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BINARY=""
REVISION=""
OUTPUT=""

usage() {
  echo "usage: assemble-release.sh --binary PATH --revision SHA --output DIR" >&2
  echo "       assemble-release.sh --self-test" >&2
  exit 2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --binary) BINARY="${2:-}"; shift 2 ;;
    --revision) REVISION="${2:-}"; shift 2 ;;
    --output) OUTPUT="${2:-}"; shift 2 ;;
    --self-test) SELF_TEST=1; shift ;;
    *) usage ;;
  esac
done

BIN_ALLOW=(
  bsky-jetstream-media-backfill.php
  bsky-notif-subject-backfill.php
  bsky-own-repost-profile-backfill.php
  deploy-canary.php
  home-timeline-warm.php
  notif-projection-fill.php
  phyrian-decay.php
  phyrian-sync.php
  ranked-warm.php
  remote-emoji-backfill.php
)

assemble() {
  local binary="$1" revision="$2" output="$3"
  [[ -f "$binary" ]] || { echo "missing binary: $binary" >&2; return 1; }
  [[ "$revision" =~ ^[0-9a-f]{40}$ ]] || { echo "revision must be a 40-character sha" >&2; return 1; }
  [[ -n "$output" && "$output" != "/" ]] || { echo "output directory required" >&2; return 1; }
  rm -rf "$output"
  mkdir -p "$output/api/bin" "$output/api/deploy" "$output/bin" "$output/vaak"

  local include=()
  local name
  for name in "${BIN_ALLOW[@]}"; do
    include+=(--include "bin/${name}")
  done

  rsync -a \
    --include 'bin/' \
    "${include[@]}" \
    --exclude 'bin/*' \
    --exclude 'fixtures' \
    --exclude 'fixtures/**' \
    --exclude '*.sqlite' \
    --exclude '*.sqlite-*' \
    --exclude '.gts-config.php' \
    --exclude 'sl_visitors.json' \
    --exclude 'test-gts.php' \
    --exclude '__pycache__/' \
    "${ROOT}/api/" "${output}/api/"
  rsync -a "${ROOT}/vaak/" "${output}/vaak/"

  cp -a "${ROOT}/deploy/ap-bsky-jetstream.py" "${output}/api/deploy/ap-bsky-jetstream.py"
  cp -a "${ROOT}/deploy/ap-bsky-jetstream-requirements.txt" "${output}/api/deploy/ap-bsky-jetstream-requirements.txt"
  cp -a "${ROOT}/deploy/wafrn-friend-hosts.txt" "${output}/api/deploy/wafrn-friend-hosts.txt"
  cp -a "${ROOT}/deploy/remote/migrate.php" "${output}/migrate.php"
  install -m 0755 "$binary" "${output}/bin/vaak-worker"

  local version
  version="$(sed -n "s/^const VAAK_VERSION = '\\([^']*\\)';/\\1/p" "${output}/api/ap-version.php")"
  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]{2}$ ]] || { echo "bad version in release: $version" >&2; return 1; }
  printf '%s\n' "$version" > "${output}/VERSION"
  printf '%s\n' "$revision" > "${output}/REVISION"

  [[ ! -e "${output}/docs" ]] || { echo "release leaked docs/" >&2; return 1; }
  [[ ! -e "${output}/api/fixtures" ]] || { echo "release leaked fixtures/" >&2; return 1; }
  [[ ! -e "${output}/api/bin/library-client-smoke.php" ]] || { echo "release leaked replay tooling" >&2; return 1; }
  [[ -f "${output}/api/bin/home-timeline-warm.php" ]] || { echo "missing production CLI" >&2; return 1; }
  [[ -f "${output}/vaak/index.php" ]] || { echo "missing vaak front controller" >&2; return 1; }
  echo "assembled ${output} version=${version} revision=${revision}"
}

self_test() {
  local tmp binary
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN
  binary="${tmp}/vaak-worker"
  printf '#!/bin/sh\necho vaak-worker\n' > "$binary"
  chmod 0755 "$binary"
  local sha
  sha="$(printf 'a%.0s' {1..40})"
  assemble "$binary" "$sha" "${tmp}/out"
  [[ -f "${tmp}/out/api/ap-version.php" ]]
  [[ -f "${tmp}/out/api/bin/notif-projection-fill.php" ]]
  [[ -f "${tmp}/out/api/bin/bsky-notif-subject-backfill.php" ]]
  [[ -f "${tmp}/out/api/bin/deploy-canary.php" ]]
  [[ ! -e "${tmp}/out/api/bin/deploy-canary-smoke.php" ]]
  [[ ! -e "${tmp}/out/api/bin/bsky-notif-subject-backfill-smoke.php" ]]
  [[ ! -e "${tmp}/out/api/bin/library-client-smoke.php" ]]
  [[ ! -e "${tmp}/out/api/fixtures" ]]
  [[ -f "${tmp}/out/api/deploy/ap-bsky-jetstream.py" ]]
  [[ -f "${tmp}/out/vaak/index.php" ]]
  [[ -x "${tmp}/out/bin/vaak-worker" ]]
  [[ -f "${tmp}/out/migrate.php" ]]
  [[ -f "${tmp}/out/api/migrations/20261014_phyrian_clans_postgres.sql" ]]
  [[ -f "${tmp}/out/api/migrations/20261019_phyrian_grid_lineage_postgres.sql" ]]
  [[ ! -e "${tmp}/out/docs" ]]
  echo "assemble_self_test_ok"
}

if [[ "${SELF_TEST:-0}" == "1" ]]; then
  self_test
  exit 0
fi

[[ -n "$BINARY" && -n "$REVISION" && -n "$OUTPUT" ]] || usage
assemble "$BINARY" "$REVISION" "$OUTPUT"
