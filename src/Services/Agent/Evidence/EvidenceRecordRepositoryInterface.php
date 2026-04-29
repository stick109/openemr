<?php

/**
 * EvidenceRecordRepositoryInterface
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Evidence;

interface EvidenceRecordRepositoryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCurrentMedications(int $pid, EvidenceCaps $caps): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllergiesToConfirm(int $pid, EvidenceCaps $caps): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRecentEvents(int $pid, EvidenceCaps $caps): array;

    /**
     * @param list<string> $grantedDataClasses
     * @return list<array<string, mixed>>
     */
    public function fetchChangedSinceLastVisit(int $pid, EvidenceCaps $caps, array $grantedDataClasses): array;

    /**
     * @return array<string, mixed>|null
     */
    public function fetchSourceRecord(int $pid, string $sourceId, EvidenceCaps $caps): ?array;
}
