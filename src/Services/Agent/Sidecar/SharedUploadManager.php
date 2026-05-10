<?php

/**
 * Manages uploads shared between OpenEMR and the agent-service sidecar.
 *
 * Copies a validated upload from PHP's temporary path to the shared Docker
 * volume so the sidecar container can read the file by its trace-ID-based
 * filename.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use Psr\Log\LoggerInterface;

final class SharedUploadManager
{
    /**
     * Default mount point inside the container for the shared volume.
     *
     * Used when neither an explicit constructor argument nor the
     * OPENEMR_AGENT_SHARED_DIR environment variable is set. Local dev-easy
     * mounts a named Docker volume here; production deployments without
     * that volume must point OPENEMR_AGENT_SHARED_DIR at a path that lives
     * on a persistent volume (e.g. inside the sites/ Railway volume).
     */
    public const DEFAULT_SHARED_DIR = '/var/uploads/agent';

    /**
     * Environment variable name that overrides DEFAULT_SHARED_DIR. Set this
     * in environments where the canonical /var/uploads/agent path is not on
     * a persistent volume (Railway prod stores PDFs ephemerally otherwise,
     * and pdf.php returns 404 after every redeploy because the file was
     * wiped with the container's writable layer).
     */
    public const SHARED_DIR_ENV = 'OPENEMR_AGENT_SHARED_DIR';

    /**
     * Maximum allowed length for a trace ID after sanitisation.
     */
    private const MAX_TRACE_ID_LENGTH = 128;

    /**
     * Allowed file extensions (lower-case, without the leading dot).
     */
    private const ALLOWED_EXTENSIONS = [
        'pdf',
        'png',
        'jpg',
        'jpeg',
        'tiff',
        'tif',
        'txt',
        'csv',
        'xml',
        'json',
        'hl7',
    ];

    private readonly string $sharedDirectory;

    public function __construct(
        private readonly LoggerInterface $logger,
        ?string $sharedDirectory = null,
    ) {
        if ($sharedDirectory !== null) {
            $this->sharedDirectory = $sharedDirectory;
            return;
        }

        $envValue = getenv(self::SHARED_DIR_ENV);
        $this->sharedDirectory = is_string($envValue) && $envValue !== ''
            ? $envValue
            : self::DEFAULT_SHARED_DIR;
    }

    /**
     * Copy a temporary upload to the shared directory using a trace-ID-based name.
     *
     * @param string $temporaryPath Absolute path to the PHP temporary upload file.
     * @param string $traceId       Unique trace identifier for this request.
     * @param string $originalName  Original client filename (used only for extension).
     *
     * @return string Absolute path inside the shared volume (container path).
     *
     * @throws \RuntimeException When the file cannot be stored.
     */
    public function store(string $temporaryPath, string $traceId, string $originalName): string
    {
        $this->validateTemporaryPath($temporaryPath);

        $safeTraceId = $this->sanitiseTraceId($traceId);
        $extension = $this->extractSafeExtension($originalName);
        $filename = $safeTraceId . '.' . $extension;
        $destination = $this->sharedDirectory . '/' . $filename;

        $this->ensureDirectoryExists($this->sharedDirectory);

        if (!copy($temporaryPath, $destination)) {
            $this->logger->error('Failed to copy upload to shared directory', [
                'source' => $temporaryPath,
                'destination' => $destination,
            ]);

            throw new \RuntimeException('Failed to copy upload to shared directory');
        }

        $this->logger->info('Upload stored in shared directory', [
            'trace_id' => $safeTraceId,
            'destination' => $destination,
        ]);

        return $destination;
    }

    /**
     * Build the shared path for a given trace ID and extension without copying.
     *
     * Useful when the caller already knows the file will be at this location
     * (e.g. when building the sidecar request payload).
     */
    public function buildSharedPath(string $traceId, string $extension): string
    {
        $safeTraceId = $this->sanitiseTraceId($traceId);
        $safeExtension = $this->normaliseExtension($extension);

        return $this->sharedDirectory . '/' . $safeTraceId . '.' . $safeExtension;
    }

    /**
     * Return the configured shared directory.
     */
    public function getSharedDirectory(): string
    {
        return $this->sharedDirectory;
    }

    /**
     * Sanitise a trace ID to contain only safe filesystem characters.
     *
     * Strips everything except alphanumerics, hyphens, and underscores.
     *
     * @throws \InvalidArgumentException When the trace ID is empty after sanitisation.
     */
    public function sanitiseTraceId(string $traceId): string
    {
        $sanitised = preg_replace('/[^a-zA-Z0-9_-]/', '', $traceId) ?? '';
        $sanitised = substr($sanitised, 0, self::MAX_TRACE_ID_LENGTH);

        if ($sanitised === '') {
            throw new \InvalidArgumentException('Trace ID is empty after sanitisation');
        }

        return $sanitised;
    }

    /**
     * Extract a safe, lower-case extension from the original filename.
     *
     * Falls back to "pdf" when the extension is absent or not allowed.
     */
    public function extractSafeExtension(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return $this->normaliseExtension($extension);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function normaliseExtension(string $extension): string
    {
        $extension = strtolower(trim($extension, '. '));

        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return 'pdf';
        }

        return $extension;
    }

    private function validateTemporaryPath(string $temporaryPath): void
    {
        if ($temporaryPath === '') {
            throw new \InvalidArgumentException('Temporary upload path must not be empty');
        }

        if (!file_exists($temporaryPath)) {
            throw new \RuntimeException('Temporary upload file does not exist: ' . $temporaryPath);
        }

        if (!is_readable($temporaryPath)) {
            throw new \RuntimeException('Temporary upload file is not readable: ' . $temporaryPath);
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create shared upload directory: ' . $directory);
        }
    }
}
