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
}
