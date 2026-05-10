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
 * Bootstrap strategy: this script deliberately AVOIDS interface/globals.php.
 * That bootstrap is web-context only — it dies on missing HTTP_HOST, depends
 * on sites/default/sqlconf.php for DB credentials (which can drift from the
 * Railway env vars), and pulls in hundreds of unrelated dependencies. The
 * preDeployCommand environment isn't on the same private network shape as
 * the running app container in some Railway scenarios, so we connect via the
 * MYSQL_* env vars directly with PDO and instantiate CryptoGen with an
 * explicit siteDir + null logger so it has no other dependencies.
 *
 * Idempotency: an UPDATE is issued when a row with the env's `client_id`
 * already exists; otherwise an INSERT is issued. Either way the row ends up
 * in a known-good state with the env-var plaintext encrypted using *this*
 * instance's drive + database keys.
 *
 * BOM defense: env vars pasted from a UTF-8-with-BOM source can carry a
 * leading U+FEFF that breaks string comparisons / HTTP headers. The same
 * trim mask used by OEEnvBag is applied here.
 *
 * Invoked by Railway's `preDeployCommand` (see railway.toml).
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
    . 'user/MedicationRequest.rs user/CareTeam.rs user/Encounter.rs '
    . 'user/Coverage.rs';

require_once __DIR__ . '/../vendor/autoload.php';

use OpenEMR\Common\Crypto\CryptoGen;
use Psr\Log\NullLogger;

/**
 * Adapter that lets CryptoGen run without the legacy `library/sql.inc.php`
 * being included. The real CryptoGen reads/creates per-site DB keys via
 * the global `sqlQueryNoLog()` / `sqlStatementNoLog()` helpers; this
 * subclass overrides the protected wrappers and routes them through a
 * vanilla PDO connection instead, keeping the script free of OpenEMR's
 * web-context bootstrap.
 */
final class StandaloneCryptoGen extends CryptoGen
{
    public function __construct(
        private readonly PDO $pdo,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?string $siteDir = null,
    ) {
        parent::__construct($logger, $siteDir);
    }

    /**
     * @param array<int, mixed> $binds
     * @return array<string, mixed>|false
     */
    protected function sqlQueryNoLog(string $statement, array $binds = []): array|false
    {
        $stmt = $this->pdo->prepare($statement);
        $stmt->execute($binds);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? false : $row;
    }

    /**
     * @param array<int, mixed> $binds
     */
    protected function sqlStatementNoLog(string $statement, array $binds = []): mixed
    {
        $stmt = $this->pdo->prepare($statement);
        $stmt->execute($binds);
        return $stmt;
    }
}

function envTrimmed(string $name): string
{
    $raw = getenv($name);
    if ($raw === false) {
        return '';
    }
    return trim((string) $raw, TRIM_MASK);
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "ensure_dashboard_oauth_client: {$message}\n");
    exit($code);
}

$clientId = envTrimmed('DASHBOARD_OIDC_CLIENT_ID');
$plainSecret = envTrimmed('DASHBOARD_OIDC_CLIENT_SECRET');
$redirectUri = envTrimmed('DASHBOARD_OIDC_REDIRECT_URI');
$clientName = envTrimmed('DASHBOARD_OIDC_CLIENT_NAME');

if ($clientId === '' || $plainSecret === '' || $redirectUri === '') {
    fail(
        "DASHBOARD_OIDC_CLIENT_ID/SECRET/REDIRECT_URI must all be set; "
        . "skipping (dashboard handoff will not work until these are set)."
    );
}

if ($clientName === '') {
    $clientName = DEFAULT_CLIENT_NAME;
}

// MySQL credentials. preDeployCommand on Railway runs in a transient
// container whose private-network access is not guaranteed in every
// region — use MYSQL_PUBLIC_URL (the external proxy hostname) when set,
// since that's reachable from anywhere with internet egress and the
// running app container can also use it. Fall back to MYSQL_URL or the
// individual MYSQL_* vars when no public URL is exposed.
$mysqlHost = '';
$mysqlPort = '3306';
$mysqlUser = '';
$mysqlPass = '';
$mysqlDb = 'openemr';

// Database name: prefer the explicit env var (Railway sets MYSQL_DATABASE),
// fall back to 'openemr' so a stray URL with /railway as the default DB
// path doesn't make us connect to the wrong schema.
$explicitDb = envTrimmed('MYSQL_DATABASE');
$urlCandidates = [envTrimmed('MYSQL_PUBLIC_URL'), envTrimmed('MYSQL_URL')];
foreach ($urlCandidates as $url) {
    if ($url === '') {
        continue;
    }
    if (preg_match('#^mysql://([^:]+):([^@]+)@([^:/]+)(?::(\d+))?/(.*)$#', $url, $m) === 1) {
        $mysqlUser = $m[1];
        $mysqlPass = $m[2];
        $mysqlHost = $m[3];
        $mysqlPort = $m[4] ?? '3306';
        $mysqlDb = $explicitDb !== '' ? $explicitDb : ($m[5] !== '' ? $m[5] : 'openemr');
        break;
    }
}

if ($mysqlHost === '') {
    $mysqlHost = envTrimmed('MYSQL_HOST');
    $mysqlPort = envTrimmed('MYSQL_PORT') ?: '3306';
    $mysqlUser = envTrimmed('MYSQL_USER');
    $mysqlPass = envTrimmed('MYSQL_PASS') ?: envTrimmed('MYSQL_PASSWORD') ?: envTrimmed('MYSQLPASSWORD');
    $mysqlDb = $explicitDb !== '' ? $explicitDb : 'openemr';
}

if ($mysqlHost === '' || $mysqlUser === '' || $mysqlPass === '') {
    fail("MySQL connection env vars missing (need MYSQL_PUBLIC_URL/MYSQL_URL or MYSQL_HOST/USER/PASS).");
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $mysqlHost, $mysqlPort, $mysqlDb),
        $mysqlUser,
        $mysqlPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    fail("MySQL connect failed: " . $e->getMessage());
}

// Diagnostic marker: stamp client_name with a timestamp before we attempt
// encryption, so MySQL inspection alone can confirm whether the script ran
// (and how far it got). Updated again to the real client name on success.
try {
    $diag = $pdo->prepare(
        'UPDATE oauth_clients SET client_name = ? WHERE client_id = ?'
    );
    $diag->execute(['DEBUG_PDO_OK_' . date('His'), $clientId]);
} catch (\Throwable $ignored) {
    // Best-effort; failure to update the marker should not abort the run.
}

// Encrypt the plaintext secret with THIS instance's CryptoGen so the row
// is decryptable on this exact site. siteDir is provided explicitly so we
// don't need OEGlobalsBag (which globals.php would otherwise initialize).
$siteDir = __DIR__ . '/../sites/default';
$crypto = new StandaloneCryptoGen($pdo, new NullLogger(), $siteDir);

try {
    $encryptedSecret = $crypto->encryptStandard($plainSecret);
} catch (\Throwable $e) {
    // Capture the error text into client_name so we can diagnose without
    // shell access. Truncated to fit varchar(80) and stripped of newlines.
    try {
        $diag = $pdo->prepare('UPDATE oauth_clients SET client_name = ? WHERE client_id = ?');
        $marker = 'DEBUG_ENC_FAIL: ' . str_replace(["\r","\n"], ' ', $e->getMessage());
        $diag->execute([substr($marker, 0, 80), $clientId]);
    } catch (\Throwable $ignored) {
    }
    fail("CryptoGen->encryptStandard failed: " . $e->getMessage());
}

$existing = $pdo->prepare('SELECT client_id FROM oauth_clients WHERE client_id = ?');
$existing->execute([$clientId]);
$found = $existing->fetchColumn();

if ($found !== false) {
    $stmt = $pdo->prepare(
        'UPDATE oauth_clients
            SET client_secret = ?,
                client_name   = ?,
                redirect_uri  = ?,
                grant_types   = ?,
                scope         = ?,
                is_confidential = 1,
                is_enabled    = 1,
                client_role   = COALESCE(NULLIF(client_role, \'\'), \'user\')
          WHERE client_id = ?'
    );
    $stmt->execute([
        $encryptedSecret,
        $clientName,
        $redirectUri,
        DEFAULT_GRANT_TYPES,
        DEFAULT_SCOPE,
        $clientId,
    ]);
    fwrite(STDOUT, "ensure_dashboard_oauth_client: updated existing row for client_id={$clientId}\n");
    exit(0);
}

$stmt = $pdo->prepare(
    'INSERT INTO oauth_clients
        (client_id, client_role, client_name, client_secret,
         redirect_uri, grant_types, scope,
         is_confidential, is_enabled, register_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())'
);
$stmt->execute([
    $clientId,
    'user',
    $clientName,
    $encryptedSecret,
    $redirectUri,
    DEFAULT_GRANT_TYPES,
    DEFAULT_SCOPE,
]);
fwrite(STDOUT, "ensure_dashboard_oauth_client: inserted new row for client_id={$clientId}\n");
exit(0);
