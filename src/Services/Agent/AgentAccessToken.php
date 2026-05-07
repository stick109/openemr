<?php

/**
 * AgentAccessToken
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

final class AgentAccessToken
{
    /**
     * @param list<string> $grantedDataClasses
     * @param list<string> $grantedTools
     * @param list<array{section: string, value: string, permission: string}> $grantedAclPolicies
     */
    public function __construct(
        private readonly string $tokenId,
        private readonly string $intentId,
        private readonly AgentPatientContext $patientContext,
        private readonly array $grantedDataClasses,
        private readonly array $grantedTools,
        private readonly array $grantedAclPolicies,
        private readonly int $issuedAt
    ) {
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function getIntentId(): string
    {
        return $this->intentId;
    }

    public function getPatientContext(): AgentPatientContext
    {
        return $this->patientContext;
    }

    /**
     * @return list<string>
     */
    public function getGrantedDataClasses(): array
    {
        return $this->grantedDataClasses;
    }

    /**
     * @return list<string>
     */
    public function getGrantedTools(): array
    {
        return $this->grantedTools;
    }

    /**
     * @return list<array{section: string, value: string, permission: string}>
     */
    public function getGrantedAclPolicies(): array
    {
        return $this->grantedAclPolicies;
    }

    public function getIssuedAt(): int
    {
        return $this->issuedAt;
    }
}
