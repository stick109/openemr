<?php

/**
 * CopilotSidecarClientTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Sidecar;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Services\Agent\Sidecar\AgentSidecarConfig;
use OpenEMR\Services\Agent\Sidecar\CopilotRunRequestDto;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarClient;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class CopilotSidecarClientTest extends TestCase
{
    private const REQUEST_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
    private const RUN_CONTEXT_TOKEN = 'eyJzaWduZWQiOiJ0b2tlbiJ9.c2lnbmF0dXJl';

    public function testRunCopilotSendsExpectedRequestAndParsesSuccess(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($this->successBody(), JSON_THROW_ON_ERROR),
            ),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new CopilotSidecarClient(
            $this->config(),
            new NullLogger(),
            $httpClient,
        );

        $result = $client->runCopilot($this->buildRequest());

        $sentRequest = $this->extractSingleRecordedRequest($history);
        $this->assertSame('POST', $sentRequest->getMethod());
        $this->assertSame(
            'http://sidecar:8010/api/copilot/run',
            (string) $sentRequest->getUri(),
        );
        $this->assertSame('test-secret', $sentRequest->getHeaderLine('X-Agent-Secret'));
        $this->assertSame('application/json', $sentRequest->getHeaderLine('Accept'));

        $body = json_decode((string) $sentRequest->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertSame(self::RUN_CONTEXT_TOKEN, $body['run_context']);
        $this->assertSame('current_medications', $body['intent_id']);
        $this->assertSame(self::REQUEST_ID, $body['request_id']);

        $this->assertSame('passed', $result->verificationStatus);
        $this->assertSame('trace-789', $result->traceId);
        $this->assertSame(0.0042, $result->costUsd);
        $this->assertCount(1, $result->answerBlocks);
        $this->assertCount(1, $result->citations);
        $this->assertCount(1, $result->toolSequence);
        $this->assertSame(['needs_confirmation'], $result->missingOrUncertain);
    }

    public function testRunCopilotMapsHttp401ToContextRejected(): void
    {
        $mock = new MockHandler([
            new Response(401, [], json_encode(['error' => 'context_rejected'], JSON_THROW_ON_ERROR)),
        ]);
        $client = new CopilotSidecarClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->runCopilot($this->buildRequest());
            $this->fail('Expected CopilotSidecarException');
        } catch (CopilotSidecarException $e) {
            $this->assertSame(CopilotSidecarException::REASON_CONTEXT_REJECTED, $e->reason);
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringNotContainsString(self::RUN_CONTEXT_TOKEN, $e->getMessage());
        }
    }

    public function testRunCopilotMapsHttp501ToSidecarNotReady(): void
    {
        $mock = new MockHandler([
            new Response(
                501,
                ['Content-Type' => 'application/json'],
                json_encode(
                    ['detail' => ['error' => 'not_implemented', 'message' => 'stub']],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
        $client = new CopilotSidecarClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->runCopilot($this->buildRequest());
            $this->fail('Expected CopilotSidecarException');
        } catch (CopilotSidecarException $e) {
            $this->assertSame(CopilotSidecarException::REASON_SIDECAR_NOT_READY, $e->reason);
            $this->assertSame(501, $e->httpStatus);
        }
    }

    public function testRunCopilotMaps500ToSidecarErrorWithGenericMessage(): void
    {
        $providerError = 'OpenAI provider error: rate_limited at upstream';
        $mock = new MockHandler([
            new Response(
                500,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'upstream_failure', 'message' => $providerError], JSON_THROW_ON_ERROR),
            ),
        ]);
        $client = new CopilotSidecarClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->runCopilot($this->buildRequest());
            $this->fail('Expected CopilotSidecarException');
        } catch (CopilotSidecarException $e) {
            $this->assertSame(CopilotSidecarException::REASON_SIDECAR_ERROR, $e->reason);
            $this->assertSame(500, $e->httpStatus);
            $this->assertStringNotContainsString('OpenAI', $e->getMessage());
            $this->assertStringNotContainsString('rate_limited', $e->getMessage());
        }
    }

    public function testRunCopilotMapsConnectionRefusedToSidecarError(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection refused',
                new Psr7Request('POST', 'http://sidecar:8010/api/copilot/run'),
            ),
        ]);
        $client = new CopilotSidecarClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->runCopilot($this->buildRequest());
            $this->fail('Expected CopilotSidecarException');
        } catch (CopilotSidecarException $e) {
            $this->assertSame(CopilotSidecarException::REASON_SIDECAR_ERROR, $e->reason);
            $this->assertSame(0, $e->httpStatus);
            $this->assertStringContainsString('unreachable', $e->getMessage());
        }
    }

    public function testRunCopilotMapsMalformedJsonToSidecarError(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], 'not-json{'),
        ]);
        $client = new CopilotSidecarClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->runCopilot($this->buildRequest());
            $this->fail('Expected CopilotSidecarException');
        } catch (CopilotSidecarException $e) {
            $this->assertSame(CopilotSidecarException::REASON_SIDECAR_ERROR, $e->reason);
            $this->assertSame(200, $e->httpStatus);
        }
    }

    public function testRunCopilotMapsNonObjectJsonToSidecarError(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '"just a string"'),
        ]);
        $client = new CopilotSidecarClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->runCopilot($this->buildRequest());
            $this->fail('Expected CopilotSidecarException');
        } catch (CopilotSidecarException $e) {
            $this->assertSame(CopilotSidecarException::REASON_SIDECAR_ERROR, $e->reason);
        }
    }

    public function testRunCopilotThrowsWhenNotConfigured(): void
    {
        $client = new CopilotSidecarClient(
            new AgentSidecarConfig(url: 'http://sidecar:8010', sharedSecret: ''),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create(new MockHandler())]),
        );

        try {
            $client->runCopilot($this->buildRequest());
            $this->fail('Expected CopilotSidecarException');
        } catch (CopilotSidecarException $e) {
            $this->assertSame(CopilotSidecarException::REASON_SIDECAR_ERROR, $e->reason);
        }
    }

    public function testRunCopilotStripsTrailingSlashFromConfiguredUrl(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode($this->successBody(), JSON_THROW_ON_ERROR)),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $client = new CopilotSidecarClient(
            new AgentSidecarConfig(
                url: 'http://sidecar:8010/',
                sharedSecret: 'test-secret',
                timeoutSeconds: 30,
            ),
            new NullLogger(),
            new Client(['handler' => $handlerStack]),
        );

        $client->runCopilot($this->buildRequest());

        $sentRequest = $this->extractSingleRecordedRequest($history);
        $this->assertSame(
            'http://sidecar:8010/api/copilot/run',
            (string) $sentRequest->getUri(),
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function config(): AgentSidecarConfig
    {
        return new AgentSidecarConfig(
            url: 'http://sidecar:8010',
            sharedSecret: 'test-secret',
            timeoutSeconds: 30,
        );
    }

    /**
     * Pull the single recorded HTTP request out of a Guzzle history middleware
     * accumulator. PHPStan sees ``Middleware::history()`` as writing into an
     * ``array|ArrayAccess<int, array>``, so we narrow the result to a
     * ``RequestInterface`` here once instead of repeating the cast at every
     * assertion site.
     *
     * @param mixed $history Guzzle history accumulator passed by reference
     *                       to ``Middleware::history()``.
     */
    private function extractSingleRecordedRequest(mixed $history): RequestInterface
    {
        $this->assertIsIterable($history);
        $entries = is_array($history) ? array_values($history) : iterator_to_array($history, preserve_keys: false);
        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('request', $entry);
        $request = $entry['request'];
        $this->assertInstanceOf(RequestInterface::class, $request);
        return $request;
    }

    private function buildRequest(): CopilotRunRequestDto
    {
        return new CopilotRunRequestDto(
            runContext: self::RUN_CONTEXT_TOKEN,
            intentId: 'current_medications',
            userGoal: null,
            requestId: self::REQUEST_ID,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function successBody(): array
    {
        return [
            'answer_blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => 'Patient is on metformin.',
                    'citation_indices' => [0],
                ],
            ],
            'missing_or_uncertain' => ['needs_confirmation'],
            'citations' => [
                [
                    'source_type' => 'medication',
                    'source_id' => 'medication:lists_medication:77',
                    'label' => 'Metformin 500 mg',
                    'url' => null,
                    'snippet' => null,
                ],
            ],
            'tool_sequence' => [
                [
                    'tool_name' => 'list_medications',
                    'arguments_keys' => ['intent_id'],
                    'result_count' => 1,
                    'latency_ms' => 25,
                    'error_class' => null,
                ],
            ],
            'verification_status' => 'passed',
            'cost_usd' => 0.0042,
            'latency_ms_per_step' => ['plan' => 12, 'execute' => 28],
            'trace_id' => 'trace-789',
        ];
    }
}
