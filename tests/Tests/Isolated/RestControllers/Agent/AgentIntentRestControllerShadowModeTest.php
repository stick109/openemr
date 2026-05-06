<?php

/**
 * AgentIntentRestControllerShadowModeTest
 *
 * Covers the M18 shadow-mode behavior of AgentIntentRestController. When
 * ``OPENEMR_COPILOT_SIDECAR_SHADOW_ENABLED`` is on (and PROXY is off), the
 * legacy PHP path returns the user-visible answer, AND the controller
 * fires a parallel sidecar call, runs a sanitized comparison, and logs
 * the comparison record. Sidecar failures must not affect the legacy
 * answer the UI receives. When PROXY is on, shadow mode is suppressed
 * because the sidecar is already authoritative.
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
use OpenEMR\Services\Agent\Anonymizer;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceToolset;
use OpenEMR\Services\Agent\Evidence\EvidenceCaps;
use OpenEMR\Services\Agent\Evidence\EvidenceRecordRepositoryInterface;
use OpenEMR\Services\Agent\Sidecar\AgentSidecarConfig;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[Group('isolated')]
#[Group('agent')]
final class AgentIntentRestControllerShadowModeTest extends TestCase
{
    public function testShadowFlagOffNoSidecarCallAndNoComparisonLogged(): void
    {
        $history = [];
        $logger = new AgentIntentRestControllerShadowModeRecordingLogger();
        $controller = $this->buildController(
            sidecarResponses: [],
            sidecarHistory: $history,
            sidecarProxyEnabled: false,
            sidecarShadowEnabled: false,
            logger: $logger,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame([], $history, 'Sidecar must not be called when shadow flag is off');
        $this->assertCount(
            0,
            $logger->messagesMatching('Sidecar shadow comparison'),
            'Shadow comparison must not be logged when shadow flag is off',
        );
    }

    public function testShadowFlagOnLegacyAnswerReturnedAndComparisonLogged(): void
    {
        $history = [];
        $logger = new AgentIntentRestControllerShadowModeRecordingLogger();
        $controller = $this->buildController(
            sidecarResponses: [
                new Psr7Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($this->sidecarSuccessBody(), JSON_THROW_ON_ERROR),
                ),
            ],
            sidecarHistory: $history,
            sidecarProxyEnabled: false,
            sidecarShadowEnabled: true,
            logger: $logger,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(1, $history, 'Shadow mode must call the sidecar once');
        $this->assertSame('current_medications', $body['data']['intent_id']);
        $this->assertSame(
            'deterministic_verified_fallback',
            $body['data']['response_generation'],
            'Shadow mode must NOT swap in the sidecar response_generation',
        );
        $this->assertArrayNotHasKey(
            'sidecar_trace_id',
            $body['data']['trace'],
            'Shadow mode must keep the trace shape identical to legacy',
        );

        $shadowEntries = $logger->messagesMatching('Sidecar shadow comparison');
        $this->assertCount(1, $shadowEntries, 'Shadow comparison must be logged exactly once');
        $entry = $shadowEntries[0];
        $this->assertSame('info', $entry['level']);
        $context = $entry['context'];
        $this->assertSame('current_medications', $context['intent_id']);
        $this->assertIsString($context['trace_id']);
        $this->assertIsBool($context['verification_status_match']);
        $this->assertIsBool($context['cited_source_ids_match']);
        $this->assertIsBool($context['missingness_shape_match']);
        $this->assertIsBool($context['headings_match']);
        $this->assertIsInt($context['php_cited_count']);
        $this->assertIsInt($context['sidecar_cited_count']);
    }

    public function testShadowFlagOnSidecarFailureLegacyAnswerStillReturned(): void
    {
        $history = [];
        $logger = new AgentIntentRestControllerShadowModeRecordingLogger();
        $controller = $this->buildController(
            sidecarResponses: [
                new Psr7Response(
                    501,
                    ['Content-Type' => 'application/json'],
                    json_encode(['detail' => ['error' => 'not_implemented']], JSON_THROW_ON_ERROR),
                ),
            ],
            sidecarHistory: $history,
            sidecarProxyEnabled: false,
            sidecarShadowEnabled: true,
            logger: $logger,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            'Legacy PHP path must still serve a 200 even when the shadow sidecar call fails',
        );
        $this->assertSame('verified', $body['data']['status']);
        $this->assertSame('deterministic_verified_fallback', $body['data']['response_generation']);
        $this->assertCount(1, $history);
        $this->assertCount(
            0,
            $logger->messagesMatching('Sidecar shadow comparison'),
            'No comparison record should be logged when the sidecar fails',
        );
        $this->assertGreaterThanOrEqual(
            1,
            count($logger->messagesMatching('agent.copilot.sidecar.shadow_failed')),
            'A WARNING-level shadow_failed log must be emitted',
        );
    }

    public function testShadowAndProxyBothOnProxyWinsNoShadowCall(): void
    {
        $history = [];
        $logger = new AgentIntentRestControllerShadowModeRecordingLogger();
        $controller = $this->buildController(
            sidecarResponses: [
                new Psr7Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($this->sidecarSuccessBody(), JSON_THROW_ON_ERROR),
                ),
            ],
            sidecarHistory: $history,
            sidecarProxyEnabled: true,
            sidecarShadowEnabled: true,
            logger: $logger,
        );

        $response = $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('sidecar_proxy', $body['data']['response_generation']);
        $this->assertCount(1, $history, 'Only the PROXY call should fire when both flags are on');
        $this->assertCount(
            0,
            $logger->messagesMatching('Sidecar shadow comparison'),
            'Shadow comparison must not run when PROXY is authoritative',
        );
    }

    public function testLoggedComparisonRecordContainsNoClaimOrBodyText(): void
    {
        $history = [];
        $logger = new AgentIntentRestControllerShadowModeRecordingLogger();
        $controller = $this->buildController(
            sidecarResponses: [
                new Psr7Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($this->sidecarSuccessBody(), JSON_THROW_ON_ERROR),
                ),
            ],
            sidecarHistory: $history,
            sidecarProxyEnabled: false,
            sidecarShadowEnabled: true,
            logger: $logger,
        );

        $controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $shadowEntries = $logger->messagesMatching('Sidecar shadow comparison');
        $this->assertCount(1, $shadowEntries);
        $context = $shadowEntries[0]['context'];

        // Verify field types: nothing free-form text-like beyond
        // headings/ids that are validated below as PHI-free.
        $this->assertIsString($context['trace_id']);
        $this->assertIsString($context['intent_id']);
        $this->assertIsBool($context['verification_status_match']);
        $this->assertIsBool($context['cited_source_ids_match']);
        $this->assertIsBool($context['missingness_shape_match']);
        $this->assertIsBool($context['headings_match']);
        $this->assertIsInt($context['php_cited_count']);
        $this->assertIsInt($context['sidecar_cited_count']);
        $this->assertIsArray($context['php_answer_block_headings']);
        $this->assertIsArray($context['sidecar_answer_block_headings']);

        $forbidden = [
            'Sidecar generated answer.',
            'Metformin 500 mg',
            'Metformin',
            'twice daily',
        ];
        $haystack = json_encode($context, JSON_THROW_ON_ERROR);
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $haystack,
                sprintf('Shadow log payload must never contain claim/body text: %s', $needle),
            );
        }
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
        bool $sidecarProxyEnabled,
        bool $sidecarShadowEnabled,
        AgentIntentRestControllerShadowModeRecordingLogger $logger,
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
                repository: new AgentIntentRestControllerShadowModeEvidenceRepository(),
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
            sidecarProxyEnabled: static fn (): bool => $sidecarProxyEnabled,
            sharedSecretProvider: static fn (): string => 'test-shared-secret',
            clock: static fn (): int => 1_700_000_000,
            sidecarShadowEnabled: static fn (): bool => $sidecarShadowEnabled,
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
 * Minimal PSR-3 capturing logger -- enough surface area for the
 * assertions in this test without pulling in a third-party mock library.
 */
final class AgentIntentRestControllerShadowModeRecordingLogger extends AbstractLogger
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

final class AgentIntentRestControllerShadowModeEvidenceRepository implements EvidenceRecordRepositoryInterface
{
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        return [];
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
