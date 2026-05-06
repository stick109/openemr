<?php

/**
 * AgentServiceClientTest
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
use OpenEMR\Services\Agent\Sidecar\AgentRunResult;
use OpenEMR\Services\Agent\Sidecar\AgentServiceClient;
use OpenEMR\Services\Agent\Sidecar\AgentServiceException;
use OpenEMR\Services\Agent\Sidecar\AgentSidecarConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class AgentServiceClientTest extends TestCase
{
    private const TRACE_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

    public function testRunSendsExpectedRequestAndParsesSuccessResponse(): void
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

        $client = new AgentServiceClient(
            $this->config(),
            new NullLogger(),
            $httpClient,
        );

        $result = $client->run(
            patientId: 42,
            filePath: '/var/uploads/agent/abc.pdf',
            docType: 'lab_pdf',
            encounterId: 7,
            traceId: self::TRACE_ID,
        );

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://sidecar:8010/api/agent/run', (string) $request->getUri());
        $this->assertSame('test-secret', $request->getHeaderLine('X-Agent-Secret'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));

        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'patient_id' => 42,
            'file_path' => '/var/uploads/agent/abc.pdf',
            'doc_type' => 'lab_pdf',
            'encounter_id' => 7,
            'trace_id' => self::TRACE_ID,
        ], $body);

        $this->assertInstanceOf(AgentRunResult::class, $result);
        $this->assertSame(['hemoglobin' => 13.5], $result->extracted);
        $this->assertSame('CBC normal.', $result->answer);
        $this->assertSame(0.0037, $result->costUsd);
        $this->assertSame(0.96, $result->extractionConfidence);
        $this->assertSame(['pdf_parser', 'lab_extractor'], $result->toolSequence);
        $this->assertSame(['pdf_parse' => 120, 'extraction' => 830], $result->latencyMsPerStep);
        $this->assertCount(1, $result->citations);
        $this->assertCount(1, $result->evidence);
    }

    public function testRunStripsTrailingSlashFromConfiguredUrl(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode($this->successBody(), JSON_THROW_ON_ERROR)),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $client = new AgentServiceClient(
            new AgentSidecarConfig(
                url: 'http://sidecar:8010/',
                sharedSecret: 'test-secret',
                timeoutSeconds: 30,
            ),
            new NullLogger(),
            new Client(['handler' => $handlerStack]),
        );

        $client->run(1, '/tmp/x.pdf', 'lab_pdf', 1, self::TRACE_ID);

        $this->assertSame(
            'http://sidecar:8010/api/agent/run',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testRunThrowsWhenSidecarReturns4xxErrorEnvelope(): void
    {
        $errorEnvelope = [
            'error' => 'extraction_failed',
            'detail' => 'Could not parse PDF',
            'trace_id' => self::TRACE_ID,
        ];
        $mock = new MockHandler([
            new Response(422, ['Content-Type' => 'application/json'], json_encode($errorEnvelope, JSON_THROW_ON_ERROR)),
        ]);
        $client = new AgentServiceClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->run(1, '/tmp/x.pdf', 'lab_pdf', 1, self::TRACE_ID);
            $this->fail('Expected AgentServiceException');
        } catch (AgentServiceException $e) {
            $this->assertSame('extraction_failed', $e->errorCode);
            $this->assertSame('Could not parse PDF', $e->detail);
            $this->assertSame(self::TRACE_ID, $e->traceId);
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function testRunThrowsWhenSidecarReturnsUnauthorized(): void
    {
        $envelope = [
            'error' => 'unauthorized',
            'detail' => 'Missing X-Agent-Secret header',
            'trace_id' => self::TRACE_ID,
        ];
        $mock = new MockHandler([
            new Response(401, [], json_encode($envelope, JSON_THROW_ON_ERROR)),
        ]);
        $client = new AgentServiceClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $this->expectException(AgentServiceException::class);
        try {
            $client->run(1, '/tmp/x.pdf', 'lab_pdf', 1, self::TRACE_ID);
        } catch (AgentServiceException $e) {
            $this->assertSame('unauthorized', $e->errorCode);
            $this->assertSame(401, $e->httpStatus);
            throw $e;
        }
    }

    public function testRunThrowsOnInvalidJsonResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], 'not json'),
        ]);
        $client = new AgentServiceClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->run(1, '/tmp/x.pdf', 'lab_pdf', 1, self::TRACE_ID);
            $this->fail('Expected AgentServiceException');
        } catch (AgentServiceException $e) {
            $this->assertSame('invalid_response', $e->errorCode);
            $this->assertSame(self::TRACE_ID, $e->traceId);
        }
    }

    public function testRunThrowsOnConnectionFailure(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection refused',
                new Psr7Request('POST', 'http://sidecar:8010/api/agent/run'),
            ),
        ]);
        $client = new AgentServiceClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->run(1, '/tmp/x.pdf', 'lab_pdf', 1, self::TRACE_ID);
            $this->fail('Expected AgentServiceException');
        } catch (AgentServiceException $e) {
            $this->assertSame('connection_failed', $e->errorCode);
            $this->assertSame(self::TRACE_ID, $e->traceId);
            $this->assertStringContainsString('unreachable', $e->getMessage());
        }
    }

    public function testRunThrowsWhenSidecarIsNotConfigured(): void
    {
        $client = new AgentServiceClient(
            new AgentSidecarConfig(url: 'http://sidecar:8010', sharedSecret: ''),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create(new MockHandler())]),
        );

        try {
            $client->run(1, '/tmp/x.pdf', 'lab_pdf', 1, self::TRACE_ID);
            $this->fail('Expected AgentServiceException');
        } catch (AgentServiceException $e) {
            $this->assertSame('not_configured', $e->errorCode);
            $this->assertSame('missing_shared_secret', $e->detail);
        }
    }

    public function testRunHandlesNonObjectJsonAsInvalidResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '"just a string"'),
        ]);
        $client = new AgentServiceClient(
            $this->config(),
            new NullLogger(),
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->run(1, '/tmp/x.pdf', 'lab_pdf', 1, self::TRACE_ID);
            $this->fail('Expected AgentServiceException');
        } catch (AgentServiceException $e) {
            $this->assertSame('invalid_response', $e->errorCode);
        }
    }

    public function testRunUsesConfiguredTimeout(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode($this->successBody(), JSON_THROW_ON_ERROR)),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $client = new AgentServiceClient(
            new AgentSidecarConfig(
                url: 'http://sidecar:8010',
                sharedSecret: 'test-secret',
                timeoutSeconds: 90,
            ),
            new NullLogger(),
            new Client(['handler' => $handlerStack]),
        );

        $client->run(1, '/tmp/x.pdf', 'lab_pdf', 1, self::TRACE_ID);

        $this->assertSame(90, $history[0]['options']['timeout']);
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
     * @return array<string, mixed>
     */
    private function successBody(): array
    {
        return [
            'extracted' => ['hemoglobin' => 13.5],
            'evidence' => [
                ['guideline' => 'AMA Lab Reference Ranges 2025', 'snippet' => 'Normal range...'],
            ],
            'answer' => 'CBC normal.',
            'citations' => [
                ['source_type' => 'pdf_bbox', 'page' => 1, 'bbox' => [72, 200, 540, 230]],
            ],
            'cost_usd' => 0.0037,
            'latency_ms_per_step' => ['pdf_parse' => 120, 'extraction' => 830],
            'tool_sequence' => ['pdf_parser', 'lab_extractor'],
            'extraction_confidence' => 0.96,
        ];
    }
}
