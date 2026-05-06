<?php

/**
 * CopilotRunContext
 *
 * Signed, short-lived authority context minted by PHP and verified by the
 * Python agent sidecar. Carries scoped claims (patient/encounter scope,
 * allowed tools, rate caps, expiry) but never raw PHI such as patient
 * names, DOB, addresses, phone numbers, or free-text chart content.
 *
 * Wire format: a JWT-like compact string of the form
 *
 *     <base64url(canonical_payload_json)>.<base64url(hmac_sha256_signature)>
 *
 * The payload is canonical JSON (RFC 8785-style minimal -- sorted object
 * keys, no whitespace, no trailing newlines) so that PHP and Python
 * produce byte-identical inputs to HMAC-SHA256 and the resulting
 * signatures compare with constant-time equality.
 *
 * Includes a `key_version` claim so secrets can be rotated. Verifiers
 * resolve the secret to use by `key_version`.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Clinical Co-Pilot Sidecar Migration
 * @copyright Copyright (c) 2026 OpenEMR contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Copilot;

use DomainException;
use JsonException;
use SensitiveParameter;

/**
 * Immutable claim set carried in a signed CopilotRunContext token.
 *
 * The constructor enforces shape invariants. Use {@see self::mint()} to
 * produce a wire string and {@see self::decode()} to inspect an unsigned
 * payload (verification happens in the Python sidecar).
 */
final readonly class CopilotRunContext
{
    /**
     * @param list<string> $allowedTools        Names of tools the runtime may invoke.
     * @param list<string> $allowedSourceTypes  Source-type filters the runtime may query.
     */
    public function __construct(
        public int $userId,
        public string $username,
        public int $patientId,
        public ?int $encounterId,
        public array $allowedTools,
        public array $allowedSourceTypes,
        public int $maxRows,
        public int $lookbackDays,
        public int $expiresAt,
        public string $requestId,
        public string $traceId,
        public string $keyVersion,
    ) {
        if ($this->userId <= 0) {
            throw new DomainException('CopilotRunContext: user_id must be positive');
        }

        if (trim($this->username) === '') {
            throw new DomainException('CopilotRunContext: username is required');
        }

        if ($this->patientId <= 0) {
            throw new DomainException('CopilotRunContext: patient_id must be positive');
        }

        if ($this->encounterId !== null && $this->encounterId <= 0) {
            throw new DomainException('CopilotRunContext: encounter_id must be positive when set');
        }

        foreach ($this->allowedTools as $toolName) {
            if ($toolName === '') {
                throw new DomainException('CopilotRunContext: allowed_tools must be a list of non-empty strings');
            }
        }

        foreach ($this->allowedSourceTypes as $sourceType) {
            if ($sourceType === '') {
                throw new DomainException(
                    'CopilotRunContext: allowed_source_types must be a list of non-empty strings'
                );
            }
        }

        if ($this->maxRows <= 0) {
            throw new DomainException('CopilotRunContext: max_rows must be positive');
        }

        if ($this->lookbackDays <= 0) {
            throw new DomainException('CopilotRunContext: lookback_days must be positive');
        }

        if ($this->expiresAt <= 0) {
            throw new DomainException('CopilotRunContext: expires_at must be a positive unix timestamp');
        }

        if (trim($this->requestId) === '') {
            throw new DomainException('CopilotRunContext: request_id is required');
        }

        if (trim($this->traceId) === '') {
            throw new DomainException('CopilotRunContext: trace_id is required');
        }

        if (trim($this->keyVersion) === '') {
            throw new DomainException('CopilotRunContext: key_version is required');
        }
    }

    /**
     * Mint a signed wire string from the supplied claim array.
     *
     * Required keys in `$claims`:
     *   user_id, username, patient_id, encounter_id (nullable),
     *   allowed_tools, allowed_source_types, max_rows, lookback_days,
     *   expires_at, request_id, trace_id.
     *
     * `key_version` is supplied separately so callers explicitly choose the
     * key generation rather than letting input data drive it.
     *
     * @param array<string, mixed> $claims Authority claims to embed.
     */
    public static function mint(
        array $claims,
        #[SensitiveParameter] string $secret,
        string $keyVersion,
    ): string {
        if ($secret === '') {
            throw new DomainException('CopilotRunContext: secret must not be empty');
        }

        if (trim($keyVersion) === '') {
            throw new DomainException('CopilotRunContext: key_version must not be empty');
        }

        // Build a CopilotRunContext to enforce shape invariants up front. We
        // re-extract the canonical payload from the value object so that
        // decode/round-trip semantics match exactly.
        $context = self::fromClaimsArray($claims, $keyVersion);
        $payloadJson = self::canonicalPayloadJson($context->toClaimsArray());
        $signature = hash_hmac('sha256', $payloadJson, $secret, true);

        return self::base64UrlEncode($payloadJson) . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Decode a wire string into its claims array WITHOUT verifying the signature.
     *
     * This is for inspection only -- never trust these claims for
     * authorization decisions. The Python sidecar performs the
     * authoritative HMAC verification.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException When the wire format is malformed.
     */
    public static function decode(string $wire): array
    {
        $parts = explode('.', $wire);
        if (count($parts) !== 2) {
            throw new DomainException('CopilotRunContext: wire format must be <payload>.<signature>');
        }

        [$encodedPayload, $encodedSignature] = $parts;
        if ($encodedPayload === '' || $encodedSignature === '') {
            throw new DomainException('CopilotRunContext: wire payload or signature segment is empty');
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            throw new DomainException('CopilotRunContext: payload segment is not valid base64url');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payloadJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('CopilotRunContext: payload is not valid JSON');
        }

        if (!is_array($decoded)) {
            throw new DomainException('CopilotRunContext: payload must decode to an object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function toClaimsArray(): array
    {
        return [
            'allowed_source_types' => $this->allowedSourceTypes,
            'allowed_tools' => $this->allowedTools,
            'encounter_id' => $this->encounterId,
            'expires_at' => $this->expiresAt,
            'key_version' => $this->keyVersion,
            'lookback_days' => $this->lookbackDays,
            'max_rows' => $this->maxRows,
            'patient_id' => $this->patientId,
            'request_id' => $this->requestId,
            'trace_id' => $this->traceId,
            'user_id' => $this->userId,
            'username' => $this->username,
        ];
    }

    /**
     * Build a canonical JSON encoding of the supplied claims array.
     *
     * "Canonical" here means: object keys sorted lexicographically at every
     * level, no insignificant whitespace, slashes left unescaped, unicode
     * left unescaped. PHP's JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE
     * matches Python's `json.dumps(..., separators=(",", ":"), sort_keys=True,
     * ensure_ascii=False)`.
     *
     * @param array<string, mixed> $claims
     */
    private static function canonicalPayloadJson(array $claims): string
    {
        $sorted = self::deepSortByKey($claims);

        try {
            return json_encode(
                $sorted,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw new DomainException('CopilotRunContext: failed to encode canonical JSON', 0, $e);
        }
    }

    /**
     * Recursively sort associative-array keys so canonical JSON is deterministic.
     *
     * Lists (numeric-keyed arrays) preserve element order; only object-like
     * keys are sorted.
     */
    private static function deepSortByKey(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $result = [];

        if ($isList) {
            foreach ($value as $item) {
                $result[] = self::deepSortByKey($item);
            }

            return $result;
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $result[$key] = self::deepSortByKey($value[$key]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function fromClaimsArray(array $claims, string $keyVersion): self
    {
        return new self(
            userId: self::requireInt($claims, 'user_id'),
            username: self::requireString($claims, 'username'),
            patientId: self::requireInt($claims, 'patient_id'),
            encounterId: self::optionalInt($claims, 'encounter_id'),
            allowedTools: self::requireListOfStrings($claims, 'allowed_tools'),
            allowedSourceTypes: self::requireListOfStrings($claims, 'allowed_source_types'),
            maxRows: self::requireInt($claims, 'max_rows'),
            lookbackDays: self::requireInt($claims, 'lookback_days'),
            expiresAt: self::requireInt($claims, 'expires_at'),
            requestId: self::requireString($claims, 'request_id'),
            traceId: self::requireString($claims, 'trace_id'),
            keyVersion: $keyVersion,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function requireInt(array $claims, string $key): int
    {
        if (!array_key_exists($key, $claims)) {
            throw new DomainException("CopilotRunContext: missing required claim '{$key}'");
        }

        $value = $claims[$key];
        if (!is_int($value)) {
            throw new DomainException("CopilotRunContext: claim '{$key}' must be an int");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function optionalInt(array $claims, string $key): ?int
    {
        if (!array_key_exists($key, $claims)) {
            return null;
        }

        $value = $claims[$key];
        if ($value === null) {
            return null;
        }

        if (!is_int($value)) {
            throw new DomainException("CopilotRunContext: claim '{$key}' must be an int or null");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function requireString(array $claims, string $key): string
    {
        if (!array_key_exists($key, $claims)) {
            throw new DomainException("CopilotRunContext: missing required claim '{$key}'");
        }

        $value = $claims[$key];
        if (!is_string($value)) {
            throw new DomainException("CopilotRunContext: claim '{$key}' must be a string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private static function requireListOfStrings(array $claims, string $key): array
    {
        if (!array_key_exists($key, $claims)) {
            throw new DomainException("CopilotRunContext: missing required claim '{$key}'");
        }

        $value = $claims[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new DomainException("CopilotRunContext: claim '{$key}' must be a list");
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new DomainException("CopilotRunContext: claim '{$key}' must contain only strings");
            }
            $result[] = $item;
        }

        return $result;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        $remainder = strlen($encoded) % 4;
        if ($remainder !== 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }
}
