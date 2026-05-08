#!/usr/bin/env bash
# Railway-specific container entrypoint. Wraps the base image's
# ./openemr.sh so we can re-sync OPENEMR_SETTING_* environment variables
# into the globals table on every container start.
#
# The pinned openemr/openemr base image only applies these settings on
# first boot, inside auto_setup. On subsequent restarts the database
# keeps whatever was last persisted, which means edits to Railway env
# vars (e.g. OPENEMR_SETTING_site_addr_oath) silently no-op until the
# database is wiped. This wrapper closes that gap.
set -euo pipefail

LOG_PREFIX="[railway-entrypoint]"
OE_ROOT="/var/www/localhost/htdocs/openemr"
SQLCONF="${OE_ROOT}/sites/default/sqlconf.php"
SYNC_SCRIPT="${OE_ROOT}/docker/railway/sync-openemr-settings.sh"

# Detect first boot the same way openemr.sh does: $config in sqlconf.php
# is 1 once auto_setup has finished, 0 (or the file is missing) before
# that. On first boot we hand straight off to the base entrypoint — its
# auto_setup will create the database and call setGlobalSettings itself,
# so re-running the sync here would just race the installer.
already_configured=0
if [[ -f "${SQLCONF}" ]]; then
    already_configured=$(php -r "require '${SQLCONF}'; echo (isset(\$config) && \$config) ? 1 : 0;" 2>/dev/null || echo 0)
fi

if [[ "${already_configured}" = "1" ]]; then
    echo "${LOG_PREFIX} Already-configured boot detected; refreshing OPENEMR_SETTING_* before handoff."
    if ! "${SYNC_SCRIPT}"; then
        # A failed refresh shouldn't take the site offline. Apache should
        # still come up serving the previous values; an operator can
        # re-run the sync via railway ssh if needed.
        echo "${LOG_PREFIX} WARN: sync script failed; continuing with stale globals." >&2
    fi
else
    echo "${LOG_PREFIX} First-boot or unconfigured state; deferring to base auto_setup for initial OPENEMR_SETTING_* apply."
fi

cd "${OE_ROOT}"
exec ./openemr.sh
