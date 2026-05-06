<?php

/**
 * CopilotRunContextTest
 *
 * Isolated unit tests for the signed CopilotRunContext minted by PHP and
 * verified by the Python agent sidecar (M3).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Copilot;

use DomainException;
use OpenEMR\Services\Agent\Copilot\CopilotRunContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class CopilotRunContextTest extends TestCase
{
    private const SECRET = 'unit-test-secret-do-not-use-in-prod';
    private const KEY_VERSION = 'v1';

    public function testMintProducesPayloadDotSignatureFormat(): void
    {
        $wire = CopilotRunContext::mint($this->validClaims(), self::SECRET, self::KEY_VERSION);

        $segments = explode('.', $wire);
        self::assertCount(2, $segments, 'wire format must be <payload>.<signature>');
        self::assertNotSame('', $segments[0]);
        self::assertNotSame('', $segments[1]);

        // base64url alphabet: A-Z a-z 0-9 - _
        self::assertMatchesRegularExpression('#^[A-Za-z0-9_\-]+$#', $segments[0]);
        self::assertMatchesRegularExpression('#^[A-Za-z0-9_\-]+$#', $segments[1]);
    }

    public function testMintAndDecodeRoundTripPreservesAllClaims(): void
    {
        $claims = $this->validClaims();
        $wire = CopilotRunContext::mint($claims, self::SECRET, self::KEY_VERSION);

        $decoded = CopilotRunContext::decode($wire);

        // The decode method returns the canonical (sorted) claim set with
        // key_version embedded; assert each input claim is present and equal.
        foreach ($claims as $key => $expected) {
            self::assertArrayHasKey($key, $decoded, "missing claim '{$key}' after round trip");
            self::assertSame($expected, $decoded[$key], "claim '{$key}' did not round-trip");
        }

        self::assertArrayHasKey('key_version', $decoded);
        self::assertSame(self::KEY_VERSION, $decoded['key_version']);
    }

    public function testKeyVersionIsRequired(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('key_version');

        CopilotRunContext::mint($this->validClaims(), self::SECRET, '');
    }

    public function testDifferentKeyVersionsRoundTripIndependently(): void
    {
        $wireV1 = CopilotRunContext::mint($this->validClaims(), self::SECRET, 'v1');
        $wireV2 = CopilotRunContext::mint($this->validClaims(), self::SECRET, 'v2');

        self::assertNotSame($wireV1, $wireV2, 'changing key_version must change the wire token');

        $decodedV1 = CopilotRunContext::decode($wireV1);
        $decodedV2 = CopilotRunContext::decode($wireV2);

        self::assertSame('v1', $decodedV1['key_version']);
        self::assertSame('v2', $decodedV2['key_version']);
    }

    public function testTamperingWithPayloadInvalidatesSignature(): void
    {
        $original = CopilotRunContext::mint($this->validClaims(), self::SECRET, self::KEY_VERSION);
        [$encodedPayload, $encodedSignature] = explode('.', $original);

        $rawPayload = self::base64UrlDecode($encodedPayload);
        self::assertNotNull($rawPayload);

        // Mutate patient_id 42 -> 99 in the canonical JSON to simulate a tamper.
        $tampered = str_replace('"patient_id":42', '"patient_id":99', $rawPayload);
        self::assertNotSame($rawPayload, $tampered, 'sentinel substitution must change the payload');

        $tamperedWire = self::base64UrlEncode($tampered) . '.' . $encodedSignature;
        $rebuiltWithSameSecret = CopilotRunContext::mint(
            ['patient_id' => 99] + $this->validClaims(),
            self::SECRET,
            self::KEY_VERSION,
        );

        // The tampered wire's signature is the original signature, but the
        // re-mint with the new payload produces a different signature -- that
        // mismatch is exactly what the Python verifier rejects.
        self::assertNotSame(
            $rebuiltWithSameSecret,
            $tamperedWire,
            'tampered payload + original signature must not match a freshly minted token',
        );

        $rebuiltSignature = explode('.', $rebuiltWithSameSecret)[1];
        self::assertNotSame(
            $rebuiltSignature,
            $encodedSignature,
            'changing the payload must change the HMAC signature',
        );
    }

    public function testDifferentSecretsProduceDifferentSignatures(): void
    {
        $a = CopilotRunContext::mint($this->validClaims(), 'secret-a', self::KEY_VERSION);
        $b = CopilotRunContext::mint($this->validClaims(), 'secret-b', self::KEY_VERSION);

        self::assertSame(explode('.', $a)[0], explode('.', $b)[0], 'payload must be deterministic across secrets');
        self::assertNotSame(
            explode('.', $a)[1],
            explode('.', $b)[1],
            'signature must differ when the secret differs',
        );
    }

    public function testCanonicalPayloadIsDeterministicAcrossClaimOrdering(): void
    {
        $claims = $this->validClaims();
        $reordered = array_reverse($claims, true);

        $a = CopilotRunContext::mint($claims, self::SECRET, self::KEY_VERSION);
        $b = CopilotRunContext::mint($reordered, self::SECRET, self::KEY_VERSION);

        self::assertSame($a, $b, 'canonical JSON must be invariant under input claim ordering');
    }

    public function testDecodeRejectsMalformedWire(): void
    {
        $this->expectException(DomainException::class);
        CopilotRunContext::decode('not-a-token');
    }

    public function testDecodeRejectsEmptySegments(): void
    {
        $this->expectException(DomainException::class);
        CopilotRunContext::decode('.');
    }

    public function testMintRejectsEmptySecret(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('secret');

        CopilotRunContext::mint($this->validClaims(), '', self::KEY_VERSION);
    }

    public function testMintRejectsInvalidPatientId(): void
    {
        $claims = ['patient_id' => 0] + $this->validClaims();

        $this->expectException(DomainException::class);
        CopilotRunContext::mint($claims, self::SECRET, self::KEY_VERSION);
    }

    public function testEncounterIdMayBeNull(): void
    {
        $claims = ['encounter_id' => null] + $this->validClaims();
        $wire = CopilotRunContext::mint($claims, self::SECRET, self::KEY_VERSION);
        $decoded = CopilotRunContext::decode($wire);

        self::assertNull($decoded['encounter_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validClaims(): array
    {
        return [
            'user_id' => 17,
            'username' => 'dr.smith',
            'patient_id' => 42,
            'encounter_id' => 100,
            'allowed_tools' => ['get_basic_patient_data', 'get_current_medications'],
            'allowed_source_types' => ['patient', 'medication'],
            'max_rows' => 50,
            'lookback_days' => 365,
            'expires_at' => 1_900_000_000,
            'request_id' => 'req-1234-5678',
            'trace_id' => 'trace-abcd-efgh',
        ];
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
