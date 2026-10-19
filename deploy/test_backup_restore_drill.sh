#!/usr/bin/env bash
# Self-test for deploy/vaak-backup-restore-drill.sh with fake PostgreSQL tools.
set -euo pipefail
drill="$(cd "$(dirname "$0")" && pwd)/vaak-backup-restore-drill.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

mkdir -p "$tmp/bin" "$tmp/backups" "$tmp/fake/novalandia" "$tmp/fake/drill" "$tmp/data"
cat > "$tmp/bin/psql" <<'SH'
#!/usr/bin/env bash
db="" sql=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -d) db="$2"; shift 2 ;;
    -c) sql="$2"; shift 2 ;;
    *) shift ;;
  esac
done
case "$sql" in
  "SHOW data_directory") echo "$FAKE_DATA_DIR" ;;
  *to_regclass*)
    t="$(sed -n "s/.*public\.\([a-z_]*\)'.*/\1/p" <<<"$sql")"
    [[ -f "$FAKE/$db/$t" ]] && echo t || echo f ;;
  *"count(*) FROM "*) cat "$FAKE/$db/${sql##* }" ;;
  *) exit 1 ;;
esac
SH
cat > "$tmp/bin/createdb" <<'SH'
#!/usr/bin/env bash
echo "createdb $*" >> "$FAKE/log"
SH
cat > "$tmp/bin/dropdb" <<'SH'
#!/usr/bin/env bash
echo "dropdb $*" >> "$FAKE/log"
SH
cat > "$tmp/bin/pg_restore" <<'SH'
#!/usr/bin/env bash
echo "pg_restore PGOPTIONS=${PGOPTIONS} $*" >> "$FAKE/log"
[[ -e "$FAKE/restore-fails" ]] && exit 1
cp "$FAKE/restored/"* "$FAKE/drill/"
SH
chmod 0755 "$tmp/bin/"*

export PATH="$tmp/bin:$PATH" FAKE="$tmp/fake" FAKE_DATA_DIR="$tmp/data"
export VAAK_DRILL_PG_AS="" VAAK_BACKUP_DIR="$tmp/backups" VAAK_DRILL_DB=drill
export VAAK_DRILL_STATE_DIR="$tmp/state" VAAK_LOCK_DIR="$tmp/lock" VAAK_DRILL_TABLES="ap_users:5 following:10 events:50"

live() { printf '%s\n' "$2" > "$FAKE/novalandia/$1"; }
restored() { mkdir -p "$FAKE/restored"; printf '%s\n' "$2" > "$FAKE/restored/$1"; }
reset() {
  rm -rf "$FAKE/drill" "$FAKE/restored" "$FAKE/restore-fails" "$tmp/state"
  mkdir -p "$FAKE/drill" "$FAKE/restored"
  : > "$FAKE/log"
}
not_logged() {
  if grep -q -E "$1" "$FAKE/log"; then echo "unexpected '$1' in log" >&2; cat "$FAKE/log" >&2; exit 1; fi
}
run() { bash "$drill" > "$tmp/out" 2>&1; }
must_fail() {
  if run; then echo "drill passed but should fail: $1" >&2; cat "$tmp/out" >&2; exit 1; fi
  grep -q -F "$2" "$tmp/out" || { echo "missing '$2' for $1" >&2; cat "$tmp/out" >&2; exit 1; }
}

(cd "$tmp/backups" && printf 'old' > novalandia-20261001T021500Z.dump && sha256sum novalandia-20261001T021500Z.dump > novalandia-20261001T021500Z.dump.sha256)
(cd "$tmp/backups" && printf 'dump-bytes' > novalandia-20261018T021500Z.dump \
  && printf 'cfg' > mkultra-config-20261018T021500Z.tar.gz \
  && sha256sum novalandia-20261018T021500Z.dump mkultra-config-20261018T021500Z.tar.gz > novalandia-20261018T021500Z.dump.sha256)
live ap_users 3; live following 200; live events 20000

# Pass: newest dump, counts within drift, scratch dropped, stamp written.
reset; restored ap_users 3; restored following 190; restored events 15000
run || { cat "$tmp/out" >&2; exit 1; }
grep -q '^restore_drill_ok dump=novalandia-20261018T021500Z.dump ' "$tmp/out"
grep -q 'ap_users=3/3 following=190/200 events=15000/20000' "$tmp/out"
grep -q 'pg_restore PGOPTIONS=-c maintenance_work_mem=64MB -c max_parallel_maintenance_workers=0 .*--no-owner .*--exit-on-error --jobs=1 --dbname=drill' "$FAKE/log"
grep -q 'createdb --template=template0 drill' "$FAKE/log"
[[ "$(tail -n 1 "$FAKE/log")" == "dropdb --if-exists --force drill" ]]
[[ "$(cut -d' ' -f2 "$tmp/state/last-ok")" == "novalandia-20261018T021500Z.dump" ]]

# A table missing on both sides is reported, not failed.
reset; restored ap_users 3; restored following 200; restored events 20000
VAAK_DRILL_TABLES="ap_users:5 not_a_table:5" run
grep -q 'not_a_table=absent' "$tmp/out"

# Fail: drift past the allowance; scratch still dropped; no stamp.
reset; restored ap_users 3; restored following 150; restored events 20000
must_fail drift "following restored 150 rows, live has 200"
[[ "$(tail -n 1 "$FAKE/log")" == "dropdb --if-exists --force drill" && ! -e "$tmp/state/last-ok" ]]

# Fail: a table restored empty.
reset; restored ap_users 0; restored following 200; restored events 20000
must_fail empty "ap_users restored empty"

# Fail: a live table missing from the dump.
reset; restored ap_users 3; restored events 20000
must_fail missing "following exists live but not in the restored dump"

# Fail: pg_restore error; scratch dropped.
reset; restored ap_users 3; : > "$FAKE/restore-fails"
must_fail restore "pg_restore of novalandia-20261018T021500Z.dump failed"
[[ "$(tail -n 1 "$FAKE/log")" == "dropdb --if-exists --force drill" ]]

# Fail before restoring: not enough disk.
reset
VAAK_DRILL_SPACE_FACTOR=999999999999999 must_fail space "not enough disk"
not_logged createdb

# Fail before restoring: checksum mismatch, then missing manifest.
reset
printf 'tampered' > "$tmp/backups/novalandia-20261018T021500Z.dump"
must_fail checksum "checksum mismatch for novalandia-20261018T021500Z.dump"
not_logged 'createdb|pg_restore'
rm "$tmp/backups/novalandia-20261018T021500Z.dump.sha256"
must_fail manifest "missing checksum manifest"

# Refuses to use the live database as scratch.
reset
VAAK_DRILL_DB=novalandia must_fail scratch "scratch database must not be the live database"
not_logged dropdb

echo "backup_restore_drill_test_ok"
