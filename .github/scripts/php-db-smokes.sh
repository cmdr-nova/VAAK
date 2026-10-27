#!/usr/bin/env bash
# PHP smokes that need PostgreSQL, Redis, and the Rust shadow HTTP worker.
#
# CI runs this against throwaway postgres:16 and redis:7 service containers.
# Locally, point it at disposable instances only; it creates a schema in
# VAAK_CI_DB and writes fixture rows there.
#
# Inputs (CI defaults):
#   PGHOST, PGPORT, PGUSER     TCP connection used by psql and the schema steps
#   VAAK_CI_DB                 empty database to build (vaak_ci)
#   LIBRARY_FIXTURE_SOCKET     Unix socket directory of the same server, under
#                              /tmp/ (direct-partial-blocks and the Postgres copy
#                              of search-direct-undo use database "postgres")
#   VAAK_REDIS_DSN             Redis for PHP; the worker uses DB 0 and 1 on it
#   VAAK_WORKER_BIN            built vaak-worker binary
#
# Schema: .github/scripts/pg-ci-schema.php stages the legacy tables, then
# deploy/remote/migrate.php applies the PostgreSQL migrations as on deploy.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
runner="${root}/api/bin/php-policy-test"

export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-postgres}"
db="${VAAK_CI_DB:-vaak_ci}"
socket="${LIBRARY_FIXTURE_SOCKET:-/tmp/vaak-pg-socket}"
redis_dsn="${VAAK_REDIS_DSN:-redis://127.0.0.1:6379/0}"
worker_bin="${VAAK_WORKER_BIN:-${root}/rust/target/debug/vaak-worker}"
worker_port="${VAAK_CI_WORKER_PORT:-18787}"
work="$(mktemp -d)"

case "$PGHOST" in
  127.0.0.1|localhost) ;;
  *) echo "refusing non-loopback PGHOST ${PGHOST}" >&2; exit 2 ;;
esac
if [[ ! -S "${socket}/.s.PGSQL.${PGPORT}" ]]; then
  echo "no PostgreSQL socket at ${socket}/.s.PGSQL.${PGPORT}" >&2
  exit 2
fi
if [[ ! -x "$worker_bin" ]]; then
  echo "missing vaak-worker binary ${worker_bin}" >&2
  exit 127
fi
redis_hostport="${redis_dsn#redis://}"
redis_hostport="${redis_hostport%%/*}"

worker_pid=""
profile_pid=""
cleanup() {
  if [[ -n "$worker_pid" ]]; then
    kill "$worker_pid" 2>/dev/null || true
    wait "$worker_pid" 2>/dev/null || true
  fi
  if [[ -n "$profile_pid" ]]; then
    kill "$profile_pid" 2>/dev/null || true
    wait "$profile_pid" 2>/dev/null || true
  fi
  rm -rf "$work"
}
trap cleanup EXIT

export AP_DB_DSN="pgsql:host=${PGHOST};port=${PGPORT};dbname=${db};user=${PGUSER}"
export VAAK_REDIS_DSN="$redis_dsn"
export LIBRARY_FIXTURE_SOCKET="$socket"

echo "==> schema"
if [[ -n "$(psql -tAc "SELECT 1 FROM pg_database WHERE datname = '${db}'" postgres)" ]]; then
  echo "database ${db} already exists; use an empty server" >&2
  exit 2
fi
psql -v ON_ERROR_STOP=1 -q -d postgres <<SQL
CREATE DATABASE ${db};
DO \$\$ BEGIN
  -- Additive migrations grant to the PHP-FPM role.
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'www-data') THEN
    CREATE ROLE "www-data" LOGIN;
  END IF;
END \$\$;
SQL
php .github/scripts/pg-ci-schema.php
printf 'AP_DB_DSN=%s\n' "$AP_DB_DSN" > "${work}/migrate.env"
VAAK_MIGRATE_ENV="${work}/migrate.env" php deploy/remote/migrate.php --api-root=api
# Schema probes are cached in Redis per release; drop the bootstrap snapshot.
php -r '$r = new Redis(); [$h, $p] = explode(":", $argv[1]) + [1 => 6379]; $r->connect($h, (int) $p); $r->flushAll();' "$redis_hostport"

# Owner 1 is the default owner of every *-client smoke and the worker.
psql -v ON_ERROR_STOP=1 -q -o /dev/null -d "$db" <<'SQL'
INSERT INTO ap_users (id, username, email, password_hash, actor_key, actor_id, is_admin, created_at, updated_at)
VALUES (1, 'ci_owner', 'ci-owner@vaak.test', 'unused', 'ci_owner', 'https://vaak.test/users/ci_owner', 1,
        '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');
SELECT setval(pg_get_serial_sequence('ap_users', 'id'), 1);
INSERT INTO actor_profile (actor_key, name, summary, updated_at)
VALUES ('ci_owner', 'CI owner', '', '2026-01-01T00:00:00Z');
SQL

echo "==> vaak-worker serve"
mkdir -p "${work}/worker"
# The worker refuses /shadow/* without a token; the PHP smokes send the same one.
export VAAK_SHADOW_HTTP_TOKEN="${VAAK_SHADOW_HTTP_TOKEN:-php-db-smokes-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')}"
VAAK_ENV_FILE=/nonexistent VAAK_PHYRIAN_BRIDGE_ENV_FILE=/nonexistent \
  VAAK_DATABASE_URL="postgresql://${PGUSER}@${PGHOST}:${PGPORT}/${db}" \
  VAAK_REDIS_URL="redis://${redis_hostport}/0" VAAK_REDIS_QUEUE_URL="redis://${redis_hostport}/1" \
  VAAK_JETSTREAM_STATE="${work}/worker/jetstream" VAAK_NOTIF_CACHE_DIR="${work}/worker" \
  RUST_LOG=warn "$worker_bin" serve --bind "127.0.0.1:${worker_port}" &
worker_pid=$!
rust_url="http://127.0.0.1:${worker_port}"
for _ in $(seq 1 60); do
  if php -r 'exit(@file_get_contents($argv[1]) === false ? 1 : 0);' "${rust_url}/healthz"; then
    break
  fi
  if ! kill -0 "$worker_pid" 2>/dev/null; then
    echo "vaak-worker exited" >&2
    exit 1
  fi
  sleep 1
done

run() {
  echo "==> $*"
  "$@"
}

run "$runner" api/bin/normalize-fixture-smoke.php
VAAK_DOWNRANK_DATABASE_URL="$AP_DB_DSN" run "$runner" api/bin/downrank-parity-smoke.php
VAAK_ADMIN_PARITY_DATABASE_URL="$AP_DB_DSN" VAAK_ADMIN_PARITY_RUST_URL="$rust_url" \
  run "$runner" api/bin/admin-safety-smoke.php
VAAK_LIBRARY_CLIENT_RUST_URL="$rust_url" run "$runner" api/bin/library-client-smoke.php
VAAK_PRIVATE_SURFACES_RUST_URL="$rust_url" run "$runner" api/bin/private-surfaces-client-smoke.php
VAAK_SEARCH_CONTRACT_RUST_URL="$rust_url" run "$runner" api/bin/search-parity-smoke.php
VAAK_SETTINGS_CLIENT_RUST_URL="$rust_url" run "$runner" api/bin/settings-client-smoke.php
VAAK_YOU_CLIENT_RUST_URL="$rust_url" run "$runner" api/bin/you-client-smoke.php
VAAK_YOU_INTEGRATIONS_RUST_URL="$rust_url" run "$runner" api/bin/you-integrations-client-smoke.php
run "$runner" api/bin/search-direct-undo-smoke.php
UNDO_FIXTURE_WIDTH=BIGINT run "$runner" api/bin/search-direct-undo-smoke.php
run "$runner" api/bin/direct-partial-blocks-smoke.php

# Complete-profile routes stay unregistered on the worker above. This process
# gets a one-shot secret so the parity smoke can call them. The secret is not
# printed.
profile_port="${VAAK_CI_PROFILE_PORT:-18788}"
profile_secret="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
VAAK_ENV_FILE=/nonexistent VAAK_PHYRIAN_BRIDGE_ENV_FILE=/nonexistent \
  VAAK_PROFILE_COMPLETE_NATIVE=1 VAAK_PROFILE_COMPLETE_SECRET="$profile_secret" \
  VAAK_DATABASE_URL="postgresql://${PGUSER}@${PGHOST}:${PGPORT}/${db}" \
  VAAK_REDIS_URL="redis://${redis_hostport}/0" VAAK_REDIS_QUEUE_URL="redis://${redis_hostport}/1" \
  VAAK_JETSTREAM_STATE="${work}/worker/jetstream-profile" VAAK_NOTIF_CACHE_DIR="${work}/worker" \
  RUST_LOG=debug "$worker_bin" serve --bind "127.0.0.1:${profile_port}" &
profile_pid=$!
profile_url="http://127.0.0.1:${profile_port}"
for _ in $(seq 1 60); do
  if php -r 'exit(@file_get_contents($argv[1]) === false ? 1 : 0);' "${profile_url}/healthz"; then
    break
  fi
  if ! kill -0 "$profile_pid" 2>/dev/null; then
    echo "profile vaak-worker exited" >&2
    exit 1
  fi
  sleep 1
done
VAAK_PROFILE_PARITY_RUST_URL="$profile_url" VAAK_PROFILE_COMPLETE_SECRET="$profile_secret" \
  run "$runner" api/bin/profile-complete-parity-smoke.php

echo "db smokes: 13 passed"
