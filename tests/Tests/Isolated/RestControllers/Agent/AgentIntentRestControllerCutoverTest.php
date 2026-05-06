<?php

/**
 * AgentIntentRestControllerCutoverTest
 *
 * Covers the M19 per-intent cutover routing in
 * :class:`AgentIntentRestController`. Each intent can independently
 * resolve to PHP, shadow, or sidecar mode based on the injected
 * :class:`CopilotSidecarRouting`. The emergency disable switch must
 * force EVERY intent back to the PHP path even when per-intent
 * overrides say otherwise.
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
use OpenEMR\Services\Agent\AgentEvidenceResponseBuilder;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\Anonymizer;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceToolset;
use OpenEMR\Services\Agent\Evidence\EvidenceCaps;
use OpenEMR\Services\Agent\Evidence\EvidenceRecordRepositoryInterface;
use OpenEMR\Services\Agent\Sidecar\AgentSidecarConfig;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarClient;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarRouting;
use OpenEMR\Services\Agent\Sidecar\IntentMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[Group('isolated')]
#[Group('agent')]
final class AgentIntentRestControllerCutoverTest extends TestCase
{
    /**
     * @return array<string, array{IntentMode, bool, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function intentModeProvider(): array
    {
        return [
            'php-mode-uses-legacy-path' => [IntentMode::Php, false, false],
            'shadow-mode-runs-legacy-and-shadow' => [IntentMode::Shadow, true, false],
            'sidecar-mode-proxies-to-sidecar' => [IntentMode::Sidecar, true, true],
        ];
    }

    #[DataProvider('intentModeProvider')]
    public function testRoutingDispatchesToCorrectHandler(
        IntentMode $mode,
        bool $expectsSidecarCall,
        bool $expectsSidecarResponseGeneration,
    ): void {
        $history = [];
        $logger = new AgentIntentRestControllerCutoverRecordingLogger();
        $controller = $this->buildController(
            sidecarResponses: $expectsSidecarCall
                ? [
                    new Psr7Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        json_encode($this->sidecarSuccessBody(), JSON_THROW_ON_ERROR),
                    ),
                ]
                : [],
            sidecarHistory: $history,
            routing: new CopilotSidecarRouting(
                emergencyDisable: false,
                perIntent: [AgentIntentCatalog::CURRENT_MEDICATIONS => $mode],
                defaultMode: IntentMode::Php,
            ),
            logger: $logger,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(
            $expectsSidecarCall ? 1 : 0,
            count($history),
            sprintf('Mode %s expected %s sidecar call(s)', $mode->value, $expectsSidecarCall ? '1' : '0'),
        );

        if ($expectsSidecarResponseGeneration) {
            $this->assertSame('sidecar_proxy', $body['data']['response_generation']);
            $this->assertArrayHasKey('sidecar_trace_id', $body['data']['trace']);
        } else {
            $this->assertArrayNotHasKey(
                'sidecar_trace_id',
                $body['data']['trace'],
                'Non-sidecar modes must keep the legacy trace shape',
            );
        }

        if ($mode === IntentMode::Shadow) {
            $shadowEntries = $logger->messagesMatching('Sidecar shadow comparison');
            $this->assertCount(1, $shadowEntries, 'Shadow mode must log exactly one comparison');
        } else {
            $this->assertCount(
                0,
                $logger->messagesMatching('Sidecar shadow comparison'),
                'Non-shadow modes must not log a shadow comparison',
            );
        }
    }

    public function testEachIntentRoutesIndependently(): void
    {
        // basic_patient_data => sidecar; everything else => php.
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
            routing: new CopilotSidecarRouting(
                emergencyDisable: false,
                perIntent: [AgentIntentCatalog::BASIC_PATIENT_DATA => IntentMode::Sidecar],
                defaultMode: IntentMode::Php,
            ),
            logger: new AgentIntentRestControllerCutoverRecordingLogger(),
        );

        // basic_patient_data should be sidecar.
        $sidecarBody = $this->decodeJsonBody($controller->postIntent($this->requestWithJson([
            'intent_id' => AgentIntentCatalog::BASIC_PATIENT_DATA,
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ])));
        $this->assertSame('sidecar_proxy', $sidecarBody['data']['response_generation']);
        $this->assertCount(1, $history, 'Sidecar must be called for basic_patient_data');

        // current_medications must STAY on the legacy path.
        $legacyBody = $this->decodeJsonBody($controller->postIntent($this->requestWithJson([
            'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ])));
        $this->assertArrayNotHasKey('sidecar_trace_id', $legacyBody['data']['trace']);
        $this->assertCount(1, $history, 'Sidecar must NOT be called for non-cutover intents');
    }

    public function testEmergencyDisableForcesLegacyEvenWhenPerIntentSaysSidecar(): void
    {
        $history = [];
        $controller = $this->buildController(
            sidecarResponses: [],
            sidecarHistory: $history,
            routing: new CopilotSidecarRouting(
                emergencyDisable: true,
                perIntent: [AgentIntentCatalog::CURRENT_MEDICATIONS => IntentMode::Sidecar],
                defaultMode: IntentMode::Sidecar,
            ),
            logger: new AgentIntentRestControllerCutoverRecordingLogger(),
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(
            [],
            $history,
            'Emergency disable must prevent the sidecar from being called even when per-intent says sidecar',
        );
        $this->assertArrayNotHasKey(
            'sidecar_trace_id',
            $body['data']['trace'],
            'Emergency disable must serve the legacy PHP response shape',
        );
    }

    public function testRoutingProviderIsConsultedPerRequest(): void
    {
        $history = [];
        $logger = new AgentIntentRestControllerCutoverRecordingLogger();
        $callCount = 0;
        $controller = $this->buildControllerWithProvider(
            sidecarResponses: [
                new Psr7Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($this->sidecarSuccessBody(), JSON_THROW_ON_ERROR),
                ),
            ],
            sidecarHistory: $history,
            routingProvider: function () use (&$callCount): CopilotSidecarRouting {
                $callCount++;
                // First call: sidecar; second call: php.
                return new CopilotSidecarRouting(
                    emergencyDisable: false,
                    perIntent: [],
                    defaultMode: $callCount === 1 ? IntentMode::Sidecar : IntentMode::Php,
                );
            },
            logger: $logger,
        );

        $first = $controller->postIntent($this->requestWithJson([
            'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));
        $second = $controller->postIntent($this->requestWithJson([
            'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $this->assertSame(2, $callCount, 'routing provider must be invoked once per request');
        $this->assertSame('sidecar_proxy', $this->decodeJsonBody($first)['data']['response_generation']);
        $this->assertArrayNotHasKey('sidecar_trace_id', $this->decodeJsonBody($second)['data']['trace']);
        $this->assertCount(1, $history, 'Only the first (sidecar-mode) request should hit the sidecar');
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
        CopilotSidecarRouting $routing,
        AgentIntentRestControllerCutoverRecordingLogger $logger,
    ): AgentIntentRestController {
        return $this->buildControllerWithProvider(
            sidecarResponses: $sidecarResponses,
            sidecarHistory: $sidecarHistory,
            routingProvider: static fn (): CopilotSidecarRouting => $routing,
            logger: $logger,
        );
    }

    /**
     * @param list<Psr7Response>          $sidecarResponses
     * @param array<mixed>                $sidecarHistory   Passed by-ref into Guzzle's history middleware.
     * @param callable(): CopilotSidecarRouting $routingProvider
     *
     * @param-out array<mixed>            $sidecarHistory
     */
    private function buildControllerWithProvider(
        array $sidecarResponses,
        array &$sidecarHistory,
        callable $routingProvider,
        AgentIntentRestControllerCutoverRecordingLogger $logger,
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
            $logger,
            $httpClient,
        );

        $responseBuilder = new AgentEvidenceResponseBuilder(
            toolset: new AgentEvidenceToolset(
                repository: new AgentIntentRestControllerCutoverEvidenceRepository(),
                logger: $logger,
                requestIdFactory: static fn (): string => 'agent-test-request',
            ),
            anonymizer: new Anonymizer(logger: $logger),
            logger: $logger,
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
                logger: $logger,
            ),
            responseBuilder: $responseBuilder,
            logger: $logger,
            requestIdFactory: static fn (): string => 'agent-test-request',
            copilotSidecarClient: $sidecarClient,
            sharedSecretProvider: static fn (): string => 'test-shared-secret',
            clock: static fn (): int => 1_700_000_000,
            routingProvider: $routingProvider,
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
                    'heading' => 'Current medications',
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

/**
 * Minimal PSR-3 capturing logger for the cutover test.
 */
final class AgentIntentRestControllerCutoverRecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function messagesMatching(string $needle): array
    {
        $matches = [];
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                $matches[] = $record;
            }
        }
        return $matches;
    }
}

final class AgentIntentRestControllerCutoverEvidenceRepository implements EvidenceRecordRepositoryInterface
{
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        return [
            [
                'source_id' => 'patient:patient_data:1',
                'source_type' => 'patient',
                'data_class' => 'demographics',
                'table' => 'patient_data',
                'record_id' => '1',
                'patient_id' => 123,
                'date' => '2026-01-01',
                'status' => 'active',
                'display' => 'Demographics record',
                'fields_used' => ['fname', 'lname'],
                'reliability' => 'structured_active_record',
            ],
        ];
    }

    public function fetchCurrentMedications(int $pid, EvidenceCaps $caps): array
    {
        return [
            [
                'source_id' => 'medication:lists_medication:77',
                'source_type' => 'medication',
                'data_class' => 'medications',
                'table' => 'lists_medication',
                'record_id' => '77',
                'patient_id' => 123,
                'date' => '2026-04-20',
                'status' => 'active',
                'display' => 'Metformin 500 mg twice daily',
                'fields_used' => ['title'],
                'reliability' => 'structured_active_record',
            ],
        ];
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
