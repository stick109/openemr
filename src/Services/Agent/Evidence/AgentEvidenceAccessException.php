<?php

/**
 * AgentEvidenceAccessException
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Evidence;

use RuntimeException;

final class AgentEvidenceAccessException extends RuntimeException
{
    public function __construct(
        private readonly string $reasonCode,
        private readonly string $publicMessage
    ) {
        parent::__construct($publicMessage);
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }

    public function getPublicMessage(): string
    {
        return $this->publicMessage;
    }
}
