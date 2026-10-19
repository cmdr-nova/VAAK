#!/usr/bin/env bash
set -euo pipefail

# Monthly restore drill. Restores the newest PostgreSQL dump into a scratch
# database, compares row counts of durable tables against the live database,
# and drops the scratch database whatever happens. Prints restore_drill_ok and
# records the time in ${STATE_DIR}/last-ok, which vaak-integrity-check.sh reads.
# Run as root (vaak-backup-restore-drill.timer).
#
# The box has 8 GB of RAM, so the restore runs with one job, no parallel
# index builds, and a small maintenance_work_mem, under nice and idle I/O.
DB_NAME="${VAAK_DB_NAME:-novalandia}"
BACKUP_DIR="${VAAK_BACKUP_DIR:-/var/backups/mkultra/vaak}"
SCRATCH_DB="${VAAK_DRILL_DB:-novalandia_restore_drill}"
STATE_DIR="${VAAK_DRILL_STATE_DIR:-/var/lib/mkultra/vaak-restore-drill}"
LOCK_DIR="${VAAK_LOCK_DIR:-/run/vaak-lock}"
PG_AS="${VAAK_DRILL_PG_AS-postgres}"
MAINT_MEM="${VAAK_DRILL_MAINTENANCE_WORK_MEM:-64MB}"
# The restored database needs roughly this many times the dump size on disk.
SPACE_FACTOR="${VAAK_DRILL_SPACE_FACTOR:-4}"
# table:percent. The restored count must be within percent of the live count
# (and within 5 rows for small tables). The dump is up to a day old, and the
# remote tables are pruned to the cold archive daily, so they get more slack.
TABLES="${VAAK_DRILL_TABLES:-ap_users:5 following:10 followers:10 outbox_notes:10 masto_statuses:10 events:50}"

fail() {
  echo "restore_drill_failed $*" >&2
  exit 1
}

pg() {
  if [[ -n "${PG_AS}" ]]; then
    runuser -u "${PG_AS}" -- "$@"
  else
    "$@"
  fi
}

count_rows() {
  local db="$1" table="$2"
  pg psql -X -v ON_ERROR_STOP=1 -d "${db}" -At -c "SELECT count(*) FROM ${table}"
}

table_exists() {
  local db="$1" table="$2"
  [[ "$(pg psql -X -d "${db}" -At -c "SELECT to_regclass('public.${table}') IS NOT NULL")" == "t" ]]
}

if [[ -n "${PG_AS}" && "${EUID}" -ne 0 ]]; then
  echo "vaak-backup-restore-drill: must run as root" >&2
  exit 1
fi
[[ "${SCRATCH_DB}" =~ ^[a-z0-9_]+$ ]] || fail "scratch database name is not plain: ${SCRATCH_DB}"
[[ "${SCRATCH_DB}" != "${DB_NAME}" ]] || fail "scratch database must not be the live database"

mkdir -p "${LOCK_DIR}"
exec 9>"${LOCK_DIR}/vaak-backup-restore-drill.lock"
flock -n 9 || { echo "vaak-backup-restore-drill: another drill is running" >&2; exit 0; }

dump="$(find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'novalandia-*.dump' -printf '%f\n' 2>/dev/null | sort | tail -n 1)"
[[ -n "${dump}" ]] || fail "no novalandia-*.dump in ${BACKUP_DIR}"
manifest="${BACKUP_DIR}/${dump}.sha256"
[[ -f "${manifest}" ]] || fail "missing checksum manifest ${manifest}"
line="$(grep -E "^[0-9a-f]{64}  ${dump}\$" "${manifest}" || true)"
[[ -n "${line}" ]] || fail "${manifest} has no line for ${dump}"
(cd "${BACKUP_DIR}" && printf '%s\n' "${line}" | sha256sum -c --status -) || fail "checksum mismatch for ${dump}"

dump_bytes="$(stat -c %s "${BACKUP_DIR}/${dump}")"
data_dir="$(pg psql -X -d "${DB_NAME}" -At -c 'SHOW data_directory')"
free_bytes="$(df -PB1 "${data_dir}" | awk 'NR==2 {print $4}')"
need_bytes=$(( dump_bytes * SPACE_FACTOR ))
(( free_bytes >= need_bytes )) || fail "not enough disk under ${data_dir}: ${free_bytes} free, want ${need_bytes}"

cleanup() {
  pg dropdb --if-exists --force "${SCRATCH_DB}" >/dev/null 2>&1 || echo "vaak-backup-restore-drill: could not drop ${SCRATCH_DB}" >&2
}
trap cleanup EXIT
# A previous run that was killed may have left the scratch database behind.
pg dropdb --if-exists --force "${SCRATCH_DB}" >/dev/null 2>&1 || true
pg createdb --template=template0 "${SCRATCH_DB}"

started="$(date +%s)"
low=()
command -v nice >/dev/null 2>&1 && low+=(nice -n 19)
command -v ionice >/dev/null 2>&1 && low+=(ionice -c 3 -t)
pg_opts="-c maintenance_work_mem=${MAINT_MEM} -c max_parallel_maintenance_workers=0 -c synchronous_commit=off"
pg env PGOPTIONS="${pg_opts}" "${low[@]}" pg_restore --no-owner --no-privileges --exit-on-error --jobs=1 \
  --dbname="${SCRATCH_DB}" "${BACKUP_DIR}/${dump}" || fail "pg_restore of ${dump} failed"
restore_secs=$(( $(date +%s) - started ))

summary=()
for spec in ${TABLES}; do
  table="${spec%%:*}"
  pct="${spec##*:}"
  [[ "${table}" =~ ^[a-z_]+$ && "${pct}" =~ ^[0-9]+$ ]] || fail "bad VAAK_DRILL_TABLES entry ${spec}"
  if ! table_exists "${DB_NAME}" "${table}"; then
    summary+=("${table}=absent")
    continue
  fi
  table_exists "${SCRATCH_DB}" "${table}" || fail "${table} exists live but not in the restored dump"
  live="$(count_rows "${DB_NAME}" "${table}")"
  restored="$(count_rows "${SCRATCH_DB}" "${table}")"
  if (( live > 0 && restored == 0 )); then
    fail "${table} restored empty, live has ${live} rows"
  fi
  diff=$(( live > restored ? live - restored : restored - live ))
  allowed=$(( live * pct / 100 ))
  (( allowed < 5 )) && allowed=5
  (( diff <= allowed )) || fail "${table} restored ${restored} rows, live has ${live} (allowed drift ${allowed})"
  summary+=("${table}=${restored}/${live}")
done

mkdir -p "${STATE_DIR}"
chmod 755 "${STATE_DIR}"
printf '%s %s\n' "$(date +%s)" "${dump}" > "${STATE_DIR}/last-ok.tmp"
mv -f "${STATE_DIR}/last-ok.tmp" "${STATE_DIR}/last-ok"
echo "restore_drill_ok dump=${dump} restore_secs=${restore_secs} ${summary[*]}"
