<?php

declare(strict_types=1);

/**
 * Re-entry shim for users returning to the OpenEMR tabbed UI from an
 * external page (e.g. the .NET Modern Dashboard).
 *
 * Mints a fresh token_main_php in the active session and 302-redirects to
 * interface/main/tabs/main.php — the same handoff that main_screen.php
 * performs at the end of the login flow. Authentication is enforced by
 * globals.php (auth.inc.php), so anonymous callers are bounced to login.
 *
 * Direct deep-linking to tabs/main.php from outside is not viable: that
 * file requires the session-bound token_main GET param and bounces the
 * user to the login screen when it is missing. main_info.php is also
 * unsuitable here because it always redirects to the calendar, not to the
 * tabbed shell that the user saw immediately after login.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Side-channel session restore. modern_dashboard.php sets an encrypted
// OpenEMR-Restore cookie before navigating the user out to the .NET
// dashboard. On Railway the dashboard origin is on a different Public-
// Suffix-List "site" from openemr-web, so the SameSite=Strict OpenEMR core
// session cookie is suppressed when the user clicks "Back to OpenEMR" — we
// land here without a usable server-side session and globals.php's auth
// check would bounce the user to the login screen. The side-channel cookie
// is SameSite=None Secure (or Lax for HTTP local dev), so it survives the
// cross-site click. We decrypt it and write the original auth keys into
// the core session BEFORE requiring globals.php so that auth.inc.php's
// authCheckSession() recognizes the session as authenticated.
//
// Run before any session is started or globals.php is required because
// session_start() once the cookie response header is locked in cannot be
// changed; we must set our keys into the same session id PHP will
// auto-pick from the OpenEMR cookie.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Session\SessionRestoreCookie;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Utils\RandomGenUtils;

$sessionAllowWrite = true;

// site_id is required by globals.php before it does anything else. The
// only multi-site scenarios in this codebase pass $_GET['site'] explicitly,
// so seeding 'default' when no site is in the URL is safe and lets
// globals.php finish parsing instead of dying with the "Site ID is
// missing" message.
if (empty($_GET['site'])) {
    $_GET['site'] = 'default';
}

// Determine the site dir CryptoGen needs for its drive-key lookup. We
// can't ask globals.php for it yet because globals.php hasn't run; the
// path it would compute is OE_SITES_BASE/<site>, where OE_SITES_BASE
// defaults to <repo>/sites and <site> is the value above.
$restoreCookieValue = $_COOKIE[SessionRestoreCookie::COOKIE_NAME] ?? null;
if (is_string($restoreCookieValue) && $restoreCookieValue !== '') {
    try {
        $sitesBase = dirname(__DIR__, 2) . '/sites';
        $siteId = (string) $_GET['site'];
        // Reject site values containing path-separator characters before
        // building a filesystem path; mirrors globals.php's own validation.
        if (preg_match('/^[A-Za-z0-9\-.]+$/', $siteId) === 1 && is_dir($sitesBase . '/' . $siteId)) {
            $cryptoGen = new CryptoGen(siteDir: $sitesBase . '/' . $siteId);
            $restoreCookie = new SessionRestoreCookie($cryptoGen, time());
            $restored = $restoreCookie->decode($restoreCookieValue);
            if (is_array($restored) && !empty($restored['authUserID']) && !empty($restored['authUser']) && !empty($restored['authPass'])) {
                // Reseed the core session by hand. Mirror the cookie
                // attributes SessionConfigurationBuilder::forCore would set
                // so the session_start below either picks up the existing
                // OpenEMR cookie (when present) or mints a fresh one with
                // the same path/samesite/etc. that subsequent requests
                // expect.
                if (session_status() === PHP_SESSION_NONE) {
                    session_name(SessionUtil::CORE_SESSION_ID);
                    $cookieParams = [
                        'lifetime' => 0,
                        'path' => '/',
                        'domain' => '',
                        'secure' => false,
                        'httponly' => false,
                        'samesite' => 'Strict',
                    ];
                    session_set_cookie_params($cookieParams);
                    @session_start();
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    foreach ($restored as $sessionKey => $sessionValue) {
                        if (!in_array($sessionKey, SessionRestoreCookie::getRestoredKeys(), true)) {
                            continue;
                        }
                        $_SESSION[$sessionKey] = $sessionValue;
                    }
                    // Ensure site_id is set so globals.php's later check
                    // doesn't trip; redundant when the restored payload
                    // carried site_id but harmless.
                    if (empty($_SESSION['site_id'])) {
                        $_SESSION['site_id'] = $siteId;
                    }
                    session_write_close();
                }
                // Clear the restore cookie now that it's been consumed —
                // single-use semantics keep replay windows tight.
                $clearAttrs = [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
                    'httponly' => true,
                    'samesite' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                        ? 'None'
                        : 'Lax',
                ];
                setcookie(SessionRestoreCookie::COOKIE_NAME, '', $clearAttrs);
            }
        }
    } catch (\Throwable $restoreException) {
        // Fall through to the regular auth check; the user will get the
        // login screen as before this fix.
        error_log('SessionRestoreCookie restore failed: ' . $restoreException->getMessage());
    }
}

require_once(__DIR__ . '/../globals.php');

$session = SessionWrapperFactory::getInstance()->getActiveSession();

$tokenMainPhp = RandomGenUtils::createUniqueToken();
$session->set('token_main_php', $tokenMainPhp);

header('Location: ' . $web_root . '/interface/main/tabs/main.php?token_main=' . urlencode($tokenMainPhp));
exit();
