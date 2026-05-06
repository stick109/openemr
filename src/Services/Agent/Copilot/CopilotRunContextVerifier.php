<?php

/**
 * CopilotRunContextVerifier
 *
 * PHP-side mirror of the Python
 * ``agent_service.auth.copilot_run_context.verify_copilot_run_context`` helper.
 * The Python sidecar verifies tokens for inbound requests; the PHP commit
 * endpoint introduced in M21 needs the same check so the controller can
 * reject tampered or expired tokens at the boundary.
 *
 * Wire format mirrors {@see CopilotRunContext}: a JWT-like compact string
 *
 *     <base64url(canonical_payload_json)>.<base64url(hmac_sha256_signature)>
 *
 * with canonical JSON (sorted keys, no whitespace, unescaped slashes /
 * unicode) so PHP and Python compare byte-identical HMAC inputs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Clinical Co-Pilot Sidecar Migration
 * @copyright Copyright (c) 2026 OpenEMR contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Copilot;

use SensitiveParameter;

final readonly class CopilotRunContextVerifier
{
    /**
     * @param callable(string): ?string $secretResolver Maps a key_version to its
     *                                                  shared secret, or null when
     *                                                  unknown.
     * @param callable(): int           $clock          Returns the current unix timestamp.
     */
    public function __construct(
        private mixed $secretResolver,
        private mixed $clock,
    ) {
    }

    /**
     * Verify a signed CopilotRunContext wire token and return the decoded value
     * object. On any failure (malformed, tampered, expired, unknown key version)
     * a {@see CopilotRunContextVerificationException} is raised so the boundary
     * controller can map every failure to a fail-closed response without
     * leaking which check failed.
     */
    public function verify(#[SensitiveParameter] string $wire): CopilotRunContext
    {
        $claims = self::splitAndDecode($wire);
        $signatureBytes = self::splitSignature($wire);

        $keyVersion = $claims['key_version'] ?? null;
        if (!is_string($keyVersion) || $keyVersion === '') {
            throw CopilotRunContextVerificationException::malformed(
                'missing or empty key_version claim',
            );
        }

        $secret = ($this->secretResolver)($keyVersion);
        if (!is_string($secret) || $secret === '') {
            throw CopilotRunContextVerificationException::unknownKeyVersion(
                "unknown key_version: '{$keyVersion}'",
            );
        }

        $expected = hash_hmac(
            'sha256',
            self::canonicalPayloadJson($claims),
            $secret,
            true,
        );

        if (!hash_equals($expected, $signatureBytes)) {
            throw CopilotRunContextVerificationException::tampered(
                'signature does not match payload + secret',
            );
        }

        $expiresAt = $claims['expires_at'] ?? null;
        if (!is_int($expiresAt) || $expiresAt <= 0) {
            throw CopilotRunContextVerificationException::malformed(
                'missing or invalid expires_at claim',
            );
        }

        if ($expiresAt < ($this->clock)()) {
            throw CopilotRunContextVerificationException::expired(
                'token expired',
            );
        }

        try {
            return new CopilotRunContext(
                userId: self::requireInt($claims, 'user_id'),
                username: self::requireString($claims, 'username'),
                patientId: self::requireInt($claims, 'patient_id'),
                encounterId: self::optionalInt($claims, 'encounter_id'),
                allowedTools: self::requireListOfStrings($claims, 'allowed_tools'),
                allowedSourceTypes: self::requireListOfStrings($claims, 'allowed_source_types'),
                maxRows: self::requireInt($claims, 'max_rows'),
                lookbackDays: self::requireInt($claims, 'lookback_days'),
                expiresAt: $expiresAt,
                requestId: self::requireString($claims, 'request_id'),
                traceId: self::requireString($claims, 'trace_id'),
                keyVersion: $keyVersion,
            );
        } catch (\DomainException $exception) {
            throw CopilotRunContextVerificationException::malformed(
                'claims failed schema validation',
                $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function splitAndDecode(string $wire): array
    {
        if ($wire === '') {
            throw CopilotRunContextVerificationException::malformed(
                'wire token must not be empty',
            );
        }
        $parts = explode('.', $wire);
        if (count($parts) !== 2) {
            throw CopilotRunContextVerificationException::malformed(
                'wire format must be <payload>.<signature>',
            );
        }
        [$payloadSegment, $signatureSegment] = $parts;
        if ($payloadSegment === '' || $signatureSegment === '') {
            throw CopilotRunContextVerificationException::malformed(
                'wire payload or signature segment is empty',
            );
        }

        $payloadJson = self::base64UrlDecode($payloadSegment);
        if ($payloadJson === null) {
            throw CopilotRunContextVerificationException::malformed(
                'payload segment is not valid base64url',
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payloadJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw CopilotRunContextVerificationException::malformed(
                'payload is not valid JSON',
                $exception,
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw CopilotRunContextVerificationException::malformed(
                'payload must decode to an object',
            );
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function splitSignature(string $wire): string
    {
        $parts = explode('.', $wire);
        $signatureSegment = $parts[1] ?? '';
        $bytes = self::base64UrlDecode($signatureSegment);
        if ($bytes === null) {
            throw CopilotRunContextVerificationException::malformed(
                'signature segment is not valid base64url',
            );
        }
        return $bytes;
    }

    /**
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
        } catch (\JsonException $exception) {
            throw CopilotRunContextVerificationException::malformed(
                'failed to encode canonical JSON',
                $exception,
            );
        }
    }

    private static function deepSortByKey(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $result = [];
            foreach ($value as $item) {
                $result[] = self::deepSortByKey($item);
            }
            return $result;
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = self::deepSortByKey($value[$key]);
        }
        return $result;
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        $remainder = strlen($encoded) % 4;
        if ($remainder !== 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function requireInt(array $claims, string $key): int
    {
        $value = $claims[$key] ?? null;
        if (!is_int($value)) {
            throw new \DomainException("claim '{$key}' must be an int");
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function optionalInt(array $claims, string $key): ?int
    {
        $value = $claims[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new \DomainException("claim '{$key}' must be an int or null");
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function requireString(array $claims, string $key): string
    {
        $value = $claims[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \DomainException("claim '{$key}' must be a non-empty string");
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
        $value = $claims[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("claim '{$key}' must be a list");
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \DomainException("claim '{$key}' must contain only non-empty strings");
            }
            $result[] = $item;
        }
        return $result;
    }
}
