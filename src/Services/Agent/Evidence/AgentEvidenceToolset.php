<?php

/**
 * AgentEvidenceToolset
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Evidence;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use Psr\Log\LoggerInterface;
use Throwable;

final class AgentEvidenceToolset
{
    /**
     * @var array<string, string>
     */
    private const TOOL_NAMES = [
        AgentIntentCatalog::BASIC_PATIENT_DATA => 'get_patient_snapshot',
        AgentIntentCatalog::CURRENT_MEDICATIONS => 'get_current_medications',
        AgentIntentCatalog::ALLERGIES_TO_CONFIRM => 'get_allergies_to_confirm',
        AgentIntentCatalog::RECENT_EVENTS => 'get_recent_events',
        AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT => 'get_changed_since_last_visit',
        AgentIntentCatalog::SHOW_SOURCE => 'get_source_detail',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const REQUIRED_DATA_CLASSES = [
        AgentIntentCatalog::BASIC_PATIENT_DATA => ['demographics'],
        AgentIntentCatalog::CURRENT_MEDICATIONS => ['medications'],
        AgentIntentCatalog::ALLERGIES_TO_CONFIRM => ['allergies'],
        AgentIntentCatalog::RECENT_EVENTS => ['recent_events'],
        AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT => ['recent_events'],
    ];

    /**
     * @var callable(): string
     */
    private $requestIdFactory;

    /**
     * @var list<array{tool: string, source_count: int, latency_ms: int, error_class: ?string}>
     */
    private array $toolRuns = [];

    public function __construct(
        private readonly EvidenceRecordRepositoryInterface $repository = new SqlEvidenceRecordRepository(),
        private readonly EvidencePacketNormalizer $normalizer = new EvidencePacketNormalizer(),
        private readonly LoggerInterface $logger = new SystemLogger(),
        ?callable $requestIdFactory = null
    ) {
        $this->requestIdFactory = $requestIdFactory ?? static fn (): string => bin2hex(random_bytes(8));
    }

    public function supportsIntent(string $intentId): bool
    {
        return isset(self::TOOL_NAMES[$intentId]);
    }

    /**
     * @param array<string, mixed> $intent
     * @return array{
     *     request_id: string,
     *     intent_id: string,
     *     caps: array{max_records: int, max_documents: int, lookback_days: int},
     *     sources: list<array<string, mixed>>,
     *     checked_evidence: list<string>,
     *     tool_runs: list<array{tool: string, source_count: int, latency_ms: int, error_class: ?string}>
     * }
     */
    public function buildPacket(
        string $intentId,
        AgentAccessToken $accessToken,
        array $intent,
        ?string $sourceId = null,
        ?string $requestId = null
    ): array {
        if (!$this->supportsIntent($intentId)) {
            throw new AgentEvidenceAccessException('unsupported_tool', 'Evidence retrieval is not available for this intent.');
        }

        $this->toolRuns = [];
        $requestId = $this->newRequestId($requestId);
        $caps = EvidenceCaps::fromIntent($intent);
        $rawRecords = $this->readRecords($requestId, $intentId, $accessToken, $caps, $sourceId);
        $sources = $this->normalizer->normalize($accessToken, $rawRecords);

        foreach ($sources as $source) {
            $this->ensureDataClassAllowed($accessToken, (string) ($source['data_class'] ?? ''));
        }

        return [
            'request_id' => $requestId,
            'intent_id' => $intentId,
            'caps' => $caps->toArray(),
            'sources' => $sources,
            'checked_evidence' => $this->checkedEvidence($sources),
            'tool_runs' => $this->toolRuns,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readRecords(
        string $requestId,
        string $intentId,
        AgentAccessToken $accessToken,
        EvidenceCaps $caps,
        ?string $sourceId
    ): array {
        if ($intentId === AgentIntentCatalog::SHOW_SOURCE) {
            $this->ensureToolAllowed($accessToken, AgentIntentCatalog::SHOW_SOURCE);
            if ($sourceId === null || $sourceId === '') {
                return [];
            }

            $source = $this->timedRead(
                $requestId,
                self::TOOL_NAMES[$intentId],
                fn (): array => $this->repository->fetchSourceRecord(
                    $accessToken->getPatientContext()->getPid(),
                    $sourceId,
                    $caps
                ) ?? []
            );

            return $source === [] || array_is_list($source) ? $source : [$source];
        }

        $this->ensureIntentAllowed($accessToken, $intentId);

        return $this->timedRead(
            $requestId,
            self::TOOL_NAMES[$intentId],
            fn (): array => match ($intentId) {
                AgentIntentCatalog::BASIC_PATIENT_DATA => $this->repository->fetchBasicPatientData(
                    $accessToken->getPatientContext()->getPid(),
                    $caps
                ),
                AgentIntentCatalog::CURRENT_MEDICATIONS => $this->repository->fetchCurrentMedications(
                    $accessToken->getPatientContext()->getPid(),
                    $caps
                ),
                AgentIntentCatalog::ALLERGIES_TO_CONFIRM => $this->repository->fetchAllergiesToConfirm(
                    $accessToken->getPatientContext()->getPid(),
                    $caps
                ),
                AgentIntentCatalog::RECENT_EVENTS => $this->repository->fetchRecentEvents(
                    $accessToken->getPatientContext()->getPid(),
                    $caps
                ),
                AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT => $this->repository->fetchChangedSinceLastVisit(
                    $accessToken->getPatientContext()->getPid(),
                    $caps,
                    $accessToken->getGrantedDataClasses()
                ),
                default => [],
            }
        );
    }

    private function ensureIntentAllowed(AgentAccessToken $accessToken, string $intentId): void
    {
        $this->ensureToolAllowed($accessToken, $intentId);
        foreach (self::REQUIRED_DATA_CLASSES[$intentId] ?? [] as $dataClass) {
            $this->ensureDataClassAllowed($accessToken, $dataClass);
        }
    }

    private function ensureToolAllowed(AgentAccessToken $accessToken, string $intentId): void
    {
        if (!in_array($intentId, $accessToken->getGrantedTools(), true)) {
            throw new AgentEvidenceAccessException(
                'tool_not_granted',
                'Organization policy does not permit this agent evidence tool.'
            );
        }
    }

    private function ensureDataClassAllowed(AgentAccessToken $accessToken, string $dataClass): void
    {
        if ($dataClass === '' || !in_array($dataClass, $accessToken->getGrantedDataClasses(), true)) {
            throw new AgentEvidenceAccessException(
                'data_class_not_granted',
                'Organization policy does not permit this agent evidence source.'
            );
        }
    }

    /**
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    private function timedRead(string $requestId, string $toolName, callable $reader): array
    {
        $startedAt = hrtime(true);
        $sourceCount = 0;
        $errorClass = null;

        try {
            $records = $reader();
            $sourceCount = array_is_list($records) ? count($records) : ($records === [] ? 0 : 1);
            return $records;
        } catch (Throwable $throwable) {
            $errorClass = $throwable::class;
            throw $throwable;
        } finally {
            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1000000);
            $summary = [
                'tool' => $toolName,
                'source_count' => $sourceCount,
                'latency_ms' => $latencyMs,
                'error_class' => $errorClass,
            ];
            $this->toolRuns[] = $summary;
            $this->logger->info('agent.tool.finished', [
                'request_id' => $requestId,
                'tool' => $toolName,
                'source_count' => $sourceCount,
                'latency_ms' => $latencyMs,
                'error_class' => $errorClass,
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @return list<string>
     */
    private function checkedEvidence(array $sources): array
    {
        $checked = [];
        foreach ($sources as $source) {
            $dataClass = $source['data_class'] ?? null;
            if (is_string($dataClass) && $dataClass !== '') {
                $checked[] = $dataClass;
            }
        }

        return array_values(array_unique($checked));
    }

    private function newRequestId(?string $requestId = null): string
    {
        if (is_string($requestId) && preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $requestId) === 1) {
            return $requestId;
        }

        $requestId = ($this->requestIdFactory)();
        return is_string($requestId) && $requestId !== '' ? $requestId : bin2hex(random_bytes(8));
    }
}
