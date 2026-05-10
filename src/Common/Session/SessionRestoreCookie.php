<?php

declare(strict_types=1);

/**
 * Side-channel session restore cookie.
 *
 * When the user clicks a link in the OpenEMR menu that takes them to the .NET
 * Modern Dashboard (a different Railway service / different "site" by the
 * Public Suffix List rules) and then later clicks "Back to OpenEMR" to return,
 * the cross-site click drops or replaces the OpenEMR core session cookie
 * (SameSite=Strict + cross-site request semantics on Railway). The user lands
 * on interface/main/return_to_main.php with no usable server-side session and
 * gets bounced to the login screen even though they were logged in before
 * leaving for the dashboard.
 *
 * This class implements a side-channel restore: modern_dashboard.php captures
 * the active session's auth keys, encrypts them with the site's CryptoGen
 * drive key (so the cookie is opaque + tamper-evident), and stores them in a
 * short-lived SameSite=None cookie. return_to_main.php reads the cookie,
 * decrypts, and reseeds the core session before globals.php's auth check
 * runs. The original OAuth session machinery is not perturbed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Session;

use ArrayAccess;
use OpenEMR\Common\Crypto\CryptoGen;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class SessionRestoreCookie
{
    /**
     * Cookie name. Distinct from the regular OpenEMR core session cookie
     * (CORE_SESSION_ID = "OpenEMR") so the two never collide.
     */
    public const COOKIE_NAME = 'OpenEMR-Restore';

    /**
     * How long the side-channel cookie / blob remains valid. Long enough for
     * the user to read the dashboard and click back, short enough that a
     * stolen cookie cannot be replayed long after the user has left.
     */
    public const TTL_SECONDS = 600;

    /**
     * Session keys to capture and restore. Only the minimum globals.php and
     * auth.inc.php need to recognize the user as authenticated — auth check
     * looks at authUserID/authUser/authPass, SessionTracker checks
     * session_database_uuid, globals.php uses site_id, and pid is preserved
     * so the patient context survives the round trip back to the tabbed UI.
     *
     * @var list<string>
     */
    private const RESTORED_KEYS = [
        'authUser',
        'authUserID',
        'authPass',
        'authProvider',
        'authProviderID',
        'userauthorized',
        'site_id',
        'pid',
        'language_choice',
        'language_direction',
        'session_database_uuid',
    ];

    public function __construct(
        private readonly CryptoGen $cryptoGen,
        private readonly int $now,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function getRestoredKeys(): array
    {
        return self::RESTORED_KEYS;
    }

    /**
     * Encrypt the given session data into a base64 token suitable for use as
     * a cookie value. Adds a `ts` timestamp the consumer uses to enforce
     * TTL_SECONDS on decode.
     *
     * @param array<string, mixed> $sessionData
     */
    public function encode(array $sessionData): string
    {
        $payload = [
            'ts' => $this->now,
            'data' => $sessionData,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        return $this->cryptoGen->encryptStandard($json);
    }

    /**
     * Decrypt and validate the cookie value. Returns the original session data
     * on success or null if the value is empty, fails decryption, fails JSON
     * parse, or has aged past TTL_SECONDS. The TTL check is intentional — a
     * stale cookie should be ignored so the user reaches the login screen and
     * can re-authenticate explicitly.
     *
     * @return array<string, mixed>|null
     */
    public function decode(?string $cookieValue): ?array
    {
        if ($cookieValue === null || $cookieValue === '') {
            return null;
        }

        $decrypted = $this->cryptoGen->decryptStandard($cookieValue);
        if ($decrypted === false || $decrypted === '') {
            return null;
        }

        try {
            $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }
        $ts = $payload['ts'] ?? null;
        $data = $payload['data'] ?? null;
        if (!is_int($ts) || !is_array($data)) {
            return null;
        }

        $age = $this->now - $ts;
        if ($age < 0 || $age > self::TTL_SECONDS) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Build the array of session keys/values to persist into the side-channel
     * cookie. Only keys present in $session are captured; missing keys are
     * skipped rather than encoded as null so the consumer never overwrites a
     * present session value with null on restore.
     *
     * @param SessionInterface|array<string, mixed>|ArrayAccess<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function buildPayloadFromSession(SessionInterface|array|ArrayAccess $session): array
    {
        $captured = [];
        foreach (self::RESTORED_KEYS as $key) {
            if ($session instanceof SessionInterface) {
                if ($session->has($key)) {
                    $value = $session->get($key);
                    if ($value !== null && $value !== '') {
                        $captured[$key] = $value;
                    }
                }
                continue;
            }

            // Array or ArrayAccess.
            if (isset($session[$key]) && $session[$key] !== '') {
                $captured[$key] = $session[$key];
            }
        }

        return $captured;
    }
}
