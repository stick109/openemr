<?php

/**
 * AgentLlmConfig
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Llm;

use OpenEMR\Core\OEEnvBag;
use SensitiveParameter;

final class AgentLlmConfig
{
    public const PROVIDER_DISABLED = 'disabled';
    public const PROVIDER_OPENAI = 'openai';

    public function __construct(
        private readonly string $provider = self::PROVIDER_DISABLED,
        #[SensitiveParameter] private readonly string $apiKey = '',
        private readonly string $model = '',
        private readonly string $baseUri = 'https://api.openai.com/v1/',
        private readonly int $timeoutSeconds = 20,
        private readonly bool $externalCallsEnabled = false,
        private readonly float $inputCostPer1MTokens = 0.0,
        private readonly float $outputCostPer1MTokens = 0.0
    ) {
    }

    public static function fromEnvironment(?OEEnvBag $env = null): self
    {
        $env ??= OEEnvBag::getInstance();
        $provider = strtolower(self::getString($env, 'OPENEMR_AGENT_LLM_PROVIDER', self::PROVIDER_DISABLED));
        $model = self::getString($env, 'OPENEMR_AGENT_LLM_MODEL');
        $baseUri = self::getString($env, 'OPENEMR_AGENT_LLM_BASE_URI', 'https://api.openai.com/v1/');
        $timeoutSeconds = self::getOptionalPositiveInt($env, 'OPENEMR_AGENT_LLM_TIMEOUT_SECONDS', 20);
        $externalCallsEnabled = self::getOptionalBool($env, 'OPENEMR_AGENT_LLM_EXTERNAL_CALLS_ENABLED', false);
        $inputCostPer1MTokens = self::getOptionalNonNegativeFloat($env, 'OPENEMR_AGENT_LLM_INPUT_COST_PER_1M_TOKENS', 0.0);
        $outputCostPer1MTokens = self::getOptionalNonNegativeFloat($env, 'OPENEMR_AGENT_LLM_OUTPUT_COST_PER_1M_TOKENS', 0.0);

        $apiKey = '';
        if ($provider === self::PROVIDER_OPENAI) {
            $apiKey = self::getString($env, 'OPENAI_API_KEY');
            if ($apiKey === '') {
                $apiKey = self::getString($env, 'OPENEMR_AGENT_LLM_API_KEY');
            }
        }

        return new self(
            provider: $provider !== '' ? $provider : self::PROVIDER_DISABLED,
            apiKey: $apiKey,
            model: $model,
            baseUri: $baseUri !== '' ? $baseUri : 'https://api.openai.com/v1/',
            timeoutSeconds: $timeoutSeconds,
            externalCallsEnabled: $externalCallsEnabled,
            inputCostPer1MTokens: $inputCostPer1MTokens,
            outputCostPer1MTokens: $outputCostPer1MTokens
        );
    }

    private static function getString(OEEnvBag $env, string $name, string $default = ''): string
    {
        $value = $env->getString($name, $default);
        $value = preg_replace('/^(?:\x{FEFF})+/u', '', $value) ?? $value;

        return trim($value);
    }

    private static function getOptionalPositiveInt(OEEnvBag $env, string $name, int $default): int
    {
        $value = self::getString($env, $name);
        if ($value === '') {
            return $default;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            return $default;
        }

        return max(1, (int) $validated);
    }

    private static function getOptionalBool(OEEnvBag $env, string $name, bool $default): bool
    {
        $value = strtolower(self::getString($env, $name));
        if ($value === '') {
            return $default;
        }

        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private static function getOptionalNonNegativeFloat(OEEnvBag $env, string $name, float $default): float
    {
        $value = self::getString($env, $name);
        if ($value === '') {
            return $default;
        }

        $validated = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($validated === false) {
            return $default;
        }

        return max(0.0, (float) $validated);
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getBaseUri(): string
    {
        return rtrim($this->baseUri, '/') . '/';
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function externalCallsEnabled(): bool
    {
        return $this->externalCallsEnabled;
    }

    public function getInputCostPer1MTokens(): float
    {
        return $this->inputCostPer1MTokens;
    }

    public function getOutputCostPer1MTokens(): float
    {
        return $this->outputCostPer1MTokens;
    }

    public function isConfigured(): bool
    {
        return $this->provider === self::PROVIDER_OPENAI
            && $this->externalCallsEnabled
            && $this->apiKey !== ''
            && $this->model !== '';
    }

    public function getConfigurationIssue(): ?string
    {
        if ($this->provider === self::PROVIDER_DISABLED) {
            return 'provider_disabled';
        }

        if ($this->provider !== self::PROVIDER_OPENAI) {
            return 'unsupported_provider';
        }

        if (!$this->externalCallsEnabled) {
            return 'external_calls_disabled';
        }

        if ($this->apiKey === '') {
            return 'missing_api_key';
        }

        if ($this->model === '') {
            return 'missing_model';
        }

        return null;
    }
}
