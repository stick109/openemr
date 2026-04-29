<?php

/**
 * EvidenceCaps
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Evidence;

use DateInterval;
use DateTimeImmutable;

final class EvidenceCaps
{
    private const DEFAULT_MAX_RECORDS = 25;
    private const DEFAULT_MAX_DOCUMENTS = 0;
    private const DEFAULT_LOOKBACK_DAYS = 90;
    private const MAX_RECORDS_CEILING = 100;
    private const MAX_DOCUMENTS_CEILING = 50;
    private const LOOKBACK_DAYS_CEILING = 3650;

    public function __construct(
        private readonly int $maxRecords,
        private readonly int $maxDocuments,
        private readonly int $lookbackDays
    ) {
    }

    /**
     * @param array<string, mixed> $intent
     */
    public static function fromIntent(array $intent): self
    {
        return new self(
            self::normalizeInt($intent['max_records'] ?? null, self::DEFAULT_MAX_RECORDS, 1, self::MAX_RECORDS_CEILING),
            self::normalizeInt($intent['max_documents'] ?? null, self::DEFAULT_MAX_DOCUMENTS, 0, self::MAX_DOCUMENTS_CEILING),
            self::normalizeInt($intent['lookback_days'] ?? null, self::DEFAULT_LOOKBACK_DAYS, 0, self::LOOKBACK_DAYS_CEILING)
        );
    }

    public function getMaxRecords(): int
    {
        return $this->maxRecords;
    }

    public function getMaxDocuments(): int
    {
        return $this->maxDocuments;
    }

    public function getLookbackDays(): int
    {
        return $this->lookbackDays;
    }

    public function clampRecords(?int $requested = null): int
    {
        $limit = $requested ?? $this->maxRecords;
        return max(0, min($limit, $this->maxRecords));
    }

    public function clampDocuments(?int $requested = null): int
    {
        $limit = $requested ?? $this->maxDocuments;
        return max(0, min($limit, $this->maxDocuments));
    }

    public function getLookbackStart(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($this->lookbackDays <= 0) {
            return null;
        }

        return $now->sub(new DateInterval('P' . $this->lookbackDays . 'D'));
    }

    /**
     * @return array{max_records: int, max_documents: int, lookback_days: int}
     */
    public function toArray(): array
    {
        return [
            'max_records' => $this->maxRecords,
            'max_documents' => $this->maxDocuments,
            'lookback_days' => $this->lookbackDays,
        ];
    }

    private static function normalizeInt(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            $value = $default;
        }

        return max($minimum, min($value, $maximum));
    }
}
