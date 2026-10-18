#!/usr/bin/env bash
set -Eeuo pipefail

# Read-only pull: "vaak-prod-backup" is an ~/.ssh/config alias for vaak-backup@<prod-host> with
# its own key, which the VPS restricts to `rrsync -ro /var/backups/mkultra/vaak/`.
REMOTE="${VAAK_BACKUP_REMOTE:-vaak-prod-backup:./}"
DEST="${VAAK_DISC3_BACKUP_DIR:-/mnt/disc3/Backups/VAAK}"
RETENTION_DAYS="${VAAK_DISC3_RETENTION_DAYS:-180}"
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=3)

if [[ "$(id -u)" == 0 ]]; then
  echo "vaak-disc3-backup: refusing to run as root" >&2; exit 2
fi
if ! findmnt -rn -T /mnt/disc3 >/dev/null 2>&1; then
  echo "vaak-disc3-backup: /mnt/disc3 is not mounted; skipping" >&2; exit 0
fi
mkdir -p "${DEST}"; chmod 700 "${DEST}"
tmp="${DEST}/.incoming.$$"; cleanup() { rm -rf -- "${tmp}"; }; trap cleanup EXIT
mkdir -p "${tmp}"
rsync -a --partial --timeout=120 -e "ssh ${SSH_OPTS[*]}" "${REMOTE}" "${tmp}/"
shopt -s nullglob
for manifest in "${tmp}"/*.dump.sha256; do
  (cd "${tmp}" && sha256sum -c "$(basename "${manifest}")")
done
find "${DEST}" -maxdepth 1 -type f \( -name 'novalandia-*.dump' -o -name 'novalandia-*.dump.sha256' -o -name 'mkultra-config-*.tar.gz' \) -mtime "+${RETENTION_DAYS}" -delete
for file in "${tmp}"/*; do
  [[ -f "${file}" ]] || continue
  install -m 600 "${file}" "${DEST}/$(basename "${file}")"
done
echo "vaak-disc3-backup: synced $(find "${tmp}" -maxdepth 1 -type f | wc -l) files to ${DEST}"
