<?php

/**
 * AgentProposalCommitControllerTest
 *
 * Isolated unit tests for the M21 two-phase write boundary. The controller
 * verifies a signed CopilotRunContext, validates the typed proposal
 * (citation coverage, idempotency-key shape, defence-in-depth payload
 * keys), and forwards a fresh proposal to {@see LabPdfDispatcher}. Replays
 * keyed by ``idempotency_key`` return the previously-committed result
 * without touching the dispatcher.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestControllers\Agent;

use DateTimeImmutable;
use DateTimeZone;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\Agent\AgentProposalCommitController;
use OpenEMR\Services\Agent\Copilot\CopilotRunContext;
use OpenEMR\Services\Agent\Copilot\CopilotRunContextVerifier;
use OpenEMR\Services\Agent\Proposals\CommittedProposalRepository;
use OpenEMR\Services\Agent\Sidecar\Dispatcher\LabPdfDispatcher;
use OpenEMR\Services\Agent\Sidecar\Dispatcher\SqlExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[Group('isolated')]
#[Group('agent')]
final class AgentProposalCommitControllerTest extends TestCase
{
    private const SECRET = 'unit-test-shared-secret';
    private const KEY_VERSION = 'v1';
    private const TRACE_ID = 'trace-m21-commit';
    private const FROZEN_NOW = 1_700_000_000;

    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'm21-commit-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
    }

    public function testValidProposalIsCommittedAndReturnsRowIds(): void
    {
        $controller = $this->buildController();
        $proposal = $this->validProposalArray();

        $response = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame([], $body['errors']);
        self::assertGreaterThan(0, $body['data']['procedure_order_id']);
        self::assertGreaterThan(0, $body['data']['procedure_report_id']);
        self::assertCount(1, $body['data']['procedure_result_ids']);
        self::assertSame(self::TRACE_ID, $body['data']['trace_id']);
        self::assertFalse($body['data']['replayed']);
    }

    public function testInvalidRunContextReturns401(): void
    {
        $controller = $this->buildController();
        $response = $controller->postCommit($this->requestWithJson([
            'run_context' => 'not-a-real-token.bad-signature',
            'proposal' => $this->validProposalArray(),
        ]));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertNotEmpty($body['errors']['access']);
    }

    public function testCitationOutsideRunContextScopeIs422(): void
    {
        $controller = $this->buildController();
        $proposal = $this->validProposalArray();
        // Mutate the citation source_type to one not in
        // allowed_source_types (which is ['documents','labs']).
        $proposal['citations'][0]['source_type'] = 'documents-other-patient';

        $response = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $body = $this->decodeBody($response);
        $reasons = $body['errors']['validation'];
        self::assertTrue(self::containsReason($reasons, 'citation_source_type_outside_scope'));
    }

    public function testReplayReturnsSamePriorResultWithoutDoubleWrite(): void
    {
        $controller = $this->buildController();
        $proposal = $this->validProposalArray();

        $first = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));
        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        $firstBody = $this->decodeBody($first);

        $second = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));
        self::assertSame(Response::HTTP_OK, $second->getStatusCode());
        $secondBody = $this->decodeBody($second);

        self::assertSame(
            $firstBody['data']['procedure_order_id'],
            $secondBody['data']['procedure_order_id'],
        );
        self::assertSame(
            $firstBody['data']['procedure_result_ids'],
            $secondBody['data']['procedure_result_ids'],
        );
        self::assertTrue($secondBody['data']['replayed']);
    }

    public function testInvalidProposalKindIs422(): void
    {
        $controller = $this->buildController();
        $proposal = $this->validProposalArray();
        $proposal['proposal_kind'] = 'unsupported_kind';

        $response = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $reasons = $this->decodeBody($response)['errors']['validation'];
        self::assertTrue(self::containsReason($reasons, 'proposal_kind_unsupported'));
    }

    public function testForbiddenPayloadKeysAreRejected422(): void
    {
        $controller = $this->buildController();
        $proposal = $this->validProposalArray();
        // Sidecar must never ship file_path / sql / patient_id; PHP rejects
        // such payloads as an extra defence layer at the boundary.
        $proposal['payload']['file_path'] = '/etc/passwd';
        $proposal['citation_field_map'][] = 'file_path';
        $proposal['citations'][] = [
            'source_type' => 'documents',
            'source_id' => 'doc:upload:99',
            'label' => 'Bad citation',
        ];

        $response = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $reasons = $this->decodeBody($response)['errors']['validation'];
        self::assertTrue(self::containsReason($reasons, 'payload_forbidden_key:file_path'));
    }

    public function testMissingPerFieldCitationIs422(): void
    {
        $controller = $this->buildController();
        $proposal = $this->validProposalArray();
        // Drop the citation backing the 'value' field.
        $idx = array_search('value', $proposal['citation_field_map'], true);
        self::assertNotFalse($idx);
        unset($proposal['citation_field_map'][$idx]);
        unset($proposal['citations'][$idx]);
        $proposal['citation_field_map'] = array_values($proposal['citation_field_map']);
        $proposal['citations'] = array_values($proposal['citations']);

        $response = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $reasons = $this->decodeBody($response)['errors']['validation'];
        self::assertTrue(self::containsReason($reasons, 'payload_field_uncited:value'));
    }

    public function testStaleProposedAtIs422(): void
    {
        $controller = $this->buildController();
        $proposal = $this->validProposalArray();
        $stale = self::FROZEN_NOW - 60 * 60 - 30;
        $proposal['proposed_at'] = gmdate('Y-m-d\TH:i:s\Z', $stale);

        $response = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $reasons = $this->decodeBody($response)['errors']['validation'];
        self::assertTrue(self::containsReason($reasons, 'proposed_at_stale'));
    }

    public function testIdempotencyKeyTraceMismatchIs422(): void
    {
        $controller = $this->buildController();
        $proposal = $this->validProposalArray();
        $proposal['idempotency_key'] = 'someone-elses-trace:scope';

        $response = $controller->postCommit($this->requestWithJson([
            'run_context' => $this->mintWire(),
            'proposal' => $proposal,
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $reasons = $this->decodeBody($response)['errors']['validation'];
        self::assertTrue(self::containsReason($reasons, 'idempotency_key_trace_mismatch'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildController(): AgentProposalCommitController
    {
        $secretResolver = static function (string $keyVersion): ?string {
            return $keyVersion === self::KEY_VERSION ? self::SECRET : null;
        };
        $verifier = new CopilotRunContextVerifier(
            secretResolver: $secretResolver,
            clock: static fn (): int => self::FROZEN_NOW,
        );
        $sql = new ProposalCommitInMemorySql();
        $dispatcher = new LabPdfDispatcher(
            $sql,
            new NullLogger(),
            new class () implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-05-01 12:00:00', new DateTimeZone('UTC'));
                }
            },
        );
        $repository = new CommittedProposalRepository(
            storageDirectory: $this->tempDir,
            logger: new NullLogger(),
        );

        return new AgentProposalCommitController(
            runContextVerifier: $verifier,
            labPdfDispatcher: $dispatcher,
            committedProposalRepository: $repository,
            logger: new NullLogger(),
            csrfVerifier: static fn (): bool => true,
            requestIdFactory: static fn (): string => 'agent-test-request',
            clock: static fn (): int => self::FROZEN_NOW,
        );
    }

    private function mintWire(): string
    {
        return CopilotRunContext::mint(
            [
                'user_id' => 17,
                'username' => 'dr.smith',
                'patient_id' => 42,
                'encounter_id' => 100,
                'allowed_tools' => ['persist_lab_observation_proposal'],
                'allowed_source_types' => ['documents', 'labs'],
                'max_rows' => 10,
                'lookback_days' => 365,
                'expires_at' => self::FROZEN_NOW + 60,
                'request_id' => 'req-m21-commit',
                'trace_id' => self::TRACE_ID,
            ],
            self::SECRET,
            self::KEY_VERSION,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validProposalArray(): array
    {
        $payload = [
            'test_name' => 'Hemoglobin',
            'value' => '13.5',
            'unit' => 'g/dL',
            'reference_range' => '13.5-17.5',
            'collection_date' => '2026-04-01',
            'abnormal_flag' => 'normal',
            'loinc_code' => '718-7',
        ];
        $citationFieldMap = array_keys($payload);
        $citations = [];
        foreach ($citationFieldMap as $idx => $field) {
            $citations[] = [
                'source_type' => 'documents',
                'source_id' => 'doc:upload:' . ($idx + 1),
                'label' => 'Lab PDF region ' . ($idx + 1),
                'snippet' => $field . '=' . $payload[$field],
            ];
        }
        // Stable hash from the same recipe Python uses.
        $hashSeed = self::stableHashOfObservation($payload);
        return [
            'proposal_id' => 'proposal-test-id',
            'proposal_kind' => 'lab_observation',
            'payload' => $payload,
            'citations' => $citations,
            'citation_field_map' => $citationFieldMap,
            'idempotency_key' => self::TRACE_ID . ':' . $hashSeed,
            'proposed_at' => gmdate('Y-m-d\TH:i:s\Z', self::FROZEN_NOW - 5),
        ];
    }

    /**
     * @param array<string, mixed> $observation
     */
    private static function stableHashOfObservation(array $observation): string
    {
        // PHP-side hash for test-only purposes; the controller itself
        // never re-derives the key, it only validates the prefix.
        ksort($observation);
        return substr(hash('sha256', json_encode($observation, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWithJson(array $body): HttpRestRequest
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('authUser', 'admin');
        $session->set('authUserID', 1);

        $request = new HttpRestRequest(content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->setSession($session);
        $request->headers->set('APICSRFTOKEN', 'test-token');

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(JsonResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @param list<mixed> $reasons
     */
    private static function containsReason(array $reasons, string $needle): bool
    {
        foreach ($reasons as $reason) {
            if (is_string($reason) && str_contains($reason, $needle)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * @internal
 *
 * @codeCoverageIgnore Test fixture, not production code.
 */
final class ProposalCommitInMemorySql implements SqlExecutor
{
    /**
     * @var array<string, list<array{sql: string, bindings: list<scalar|null>, returnedId: int}>>
     */
    private array $inserts = [];

    /** @var array<string, int> */
    private array $nextId = [];

    /**
     * @param list<scalar|null> $bindings
     */
    public function insert(string $sql, array $bindings): int
    {
        $table = self::extractTable($sql);
        $next = $this->nextId[$table] ?? 100;
        $id = $next + 1;
        $this->nextId[$table] = $id;

        $this->inserts[$table][] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'returnedId' => $id,
        ];
        return $id;
    }

    /**
     * @param list<scalar|null>     $bindings
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $bindings): ?array
    {
        if (str_contains($sql, 'FROM `procedure_order`')) {
            $traceId = $bindings[0] ?? null;
            foreach ($this->inserts['procedure_order'] ?? [] as $insert) {
                if (in_array($traceId, $insert['bindings'], true)) {
                    return ['procedure_order_id' => $insert['returnedId']];
                }
            }
            return null;
        }
        if (str_contains($sql, 'FROM `procedure_report`')) {
            $orderId = $bindings[0] ?? null;
            foreach ($this->inserts['procedure_report'] ?? [] as $insert) {
                if (in_array($orderId, $insert['bindings'], true)) {
                    return ['procedure_report_id' => $insert['returnedId']];
                }
            }
            return null;
        }
        if (str_contains($sql, 'FROM `procedure_result`')) {
            $reportId = $bindings[0] ?? null;
            $matching = [];
            foreach ($this->inserts['procedure_result'] ?? [] as $insert) {
                if (in_array($reportId, $insert['bindings'], true)) {
                    $matching[] = ['procedure_result_id' => $insert['returnedId']];
                }
            }
            $offset = 0;
            if (preg_match('/OFFSET (\d+)/', $sql, $matches) === 1) {
                $offset = (int) $matches[1];
            }
            return $matching[$offset] ?? null;
        }
        return null;
    }

    private static function extractTable(string $sql): string
    {
        if (preg_match('/INSERT INTO `([a-z_]+)`/i', $sql, $matches) === 1) {
            return $matches[1];
        }
        return 'unknown';
    }
}
