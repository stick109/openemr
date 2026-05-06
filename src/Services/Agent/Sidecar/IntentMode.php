<?php

/**
 * IntentMode
 *
 * Enumerates the three possible routing modes for a Clinical Co-Pilot
 * intent during the M19 cutover from PHP to the Python sidecar:
 *
 * - Php:     legacy PHP path serves the user-visible response.
 * - Shadow:  legacy PHP serves the user, plus a parallel sidecar call is
 *            made for sanitized comparison logging only (M18).
 * - Sidecar: the sidecar serves the user-visible response (M17).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

enum IntentMode: string
{
    case Php = 'php';
    case Shadow = 'shadow';
    case Sidecar = 'sidecar';

    /**
     * Parse a string from configuration into an IntentMode. Trims and
     * normalizes case. Returns null when the input is not a recognized
     * mode value -- callers decide how to handle the unknown case.
     */
    public static function tryFromConfig(string $value): ?self
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }
        return self::tryFrom($normalized);
    }
}
