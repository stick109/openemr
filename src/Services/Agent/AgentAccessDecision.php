<?php

/**
 * AgentAccessDecision
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

final class AgentAccessDecision
{
    private function __construct(
        private readonly bool $allowed,
        private readonly string $intentId,
        private readonly ?AgentPatientContext $patientContext,
        private readonly ?AgentAccessToken $accessToken,
        private readonly string $reasonCode,
        private readonly string $publicMessage
    ) {
    }

    public static function allowed(string $intentId, AgentAccessToken $accessToken): self
    {
        return new self(true, $intentId, $accessToken->getPatientContext(), $accessToken, 'allowed', '');
    }

    public static function denied(
        string $intentId,
        string $reasonCode,
        string $publicMessage,
        ?AgentPatientContext $patientContext = null
    ): self {
        return new self(false, $intentId, $patientContext, null, $reasonCode, $publicMessage);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getIntentId(): string
    {
        return $this->intentId;
    }

    public function getPatientContext(): ?AgentPatientContext
    {
        return $this->patientContext;
    }

    public function getAccessToken(): ?AgentAccessToken
    {
        return $this->accessToken;
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
