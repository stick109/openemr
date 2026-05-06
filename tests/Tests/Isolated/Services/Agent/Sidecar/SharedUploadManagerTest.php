<?php

/**
 * SharedUploadManagerTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Sidecar;

use OpenEMR\Services\Agent\Sidecar\SharedUploadManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class SharedUploadManagerTest extends TestCase
{
    private SharedUploadManager $manager;

    protected function setUp(): void
    {
        $this->manager = new SharedUploadManager(
            logger: new NullLogger(),
        );
    }

    // ------------------------------------------------------------------
    // buildSharedPath / path generation
    // ------------------------------------------------------------------

    public function testBuildSharedPathUsesDefaultDirectory(): void
    {
        $path = $this->manager->buildSharedPath('abc-123', 'pdf');

        $this->assertSame('/var/uploads/agent/abc-123.pdf', $path);
    }

    public function testBuildSharedPathWithCustomDirectory(): void
    {
        $manager = new SharedUploadManager(
            logger: new NullLogger(),
            sharedDirectory: '/tmp/custom',
        );

        $path = $manager->buildSharedPath('trace-42', 'png');

        $this->assertSame('/tmp/custom/trace-42.png', $path);
    }

    public function testBuildSharedPathFallsBackToPdfForUnknownExtension(): void
    {
        $path = $this->manager->buildSharedPath('trace-1', 'exe');

        $this->assertSame('/var/uploads/agent/trace-1.pdf', $path);
    }

    public function testBuildSharedPathFallsBackToPdfForEmptyExtension(): void
    {
        $path = $this->manager->buildSharedPath('trace-1', '');

        $this->assertSame('/var/uploads/agent/trace-1.pdf', $path);
    }

    // ------------------------------------------------------------------
    // sanitiseTraceId
    // ------------------------------------------------------------------

    public function testSanitiseTraceIdRemovesUnsafeCharacters(): void
    {
        $result = $this->manager->sanitiseTraceId('abc/../../../etc/passwd');

        $this->assertSame('abcetcpasswd', $result);
    }

    public function testSanitiseTraceIdPreservesHyphensAndUnderscores(): void
    {
        $result = $this->manager->sanitiseTraceId('trace_id-123');

        $this->assertSame('trace_id-123', $result);
    }

    public function testSanitiseTraceIdStripsDotsAndSlashes(): void
    {
        $result = $this->manager->sanitiseTraceId('foo.bar/baz\\qux');

        $this->assertSame('foobarbazqux', $result);
    }

    public function testSanitiseTraceIdTruncatesLongValues(): void
    {
        $longId = str_repeat('a', 200);
        $result = $this->manager->sanitiseTraceId($longId);

        $this->assertSame(128, strlen($result));
    }

    public function testSanitiseTraceIdThrowsOnEmptyResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trace ID is empty after sanitisation');

        $this->manager->sanitiseTraceId('/../../../');
    }

    // ------------------------------------------------------------------
    // extractSafeExtension
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function extensionProvider(): array
    {
        return [
            'pdf extension' => ['report.pdf', 'pdf'],
            'png extension' => ['image.PNG', 'png'],
            'jpeg extension' => ['photo.jpeg', 'jpeg'],
            'jpg extension' => ['photo.JPG', 'jpg'],
            'csv extension' => ['data.csv', 'csv'],
            'xml extension' => ['record.xml', 'xml'],
            'json extension' => ['payload.json', 'json'],
            'txt extension' => ['notes.txt', 'txt'],
            'tiff extension' => ['scan.tiff', 'tiff'],
            'tif extension' => ['scan.TIF', 'tif'],
            'hl7 extension' => ['message.hl7', 'hl7'],
            'exe blocked' => ['virus.exe', 'pdf'],
            'php blocked' => ['shell.php', 'pdf'],
            'sh blocked' => ['script.sh', 'pdf'],
            'no extension' => ['noext', 'pdf'],
            'empty string' => ['', 'pdf'],
            'dot only' => ['.', 'pdf'],
            'double extension uses last' => ['archive.tar.gz', 'pdf'],
            'path traversal in name' => ['../../etc/passwd', 'pdf'],
        ];
    }

    #[DataProvider('extensionProvider')]
    public function testExtractSafeExtension(string $filename, string $expected): void
    {
        $this->assertSame($expected, $this->manager->extractSafeExtension($filename));
    }

    // ------------------------------------------------------------------
    // getSharedDirectory
    // ------------------------------------------------------------------

    public function testGetSharedDirectoryReturnsConfiguredValue(): void
    {
        $this->assertSame(SharedUploadManager::DEFAULT_SHARED_DIR, $this->manager->getSharedDirectory());
    }

    // ------------------------------------------------------------------
    // store — filesystem interactions
    // ------------------------------------------------------------------

    public function testStoreRejectsEmptyTemporaryPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Temporary upload path must not be empty');

        $this->manager->store('', 'trace-1', 'file.pdf');
    }

    public function testStoreRejectsMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->manager->store('/nonexistent/path/file.tmp', 'trace-1', 'file.pdf');
    }

    public function testStoreCopiesFileToSharedDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/openemr-test-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o755, true);

        $sharedDir = $tempDir . '/shared';
        $sourcePath = $tempDir . '/upload.tmp';
        file_put_contents($sourcePath, 'test-content');

        try {
            $manager = new SharedUploadManager(
                logger: new NullLogger(),
                sharedDirectory: $sharedDir,
            );

            $result = $manager->store($sourcePath, 'trace-xyz', 'report.pdf');

            $this->assertSame($sharedDir . '/trace-xyz.pdf', $result);
            $this->assertFileExists($result);
            $this->assertSame('test-content', file_get_contents($result));
        } finally {
            // Clean up
            $this->removeDirectory($tempDir);
        }
    }

    public function testStoreSanitisesTraceIdInPath(): void
    {
        $tempDir = sys_get_temp_dir() . '/openemr-test-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o755, true);

        $sharedDir = $tempDir . '/shared';
        $sourcePath = $tempDir . '/upload.tmp';
        file_put_contents($sourcePath, 'test-content');

        try {
            $manager = new SharedUploadManager(
                logger: new NullLogger(),
                sharedDirectory: $sharedDir,
            );

            $result = $manager->store($sourcePath, '../../../etc/passwd-trace', 'doc.pdf');

            // Path traversal characters stripped; only safe chars remain
            $this->assertSame($sharedDir . '/etcpasswd-trace.pdf', $result);
            $this->assertFileExists($result);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $items */
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
