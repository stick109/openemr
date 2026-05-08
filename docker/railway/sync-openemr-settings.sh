#!/usr/bin/env bash
# Upsert every OPENEMR_SETTING_* environment variable into the OpenEMR
# globals table.
#
# Why this exists: the openemr/openemr:latest base image only applies
# OPENEMR_SETTING_* values inside its first-boot auto_setup path. After
# initial install the auto_configure.php script is removed and that branch
# never runs again, so a Railway container restart leaves the globals row
# at whatever value was last persisted to the database. This script is
# called from docker/railway/entrypoint.sh on every container start so
# that env-var-driven settings (e.g. site_addr_oath) stay authoritative.
#
# Idempotent: rerunning with the same env vars produces no observable
# change. Uses INSERT ... ON DUPLICATE KEY UPDATE so it works whether the
# row already exists or not.
set -euo pipefail

LOG_PREFIX="[railway-settings-sync]"

# Default to the same connection variables the base image's openemr.sh
# uses, so a single set of env vars drives both paths.
MYSQL_HOST="${MYSQL_HOST:-mysql}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-openemr}"
MYSQL_PASS="${MYSQL_PASS:-openemr}"
MYSQL_DATABASE="${MYSQL_DATABASE:-openemr}"

# Wait for the globals table to be reachable. Checking the table directly
# (rather than just mysqladmin ping) lets us also ride out the gap on a
# fresh deployment where mysqld is up but the openemr database hasn't
# been created yet — in that case the entrypoint shouldn't have called
# us in the first place, but guarding here keeps the script safe to run
# from anywhere.
echo "${LOG_PREFIX} Waiting for ${MYSQL_DATABASE}.globals at ${MYSQL_HOST}:${MYSQL_PORT}..."
retries=60
delay=2
until mariadb --skip-ssl --connect-timeout=5 \
        -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" \
        -u "${MYSQL_USER}" --password="${MYSQL_PASS}" \
        -e "SELECT 1 FROM globals LIMIT 1" \
        "${MYSQL_DATABASE}" >/dev/null 2>&1; do
    if (( retries-- == 0 )); then
        echo "${LOG_PREFIX} ERROR: ${MYSQL_DATABASE}.globals not reachable after retries; aborting sync." >&2
        exit 1
    fi
    sleep "${delay}"
done
echo "${LOG_PREFIX} Database reachable; syncing OPENEMR_SETTING_* env vars."

# Collect every OPENEMR_SETTING_* env var. Same printenv-grep pattern the
# upstream setGlobalSettings function uses, so behavior matches what the
# base image does on first boot. Values containing literal newlines would
# break this loop, but OPENEMR_SETTING_* values are simple strings (URLs,
# names, ports) and the upstream code has the same limitation.
mapfile -t lines < <(printenv | grep '^OPENEMR_SETTING_' || true)
if (( ${#lines[@]} == 0 )); then
    echo "${LOG_PREFIX} No OPENEMR_SETTING_* env vars present; nothing to sync."
    exit 0
fi

count=0
for line in "${lines[@]}"; do
    name="${line%%=*}"
    value="${line#*=}"
    setting="${name#OPENEMR_SETTING_}"

    # Defensive: a literal "OPENEMR_SETTING_=" with empty suffix would
    # produce an empty gl_name and corrupt the table.
    if [[ -z "${setting}" ]]; then
        continue
    fi

    # Single-quote escaping per SQL standard: doubling an apostrophe
    # inside a single-quoted string yields a literal apostrophe. The
    # values come from Railway env vars (operator-provided), not end-user
    # input, so the trust boundary is the platform.
    escaped_setting="${setting//\'/\'\'}"
    escaped_value="${value//\'/\'\'}"

    # gl_index = 0 matches what the upstream setGlobalSettings would
    # write. Settings that have multiple indexed rows (e.g. background
    # service lists) aren't representable as a single env var anyway.
    mariadb --skip-ssl \
        -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" \
        -u "${MYSQL_USER}" --password="${MYSQL_PASS}" \
        "${MYSQL_DATABASE}" \
        -e "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('${escaped_setting}', 0, '${escaped_value}') ON DUPLICATE KEY UPDATE gl_value = VALUES(gl_value);"
    echo "${LOG_PREFIX} ${setting} synced."
    count=$((count + 1))
done

echo "${LOG_PREFIX} Done; ${count} setting(s) synced."
