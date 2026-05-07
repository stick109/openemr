<?php

/**
 * Configuration for the Python agent-service sidecar.
 *
 * Reads env vars via OEEnvBag and exposes typed, validated values.
 * Secrets are annotated with #[SensitiveParameter] so they do not
 * leak into stack traces or debug output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use OpenEMR\Core\OEEnvBag;
use SensitiveParameter;

final readonly class AgentSidecarConfig
{
    private const DEFAULT_URL = 'http://agent-service:8010';
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    public function __construct(
        private string $url = self::DEFAULT_URL,
        #[SensitiveParameter] private string $sharedSecret = '',
        private int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        #[SensitiveParameter] private string $cohereApiKey = '',
        #[SensitiveParameter] private string $honeycombApiKey = '',
    ) {
    }

    /**
     * Build from environment variables using OEEnvBag.
     *
     * Variable mapping:
     *   OPENEMR_AGENT_SIDECAR_URL             -> url
     *   OPENEMR_AGENT_SIDECAR_SECRET          -> sharedSecret
     *   OPENEMR_AGENT_SIDECAR_TIMEOUT_SECONDS -> timeoutSeconds
     *   COHERE_API_KEY                         -> cohereApiKey
     *   HONEYCOMB_API_KEY                      -> honeycombApiKey
     */
    public static function fromEnvironment(?OEEnvBag $env = null): self
    {
        $env ??= OEEnvBag::getInstance();

        $url = self::getString($env, 'OPENEMR_AGENT_SIDECAR_URL', self::DEFAULT_URL);
        $sharedSecret = self::getString($env, 'OPENEMR_AGENT_SIDECAR_SECRET');
        $timeoutSeconds = self::getOptionalPositiveInt($env, 'OPENEMR_AGENT_SIDECAR_TIMEOUT_SECONDS', self::DEFAULT_TIMEOUT_SECONDS);
        $cohereApiKey = self::getString($env, 'COHERE_API_KEY');
        $honeycombApiKey = self::getString($env, 'HONEYCOMB_API_KEY');

        return new self(
            url: $url !== '' ? $url : self::DEFAULT_URL,
            sharedSecret: $sharedSecret,
            timeoutSeconds: $timeoutSeconds,
            cohereApiKey: $cohereApiKey,
            honeycombApiKey: $honeycombApiKey,
        );
    }

    public function getUrl(): string
    {
        return rtrim($this->url, '/');
    }

    public function getSharedSecret(): string
    {
        return $this->sharedSecret;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getCohereApiKey(): string
    {
        return $this->cohereApiKey;
    }

    public function getHoneycombApiKey(): string
    {
        return $this->honeycombApiKey;
    }

    /**
     * Whether the minimum config (URL + shared secret) is present.
     */
    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->sharedSecret !== '';
    }

    /**
     * Human-readable reason the config is incomplete, or null when valid.
     */
    public function getConfigurationIssue(): ?string
    {
        if ($this->url === '') {
            return 'missing_sidecar_url';
        }

        if ($this->sharedSecret === '') {
            return 'missing_shared_secret';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function getString(OEEnvBag $env, string $name, string $default = ''): string
    {
        $value = $env->getString($name, $default);
        $value = preg_replace('/^\x{FEFF}+/u', '', $value) ?? $value;

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
}
