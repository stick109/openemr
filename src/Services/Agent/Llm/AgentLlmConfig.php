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
        private readonly int $timeoutSeconds = 20
    ) {
    }

    public static function fromEnvironment(?OEEnvBag $env = null): self
    {
        $env ??= OEEnvBag::getInstance();
        $provider = strtolower(self::getString($env, 'OPENEMR_AGENT_LLM_PROVIDER', self::PROVIDER_DISABLED));
        $model = self::getString($env, 'OPENEMR_AGENT_LLM_MODEL');
        $baseUri = self::getString($env, 'OPENEMR_AGENT_LLM_BASE_URI', 'https://api.openai.com/v1/');
        $timeoutSeconds = self::getOptionalPositiveInt($env, 'OPENEMR_AGENT_LLM_TIMEOUT_SECONDS', 20);

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
            timeoutSeconds: $timeoutSeconds
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

    public function isConfigured(): bool
    {
        return $this->provider === self::PROVIDER_OPENAI
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

        if ($this->apiKey === '') {
            return 'missing_api_key';
        }

        if ($this->model === '') {
            return 'missing_model';
        }

        return null;
    }
}
