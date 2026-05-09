#!/bin/sh

# railway-startup.sh
#
# Runs the dashboard OAuth-client ensure script with the OpenEMR sites/
# volume mounted, then hands off to Apache. This wrapper replaces
# openemr.sh's final `exec /usr/sbin/httpd -D FOREGROUND` line via a sed
# patch in Dockerfile.railway.
#
# Why this is necessary: the OAuth client_secret is encrypted with
# CryptoGen, which derives part of its key from the per-site drive-side
# key files in `sites/default/documents/logs_and_misc/methods/`. Those
# files live on the persistent Railway volume that's mounted at
# `/var/www/localhost/htdocs/openemr/sites`. Railway's preDeployCommand
# runs in a separate ephemeral container without that volume mount, so
# CryptoGen there silently creates a fresh drive-side key, encrypts the
# client_secret with it, and writes the row to oauth_clients. The
# running container then tries to decrypt with the *real* drive-side key
# from the volume and HMAC fails ("invalid_client" / "Decryption failed
# HMAC authentication" on /token). Running the ensure script here -- in
# the live container, with the volume mounted -- means CryptoGen reads
# the same drive-side key that the token endpoint will later use to
# decrypt, so encryption and decryption agree.
#
# The script's exit code is intentionally swallowed (|| true): a failure
# here should not block Apache, since the dashboard handoff is one
# feature of many. Operators can still run the script manually if it
# fails repeatedly.

php /var/www/localhost/htdocs/openemr/bin/ensure_dashboard_oauth_client.php || true

exec /usr/sbin/httpd -D FOREGROUND
