<?php

declare(strict_types=1);

/**
 * Isolated tests for SessionRestoreCookie encode/decode + TTL semantics.
 *
 * Uses a CryptoGen test double whose encryptStandard is base64(json) and
 * decryptStandard is the inverse, so the test doesn't require a key file
 * on disk while still exercising the same code paths the production class
 * uses against a real CryptoGen.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Tests\Isolated\Common\Session;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Crypto\KeySource;
use OpenEMR\Common\Session\SessionRestoreCookie;
use PHPUnit\Framework\TestCase;

class SessionRestoreCookieTest extends TestCase
{
    private CryptoGen $cryptoGen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cryptoGen = new class () extends CryptoGen {
            public function __construct()
            {
                // skip parent constructor; we don't need site dir
            }

            public function encryptStandard(?string $value, KeySource $keySource = KeySource::Drive): string
            {
                return 'enc:' . base64_encode((string) $value);
            }

            public function decryptStandard(?string $value, KeySource $keySource = KeySource::Drive, ?int $minimumVersion = null): false|string
            {
                if ($value === null || $value === '') {
                    return '';
                }
                if (!str_starts_with($value, 'enc:')) {
                    return false;
                }
                $raw = base64_decode(substr($value, 4), true);
                return $raw === false ? false : $raw;
            }
        };
    }

    public function testRoundTripPreservesData(): void
    {
        $restore = new SessionRestoreCookie($this->cryptoGen, 1_000);
        $data = [
            'authUser' => 'admin',
            'authUserID' => 1,
            'authPass' => 'hash-value',
            'site_id' => 'default',
            'pid' => 42,
        ];

        $encoded = $restore->encode($data);
        $decoded = $restore->decode($encoded);

        $this->assertSame($data, $decoded);
    }

    public function testRoundTripPreservesBinaryStringValues(): void
    {
        // authPass in OpenEMR is an MD5-derived raw byte string; json_encode
        // would normally reject it as "Malformed UTF-8 characters". The
        // envelope wrapping in encode() must round-trip these byte strings
        // exactly so the consumer sees the same bytes that were captured.
        $restore = new SessionRestoreCookie($this->cryptoGen, 1_000);
        $binaryAuthPass = hex2bin('0102030405060708090a0bff80c1d2e3f4');
        $data = [
            'authUser' => 'admin',
            'authUserID' => 1,
            'authPass' => $binaryAuthPass,
        ];

        $encoded = $restore->encode($data);
        $decoded = $restore->decode($encoded);

        $this->assertSame($data, $decoded);
        $this->assertSame($binaryAuthPass, $decoded['authPass'] ?? null);
    }

    public function testDecodeRejectsExpiredPayload(): void
    {
        $producer = new SessionRestoreCookie($this->cryptoGen, 1_000);
        $expired = $producer->encode(['authUser' => 'admin', 'authUserID' => 1, 'authPass' => 'h']);

        // Consumer is far enough in the future that TTL has elapsed.
        $consumer = new SessionRestoreCookie($this->cryptoGen, 1_000 + SessionRestoreCookie::TTL_SECONDS + 1);

        $this->assertNull($consumer->decode($expired));
    }

    public function testDecodeRejectsFutureTimestamp(): void
    {
        $producer = new SessionRestoreCookie($this->cryptoGen, 10_000);
        $futureBlob = $producer->encode(['authUser' => 'admin']);

        $consumer = new SessionRestoreCookie($this->cryptoGen, 100);
        $this->assertNull($consumer->decode($futureBlob));
    }

    public function testDecodeRejectsEmpty(): void
    {
        $restore = new SessionRestoreCookie($this->cryptoGen, 1_000);
        $this->assertNull($restore->decode(null));
        $this->assertNull($restore->decode(''));
    }

    public function testDecodeRejectsTamperedCiphertext(): void
    {
        $restore = new SessionRestoreCookie($this->cryptoGen, 1_000);
        // Garbage that won't decrypt cleanly under our test double.
        $this->assertNull($restore->decode('not-a-real-blob'));
    }

    public function testBuildPayloadFromArraySession(): void
    {
        $session = [
            'authUser' => 'admin',
            'authUserID' => 1,
            'authPass' => 'hash',
            'site_id' => 'default',
            'pid' => 12,
            'irrelevant_extra' => 'should not be captured',
            'language_choice' => 1,
        ];

        $payload = SessionRestoreCookie::buildPayloadFromSession($session);

        $this->assertSame(
            ['authUser', 'authUserID', 'authPass', 'site_id', 'pid', 'language_choice'],
            array_keys($payload)
        );
        $this->assertArrayNotHasKey('irrelevant_extra', $payload);
    }

    public function testBuildPayloadSkipsBlankAndNullValues(): void
    {
        $session = [
            'authUser' => '',
            'authUserID' => null,
            'authPass' => 'hash',
            'site_id' => 'default',
        ];

        $payload = SessionRestoreCookie::buildPayloadFromSession($session);

        $this->assertSame(['authPass', 'site_id'], array_keys($payload));
    }
}
