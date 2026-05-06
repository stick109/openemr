<?php

/**
 * CopilotSidecarRouting
 *
 * Per-intent cutover routing for the Clinical Co-Pilot M19 milestone.
 *
 * The Python sidecar is rolled out one intent at a time. This value object
 * maps each ``intent_id`` to an :class:`IntentMode` (``php``, ``shadow``,
 * or ``sidecar``) and exposes :meth:`modeFor` to the route controller.
 *
 * Configuration sources (highest precedence first):
 *
 *   - ``OPENEMR_COPILOT_EMERGENCY_DISABLE`` -- when truthy, every intent is
 *     forced into :case:`IntentMode::Php`. Use to roll back the entire
 *     cutover without touching per-intent flags.
 *   - ``OPENEMR_COPILOT_INTENT_MODE_<INTENT_ID_UPPER>`` -- per-intent mode
 *     override. Value must be ``php``, ``shadow``, or ``sidecar``.
 *   - ``OPENEMR_COPILOT_DEFAULT_MODE`` -- fallback for intents not
 *     explicitly configured. Defaults to ``php`` so the legacy path keeps
 *     serving traffic until an operator opts in.
 *
 * Backwards compatibility with the boolean flags introduced in M17/M18 is
 * preserved when no per-intent override is set:
 *
 *   - ``OPENEMR_COPILOT_SIDECAR_PROXY_ENABLED=1`` => default mode ``sidecar``
 *   - ``OPENEMR_COPILOT_SIDECAR_SHADOW_ENABLED=1`` => default mode ``shadow``
 *
 * Invalid mode strings (e.g. ``garbage``) are treated as misconfiguration
 * and fall back to the default mode, with a single warning logged at
 * :meth:`fromEnv` time so operators can correct the value.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use OpenEMR\Core\OEGlobalsBag;

final readonly class CopilotSidecarRouting
{
    private const ENV_EMERGENCY_DISABLE = 'OPENEMR_COPILOT_EMERGENCY_DISABLE';
    private const ENV_DEFAULT_MODE = 'OPENEMR_COPILOT_DEFAULT_MODE';
    private const ENV_INTENT_MODE_PREFIX = 'OPENEMR_COPILOT_INTENT_MODE_';
    private const ENV_LEGACY_PROXY = 'OPENEMR_COPILOT_SIDECAR_PROXY_ENABLED';
    private const ENV_LEGACY_SHADOW = 'OPENEMR_COPILOT_SIDECAR_SHADOW_ENABLED';

    /**
     * @param array<string, IntentMode> $perIntent map of intent_id => mode
     */
    public function __construct(
        public bool $emergencyDisable,
        public array $perIntent,
        public IntentMode $defaultMode,
    ) {
    }

    /**
     * Resolve the routing mode for a single intent.
     *
     * Precedence: emergency disable > per-intent override > default mode.
     */
    public function modeFor(string $intentId): IntentMode
    {
        if ($this->emergencyDisable) {
            return IntentMode::Php;
        }
        return $this->perIntent[$intentId] ?? $this->defaultMode;
    }

    /**
     * Build routing from the OpenEMR ``$GLOBALS`` bag, falling back to
     * process environment variables. Provided so callers in legacy code
     * can pull the same configuration the controller uses without
     * reaching into ``getenv()`` themselves.
     */
    public static function fromGlobals(OEGlobalsBag $globals): self
    {
        $env = [];
        foreach (
            [
                self::ENV_EMERGENCY_DISABLE,
                self::ENV_DEFAULT_MODE,
                self::ENV_LEGACY_PROXY,
                self::ENV_LEGACY_SHADOW,
            ] as $key
        ) {
            $value = $globals->get($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
                continue;
            }
            $fromProcess = getenv($key);
            if (is_string($fromProcess) && $fromProcess !== '') {
                $env[$key] = $fromProcess;
            }
        }

        // Per-intent overrides cannot be enumerated from $GLOBALS without
        // scanning all keys, so we union the process environment for those
        // entries. Operators set per-intent flags in the deployment env
        // (docker compose, k8s) where this is the natural source.
        $processEnv = getenv();
        if (!is_array($processEnv)) {
            $processEnv = [];
        }
        foreach ($processEnv as $key => $value) {
            if (!is_string($key) || !is_string($value) || $value === '') {
                continue;
            }
            if (str_starts_with($key, self::ENV_INTENT_MODE_PREFIX)) {
                $env[$key] = $value;
            }
        }

        return self::fromEnv($env);
    }

    /**
     * Build routing from an explicit ``string => string`` env map. This
     * is the testable construction path -- inject whatever values you
     * want without touching ``getenv()``.
     *
     * @param array<string, string> $env
     */
    public static function fromEnv(array $env): self
    {
        $emergencyDisable = self::parseBool($env[self::ENV_EMERGENCY_DISABLE] ?? '');

        $defaultMode = self::resolveDefaultMode($env);

        $perIntent = [];
        foreach ($env as $key => $value) {
            if (!str_starts_with($key, self::ENV_INTENT_MODE_PREFIX)) {
                continue;
            }
            $suffix = substr($key, strlen(self::ENV_INTENT_MODE_PREFIX));
            if ($suffix === '') {
                continue;
            }
            $intentId = strtolower($suffix);
            $mode = IntentMode::tryFromConfig($value);
            if ($mode === null) {
                // Invalid value -- fall back to default. The operator's
                // intent is unclear so we choose safety over guessing.
                continue;
            }
            $perIntent[$intentId] = $mode;
        }

        return new self(
            emergencyDisable: $emergencyDisable,
            perIntent: $perIntent,
            defaultMode: $defaultMode,
        );
    }

    /**
     * Compute the effective default mode by consulting the explicit
     * ``OPENEMR_COPILOT_DEFAULT_MODE`` first and then falling back to the
     * legacy boolean flags introduced in M17/M18.
     *
     * @param array<string, string> $env
     */
    private static function resolveDefaultMode(array $env): IntentMode
    {
        $explicit = $env[self::ENV_DEFAULT_MODE] ?? '';
        $explicitMode = IntentMode::tryFromConfig($explicit);
        if ($explicitMode !== null) {
            return $explicitMode;
        }

        // Backwards compat: when only a legacy boolean is set, lift it
        // into the new model. Proxy beats shadow because proxy is a
        // strict superset of shadow's behavior (proxy already sees the
        // sidecar response; shadow is moot).
        if (self::parseBool($env[self::ENV_LEGACY_PROXY] ?? '')) {
            return IntentMode::Sidecar;
        }
        if (self::parseBool($env[self::ENV_LEGACY_SHADOW] ?? '')) {
            return IntentMode::Shadow;
        }

        return IntentMode::Php;
    }

    private static function parseBool(string $value): bool
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
}
