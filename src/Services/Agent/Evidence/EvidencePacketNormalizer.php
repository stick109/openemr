<?php

/**
 * EvidencePacketNormalizer
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Evidence;

use OpenEMR\Services\Agent\AgentAccessToken;

final class EvidencePacketNormalizer
{
    /**
     * @param list<array<string, mixed>> $records
     * @return list<array{
     *     source_id: string,
     *     source_type: string,
     *     data_class: string,
     *     table: string,
     *     record_id: string,
     *     record_uuid: ?string,
     *     patient_id: int,
     *     patient_uuid: ?string,
     *     date: ?string,
     *     status: string,
     *     display: string,
     *     excerpt: string,
     *     fields_used: list<string>,
     *     reliability: string
     * }>
     */
    public function normalize(AgentAccessToken $accessToken, array $records): array
    {
        $normalized = [];
        $seen = [];
        $tokenPid = $accessToken->getPatientContext()->getPid();

        foreach ($records as $record) {
            $recordPid = $this->intOrNull($record['patient_id'] ?? null);
            if ($recordPid !== null && $recordPid !== $tokenPid) {
                throw new AgentEvidenceAccessException(
                    'patient_mismatch',
                    'Evidence source does not belong to the current patient.'
                );
            }

            $sourceType = $this->safeIdentifier($record['source_type'] ?? null, 'record');
            $dataClass = $this->safeIdentifier($record['data_class'] ?? null, $sourceType);
            $table = $this->safeIdentifier($record['table'] ?? null, 'unknown');
            $recordId = $this->stringValue($record['record_id'] ?? ($record['id'] ?? 'unknown'));
            $sourceId = $this->sourceId($record['source_id'] ?? null, $sourceType, $table, $recordId);

            if (isset($seen[$sourceId])) {
                continue;
            }
            $seen[$sourceId] = true;

            $display = $this->truncate($this->stringValue($record['display'] ?? ''), 500);
            if ($display === '') {
                $display = $sourceType . ' record ' . $recordId;
            }

            $excerpt = $this->truncate($this->stringValue($record['excerpt'] ?? $display), 700);

            $normalized[] = [
                'source_id' => $sourceId,
                'source_type' => $sourceType,
                'data_class' => $dataClass,
                'table' => $table,
                'record_id' => $recordId,
                'record_uuid' => $this->nullableString($record['record_uuid'] ?? null),
                'patient_id' => $recordPid ?? $tokenPid,
                'patient_uuid' => $this->nullableString($record['patient_uuid'] ?? null),
                'date' => $this->nullableString($record['date'] ?? null),
                'status' => $this->safeIdentifier($record['status'] ?? null, 'unknown'),
                'display' => $display,
                'excerpt' => $excerpt,
                'fields_used' => $this->stringList($record['fields_used'] ?? []),
                'reliability' => $this->safeIdentifier($record['reliability'] ?? null, 'structured_record'),
            ];
        }

        return $normalized;
    }

    private function sourceId(mixed $value, string $sourceType, string $table, string $recordId): string
    {
        if (is_string($value) && preg_match('/\A[A-Za-z0-9_:-]{1,160}\z/', $value) === 1) {
            return $value;
        }

        $safeRecordId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $recordId);
        return $sourceType . ':' . $table . ':' . ($safeRecordId ?: 'unknown');
    }

    private function safeIdentifier(mixed $value, string $fallback): string
    {
        if (!is_string($value) && !is_int($value)) {
            return $fallback;
        }

        $safe = preg_replace('/[^A-Za-z0-9_:-]/', '_', (string) $value);
        return $safe === null || $safe === '' ? $fallback : $safe;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->stringValue($value);
        return $string === '' ? null : $string;
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $string = $this->stringValue($item);
            if ($string !== '') {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(substr($value, 0, $limit - 3)) . '...';
    }
}
