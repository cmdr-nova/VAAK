#!/usr/bin/env bash
set -euo pipefail

# Durable VAAK backup. Run as root (or a privileged operator) so the database
# dump and deployment/config archive can be protected with mode 0600.
DB_NAME="${VAAK_DB_NAME:-novalandia}"
BACKUP_DIR="${VAAK_BACKUP_DIR:-/var/backups/mkultra/vaak}"
RETENTION_DAYS="${VAAK_BACKUP_RETENTION_DAYS:-30}"
# Admin-uploaded avatars and headers (ap_profile_media_dir()). They are not in
# git, the deploy never writes them, and the database only holds their URLs.
PROFILE_MEDIA_DIR="${VAAK_PROFILE_MEDIA_DIR:-/srv/mkultra/html/img/profile}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

if [[ "${EUID}" -ne 0 ]]; then
  echo "vaak-postgres-backup: must run as root" >&2
  exit 1
fi
mkdir -p "${BACKUP_DIR}"
chgrp vaak-backup "${BACKUP_DIR}"
chmod 750 "${BACKUP_DIR}"

tmp_dump="${BACKUP_DIR}/.novalandia-${STAMP}.dump.tmp"
tmp_cfg="${BACKUP_DIR}/.mkultra-config-${STAMP}.tar.gz.tmp"
dump="${BACKUP_DIR}/novalandia-${STAMP}.dump"
cfg="${BACKUP_DIR}/mkultra-config-${STAMP}.tar.gz"
trap 'rm -f "${tmp_dump}" "${tmp_cfg}"' EXIT

runuser -u postgres -- pg_dump --format=custom --no-owner --no-privileges --dbname="${DB_NAME}" >"${tmp_dump}"
chgrp vaak-backup "${tmp_dump}"; chmod 640 "${tmp_dump}"
mv -f "${tmp_dump}" "${dump}"

# Keep the credentials/signing configuration recoverable, but private. The
# profile media rides in the same archive so it shares its mode and retention.
tar -czf "${tmp_cfg}" --ignore-failed-read /etc/mkultra /etc/caddy "${PROFILE_MEDIA_DIR}" 2>/dev/null || true
if [[ -s "${tmp_cfg}" ]]; then
  chgrp vaak-backup "${tmp_cfg}"; chmod 640 "${tmp_cfg}"
  mv -f "${tmp_cfg}" "${cfg}"
fi

(cd "${BACKUP_DIR}" && sha256sum "$(basename "${dump}")" ${cfg:+"$(basename "${cfg}")"} 2>/dev/null >"${dump}.sha256" || true)
chgrp vaak-backup "${dump}.sha256"; chmod 640 "${dump}.sha256"

find "${BACKUP_DIR}" -maxdepth 1 -type f \( -name 'novalandia-*.dump' -o -name 'mkultra-config-*.tar.gz' -o -name 'novalandia-*.dump.sha256' \) -mtime "+${RETENTION_DAYS}" -delete
echo "backup_ok db=${dump} config=$([[ -s "${cfg}" ]] && echo yes || echo no) profile_media=$([[ -d "${PROFILE_MEDIA_DIR}" ]] && echo yes || echo no) retention_days=${RETENTION_DAYS}"
