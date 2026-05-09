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

$sessionAllowWrite = true;
require_once(__DIR__ . '/../globals.php');

use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Utils\RandomGenUtils;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

$tokenMainPhp = RandomGenUtils::createUniqueToken();
$session->set('token_main_php', $tokenMainPhp);

header('Location: ' . $web_root . '/interface/main/tabs/main.php?token_main=' . urlencode($tokenMainPhp));
exit();
