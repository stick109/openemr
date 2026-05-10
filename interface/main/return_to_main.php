<?php

declare(strict_types=1);

/**
 * Re-entry shim for users returning to the OpenEMR tabbed UI from an
 * external page (e.g. the .NET Modern Dashboard).
 *
 * Mints a fresh token_main_php in the active session and 302-redirects to
 * interface/main/tabs/main.php — the same handoff that main_screen.php
 * performs at the end of the login flow.
 *
 * Direct deep-linking to tabs/main.php from outside is not viable: that
 * file requires the session-bound token_main GET param and bounces the
 * user to the login screen when it is missing. main_info.php is also
 * unsuitable here because it always redirects to the calendar, not to the
 * tabbed shell that the user saw immediately after login.
 *
 * Cross-site SameSite cookie handling: when the user reaches us from the
 * .NET dashboard on a different Public-Suffix-List "site" (Railway prod),
 * the SameSite=Strict OpenEMR core cookie is suppressed by the browser, so
 * the active session is anonymous. modern_dashboard.php sets a SameSite=
 * None Secure side-channel cookie (OpenEMR-Restore) that survives the
 * cross-site click; we decrypt it after globals.php boots (so CryptoGen
 * has DB access for its key chain) and reseed the core session by hand.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;

// site_id is required by globals.php before it does anything else. The
// only multi-site scenarios in this codebase pass $_GET['site'] explicitly,
// so seeding 'default' when no site is in the URL is safe.
if (empty($_GET['site'])) {
    $_GET['site'] = 'default';
}

// Skip globals.php's auth.inc.php bounce so we can attempt the side-channel
// cookie restore even when the user lands without an authenticated session
// (the common case on Railway's cross-site Back to OpenEMR click). We do
// our own auth gate below: if the cookie restore (or the existing session)
// did not produce a valid authUserID we hand the user off to login.php.
$ignoreAuth = true;
require_once(__DIR__ . '/../globals.php');

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Session\SessionRestoreCookie;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Utils\RandomGenUtils;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

// Attempt side-channel restore only when the active session is anonymous.
// If the user already has authUserID (same-origin nav, or the SameSite=
// Strict cookie did make it through), skip the restore so we don't replace
// a fresh session with stale cookie data.
$alreadyAuthenticated = !empty($session->get('authUserID'))
    && !empty($session->get('authUser'))
    && !empty($session->get('authPass'));

if (!$alreadyAuthenticated) {
    $restoreCookieValue = $_COOKIE[SessionRestoreCookie::COOKIE_NAME] ?? null;
    if (is_string($restoreCookieValue) && $restoreCookieValue !== '') {
        try {
            // CryptoGen with siteDir from globals.php so the drive-key path
            // matches the one modern_dashboard.php used when encoding.
            $siteDir = $GLOBALS['OE_SITE_DIR'] ?? null;
            if (is_string($siteDir) && $siteDir !== '' && is_dir($siteDir)) {
                $cryptoGen = new CryptoGen(siteDir: $siteDir);
                $restoreCookie = new SessionRestoreCookie($cryptoGen, time());
                $restored = $restoreCookie->decode($restoreCookieValue);
                if (
                    is_array($restored)
                    && !empty($restored['authUserID'])
                    && !empty($restored['authUser'])
                    && !empty($restored['authPass'])
                ) {
                    // Reseed the active core session via the same wrapper
                    // globals.php handed us so the write goes through the
                    // same Symfony session/storage layer the rest of the app
                    // reads from. SessionUtil::setSession reopens read_and_
                    // close storage for writing internally.
                    $reseed = [];
                    foreach (SessionRestoreCookie::getRestoredKeys() as $key) {
                        if (array_key_exists($key, $restored) && $restored[$key] !== '') {
                            $reseed[$key] = $restored[$key];
                        }
                    }
                    if (empty($reseed['site_id'])) {
                        $reseed['site_id'] = (string) $_GET['site'];
                    }
                    SessionUtil::setSession($reseed);

                    $alreadyAuthenticated = true;

                    // Single-use: clear the cookie immediately so a stale
                    // copy can't be replayed if the request is repeated.
                    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                    setcookie(
                        SessionRestoreCookie::COOKIE_NAME,
                        '',
                        [
                            'expires' => time() - 3600,
                            'path' => '/',
                            'secure' => $isHttps,
                            'httponly' => true,
                            'samesite' => $isHttps ? 'None' : 'Lax',
                        ]
                    );
                }
            }
        } catch (\Throwable $restoreException) {
            // Fall through to login bounce; the user just re-authenticates
            // by hand, same outcome as before this code path existed.
            error_log('SessionRestoreCookie restore failed: ' . $restoreException->getMessage());
        }
    }
}

if (!$alreadyAuthenticated) {
    // No authenticated session and either no/expired/invalid restore cookie.
    // Bounce to the regular login screen.
    header('Location: ' . $web_root . '/interface/login/login.php?site=' . urlencode((string) $_GET['site']));
    exit();
}

// Re-read the active session after the optional reseed so the wrapper
// reflects the writes; getActiveSession() returns a singleton, but we
// fetch it again for clarity.
$session = SessionWrapperFactory::getInstance()->getActiveSession();

$tokenMainPhp = RandomGenUtils::createUniqueToken();
SessionUtil::setSession('token_main_php', $tokenMainPhp);

header('Location: ' . $web_root . '/interface/main/tabs/main.php?token_main=' . urlencode($tokenMainPhp));
exit();
