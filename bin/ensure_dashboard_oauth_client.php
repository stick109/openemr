<?php

/**
 * ensure_dashboard_oauth_client.php
 *
 * Post-deploy idempotent shim: makes sure the .NET Modern Dashboard's OAuth
 * client row exists in `oauth_clients` and is keyed off the values currently
 * sitting in the `DASHBOARD_OIDC_CLIENT_ID` / `DASHBOARD_OIDC_CLIENT_SECRET`
 * env vars on this exact OpenEMR instance.
 *
 * Why this script needs to run inside the running container (and not against
 * the public MySQL endpoint from the deploy host): `oauth_clients.client_secret`
 * is stored encrypted with the OpenEMR site's per-instance key (CryptoGen).
 * Copying an encrypted blob from another environment leaves the row
 * undecryptable here, which surfaces at token-exchange time as
 * "Decryption failed HMAC authentication" / `invalid_client`. Running the
 * encryption inside the container picks the local key up automatically.
 *
 * Idempotency: an UPDATE is issued when a row with the env's `client_id`
 * already exists (most common case, after the initial first-deploy);
 * otherwise an INSERT is issued. Either way the row ends up in a known-good
 * state with the env-var plaintext encrypted using this instance's key.
 *
 * BOM defense: env vars pasted from a UTF-8-with-BOM source can carry a
 * leading U+FEFF that breaks downstream string comparisons / HTTP headers.
 * The same trim mask used by `OpenAIClient::getApiKey()` is applied here.
 *
 * Invoked by `deploy-railway.ps1` via `railway ssh openemr-web` after the
 * service redeploys. Run order matters: the script depends on the running
 * code being able to load `OpenEMR\\Common\\Crypto\\CryptoGen`, so the
 * deploy must complete before this fires.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

const TRIM_MASK = " \t\n\r\0\x0B\xEF\xBB\xBF";

const DEFAULT_CLIENT_NAME = 'Dashboard .NET';
const DEFAULT_GRANT_TYPES = 'authorization_code refresh_token';
const DEFAULT_SCOPE = 'openid fhirUser offline_access api:fhir '
    . 'user/Patient.rs user/AllergyIntolerance.rs user/Condition.rs '
    . 'user/MedicationRequest.rs user/CareTeam.rs user/Encounter.rs';

// We do not want a HTML/login redirect just because a globals require chain
// noticed an unauthenticated CLI session.
$ignoreAuth = true;
require_once __DIR__ . '/../interface/globals.php';

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Database\QueryUtils;

function envTrimmed(string $name): string
{
    $raw = getenv($name);
    if ($raw === false) {
        return '';
    }
    return trim((string) $raw, TRIM_MASK);
}

$clientId = envTrimmed('DASHBOARD_OIDC_CLIENT_ID');
$plainSecret = envTrimmed('DASHBOARD_OIDC_CLIENT_SECRET');
$redirectUri = envTrimmed('DASHBOARD_OIDC_REDIRECT_URI');
$clientName = envTrimmed('DASHBOARD_OIDC_CLIENT_NAME');

if ($clientId === '' || $plainSecret === '' || $redirectUri === '') {
    fwrite(
        STDERR,
        "ensure_dashboard_oauth_client: required env vars missing.\n"
        . "  DASHBOARD_OIDC_CLIENT_ID set? "
            . ($clientId !== '' ? 'yes' : 'no') . "\n"
        . "  DASHBOARD_OIDC_CLIENT_SECRET set? "
            . ($plainSecret !== '' ? 'yes' : 'no') . "\n"
        . "  DASHBOARD_OIDC_REDIRECT_URI set? "
            . ($redirectUri !== '' ? 'yes' : 'no') . "\n"
        . "Skipping; dashboard handoff will not work until these are set.\n"
    );
    exit(2);
}

if ($clientName === '') {
    $clientName = DEFAULT_CLIENT_NAME;
}

$crypto = new CryptoGen();
$encryptedSecret = $crypto->encryptStandard($plainSecret);

$existing = QueryUtils::fetchSingleValue(
    'SELECT client_id FROM oauth_clients WHERE client_id = ?',
    'client_id',
    [$clientId]
);

if ($existing !== null) {
    $rows = QueryUtils::sqlStatementThrowException(
        'UPDATE oauth_clients
            SET client_secret = ?,
                client_name   = ?,
                redirect_uri  = ?,
                grant_types   = ?,
                scope         = ?,
                is_confidential = 1,
                is_enabled    = 1,
                client_role   = COALESCE(NULLIF(client_role, \'\'), \'user\')
          WHERE client_id = ?',
        [
            $encryptedSecret,
            $clientName,
            $redirectUri,
            DEFAULT_GRANT_TYPES,
            DEFAULT_SCOPE,
            $clientId,
        ]
    );
    fwrite(STDOUT, "ensure_dashboard_oauth_client: updated existing row for client_id={$clientId}\n");
    exit(0);
}

QueryUtils::sqlStatementThrowException(
    'INSERT INTO oauth_clients
        (client_id, client_role, client_name, client_secret,
         redirect_uri, grant_types, scope,
         is_confidential, is_enabled, register_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())',
    [
        $clientId,
        'user',
        $clientName,
        $encryptedSecret,
        $redirectUri,
        DEFAULT_GRANT_TYPES,
        DEFAULT_SCOPE,
    ]
);
fwrite(STDOUT, "ensure_dashboard_oauth_client: inserted new row for client_id={$clientId}\n");
exit(0);
