<?php

/**
 * AgentServiceException
 *
 * Thrown when the Python agent-service sidecar returns an error response
 * or is unreachable. Carries the machine-readable error code and detail
 * from the sidecar's JSON error envelope so callers can log or surface
 * the failure without parsing raw HTTP bodies.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use RuntimeException;
use Throwable;

final class AgentServiceException extends RuntimeException
{
    /**
     * @param string      $message   Human-readable summary for logging.
     * @param string      $errorCode Machine-readable error code from the
     *                               sidecar response (e.g. "extraction_failed",
     *                               "unauthorized"). Empty when the sidecar
     *                               was unreachable.
     * @param string      $detail    The `detail` field from the sidecar
     *                               error envelope, or an empty string.
     * @param string      $traceId   The `trace_id` echoed back by the sidecar,
     *                               or the request-side trace ID if the
     *                               sidecar never responded.
     * @param int         $httpStatus HTTP status code (0 for network errors).
     * @param ?Throwable  $previous  The underlying transport exception, if any.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = '',
        public readonly string $detail = '',
        public readonly string $traceId = '',
        public readonly int $httpStatus = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
