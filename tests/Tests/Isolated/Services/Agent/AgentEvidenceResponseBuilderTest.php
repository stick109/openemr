<?php

/**
 * AgentEvidenceResponseBuilderTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent;

use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentEvidenceResponseBuilder;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\AgentLlmOrchestrator;
use OpenEMR\Services\Agent\AgentPatientContext;
use OpenEMR\Services\Agent\Anonymizer;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceToolset;
use OpenEMR\Services\Agent\Evidence\EvidenceCaps;
use OpenEMR\Services\Agent\Evidence\EvidenceRecordRepositoryInterface;
use OpenEMR\Services\Agent\Llm\DisabledAgentLlmProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class AgentEvidenceResponseBuilderTest extends TestCase
{
    public function testCurrentMedicationDeterministicAnswerStaysVerifiedWithExpandedEvidence(): void
    {
        $builder = new AgentEvidenceResponseBuilder(
            toolset: new AgentEvidenceToolset(
                repository: new AgentEvidenceResponseBuilderMedicationRepository(),
                logger: new NullLogger(),
                requestIdFactory: static fn (): string => 'request-current-meds'
            ),
            anonymizer: new Anonymizer(logger: new NullLogger()),
            llmOrchestrator: new AgentLlmOrchestrator(
                provider: new DisabledAgentLlmProvider(),
                logger: new NullLogger()
            ),
            logger: new NullLogger()
        );

        $response = $builder->build(AgentIntentCatalog::CURRENT_MEDICATIONS, $this->accessToken());
        $claimTexts = array_column($response['answer']['answer_blocks'][0]['claims'], 'text');
        $claimText = implode(
            "\n",
            $claimTexts
        );

        $this->assertSame('verified', $response['status']);
        $this->assertSame('deterministic_verified', $response['response_generation']);
        $this->assertSame('passed', $response['verification']['status']);
        $this->assertCount(25, $response['answer']['answer_blocks'][0]['claims']);
        $this->assertStringStartsWith('Medication 1', $claimTexts[0]);
        $this->assertStringNotContainsString('medication: Medication 1', $claimText);
        $this->assertStringContainsString('status: active', $claimText);
        $this->assertStringNotContainsString('A verified answer is not available', $claimText);
        $this->assertStringNotContainsString('start date', $claimText);
        $this->assertLessThan(4000, strlen($claimText));
    }

    public function testAllergyReviewMarkerProducesVerifiedNoCurrentAllergyAnswer(): void
    {
        $builder = new AgentEvidenceResponseBuilder(
            toolset: new AgentEvidenceToolset(
                repository: new AgentEvidenceResponseBuilderAllergyReviewRepository(),
                logger: new NullLogger(),
                requestIdFactory: static fn (): string => 'request-allergy-review'
            ),
            anonymizer: new Anonymizer(logger: new NullLogger()),
            llmOrchestrator: new AgentLlmOrchestrator(
                provider: new DisabledAgentLlmProvider(),
                logger: new NullLogger()
            ),
            logger: new NullLogger()
        );

        $response = $builder->build(AgentIntentCatalog::ALLERGIES_TO_CONFIRM, $this->allergyAccessToken());
        $claimText = implode(
            "\n",
            array_column($response['answer']['answer_blocks'][0]['claims'], 'text')
        );

        $this->assertSame('verified', $response['status']);
        $this->assertSame('deterministic_verified', $response['response_generation']);
        $this->assertSame('passed', $response['verification']['status']);
        $this->assertSame(['allergies'], $response['checked_evidence']);
        $this->assertStringContainsString('Current allergy records were not found in checked evidence.', $claimText);
        $this->assertStringContainsString('Allergy list review marker: reviewed/touched on 2026-04-30 11:22:33', $claimText);
    }

    public function testAllergyDeterministicAnswerStaysVerifiedWithExpandedEvidence(): void
    {
        $builder = new AgentEvidenceResponseBuilder(
            toolset: new AgentEvidenceToolset(
                repository: new AgentEvidenceResponseBuilderExpandedAllergyRepository(),
                logger: new NullLogger(),
                requestIdFactory: static fn (): string => 'request-expanded-allergies'
            ),
            anonymizer: new Anonymizer(logger: new NullLogger()),
            llmOrchestrator: new AgentLlmOrchestrator(
                provider: new DisabledAgentLlmProvider(),
                logger: new NullLogger()
            ),
            logger: new NullLogger()
        );

        $response = $builder->build(AgentIntentCatalog::ALLERGIES_TO_CONFIRM, $this->allergyAccessToken());
        $claimText = implode(
            "\n",
            array_column($response['answer']['answer_blocks'][0]['claims'], 'text')
        );

        $this->assertSame('verified', $response['status']);
        $this->assertSame('deterministic_verified', $response['response_generation']);
        $this->assertSame('passed', $response['verification']['status']);
        $this->assertCount(25, $response['answer']['answer_blocks'][0]['claims']);
        $this->assertStringContainsString('allergen: Allergy 1', $claimText);
        $this->assertStringContainsString('coded allergen: Penicillin (penicillin)', $claimText);
        $this->assertStringNotContainsString('external allergy id', $claimText);
        $this->assertLessThan(4000, strlen($claimText));
    }

    private function accessToken(): AgentAccessToken
    {
        return new AgentAccessToken(
            'token',
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            new AgentPatientContext(123),
            ['medications'],
            [AgentIntentCatalog::CURRENT_MEDICATIONS, AgentIntentCatalog::SHOW_SOURCE],
            [],
            1234567890
        );
    }

    private function allergyAccessToken(): AgentAccessToken
    {
        return new AgentAccessToken(
            'token',
            AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
            new AgentPatientContext(123),
            ['allergies'],
            [AgentIntentCatalog::ALLERGIES_TO_CONFIRM, AgentIntentCatalog::SHOW_SOURCE],
            [],
            1234567890
        );
    }
}

final class AgentEvidenceResponseBuilderMedicationRepository implements EvidenceRecordRepositoryInterface
{
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        return [];
    }

    public function fetchCurrentMedications(int $pid, EvidenceCaps $caps): array
    {
        $records = [];
        for ($index = 1; $index <= 25; $index++) {
            $records[] = [
                'source_id' => 'medication:lists_medication:' . $index,
                'source_type' => 'medication',
                'data_class' => 'medications',
                'table' => 'lists_medication',
                'record_id' => (string) $index,
                'patient_id' => 123,
                'date' => '2026-04-20',
                'status' => 'active',
                'display' => 'medication: Medication ' . $index
                    . '; status: active'
                    . '; start date: 2026-04-01 00:00:00'
                    . '; prescription start date: 2026-04-01'
                    . '; dosage instructions: Take ' . $index . ' tablet by mouth twice daily with meals'
                    . '; request intent: Order (order)'
                    . '; linked prescription drug: Medication ' . $index . ' 10 mg tablet'
                    . '; rxnorm: RX' . $index
                    . '; prescribing provider id: 42'
                    . '; prescription note: Long note value that should remain available in source evidence but should not make the deterministic answer exceed the verifier text limit.',
                'fields_used' => ['title', 'activity', 'begdate', 'drug_dosage_instructions', 'request_intent'],
                'reliability' => 'structured_active_record',
            ];
        }

        return $records;
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
        return null;
    }
}

final class AgentEvidenceResponseBuilderAllergyReviewRepository implements EvidenceRecordRepositoryInterface
{
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        return [];
    }

    public function fetchCurrentMedications(int $pid, EvidenceCaps $caps): array
    {
        return [];
    }

    public function fetchAllergiesToConfirm(int $pid, EvidenceCaps $caps): array
    {
        return [
            [
                'source_id' => 'allergy:lists_touch:123',
                'source_type' => 'allergy_review',
                'data_class' => 'allergies',
                'table' => 'lists_touch',
                'record_id' => '123',
                'patient_id' => 123,
                'date' => '2026-04-30 11:22:33',
                'status' => 'reviewed',
                'display' => 'Allergy list review marker: reviewed/touched on 2026-04-30 11:22:33',
                'fields_used' => ['pid', 'type', 'date'],
                'reliability' => 'structured_allergy_review_marker',
            ],
        ];
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
        return null;
    }
}

final class AgentEvidenceResponseBuilderExpandedAllergyRepository implements EvidenceRecordRepositoryInterface
{
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        return [];
    }

    public function fetchCurrentMedications(int $pid, EvidenceCaps $caps): array
    {
        return [];
    }

    public function fetchAllergiesToConfirm(int $pid, EvidenceCaps $caps): array
    {
        $records = [];
        for ($index = 1; $index <= 25; $index++) {
            $records[] = [
                'source_id' => 'allergy:lists:' . $index,
                'source_type' => 'allergy',
                'data_class' => 'allergies',
                'table' => 'lists',
                'record_id' => (string) $index,
                'patient_id' => 123,
                'date' => '2026-04-20',
                'status' => 'Confirmed',
                'display' => 'allergen: Allergy ' . $index
                    . '; coded allergen: Penicillin (penicillin)'
                    . '; reaction: Hives (hives)'
                    . '; severity: Mild (mild)'
                    . '; verification status: Confirmed (confirmed)'
                    . '; current status: current'
                    . '; begin date: 2026-04-01 00:00:00'
                    . '; subtype: drug'
                    . '; diagnosis: Z88.0'
                    . '; allergy eRx source: external/eRx'
                    . '; external allergy id: EXT-' . $index
                    . '; external list id: LIST-' . $index
                    . '; comments: Long allergy comment that should remain available in source evidence but should not make the deterministic answer exceed the verifier text limit.',
                'fields_used' => ['title', 'list_option_id', 'reaction', 'severity_al', 'verification'],
                'reliability' => 'structured_active_record',
            ];
        }

        return $records;
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
        return null;
    }
}
