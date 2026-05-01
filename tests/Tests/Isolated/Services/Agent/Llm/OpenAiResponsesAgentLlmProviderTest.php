<?php

/**
 * OpenAiResponsesAgentLlmProviderTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\Llm\AgentAnswerSchema;
use OpenEMR\Services\Agent\Llm\AgentLlmConfig;
use OpenEMR\Services\Agent\Llm\AgentLlmRequest;
use OpenEMR\Services\Agent\Llm\OpenAiResponsesAgentLlmProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class OpenAiResponsesAgentLlmProviderTest extends TestCase
{
    public function testPostsResponsesRequestWithStructuredOutputAndServerSideKey(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'resp_123',
                'model' => 'gpt-test',
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode($this->answer(), JSON_THROW_ON_ERROR),
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 8,
                    'total_tokens' => 18,
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack, 'base_uri' => 'https://api.openai.test/v1/']);
        $provider = new OpenAiResponsesAgentLlmProvider(
            new AgentLlmConfig(
                provider: AgentLlmConfig::PROVIDER_OPENAI,
                apiKey: 'server-side-secret',
                model: 'gpt-test',
                baseUri: 'https://api.openai.test/v1/',
                externalCallsEnabled: true,
                inputCostPer1MTokens: 2.50,
                outputCostPer1MTokens: 10.00
            ),
            $client
        );

        $response = $provider->complete(new AgentLlmRequest(
            intent: [
                'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
                'button_label' => 'Current medications',
                'prompt_text' => 'Show me current medications.',
            ],
            evidencePacket: ['request_id' => 'request-123', 'sources' => []],
            jsonSchema: (new AgentAnswerSchema())->jsonSchema()
        ));

        $this->assertSame($this->answer(), $response->getAnswer());
        $this->assertSame('openai', $response->toMetadata()['provider']);
        $this->assertSame('gpt-test', $response->toMetadata()['model']);
        $this->assertSame(18, $response->toMetadata()['usage']['total_tokens']);
        $this->assertSame(10, $response->toMetadata()['token_counters']['input_tokens']);
        $this->assertSame(8, $response->toMetadata()['token_counters']['output_tokens']);
        $this->assertSame(18, $response->toMetadata()['token_counters']['total_tokens']);
        $this->assertTrue($response->toMetadata()['cost_counters']['rates_configured']);
        $this->assertSame(0.000105, $response->toMetadata()['cost_counters']['total_cost_usd']);
        $this->assertCount(1, $history);
        $this->assertSame('Bearer server-side-secret', $history[0]['request']->getHeaderLine('Authorization'));
        $requestBody = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($requestBody['store']);
        $this->assertSame('json_schema', $requestBody['text']['format']['type']);
        $this->assertSame('openemr_agent_answer', $requestBody['text']['format']['name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'answer_blocks' => [],
            'missing_or_uncertain' => [],
        ];
    }
}
