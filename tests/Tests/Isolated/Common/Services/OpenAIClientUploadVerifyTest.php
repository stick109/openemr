<?php

/**
 * OpenAIClientUploadVerifyTest
 *
 * Unit tests for the verify-after-upload logic added to
 * {@see \OpenEMR\Services\Intake\OpenAi\OpenAIClient::uploadPdf()}.
 *
 * OpenAI's Files API can return a successful upload (HTTP 200 with a
 * file id) but later reject the `file_id` at `/v1/chat/completions`
 * if the file's asynchronous processing did not complete — surfaced as
 * `400: ... got unsupported MIME type 'None'`.
 *
 * The fix issues a `GET /v1/files/{id}` immediately after upload and
 * fails fast when `status != "processed"`.
 *
 * All tests use Symfony's {@see MockHttpClient} so there is no network
 * activity; the suite runs in the isolated (no-Docker) environment.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

use OpenEMR\Core\OEEnvBag;
use OpenEMR\Services\Intake\OpenAi\Exception\OpenAIRequestFailedException;
use OpenEMR\Services\Intake\OpenAi\OpenAIClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[Group('isolated')]
#[Group('intake-forms')]
class OpenAIClientUploadVerifyTest extends TestCase
{
    private const FAKE_KEY = 'sk-test-1234567890abcdef';
    private const FAKE_FILE_ID = 'file-testABC123';
    private const UPLOAD_RESPONSE = [
        'id' => self::FAKE_FILE_ID,
        'object' => 'file',
        'purpose' => 'user_data',
        'filename' => 'test.pdf',
        'bytes' => 1234,
        'created_at' => 1700000000,
        'status' => 'uploaded',
    ];

    /** Absolute path to the temp PDF; set in setUp, guaranteed non-empty. */
    private string $pdfPath = '';

    protected function setUp(): void
    {
        // Write a minimal but valid PDF to a temp file so the file-readable
        // guard inside uploadPdf() passes without touching the real FS.
        $tmp = tempnam(sys_get_temp_dir(), 'oemr-test-');
        if ($tmp === false) {
            $this->markTestSkipped('Could not create temp file.');
        }

        $path = $tmp . '.pdf';
        file_put_contents(
            $path,
            "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/MediaBox[0 0 3 3]>>endobj\n"
            . "xref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n"
            . "0000000058 00000 n\n0000000115 00000 n\n"
            . "trailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF\n"
        );

        // Clean up the base temp file (we use the .pdf variant)
        @unlink($tmp);
        $this->pdfPath = $path;
    }

    protected function tearDown(): void
    {
        if ($this->pdfPath !== '' && is_file($this->pdfPath)) {
            @unlink($this->pdfPath);
        }
    }

    /**
     * Returns the temp PDF path, asserting it is non-empty so PHPStan can
     * narrow the type to `non-empty-string` at call sites.
     *
     * @return non-empty-string
     */
    private function pdfPath(): string
    {
        $this->assertNotSame('', $this->pdfPath, 'setUp did not initialise pdfPath');
        return $this->pdfPath;
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testUploadSucceedsWhenStatusIsProcessed(): void
    {
        $uploadBody = (string) json_encode(self::UPLOAD_RESPONSE);
        $metaBody   = (string) json_encode(array_merge(self::UPLOAD_RESPONSE, ['status' => 'processed']));

        $mockClient = new MockHttpClient([
            new MockResponse($uploadBody, ['http_code' => 200]),
            new MockResponse($metaBody, ['http_code' => 200]),
        ]);

        $client = $this->makeClient($mockClient);
        $fileId = $client->uploadPdf($this->pdfPath(), 'test.pdf');

        $this->assertSame(self::FAKE_FILE_ID, $fileId);
    }

    // -------------------------------------------------------------------------
    // Failure paths
    // -------------------------------------------------------------------------

    /**
     * @param non-empty-string $badStatus
     */
    #[DataProvider('badFileStatusProvider')]
    public function testUploadThrowsWhenStatusIsNotProcessed(string $badStatus): void
    {
        $uploadBody = (string) json_encode(self::UPLOAD_RESPONSE);
        $metaBody   = (string) json_encode(array_merge(self::UPLOAD_RESPONSE, ['status' => $badStatus]));

        // Three responses: upload 200, meta 200, then a DELETE 200 (cleanup)
        $mockClient = new MockHttpClient([
            new MockResponse($uploadBody, ['http_code' => 200]),
            new MockResponse($metaBody, ['http_code' => 200]),
            new MockResponse((string) json_encode(['deleted' => true]), ['http_code' => 200]),
        ]);

        $client = $this->makeClient($mockClient);

        $this->expectException(OpenAIRequestFailedException::class);
        $this->expectExceptionMessageMatches('/processed state.*status.*' . preg_quote($badStatus, '/') . '/i');

        $client->uploadPdf($this->pdfPath(), 'test.pdf');
    }

    public function testUploadThrowsWhenStatusIsMissing(): void
    {
        $uploadBody = (string) json_encode(self::UPLOAD_RESPONSE);
        // Meta response without a 'status' key at all
        $metaBody   = (string) json_encode(['id' => self::FAKE_FILE_ID, 'object' => 'file']);

        $mockClient = new MockHttpClient([
            new MockResponse($uploadBody, ['http_code' => 200]),
            new MockResponse($metaBody, ['http_code' => 200]),
            new MockResponse((string) json_encode(['deleted' => true]), ['http_code' => 200]),
        ]);

        $client = $this->makeClient($mockClient);

        $this->expectException(OpenAIRequestFailedException::class);
        $this->expectExceptionMessageMatches('/processed state.*status.*unknown/i');

        $client->uploadPdf($this->pdfPath(), 'test.pdf');
    }

    // -------------------------------------------------------------------------
    // Resilience: meta-fetch failures are non-fatal
    // -------------------------------------------------------------------------

    public function testUploadSucceedsWhenMetaFetchTransportFails(): void
    {
        $uploadBody = (string) json_encode(self::UPLOAD_RESPONSE);

        $mockClient = new MockHttpClient([
            new MockResponse($uploadBody, ['http_code' => 200]),
            new MockResponse('', ['http_code' => 0, 'error' => 'connection reset']),
        ]);

        $client = $this->makeClient($mockClient);

        // Transport failure on meta-fetch must NOT throw — we degrade
        // gracefully and let the completions call surface the error if any.
        $fileId = $client->uploadPdf($this->pdfPath(), 'test.pdf');
        $this->assertSame(self::FAKE_FILE_ID, $fileId);
    }

    public function testUploadSucceedsWhenMetaFetchReturnsNon2xx(): void
    {
        $uploadBody = (string) json_encode(self::UPLOAD_RESPONSE);
        $errorBody  = (string) json_encode(['error' => ['message' => 'not found']]);

        $mockClient = new MockHttpClient([
            new MockResponse($uploadBody, ['http_code' => 200]),
            new MockResponse($errorBody, ['http_code' => 404]),
        ]);

        $client = $this->makeClient($mockClient);

        $fileId = $client->uploadPdf($this->pdfPath(), 'test.pdf');
        $this->assertSame(self::FAKE_FILE_ID, $fileId);
    }

    // -------------------------------------------------------------------------
    // Data providers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{non-empty-string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function badFileStatusProvider(): array
    {
        return [
            'pending'    => ['pending'],
            'processing' => ['processing'],
            'error'      => ['error'],
            'deleted'    => ['deleted'],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClient(MockHttpClient $mockClient): OpenAIClient
    {
        $env = new OEEnvBag(['OPENAI_API_KEY' => self::FAKE_KEY]);

        return new OpenAIClient(
            logger: new NullLogger(),
            env: $env,
            httpClient: $mockClient,
        );
    }
}
