<?php

/**
 * CopilotRunContractTest
 *
 * Isolated tests for the M2 wire-contract DTOs that pair with the
 * Python ``CopilotRunRequest`` / ``CopilotRunResponse`` schemas in
 * ``agent-service/agent_service/schemas/copilot.py``.
 *
 * Coverage:
 *   - Request DTO ``toArray()`` produces the snake_case wire shape.
 *   - Request DTO rejects empty run_context, empty request_id, and
 *     missing both intent_id / user_goal.
 *   - Request DTO rejects user_goal over the 4000-char cap.
 *   - Response DTO ``fromArray()`` populates every field including
 *     nested AnswerBlock / Citation / ToolCallRecord lists.
 *
 * These tests run on the host without a database -- no Docker required.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Sidecar;

use InvalidArgumentException;
use OpenEMR\Services\Agent\Sidecar\AnswerBlockDto;
use OpenEMR\Services\Agent\Sidecar\CitationDto;
use OpenEMR\Services\Agent\Sidecar\CopilotRunRequestDto;
use OpenEMR\Services\Agent\Sidecar\CopilotRunResponseDto;
use OpenEMR\Services\Agent\Sidecar\ToolCallRecordDto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class CopilotRunContractTest extends TestCase
{
    private const SIGNED_TOKEN = 'signed.token.opaque';
    private const REQUEST_ID = '11111111-2222-4333-8444-555555555555';
    private const TRACE_ID = 'aaaaaaaa-bbbb-4ccc-dddd-eeeeeeeeeeee';

    // ------------------------------------------------------------------
    // Request DTO
    // ------------------------------------------------------------------

    public function testRequestDtoToArrayProducesWireShape(): void
    {
        $dto = new CopilotRunRequestDto(
            runContext: self::SIGNED_TOKEN,
            intentId: 'current_medications',
            userGoal: null,
            requestId: self::REQUEST_ID,
            conversationState: ['page' => 2],
        );

        $expected = [
            'run_context' => self::SIGNED_TOKEN,
            'intent_id' => 'current_medications',
            'user_goal' => null,
            'request_id' => self::REQUEST_ID,
            'conversation_state' => ['page' => 2],
            'source_id' => null,
        ];

        $this->assertSame($expected, $dto->toArray());
    }

    public function testRequestDtoAcceptsUserGoalWithoutIntent(): void
    {
        $dto = new CopilotRunRequestDto(
            runContext: self::SIGNED_TOKEN,
            intentId: null,
            userGoal: 'Summarize this patient\'s active medications.',
            requestId: self::REQUEST_ID,
            conversationState: null,
        );

        $payload = $dto->toArray();

        $this->assertNull($payload['intent_id']);
        $this->assertSame('Summarize this patient\'s active medications.', $payload['user_goal']);
        $this->assertNull($payload['conversation_state']);
    }

    public function testRequestDtoRejectsEmptyRunContext(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('runContext');

        new CopilotRunRequestDto(
            runContext: '',
            intentId: 'current_medications',
            userGoal: null,
            requestId: self::REQUEST_ID,
        );
    }

    public function testRequestDtoRejectsEmptyRequestId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requestId');

        new CopilotRunRequestDto(
            runContext: self::SIGNED_TOKEN,
            intentId: 'current_medications',
            userGoal: null,
            requestId: '',
        );
    }

    public function testRequestDtoRejectsMissingIntentAndGoal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('intentId or userGoal');

        new CopilotRunRequestDto(
            runContext: self::SIGNED_TOKEN,
            intentId: null,
            userGoal: null,
            requestId: self::REQUEST_ID,
        );
    }

    public function testRequestDtoRejectsBlankIntentAndBlankGoal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CopilotRunRequestDto(
            runContext: self::SIGNED_TOKEN,
            intentId: '   ',
            userGoal: "\t\n",
            requestId: self::REQUEST_ID,
        );
    }

    public function testRequestDtoRejectsUserGoalOverCap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum length');

        new CopilotRunRequestDto(
            runContext: self::SIGNED_TOKEN,
            intentId: null,
            userGoal: str_repeat('x', CopilotRunRequestDto::USER_GOAL_MAX_CHARS + 1),
            requestId: self::REQUEST_ID,
        );
    }

    public function testRequestDtoAcceptsUserGoalAtCap(): void
    {
        $atCap = str_repeat('x', CopilotRunRequestDto::USER_GOAL_MAX_CHARS);

        $dto = new CopilotRunRequestDto(
            runContext: self::SIGNED_TOKEN,
            intentId: null,
            userGoal: $atCap,
            requestId: self::REQUEST_ID,
        );

        $this->assertSame($atCap, $dto->userGoal);
    }

    // ------------------------------------------------------------------
    // Response DTO
    // ------------------------------------------------------------------

    public function testResponseDtoFromArrayPopulatesAllFields(): void
    {
        $payload = $this->sampleResponsePayload();

        $dto = CopilotRunResponseDto::fromArray($payload);

        // Top-level fields
        $this->assertSame('passed', $dto->verificationStatus);
        $this->assertSame(0.0123, $dto->costUsd);
        $this->assertSame(self::TRACE_ID, $dto->traceId);
        $this->assertSame(['plan' => 14, 'tool_calls' => 160, 'verify' => 22], $dto->latencyMsPerStep);
        $this->assertSame(['Last refill date for atorvastatin not confirmed.'], $dto->missingOrUncertain);

        // Answer blocks
        $this->assertCount(2, $dto->answerBlocks);
        $this->assertInstanceOf(AnswerBlockDto::class, $dto->answerBlocks[0]);
        $this->assertSame('paragraph', $dto->answerBlocks[0]->type);
        $this->assertSame('Patient is on lisinopril 10 mg daily.', $dto->answerBlocks[0]->content);
        $this->assertSame([0], $dto->answerBlocks[0]->citationIndices);
        $this->assertSame([0, 1], $dto->answerBlocks[1]->citationIndices);

        // Citations
        $this->assertCount(2, $dto->citations);
        $this->assertInstanceOf(CitationDto::class, $dto->citations[0]);
        $this->assertSame('patient_record', $dto->citations[0]->sourceType);
        $this->assertSame('med:1234', $dto->citations[0]->sourceId);
        $this->assertNull($dto->citations[0]->url);
        $this->assertNull($dto->citations[0]->snippet);
        $this->assertSame('https://example.org/guideline/hypertension', $dto->citations[1]->url);

        // Tool sequence -- argument keys, never values
        $this->assertCount(2, $dto->toolSequence);
        $this->assertInstanceOf(ToolCallRecordDto::class, $dto->toolSequence[0]);
        $this->assertSame('list_active_medications', $dto->toolSequence[0]->toolName);
        $this->assertSame(['lookback_days'], $dto->toolSequence[0]->argumentsKeys);
        $this->assertSame(2, $dto->toolSequence[0]->resultCount);
        $this->assertSame(42, $dto->toolSequence[0]->latencyMs);
        $this->assertNull($dto->toolSequence[0]->errorClass);
        $this->assertSame(['query', 'top_k'], $dto->toolSequence[1]->argumentsKeys);
    }

    public function testResponseDtoFromArrayHandlesUnknownFieldsGracefully(): void
    {
        $payload = $this->sampleResponsePayload();
        $payload['future_telemetry_field'] = ['ignored' => true];

        $dto = CopilotRunResponseDto::fromArray($payload);

        $this->assertSame('passed', $dto->verificationStatus);
        $this->assertCount(2, $dto->answerBlocks);
    }

    public function testResponseDtoFromArrayDefaultsUnknownStatusToError(): void
    {
        $payload = $this->sampleResponsePayload();
        $payload['verification_status'] = 'something_else';

        $dto = CopilotRunResponseDto::fromArray($payload);

        $this->assertSame('error', $dto->verificationStatus);
    }

    public function testResponseDtoFromArrayHandlesMissingOptionalLists(): void
    {
        $minimal = [
            'verification_status' => 'refused',
            'cost_usd' => 0,
            'trace_id' => self::TRACE_ID,
        ];

        $dto = CopilotRunResponseDto::fromArray($minimal);

        $this->assertSame([], $dto->answerBlocks);
        $this->assertSame([], $dto->missingOrUncertain);
        $this->assertSame([], $dto->citations);
        $this->assertSame([], $dto->toolSequence);
        $this->assertSame([], $dto->latencyMsPerStep);
        $this->assertSame(0.0, $dto->costUsd);
        $this->assertSame('refused', $dto->verificationStatus);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function sampleResponsePayload(): array
    {
        return [
            'answer_blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => 'Patient is on lisinopril 10 mg daily.',
                    'citation_indices' => [0],
                ],
                [
                    'type' => 'list',
                    'content' => "- Lisinopril 10 mg PO daily\n- Atorvastatin 20 mg PO nightly",
                    'citation_indices' => [0, 1],
                ],
            ],
            'missing_or_uncertain' => [
                'Last refill date for atorvastatin not confirmed.',
            ],
            'citations' => [
                [
                    'source_type' => 'patient_record',
                    'source_id' => 'med:1234',
                    'label' => 'Active medication list',
                    'url' => null,
                    'snippet' => null,
                ],
                [
                    'source_type' => 'guideline',
                    'source_id' => 'chunk:hypertension-2024-12',
                    'label' => 'ACC/AHA hypertension guideline',
                    'url' => 'https://example.org/guideline/hypertension',
                    'snippet' => 'First-line therapy for stage 1 hypertension...',
                ],
            ],
            'tool_sequence' => [
                [
                    'tool_name' => 'list_active_medications',
                    'arguments_keys' => ['lookback_days'],
                    'result_count' => 2,
                    'latency_ms' => 42,
                    'error_class' => null,
                ],
                [
                    'tool_name' => 'search_guidelines',
                    'arguments_keys' => ['query', 'top_k'],
                    'result_count' => 5,
                    'latency_ms' => 118,
                    'error_class' => null,
                ],
            ],
            'verification_status' => 'passed',
            'cost_usd' => 0.0123,
            'latency_ms_per_step' => [
                'plan' => 14,
                'tool_calls' => 160,
                'verify' => 22,
            ],
            'trace_id' => self::TRACE_ID,
        ];
    }
}
