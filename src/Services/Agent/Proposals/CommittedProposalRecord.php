<?php

/**
 * CommittedProposalRecord
 *
 * Typed value object describing a previously-committed M21 lab observation
 * proposal. Stored by {@see CommittedProposalRepository} so that replays of
 * the same idempotency key return the original commit result rather than
 * re-applying the write.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Clinical Co-Pilot Sidecar Migration
 * @copyright Copyright (c) 2026 OpenEMR contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Proposals;

final readonly class CommittedProposalRecord
{
    /**
     * @param string             $idempotencyKey      M21 dedupe key, ``<trace_id>:<scope>``.
     * @param string             $traceId             Originating run-context trace id.
     * @param int                $committedAtUnix     Wall-clock unix timestamp of the original commit.
     * @param int                $procedureOrderId    procedure_order primary key.
     * @param int                $procedureReportId   procedure_report primary key.
     * @param list<int>          $procedureResultIds  procedure_result primary keys.
     */
    public function __construct(
        public string $idempotencyKey,
        public string $traceId,
        public int $committedAtUnix,
        public int $procedureOrderId,
        public int $procedureReportId,
        public array $procedureResultIds,
    ) {
    }

    /**
     * @return array{
     *     idempotency_key: string,
     *     trace_id: string,
     *     committed_at_unix: int,
     *     procedure_order_id: int,
     *     procedure_report_id: int,
     *     procedure_result_ids: list<int>
     * }
     */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'trace_id' => $this->traceId,
            'committed_at_unix' => $this->committedAtUnix,
            'procedure_order_id' => $this->procedureOrderId,
            'procedure_report_id' => $this->procedureReportId,
            'procedure_result_ids' => $this->procedureResultIds,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $idempotencyKey = self::requireString($data, 'idempotency_key');
        $traceId = self::requireString($data, 'trace_id');
        $committedAtUnix = self::requireInt($data, 'committed_at_unix');
        $procedureOrderId = self::requireInt($data, 'procedure_order_id');
        $procedureReportId = self::requireInt($data, 'procedure_report_id');
        $resultIds = self::requireIntList($data, 'procedure_result_ids');

        return new self(
            idempotencyKey: $idempotencyKey,
            traceId: $traceId,
            committedAtUnix: $committedAtUnix,
            procedureOrderId: $procedureOrderId,
            procedureReportId: $procedureReportId,
            procedureResultIds: $resultIds,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \DomainException(
                "CommittedProposalRecord: missing required string '{$key}'.",
            );
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \DomainException(
                "CommittedProposalRecord: missing required int '{$key}'.",
            );
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    private static function requireIntList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException(
                "CommittedProposalRecord: '{$key}' must be a list.",
            );
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new \DomainException(
                    "CommittedProposalRecord: '{$key}' must contain only ints.",
                );
            }
            $result[] = $item;
        }
        return $result;
    }
}
