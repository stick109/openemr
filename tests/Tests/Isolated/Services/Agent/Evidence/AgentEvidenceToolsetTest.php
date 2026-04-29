<?php

/**
 * AgentEvidenceToolsetTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Evidence;

use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\AgentPatientContext;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceAccessException;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceToolset;
use OpenEMR\Services\Agent\Evidence\EvidenceCaps;
use OpenEMR\Services\Agent\Evidence\EvidenceRecordRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class AgentEvidenceToolsetTest extends TestCase
{
    public function testBuildsNormalizedMedicationEvidencePacketWithCapsAndTiming(): void
    {
        $catalog = new AgentIntentCatalog();
        $toolset = $this->toolset(new AgentEvidenceToolsetRepositoryFixture(), 'request-123');

        $packet = $toolset->buildPacket(
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            $this->accessToken(),
            $catalog->get(AgentIntentCatalog::CURRENT_MEDICATIONS)
        );

        $this->assertSame('request-123', $packet['request_id']);
        $this->assertSame(AgentIntentCatalog::CURRENT_MEDICATIONS, $packet['intent_id']);
        $this->assertSame(['max_records' => 25, 'max_documents' => 0, 'lookback_days' => 365], $packet['caps']);
        $this->assertSame(['medications'], $packet['checked_evidence']);
        $this->assertCount(1, $packet['sources']);
        $this->assertSame('medication:lists_medication:77', $packet['sources'][0]['source_id']);
        $this->assertSame('Metformin 500 mg twice daily', $packet['sources'][0]['display']);
        $this->assertSame('get_current_medications', $packet['tool_runs'][0]['tool']);
        $this->assertSame(1, $packet['tool_runs'][0]['source_count']);
        $this->assertNull($packet['tool_runs'][0]['error_class']);
    }

    public function testRejectsToolWhenBrokerTokenDoesNotGrantIntent(): void
    {
        $this->expectException(AgentEvidenceAccessException::class);
        $this->expectExceptionMessage('Organization policy does not permit this agent evidence tool.');

        $catalog = new AgentIntentCatalog();
        $toolset = $this->toolset(new AgentEvidenceToolsetRepositoryFixture(), 'request-123');

        $toolset->buildPacket(
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            $this->accessToken(grantedDataClasses: ['demographics'], grantedTools: [AgentIntentCatalog::BASIC_PATIENT_DATA]),
            $catalog->get(AgentIntentCatalog::CURRENT_MEDICATIONS)
        );
    }

    public function testRejectsEvidenceForAnotherPatient(): void
    {
        $this->expectException(AgentEvidenceAccessException::class);
        $this->expectExceptionMessage('Evidence source does not belong to the current patient.');

        $catalog = new AgentIntentCatalog();
        $toolset = $this->toolset(new AgentEvidenceToolsetRepositoryFixture(patientId: 999), 'request-123');

        $toolset->buildPacket(
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            $this->accessToken(),
            $catalog->get(AgentIntentCatalog::CURRENT_MEDICATIONS)
        );
    }

    public function testSourceDrilldownReturnsOneServerIssuedSource(): void
    {
        $catalog = new AgentIntentCatalog();
        $toolset = $this->toolset(new AgentEvidenceToolsetRepositoryFixture(), 'request-123');

        $packet = $toolset->buildPacket(
            AgentIntentCatalog::SHOW_SOURCE,
            $this->accessToken(),
            $catalog->get(AgentIntentCatalog::SHOW_SOURCE),
            'medication:lists_medication:77'
        );

        $this->assertCount(1, $packet['sources']);
        $this->assertSame('medication:lists_medication:77', $packet['sources'][0]['source_id']);
        $this->assertSame(['medications'], $packet['checked_evidence']);
        $this->assertSame('get_source_detail', $packet['tool_runs'][0]['tool']);
    }

    private function toolset(EvidenceRecordRepositoryInterface $repository, string $requestId): AgentEvidenceToolset
    {
        return new AgentEvidenceToolset(
            repository: $repository,
            logger: new NullLogger(),
            requestIdFactory: static fn (): string => $requestId
        );
    }

    /**
     * @param list<string> $grantedDataClasses
     * @param list<string> $grantedTools
     */
    private function accessToken(
        array $grantedDataClasses = ['demographics', 'medications', 'allergies', 'recent_events'],
        array $grantedTools = [
            AgentIntentCatalog::BASIC_PATIENT_DATA,
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
            AgentIntentCatalog::RECENT_EVENTS,
            AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT,
            AgentIntentCatalog::SHOW_SOURCE,
        ]
    ): AgentAccessToken {
        return new AgentAccessToken(
            'test-token',
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            new AgentPatientContext(123),
            $grantedDataClasses,
            $grantedTools,
            [],
            1234567890
        );
    }
}

final class AgentEvidenceToolsetRepositoryFixture implements EvidenceRecordRepositoryInterface
{
    public function __construct(private readonly int $patientId = 123)
    {
    }

    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        return [];
    }

    public function fetchCurrentMedications(int $pid, EvidenceCaps $caps): array
    {
        return [$this->medicationRecord()];
    }

    public function fetchAllergiesToConfirm(int $pid, EvidenceCaps $caps): array
    {
        return [];
    }

    public function fetchRecentEvents(int $pid, EvidenceCaps $caps): array
    {
        return [];
    }

    public function fetchChangedSinceLastVisit(int $pid, EvidenceCaps $caps, array $grantedDataClasses): array
    {
        return [];
    }

    public function fetchSourceRecord(int $pid, string $sourceId, EvidenceCaps $caps): ?array
    {
        return $sourceId === 'medication:lists_medication:77' ? $this->medicationRecord() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function medicationRecord(): array
    {
        return [
            'source_id' => 'medication:lists_medication:77',
            'source_type' => 'medication',
            'data_class' => 'medications',
            'table' => 'lists_medication',
            'record_id' => '77',
            'patient_id' => $this->patientId,
            'date' => '2026-04-20',
            'status' => 'active',
            'display' => 'Metformin 500 mg twice daily',
            'fields_used' => ['title', 'drug_dosage_instructions'],
            'reliability' => 'structured_active_record',
        ];
    }
}
