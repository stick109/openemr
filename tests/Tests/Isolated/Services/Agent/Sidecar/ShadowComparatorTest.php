<?php

/**
 * ShadowComparatorTest
 *
 * Verifies that the M18 ShadowComparator builds sanitized comparison
 * records and never propagates claim text, evidence bodies, citation
 * snippets, or any patient identifiers beyond the trace id and intent
 * id supplied by the caller.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Sidecar;

use DateTimeImmutable;
use OpenEMR\Services\Agent\Sidecar\AnswerBlockDto;
use OpenEMR\Services\Agent\Sidecar\CitationDto;
use OpenEMR\Services\Agent\Sidecar\CopilotRunResponseDto;
use OpenEMR\Services\Agent\Sidecar\ShadowComparator;
use OpenEMR\Services\Agent\Sidecar\ShadowComparisonRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
final class ShadowComparatorTest extends TestCase
{
    private const TRACE_ID = 'trace-shadow-001';
    private const INTENT_ID = 'current_medications';

    /**
     * Forbidden text that may appear in real PHP responses or sidecar
     * payloads but must NEVER be carried over to the comparison record.
     */
    private const PHI_BEARING_STRINGS = [
        'Lisinopril 10 mg PO daily',
        'Atorvastatin 20 mg PO nightly',
        'Patient John Doe',
        'Last refill date for atorvastatin not confirmed.',
        'verbatim guideline excerpt',
        'A verified answer is not available from the checked evidence',
    ];

    public function testIdenticalResponsesProduceAllMatches(): void
    {
        $phpResponse = $this->phpResponse(
            verificationStatus: 'passed',
            citations: [
                ['source_id' => 'medication:lists_medication:77'],
                ['source_id' => 'guideline:chunk:hypertension-2024-12'],
            ],
            answerBlocks: [
                ['heading' => 'Current medications', 'claims' => [['text' => 'Lisinopril 10 mg PO daily']]],
            ],
            missingOrUncertain: [],
        );
        $sidecarResponse = $this->sidecarResponse(
            verificationStatus: 'passed',
            citations: [
                ['medication', 'medication:lists_medication:77'],
                ['guideline', 'guideline:chunk:hypertension-2024-12'],
            ],
            answerBlockHeadings: ['Current medications'],
            missingOrUncertainCount: 0,
        );

        $record = (new ShadowComparator())->compare(
            $phpResponse,
            $sidecarResponse,
            self::TRACE_ID,
            self::INTENT_ID,
        );

        $this->assertTrue($record->verificationStatusMatch);
        $this->assertTrue($record->citedSourceIdsMatch);
        $this->assertSame(2, $record->phpCitedCount);
        $this->assertSame(2, $record->sidecarCitedCount);
        $this->assertTrue($record->missingnessShapeMatch);
        $this->assertTrue($record->headingsMatch);
        $this->assertSame(['Current medications'], $record->phpAnswerBlockHeadings);
        $this->assertSame(['Current medications'], $record->sidecarAnswerBlockHeadings);
    }

    public function testHeadingsMatchIgnoresCaseAndWhitespace(): void
    {
        $phpResponse = $this->phpResponse(
            verificationStatus: 'passed',
            citations: [],
            answerBlocks: [['heading' => 'Active Medications', 'claims' => []]],
            missingOrUncertain: [],
        );
        $sidecarResponse = $this->sidecarResponse(
            verificationStatus: 'passed',
            citations: [],
            answerBlockHeadings: ['  active medications  '],
            missingOrUncertainCount: 0,
        );

        $record = (new ShadowComparator())->compare(
            $phpResponse,
            $sidecarResponse,
            self::TRACE_ID,
            self::INTENT_ID,
        );

        $this->assertTrue($record->headingsMatch);
        $this->assertSame(['Active Medications'], $record->phpAnswerBlockHeadings);
        $this->assertSame(['  active medications  '], $record->sidecarAnswerBlockHeadings);
    }

    public function testDifferentVerificationStatuses(): void
    {
        $phpResponse = $this->phpResponse(
            verificationStatus: 'failed',
            citations: [],
            answerBlocks: [],
            missingOrUncertain: [],
        );
        $sidecarResponse = $this->sidecarResponse(
            verificationStatus: 'passed',
            citations: [],
            answerBlockHeadings: [],
            missingOrUncertainCount: 0,
        );

        $record = (new ShadowComparator())->compare(
            $phpResponse,
            $sidecarResponse,
            self::TRACE_ID,
            self::INTENT_ID,
        );

        $this->assertFalse($record->verificationStatusMatch);
    }

    public function testRefusedSidecarStatusDiffersFromPhpPassed(): void
    {
        $phpResponse = $this->phpResponse(
            verificationStatus: 'passed',
            citations: [],
            answerBlocks: [],
            missingOrUncertain: [],
        );
        $sidecarResponse = $this->sidecarResponse(
            verificationStatus: 'refused',
            citations: [],
            answerBlockHeadings: [],
            missingOrUncertainCount: 0,
        );

        $record = (new ShadowComparator())->compare(
            $phpResponse,
            $sidecarResponse,
            self::TRACE_ID,
            self::INTENT_ID,
        );

        $this->assertFalse($record->verificationStatusMatch);
    }

    public function testDifferentCitedSetsAndCounts(): void
    {
        $phpResponse = $this->phpResponse(
            verificationStatus: 'passed',
            citations: [
                ['source_id' => 'medication:lists_medication:77'],
            ],
            answerBlocks: [],
            missingOrUncertain: [],
        );
        $sidecarResponse = $this->sidecarResponse(
            verificationStatus: 'passed',
            citations: [
                ['medication', 'medication:lists_medication:77'],
                ['medication', 'medication:lists_medication:88'],
            ],
            answerBlockHeadings: [],
            missingOrUncertainCount: 0,
        );

        $record = (new ShadowComparator())->compare(
            $phpResponse,
            $sidecarResponse,
            self::TRACE_ID,
            self::INTENT_ID,
        );

        $this->assertFalse($record->citedSourceIdsMatch);
        $this->assertSame(1, $record->phpCitedCount);
        $this->assertSame(2, $record->sidecarCitedCount);
    }

    public function testDifferentHeadingSets(): void
    {
        $phpResponse = $this->phpResponse(
            verificationStatus: 'passed',
            citations: [],
            answerBlocks: [
                ['heading' => 'Active medications', 'claims' => []],
                ['heading' => 'Allergies', 'claims' => []],
            ],
            missingOrUncertain: [],
        );
        $sidecarResponse = $this->sidecarResponse(
            verificationStatus: 'passed',
            citations: [],
            answerBlockHeadings: ['Current Medications', 'Allergies'],
            missingOrUncertainCount: 0,
        );

        $record = (new ShadowComparator())->compare(
            $phpResponse,
            $sidecarResponse,
            self::TRACE_ID,
            self::INTENT_ID,
        );

        $this->assertFalse($record->headingsMatch);
        // Both heading sources still present unchanged for human review.
        $this->assertSame(['Active medications', 'Allergies'], $record->phpAnswerBlockHeadings);
        $this->assertSame(['Current Medications', 'Allergies'], $record->sidecarAnswerBlockHeadings);
    }

    public function testMissingnessShapeMismatch(): void
    {
        $phpResponse = $this->phpResponse(
            verificationStatus: 'passed',
            citations: [],
            answerBlocks: [],
            missingOrUncertain: [
                ['text' => 'Last refill date for atorvastatin not confirmed.'],
            ],
        );
        $sidecarResponse = $this->sidecarResponse(
            verificationStatus: 'passed',
            citations: [],
            answerBlockHeadings: [],
            missingOrUncertainCount: 0,
        );

        $record = (new ShadowComparator())->compare(
            $phpResponse,
            $sidecarResponse,
            self::TRACE_ID,
            self::INTENT_ID,
        );

        $this->assertFalse($record->missingnessShapeMatch);
    }

    public function testRecordContainsNoClaimOrBodyText(): void
    {
        $phpResponse = $this->phpResponse(
            verificationStatus: 'passed',
            citations: [
                ['source_id' => 'medication:lists_medication:77'],
            ],
            answerBlocks: [
                [
                    'heading' => 'Current medications',
                    'claims' => [
                        ['text' => 'Lisinopril 10 mg PO daily'],
                        ['text' => 'Atorvastatin 20 mg PO nightly'],
                    ],
                ],
            ],
            missingOrUncertain: [
                ['text' => 'Last refill date for atorvastatin not confirmed.'],
            ],
        );
        $sidecarResponse = $this->sidecarResponse(
            verificationStatus: 'passed',
            citations: [
                ['medication', 'medication:lists_medication:77'],
            ],
            answerBlockHeadings: ['Current medications'],
            missingOrUncertainCount: 1,
            phiSnippet: 'verbatim guideline excerpt',
        );

        $record = (new ShadowComparator())->compare(
            $phpResponse,
            $sidecarResponse,
            self::TRACE_ID,
            self::INTENT_ID,
        );

        $haystack = $this->serializeRecordForLeakAudit($record);
        foreach (self::PHI_BEARING_STRINGS as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $haystack,
                sprintf('Comparison record must not include PHI-bearing text: %s', $forbidden),
            );
        }

        // Sanity: the record carries trace/intent ids and headings only.
        $this->assertSame(self::TRACE_ID, $record->traceId);
        $this->assertSame(self::INTENT_ID, $record->intentId);
        $this->assertSame(['Current medications'], $record->phpAnswerBlockHeadings);
        $this->assertSame(['Current medications'], $record->sidecarAnswerBlockHeadings);
        $this->assertInstanceOf(DateTimeImmutable::class, $record->comparedAt);
    }

    /**
     * Renders all string-shaped fields of a record into a single haystack
     * so the no-PHI assertion can scan everything in one pass.
     */
    private function serializeRecordForLeakAudit(ShadowComparisonRecord $record): string
    {
        $parts = [
            $record->traceId,
            $record->intentId,
            implode('|', $record->phpAnswerBlockHeadings),
            implode('|', $record->sidecarAnswerBlockHeadings),
        ];
        return implode("\n", $parts);
    }

    /**
     * @param list<array<string, mixed>> $citations
     * @param list<array<string, mixed>> $answerBlocks
     * @param list<array<string, mixed>> $missingOrUncertain
     * @return array<string, mixed>
     */
    private function phpResponse(
        string $verificationStatus,
        array $citations,
        array $answerBlocks,
        array $missingOrUncertain,
    ): array {
        return [
            'status' => 'verified',
            'verification' => ['status' => $verificationStatus],
            'citations' => $citations,
            'answer' => [
                'answer_blocks' => $answerBlocks,
                'missing_or_uncertain' => $missingOrUncertain,
            ],
        ];
    }

    /**
     * @param list<array{0: string, 1: string}> $citations  [source_type, source_id]
     * @param list<string>                      $answerBlockHeadings
     */
    private function sidecarResponse(
        string $verificationStatus,
        array $citations,
        array $answerBlockHeadings,
        int $missingOrUncertainCount,
        ?string $phiSnippet = null,
    ): CopilotRunResponseDto {
        $citationDtos = [];
        foreach ($citations as [$sourceType, $sourceId]) {
            $citationDtos[] = new CitationDto(
                sourceType: $sourceType,
                sourceId: $sourceId,
                label: $sourceId,
                url: null,
                snippet: $phiSnippet,
            );
        }
        $answerBlockDtos = [];
        foreach ($answerBlockHeadings as $heading) {
            $answerBlockDtos[] = new AnswerBlockDto(
                type: 'paragraph',
                content: 'sidecar-content (intentionally PHI-bearing for the leak audit test)',
                citationIndices: [],
                heading: $heading,
            );
        }
        $missing = array_fill(0, $missingOrUncertainCount, 'sidecar-missingness-note');

        return new CopilotRunResponseDto(
            answerBlocks: $answerBlockDtos,
            missingOrUncertain: $missing,
            citations: $citationDtos,
            toolSequence: [],
            verificationStatus: $verificationStatus,
            costUsd: 0.0,
            latencyMsPerStep: [],
            traceId: self::TRACE_ID,
        );
    }
}
