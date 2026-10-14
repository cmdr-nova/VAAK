#!/usr/bin/env bash
# PHP (and two small drivers) that pass with no Postgres, Redis, or live worker.
#
# Not run here:
#   Needs a service or a live worker:
#     normalize-fixture-smoke.php (ap-db.php peer Postgres; migrations are not a schema)
#     admin-safety-smoke.php, downrank-parity-smoke.php
#     library-client-smoke.php, private-surfaces-client-smoke.php
#     search-parity-smoke.php, settings-client-smoke.php
#     you-client-smoke.php, you-integrations-client-smoke.php
#     search-direct-undo-smoke.php's PostgreSQL copy (only if LIBRARY_FIXTURE_SOCKET is set;
#       the SQLite path below does run)
#   Already failing on current main (not gated, so a red run means a new break):
#     search-native-bridge-smoke.php
#     settings-behavior-smoke.php
#     library-privacy-admission-smoke.php
#     library-facts-independent-smoke.php (requires the privacy smoke)
#     library-settings-durable-smoke.php
#     library-controller-action-smoke.php
#     migration-folder-reference-smoke.php
#     direct-partial-blocks-smoke.php
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
runner="${root}/api/bin/php-policy-test"

if [[ ! -x "$runner" ]]; then
  echo "missing executable ${runner}" >&2
  exit 127
fi

# Real mbstring is required. php-policy-test refuses polyfills and a missing extension.
if ! php -r 'exit(extension_loaded("mbstring") ? 0 : 1);'; then
  echo "mbstring is not loaded; install the php-mbstring extension" >&2
  exit 1
fi
if ! php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'; then
  echo "pdo_sqlite is not loaded" >&2
  exit 1
fi

php_tests=(
  api/bin/account-acct-key-error-smoke.php
  api/bin/bsky-thread-fragment-smoke.php
  api/bin/dm-ask-parity-smoke.php
  api/bin/library-deletion-smoke.php
  api/bin/library-moderation-actions-smoke.php
  api/bin/library-native-bridge-test.php
  api/bin/library-own-cw-smoke.php
  api/bin/migration-php-reference-smoke.php
  api/bin/moderation-alias-cache-smoke.php
  api/bin/notif-reply-preview-smoke.php
  api/bin/phyrian-parity-smoke.php
  api/bin/pin-federation-behavior-smoke.php
  api/bin/pluraldawn-decode-smoke.php
  api/bin/post-single-overflow-smoke.php
  api/bin/privacy-moderation-smoke.php
  api/bin/profile-native-first-paint-empty-test.php
  api/bin/profile-native-reply-test.php
  api/bin/quote-avatar-behavior-smoke.php
  api/bin/recommendations-behavior-smoke.php
  api/bin/search-direct-undo-smoke.php
  api/bin/search-indexing-behavior-smoke.php
  api/bin/schema-probe-cache-smoke.php
  api/bin/search-quote-preview-boundary-smoke.php
  api/bin/settings-parity-smoke.php
  api/bin/trends-response-cache-smoke.php
  api/bin/trending-open-ref-smoke.php
)

# Drivers for isolated PHP fixtures. They do not open Postgres or a worker.
other_tests=(
  api/bin/bsky-thread-http-smoke.py
  api/bin/privacy-audience-smoke.py
)

for test in "${php_tests[@]}"; do
  echo "==> ${test}"
  "$runner" "$test"
done

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is not on PATH" >&2
  exit 127
fi
for test in "${other_tests[@]}"; do
  echo "==> ${test}"
  python3 "$test"
done

if ! command -v node >/dev/null 2>&1; then
  echo "node is not on PATH" >&2
  exit 127
fi
node_tests=(
  api/bin/timeline-new-posts-poll-regression.js
  api/bin/notif-badge-poll-regression.js
)
for test in "${node_tests[@]}"; do
  echo "==> ${test}"
  node "$test"
done
echo "==> api/bin/notif-badge-poll-regression.js --without-abort-controller"
node api/bin/notif-badge-poll-regression.js --without-abort-controller

echo "standalone tests: $((${#php_tests[@]} + ${#other_tests[@]} + ${#node_tests[@]} + 1)) passed"
