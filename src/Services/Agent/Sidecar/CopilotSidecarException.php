<?php

/**
 * CopilotSidecarException
 *
 * Thrown by {@see CopilotSidecarClient} when the Python sidecar's
 * ``POST /api/copilot/run`` endpoint cannot be invoked successfully.
 * The exception carries a typed ``reason`` so the PHP route can
 * differentiate between context rejection, sidecar-not-ready (the
 * current M13 stub), and generic transport/protocol failures
 * without leaking provider error messages or PHI.
 *
 * Allowed reason codes:
 *
 *   - ``context_rejected``    HTTP 401 -- the signed run context was
 *                             invalid, expired, or signed with a key
 *                             the sidecar did not recognize.
 *   - ``sidecar_not_ready``   HTTP 501 -- the sidecar accepted the
 *                             request but the agent loop is not yet
 *                             implemented (M13 placeholder).
 *   - ``sidecar_error``       Any other transport, HTTP, or response
 *                             parsing failure. The route surfaces this
 *                             as a generic "service unavailable" to
 *                             the UI; details are logged but never
 *                             returned to the browser.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use RuntimeException;
use Throwable;

final class CopilotSidecarException extends RuntimeException
{
    public const REASON_CONTEXT_REJECTED = 'context_rejected';
    public const REASON_SIDECAR_NOT_READY = 'sidecar_not_ready';
    public const REASON_SIDECAR_ERROR = 'sidecar_error';

    /**
     * @param string     $message    Generic, log-safe message. Never echoed to the UI.
     * @param string     $reason     One of the REASON_* constants above.
     * @param int        $httpStatus HTTP status returned by the sidecar; ``0`` for transport errors.
     * @param ?Throwable $previous   Underlying transport or parsing exception, if any.
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $httpStatus = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
