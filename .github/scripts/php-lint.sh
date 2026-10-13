#!/usr/bin/env bash
# Syntax-check every PHP file under api/. This does not execute the files.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

if ! command -v php >/dev/null 2>&1; then
  echo "php is not on PATH" >&2
  exit 127
fi

fail=0
count=0
while IFS= read -r -d '' file; do
  count=$((count + 1))
  if ! php -l "$file" >/dev/null; then
    php -l "$file" || true
    fail=1
  fi
done < <(find api -type f -name '*.php' -print0 | sort -z)

echo "php -l: ${count} files under api/"
if [[ "$count" -eq 0 ]]; then
  echo "no PHP files found under api/" >&2
  exit 1
fi
exit "$fail"
