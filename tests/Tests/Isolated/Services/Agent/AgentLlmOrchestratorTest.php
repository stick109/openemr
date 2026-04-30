<?php

/**
 * AgentLlmOrchestratorTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent;

use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\AgentLlmOrchestrator;
use OpenEMR\Services\Agent\AgentPatientContext;
use OpenEMR\Services\Agent\Llm\AgentLlmProviderInterface;
use OpenEMR\Services\Agent\Llm\AgentLlmRequest;
use OpenEMR\Services\Agent\Llm\AgentLlmResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class AgentLlmOrchestratorTest extends TestCase
{
    public function testReturnsVerifiedProviderAnswer(): void
    {
        $orchestrator = new AgentLlmOrchestrator(
            provider: new AgentLlmOrchestratorProviderFixture($this->supportedAnswer()),
            logger: new NullLogger()
        );

        $response = $orchestrator->buildVerifiedAnswer(
            $this->intent(),
            $this->accessToken(),
            $this->packet(),
            $this->deterministicAnswer()
        );

        $this->assertSame('verified', $response['status']);
        $this->assertSame('llm_structured_verified', $response['response_generation']);
        $this->assertSame('passed', $response['verification']['status']);
        $this->assertTrue($response['llm']['used']);
    }

    public function testLogsProviderAnswerWhenProviderOutputPassesVerification(): void
    {
        $logger = new AgentLlmOrchestratorCapturingLogger();
        $orchestrator = new AgentLlmOrchestrator(
            provider: new AgentLlmOrchestratorProviderFixture($this->supportedAnswer()),
            logger: $logger
        );

        $orchestrator->buildVerifiedAnswer(
            $this->intent(),
            $this->accessToken(),
            $this->packet(),
            $this->deterministicAnswer()
        );

        $record = $logger->firstRecord('info', 'agent.llm.finished');
        $this->assertNotNull($record);

        $context = $record['context'];
        $this->assertSame('passed', $context['verification_status']);
        $this->assertSame($this->supportedAnswer(), $context['llm_response']);
    }

    public function testLogsProviderRequestPayloadBeforeCompletion(): void
    {
        $logger = new AgentLlmOrchestratorCapturingLogger();
        $orchestrator = new AgentLlmOrchestrator(
            provider: new AgentLlmOrchestratorProviderFixture($this->supportedAnswer()),
            logger: $logger
        );

        $orchestrator->buildVerifiedAnswer(
            $this->intent(),
            $this->accessToken(),
            $this->packet(),
            $this->deterministicAnswer()
        );

        $record = $logger->firstRecord('info', 'agent.llm.started');
        $this->assertNotNull($record);

        $context = $record['context'];
        $this->assertSame('request-123', $context['request_id']);
        $this->assertSame(AgentIntentCatalog::CURRENT_MEDICATIONS, $context['intent_id']);
        $this->assertSame('fixture', $context['provider']);
        $this->assertSame('fixture-model', $context['model']);
        $this->assertSame('agent.llm.request_readable', $context['llm_request_log']);
        $this->assertArrayNotHasKey('llm_request', $context);

        $requestRecord = $logger->firstRecordWithMessagePrefix('info', 'agent.llm.request_readable');
        $this->assertNotNull($requestRecord);
        $requestLog = $requestRecord['message'];
        $this->assertStringContainsString("\r\nllm_request:\r\n", $requestLog);
        $this->assertStringContainsString('model: fixture-model', $requestLog);
        $this->assertStringContainsString('You are the Clinical Co-Pilot inside OpenEMR.', $requestLog);
        $this->assertStringContainsString('"not found in checked evidence"', $requestLog);
        $this->assertStringContainsString('do not add completeness statements', $requestLog);
        $this->assertStringContainsString('store: false', $requestLog);
        $this->assertStringContainsString('temperature: 0.1', $requestLog);
        $this->assertStringContainsString('max_output_tokens: 1200', $requestLog);
        $this->assertStringContainsString('"type": "json_schema"', $requestLog);
        $this->assertStringContainsString('"name": "openemr_agent_answer"', $requestLog);
        $this->assertStringNotContainsString('\u0026quot;', $requestLog);
        $this->assertStringNotContainsString('&quot;', $requestLog);
        $this->assertStringNotContainsString('\n', $requestLog);

        $inputStart = strpos($requestLog, "input:\r\n");
        $this->assertIsInt($inputStart);
        $inputEnd = strpos($requestLog, "\r\n  store:", $inputStart);
        $this->assertIsInt($inputEnd);
        $inputJson = substr(
            $requestLog,
            $inputStart + strlen("input:\r\n"),
            $inputEnd - $inputStart - strlen("input:\r\n")
        );
        $normalizedInputJson = preg_replace('/^    /m', '', $inputJson);
        $this->assertIsString($normalizedInputJson);
        $input = json_decode($normalizedInputJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AgentIntentCatalog::CURRENT_MEDICATIONS, $input['intent']['intent_id']);
        $this->assertSame('request-123', $input['evidence_packet']['request_id']);
        $this->assertSame(
            'medication:lists_medication:77',
            $input['evidence_packet']['sources'][0]['source_id']
        );
        $this->assertSame(
            'Metformin 500 mg twice daily',
            $input['evidence_packet']['sources'][0]['display']
        );
    }

    public function testFallsBackToDeterministicAnswerWhenProviderOutputFailsVerification(): void
    {
        $badAnswer = $this->supportedAnswer();
        $badAnswer['answer_blocks'][0]['claims'][0]['citation_ids'] = ['fabricated:source:1'];
        $orchestrator = new AgentLlmOrchestrator(
            provider: new AgentLlmOrchestratorProviderFixture($badAnswer),
            logger: new NullLogger()
        );

        $response = $orchestrator->buildVerifiedAnswer(
            $this->intent(),
            $this->accessToken(),
            $this->packet(),
            $this->deterministicAnswer()
        );

        $this->assertSame('verified', $response['status']);
        $this->assertSame('deterministic_verified_fallback', $response['response_generation']);
        $this->assertSame('verification_failed', $response['llm']['fallback_reason']);
        $this->assertSame($this->deterministicAnswer(), $response['answer']);
    }

    public function testFallsBackWhenProviderPutsCompletenessStatementInMissingOrUncertain(): void
    {
        $badAnswer = $this->supportedAnswer();
        $badAnswer['missing_or_uncertain'] = [
            [
                'text' => 'No additional current medications were found in checked evidence.',
                'citation_ids' => ['medication:lists_medication:77'],
            ],
        ];
        $orchestrator = new AgentLlmOrchestrator(
            provider: new AgentLlmOrchestratorProviderFixture($badAnswer),
            logger: new NullLogger()
        );

        $response = $orchestrator->buildVerifiedAnswer(
            $this->intent(),
            $this->accessToken(),
            $this->packet(),
            $this->deterministicAnswer()
        );

        $this->assertSame('verified', $response['status']);
        $this->assertSame('deterministic_verified_fallback', $response['response_generation']);
        $this->assertSame('verification_failed', $response['llm']['fallback_reason']);
        $this->assertSame([], $response['answer']['missing_or_uncertain']);
    }

    public function testLogsProviderAnswerAndErrorsWhenProviderOutputFailsVerification(): void
    {
        $badAnswer = $this->supportedAnswer();
        $badAnswer['answer_blocks'][0]['claims'][0]['citation_ids'] = ['fabricated:source:1'];
        $logger = new AgentLlmOrchestratorCapturingLogger();
        $orchestrator = new AgentLlmOrchestrator(
            provider: new AgentLlmOrchestratorProviderFixture($badAnswer),
            logger: $logger
        );

        $orchestrator->buildVerifiedAnswer(
            $this->intent(),
            $this->accessToken(),
            $this->packet(),
            $this->deterministicAnswer()
        );

        $finishedRecord = $logger->firstRecord('info', 'agent.llm.finished');
        $this->assertNotNull($finishedRecord);
        $finishedContext = $finishedRecord['context'];
        $this->assertSame('failed', $finishedContext['verification_status']);
        $this->assertSame($badAnswer, $finishedContext['llm_response']);

        $failureRecord = $logger->firstRecord('warning', 'agent.verification.failed');
        $this->assertNotNull($failureRecord);
        $context = $failureRecord['context'];
        $this->assertSame(1, $context['error_count']);
        $this->assertSame(
            ['answer_blocks[0].claims[0] cites unknown source_id fabricated:source:1.'],
            $context['verification_errors']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function intent(): array
    {
        return [
            'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
            'button_label' => 'Current medications',
            'prompt_text' => 'Show me current medications.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supportedAnswer(): array
    {
        return [
            'answer_blocks' => [
                [
                    'heading' => 'Current medications',
                    'claims' => [
                        [
                            'text' => 'Metformin 500 mg twice daily is listed in checked evidence.',
                            'citation_ids' => ['medication:lists_medication:77'],
                            'certainty' => 'supported',
                        ],
                    ],
                ],
            ],
            'missing_or_uncertain' => [],
            'followup_intents' => [AgentIntentCatalog::SHOW_SOURCE],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deterministicAnswer(): array
    {
        return [
            'answer_blocks' => [
                [
                    'heading' => 'Current medications',
                    'claims' => [
                        [
                            'text' => 'Metformin 500 mg twice daily',
                            'citation_ids' => ['medication:lists_medication:77'],
                            'certainty' => 'active',
                        ],
                    ],
                ],
            ],
            'missing_or_uncertain' => [],
            'followup_intents' => [AgentIntentCatalog::SHOW_SOURCE],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return [
            'request_id' => 'request-123',
            'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
            'sources' => [
                [
                    'source_id' => 'medication:lists_medication:77',
                    'source_type' => 'medication',
                    'data_class' => 'medications',
                    'status' => 'active',
                    'display' => 'Metformin 500 mg twice daily',
                    'excerpt' => 'Metformin 500 mg twice daily',
                    'patient_id' => 123,
                ],
            ],
            'tool_runs' => [],
        ];
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
}

final class AgentLlmOrchestratorProviderFixture implements AgentLlmProviderInterface
{
    /**
     * @param array<string, mixed> $answer
     */
    public function __construct(private readonly array $answer)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getProviderName(): string
    {
        return 'fixture';
    }

    public function getModelName(): string
    {
        return 'fixture-model';
    }

    public function getConfigurationIssue(): ?string
    {
        return null;
    }

    public function complete(AgentLlmRequest $request): AgentLlmResponse
    {
        return new AgentLlmResponse(
            answer: $this->answer,
            providerName: 'fixture',
            modelName: 'fixture-model',
            usage: ['total_tokens' => 1],
            providerResponseId: 'fixture-response'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestPayload(AgentLlmRequest $request): array
    {
        return [
            'model' => 'fixture-model',
            'instructions' => $request->getSystemInstructions(),
            'input' => $request->getUserInput(),
            'store' => false,
            'temperature' => 0.1,
            'max_output_tokens' => 1200,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'openemr_agent_answer',
                    'strict' => true,
                    'schema' => $request->getJsonSchema(),
                ],
            ],
            'metadata' => [
                'openemr_component' => 'clinical_copilot',
                'intent_id' => $request->getIntentId(),
            ],
        ];
    }
}

final class AgentLlmOrchestratorCapturingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return array{level: mixed, message: string, context: array<string, mixed>}|null
     */
    public function firstRecord(string $level, string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && $record['message'] === $message) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{level: mixed, message: string, context: array<string, mixed>}|null
     */
    public function firstRecordWithMessagePrefix(string $level, string $messagePrefix): ?array
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_starts_with($record['message'], $messagePrefix)) {
                return $record;
            }
        }

        return null;
    }
}
