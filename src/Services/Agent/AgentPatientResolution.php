<?php

/**
 * AgentPatientResolution
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

final class AgentPatientResolution
{
    private function __construct(
        private readonly bool $allowed,
        private readonly ?AgentPatientContext $patientContext,
        private readonly string $reasonCode,
        private readonly string $publicMessage
    ) {
    }

    public static function allowed(AgentPatientContext $patientContext): self
    {
        return new self(true, $patientContext, 'allowed', '');
    }

    public static function denied(string $reasonCode, string $publicMessage): self
    {
        return new self(false, null, $reasonCode, $publicMessage);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getPatientContext(): ?AgentPatientContext
    {
        return $this->patientContext;
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
