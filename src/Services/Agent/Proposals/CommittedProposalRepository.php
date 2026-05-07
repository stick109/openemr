<?php

/**
 * CommittedProposalRepository
 *
 * Persists the {@see CommittedProposalRecord}s produced by
 * {@see \OpenEMR\RestControllers\Agent\AgentProposalCommitController} so that
 * a replay of the same M21 idempotency key returns the previously committed
 * result rather than re-applying the lab dispatch.
 *
 * Storage trade-off (M21 vs. M24)
 * --------------------------------
 *
 * The first cut backs the repository with a JSON file under
 * ``sites/default/documents/agent_proposals/``. This avoids a new schema
 * migration during the migration milestones (M21 lands while M24 cleanup
 * is still ahead of us) and makes isolated testing trivial: tests inject a
 * temporary directory and never touch MySQL.
 *
 * Future steps may swap the storage to a dedicated audit table; the
 * interface intentionally exposes ``find`` / ``record`` semantics that
 * would carry over unchanged.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Clinical Co-Pilot Sidecar Migration
 * @copyright Copyright (c) 2026 OpenEMR contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Proposals;

use Psr\Log\LoggerInterface;

final readonly class CommittedProposalRepository
{
    private const FILE_PERMISSIONS = 0640;
    private const DIR_PERMISSIONS = 0750;

    public function __construct(
        private string $storageDirectory,
        private LoggerInterface $logger,
    ) {
        if ($storageDirectory === '') {
            throw new \DomainException(
                'CommittedProposalRepository: storage directory is required.',
            );
        }
    }

    /**
     * Look up a previously-committed record by its idempotency key.
     *
     * Returns ``null`` if no record exists.  Corrupt records are
     * logged at warning level and treated as absent so a fresh commit
     * can proceed.
     */
    public function find(string $idempotencyKey): ?CommittedProposalRecord
    {
        if (!self::isWellFormedKey($idempotencyKey)) {
            return null;
        }

        $path = $this->pathForKey($idempotencyKey);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->logger->warning('agent.proposals.read_failed', [
                'idempotency_key' => $idempotencyKey,
            ]);
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning('agent.proposals.json_corrupt', [
                'idempotency_key' => $idempotencyKey,
                'exception' => $exception,
            ]);
            return null;
        }

        if (!is_array($data) || array_is_list($data)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            return CommittedProposalRecord::fromArray($data);
        } catch (\DomainException $exception) {
            $this->logger->warning('agent.proposals.record_invalid', [
                'idempotency_key' => $idempotencyKey,
                'exception' => $exception,
            ]);
            return null;
        }
    }

    /**
     * Record a successful commit. Re-recording the same key is a no-op
     * — the first commit wins so concurrent retries cannot overwrite
     * the canonical row IDs.
     */
    public function record(CommittedProposalRecord $record): CommittedProposalRecord
    {
        if (!self::isWellFormedKey($record->idempotencyKey)) {
            throw new \DomainException(
                'CommittedProposalRepository: idempotency_key is not well-formed.',
            );
        }

        $existing = $this->find($record->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $this->ensureDirectory();
        $path = $this->pathForKey($record->idempotencyKey);
        $payload = json_encode(
            $record->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        // Write to a sibling temp file then rename so a partial write
        // never leaves a half-written record visible to readers.
        $tmpPath = $path . '.tmp';
        if (file_put_contents($tmpPath, $payload, LOCK_EX) === false) {
            throw new \RuntimeException(
                'CommittedProposalRepository: failed to persist record.',
            );
        }
        @chmod($tmpPath, self::FILE_PERMISSIONS);
        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException(
                'CommittedProposalRepository: failed to commit record file.',
            );
        }

        return $record;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->storageDirectory)) {
            return;
        }
        if (!@mkdir($this->storageDirectory, self::DIR_PERMISSIONS, true) && !is_dir($this->storageDirectory)) {
            throw new \RuntimeException(
                'CommittedProposalRepository: failed to create storage directory.',
            );
        }
    }

    private function pathForKey(string $idempotencyKey): string
    {
        // Hash so the on-disk filename never echoes raw trace ids back
        // into directory listings (which can leak through backup tooling
        // or log scrapers).
        $hash = hash('sha256', $idempotencyKey);
        return rtrim($this->storageDirectory, '/\\') . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    /**
     * Validate that ``$key`` follows the M21 ``<trace_id>:<scope>`` shape.
     * Mirrors :func:`agent_service.proposals.validator._idempotency_key_well_formed`.
     */
    public static function isWellFormedKey(string $key): bool
    {
        if ($key === '' || strlen($key) > 256) {
            return false;
        }
        $colon = strpos($key, ':');
        if ($colon === false || $colon === 0 || $colon === strlen($key) - 1) {
            return false;
        }
        // Printable ASCII without whitespace / control chars.
        for ($i = 0, $n = strlen($key); $i < $n; $i++) {
            $code = ord($key[$i]);
            if ($code < 0x21 || $code > 0x7E) {
                return false;
            }
        }
        return true;
    }
}
