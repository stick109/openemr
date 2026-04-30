<?php

/**
 * AgentLlmProviderInterface
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Llm;

interface AgentLlmProviderInterface
{
    public function isConfigured(): bool;

    public function getProviderName(): string;

    public function getModelName(): string;

    public function getConfigurationIssue(): ?string;

    public function complete(AgentLlmRequest $request): AgentLlmResponse;
}
