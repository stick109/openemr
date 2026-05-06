<?php

/**
 * AgentIntentRestControllerTest
 *
 * Sidecar-only contract for the M24 controller. After M24 the PHP
 * orchestrator/verifier/response-builder/evidence-toolset chain is gone;
 * the controller is a thin trust boundary that mints a CopilotRunContext
 * and proxies to the Python sidecar. These tests pin:
 *
 *   - happy path: sidecar 200 -> controller emits the documented
 *     ``data.response_generation = sidecar_proxy`` envelope with
 *     ``answer_blocks`` / ``citations`` / ``verification`` populated from
 *     the sidecar response.
 *   - sidecar failure (401, 501, etc.) collapses to HTTP 503 without
 *     leaking provider error messages.
 *   - input validation rejects unknown intents and free-text fields
 *     before the sidecar is contacted.
 *   - missing shared secret / unauthenticated session short-circuit
 *     before any sidecar call is made.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestControllers\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\Agent\AgentIntentRestController;
use OpenEMR\Services\Agent\AgentAccessBroker;
use OpenEMR\Services\Agent\Sidecar\AgentSidecarConfig;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[Group('isolated')]
#[Group('agent')]
final class AgentIntentRestControllerTest extends TestCase
{
    public function testSidecar200ReturnsTypedSidecarEnvelope(): void
    {
        $history = [];
        $controller = $this->buildController(
            sidecarResponses: [
                new Psr7Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($this->sidecarSuccessBody(), JSON_THROW_ON_ERROR),
                ),
            ],
            sidecarHistory: $history,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(1, $history, 'Sidecar must be called exactly once on a happy path');
        $this->assertSame(
            'http://sidecar:8010/api/copilot/run',
            (string) $history[0]['request']->getUri(),
        );
        $this->assertSame('current_medications', $body['data']['intent_id']);
        $this->assertSame('sidecar_proxy', $body['data']['response_generation']);
        $this->assertSame('trace-from-sidecar', $body['data']['trace']['sidecar_trace_id']);
        $this->assertSame('passed', $body['data']['verification']['status']);
        $this->assertCount(1, $body['data']['answer']['answer_blocks']);
        $this->assertSame('Sidecar generated answer.', $body['data']['answer']['answer_blocks'][0]['content']);
        $this->assertSame('medication:lists_medication:77', $body['data']['citations'][0]['source_id']);
    }

    public function testSidecar501IsCollapsedToServiceUnavailable(): void
    {
        $history = [];
        $controller = $this->buildController(
            sidecarResponses: [
                new Psr7Response(
                    501,
                    ['Content-Type' => 'application/json'],
                    json_encode(['detail' => ['error' => 'not_implemented']], JSON_THROW_ON_ERROR),
                ),
            ],
            sidecarHistory: $history,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame([], $body['data']);
        $this->assertSame(
            ['The clinical co-pilot is temporarily unavailable. Please try again shortly.'],
            $body['internalErrors']['service'],
        );
    }

    public function testSidecar401IsCollapsedToServiceUnavailable(): void
    {
        $history = [];
        $controller = $this->buildController(
            sidecarResponses: [
                new Psr7Response(
                    401,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'context_rejected'], JSON_THROW_ON_ERROR),
                ),
            ],
            sidecarHistory: $history,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $response->getStatusCode(),
            'A 401 from the sidecar must NOT propagate to the UI -- it surfaces as 503',
        );
        $this->assertSame([], $body['data']);
    }

    public function testCsrfFailurePreventsSidecarFromBeingCalled(): void
    {
        $history = [];
        $controller = $this->buildController(
            sidecarResponses: [],
            sidecarHistory: $history,
        );

        $request = $this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]);
        $request->headers->set('APICSRFTOKEN', 'tampered-token');

        $response = $controller->postIntent($request);
        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame([], $history, 'Sidecar must not be called when CSRF check fails');
        $this->assertSame(['Agent access requires a valid API CSRF token.'], $body['internalErrors']['access']);
    }

    public function testInvalidIntentIdPreventsSidecarFromBeingCalled(): void
    {
        $history = [];
        $controller = $this->buildController(
            sidecarResponses: [],
            sidecarHistory: $history,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'fictional_intent',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([], $history, 'Sidecar must not be called for unknown intents');
        $this->assertSame(['Unknown agent intent_id.'], $body['validationErrors']['intent_id']);
    }

    public function testFreeTextPayloadRejectedBeforeSidecarCall(): void
    {
        $history = [];
        $controller = $this->buildController(
            sidecarResponses: [],
            sidecarHistory: $history,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
            'free_text' => 'patient note',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(
            ['Free-text agent input is not supported. Use a cataloged intent_id.'],
            $body['validationErrors']['free_text'],
        );
        $this->assertSame([], $history, 'Free-text rejection must short-circuit before any sidecar call');
    }

    public function testMissingSharedSecretSurfacesAsServiceUnavailable(): void
    {
        $history = [];
        $controller = $this->buildController(
            sidecarResponses: [],
            sidecarHistory: $history,
            sharedSecret: '',
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame([], $body['data']);
        $this->assertSame([], $history, 'Without a shared secret, the sidecar must not be reachable from the controller');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<Psr7Response> $sidecarResponses
     * @param array<mixed>       $sidecarHistory   Passed by-ref into Guzzle's history middleware.
     *
     * @param-out array<mixed>   $sidecarHistory
     */
    private function buildController(
        array $sidecarResponses,
        array &$sidecarHistory,
        string $sharedSecret = 'test-shared-secret',
    ): AgentIntentRestController {
        $mock = new MockHandler($sidecarResponses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($sidecarHistory));
        $httpClient = new Client(['handler' => $handlerStack]);

        $sidecarClient = new CopilotSidecarClient(
            new AgentSidecarConfig(
                url: 'http://sidecar:8010',
                sharedSecret: 'test-shared-secret',
                timeoutSeconds: 30,
            ),
            new NullLogger(),
            $httpClient,
        );

        return new AgentIntentRestController(
            accessBroker: new AgentAccessBroker(
                aclChecker: static fn (
                    string $section,
                    string $value,
                    string $user,
                    string $permission
                ): bool => true,
                auditLogger: static function (
                    string $event,
                    string $user,
                    string $groupname,
                    int $success,
                    string $comments,
                    ?int $patientId
                ): void {
                },
                logger: new NullLogger(),
            ),
            logger: new NullLogger(),
            requestIdFactory: static fn (): string => 'agent-test-request',
            copilotSidecarClient: $sidecarClient,
            sharedSecretProvider: static fn (): string => $sharedSecret,
            clock: static fn (): int => 1_700_000_000,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestWithJson(array $payload): HttpRestRequest
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('authUser', 'admin');
        $session->set('authUserID', 1);
        $session->set('authProvider', 'Default');
        $session->set('pid', 123);
        CsrfUtils::setupCsrfKey($session);

        $request = new HttpRestRequest(content: json_encode($payload, JSON_THROW_ON_ERROR));
        $request->setSession($session);
        $request->headers->set('APICSRFTOKEN', CsrfUtils::collectCsrfToken($session, 'api'));

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $result = [];
        foreach ($decoded as $key => $value) {
            $this->assertIsString($key);
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function sidecarSuccessBody(): array
    {
        return [
            'answer_blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => 'Sidecar generated answer.',
                    'citation_indices' => [0],
                ],
            ],
            'missing_or_uncertain' => [],
            'citations' => [
                [
                    'source_type' => 'medication',
                    'source_id' => 'medication:lists_medication:77',
                    'label' => 'Metformin 500 mg',
                    'url' => null,
                    'snippet' => null,
                ],
            ],
            'tool_sequence' => [],
            'verification_status' => 'passed',
            'cost_usd' => 0.0042,
            'latency_ms_per_step' => ['plan' => 5, 'execute' => 22],
            'trace_id' => 'trace-from-sidecar',
        ];
    }
}
