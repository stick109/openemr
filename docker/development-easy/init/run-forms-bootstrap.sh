#!/bin/sh
# Dev-easy one-shot init: register the Week 2 "Upload Document (Co-Pilot)"
# encounter form so a fresh `docker compose up` lands with the form ready
# to use, with no manual click in Admin -> Forms -> Forms Administration.
#
# This runs in a small mariadb-client sidecar service that depends on the
# openemr service being healthy (which means auto_configure.php has finished
# building the core schema). At that point the `registry` and
# `form_upload_intake_form*` tables either already exist (if a previous run
# created them) or are about to be created here. Both `register-week2-forms.sql`
# statements are idempotent guards so re-runs are no-ops.
#
# DEV-EASY ONLY. Production installs use sql/8_1_0-to-8_1_1_upgrade.sql.

set -eu

DB_HOST="${MYSQL_HOST:-mysql}"
DB_PORT="${MYSQL_PORT:-3306}"
DB_USER="${MYSQL_USER:-openemr}"
DB_PASS="${MYSQL_PASS:-openemr}"
DB_NAME="${MYSQL_DATABASE:-openemr}"

echo "[forms-bootstrap] Waiting for ${DB_NAME} on ${DB_HOST}:${DB_PORT} to be reachable as ${DB_USER}..."

# The openemr healthcheck guarantees install completed, but we still poll the
# DB connection because the openemr healthcheck races with the openemr user
# being granted privileges on first boot.
#
# Cap retries so a misconfigured stack fails the service rather than spinning
# silently. 60 retries * 5s = 5 minutes — more than enough headroom on top of
# the openemr 3 minute healthcheck start period.
attempt=0
max_attempts=60
until mariadb \
        --skip-ssl \
        --host "${DB_HOST}" \
        --port "${DB_PORT}" \
        --user "${DB_USER}" \
        --password="${DB_PASS}" \
        --execute "SELECT 1 FROM registry LIMIT 1" \
        "${DB_NAME}" >/dev/null 2>&1
do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge "${max_attempts}" ]; then
        echo "[forms-bootstrap] ERROR: gave up waiting for ${DB_NAME} after ${attempt} attempts"
        exit 1
    fi
    echo "[forms-bootstrap] DB not ready yet (attempt ${attempt}/${max_attempts}); sleeping 5s..."
    sleep 5
done

echo "[forms-bootstrap] DB is ready; running register-week2-forms.sql"

mariadb \
    --skip-ssl \
    --host "${DB_HOST}" \
    --port "${DB_PORT}" \
    --user "${DB_USER}" \
    --password="${DB_PASS}" \
    "${DB_NAME}" \
    < /init/register-week2-forms.sql

echo "[forms-bootstrap] Verifying registration:"
mariadb \
    --skip-ssl \
    --host "${DB_HOST}" \
    --port "${DB_PORT}" \
    --user "${DB_USER}" \
    --password="${DB_PASS}" \
    "${DB_NAME}" \
    --execute "SELECT name, directory, state FROM registry WHERE directory = 'upload_intake_form';"

echo "[forms-bootstrap] Done."
