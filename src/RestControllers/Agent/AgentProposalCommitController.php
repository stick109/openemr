<?php

/**
 * AgentProposalCommitController
 *
 * Two-phase write boundary for the chart copilot (M21). The Python sidecar
 * never commits clinical writes itself; tools that look like writes emit a
 * typed proposal which this controller validates and then forwards to the
 * existing {@see LabPdfDispatcher} (S16). The controller is the single PHP-
 * side trust boundary for write proposals: the run_context HMAC, citation
 * coverage, idempotency-key shape and forbidden-input defence all happen
 * here before any database row is touched.
 *
 * Endpoint: ``POST /apis/api/agent/proposals/commit``.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Clinical Co-Pilot Sidecar Migration
 * @copyright Copyright (c) 2026 OpenEMR contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers\Agent;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\Agent\Copilot\CopilotRunContext;
use OpenEMR\Services\Agent\Copilot\CopilotRunContextVerificationException;
use OpenEMR\Services\Agent\Copilot\CopilotRunContextVerifier;
use OpenEMR\Services\Agent\Proposals\CommittedProposalRecord;
use OpenEMR\Services\Agent\Proposals\CommittedProposalRepository;
use OpenEMR\Services\Agent\Sidecar\Dispatcher\LabDispatchResult;
use OpenEMR\Services\Agent\Sidecar\Dispatcher\LabPdfDispatcher;
use OpenEMR\Services\Intake\Exception\IngestionFailedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AgentProposalCommitController
{
    private const REQUEST_ID_HEADER = 'X-OpenEMR-Agent-Request-Id';
    private const FRESHNESS_WINDOW_SECONDS = 60 * 60;
    private const SUPPORTED_PROPOSAL_KIND = 'lab_observation';

    /**
     * Forbidden top-level keys the sidecar is never allowed to ship inside
     * a proposal payload. Mirrors the EXECUTOR_FORBIDDEN_MODEL_KEYS set in
     * ``agent_service/tools/executor.py`` plus the file/sql defence keys
     * called out by M21.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'patient_id',
        'encounter_id',
        'document_id',
        'mrn',
        'path',
        'file_path',
        'sql',
        'query',
        'query_string',
        'user_id',
        'username',
    ];

    /** @var callable(string, \Symfony\Component\HttpFoundation\Session\SessionInterface): bool */
    private mixed $csrfVerifier;

    /** @var callable(): string */
    private mixed $requestIdFactory;

    /** @var callable(): int */
    private mixed $clock;

    public function __construct(
        private CopilotRunContextVerifier $runContextVerifier,
        private LabPdfDispatcher $labPdfDispatcher,
        private CommittedProposalRepository $committedProposalRepository,
        private LoggerInterface $logger = new SystemLogger(),
        ?callable $csrfVerifier = null,
        ?callable $requestIdFactory = null,
        ?callable $clock = null,
    ) {
        $this->csrfVerifier = $csrfVerifier ?? (static fn(string $token, \Symfony\Component\HttpFoundation\Session\SessionInterface $session): bool => CsrfUtils::verifyCsrfToken($token, $session, 'api'));
        $this->requestIdFactory = $requestIdFactory ?? static fn (): string => bin2hex(random_bytes(16));
        $this->clock = $clock ?? time(...);
    }

    public function postCommit(HttpRestRequest $request): JsonResponse
    {
        $requestId = ($this->requestIdFactory)();
        $request->attributes->set('skipResponseLogging', true);
        $request->attributes->set('agentRouteRawResponseLoggingDisabled', true);

        // ------------------------------------------------------------------
        // Boundary checks: session + CSRF
        // ------------------------------------------------------------------
        if (!$request->hasSession()) {
            return $this->forbidden('missing_session', $requestId);
        }
        try {
            $session = $request->getSession();
        } catch (Throwable) {
            return $this->forbidden('missing_session', $requestId);
        }
        $csrfToken = $request->headers->get('APICSRFTOKEN');
        if (!is_string($csrfToken) || $csrfToken === '') {
            return $this->forbidden('missing_csrf', $requestId);
        }
        try {
            $csrfValid = ($this->csrfVerifier)($csrfToken, $session);
        } catch (Throwable) {
            $csrfValid = false;
        }
        if (!$csrfValid) {
            return $this->forbidden('invalid_csrf', $requestId);
        }

        // ------------------------------------------------------------------
        // Decode JSON body
        // ------------------------------------------------------------------
        $decoded = $this->decodeJsonBody($request->getContent());
        if ($decoded === null) {
            return $this->validationError('payload_invalid_json', $requestId);
        }

        $wireToken = $decoded['run_context'] ?? null;
        if (!is_string($wireToken) || $wireToken === '') {
            return $this->unauthorized('run_context_missing', $requestId);
        }

        // ------------------------------------------------------------------
        // Verify run_context HMAC
        // ------------------------------------------------------------------
        try {
            $runContext = $this->runContextVerifier->verify($wireToken);
        } catch (CopilotRunContextVerificationException $exception) {
            $this->logger->warning('agent.proposals.run_context_verification_failed', [
                'request_id' => $requestId,
                'reason' => $exception->reason,
            ]);
            return $this->unauthorized('run_context_' . $exception->reason, $requestId);
        }

        // ------------------------------------------------------------------
        // Pull and validate the proposal
        // ------------------------------------------------------------------
        $proposal = $decoded['proposal'] ?? null;
        if (!is_array($proposal) || array_is_list($proposal)) {
            return $this->validationError('proposal_missing', $requestId);
        }
        /** @var array<string, mixed> $proposal */

        $errors = $this->validateProposalShape($proposal, $runContext);
        if ($errors !== []) {
            $this->logger->warning('agent.proposals.validation_failed', [
                'request_id' => $requestId,
                'trace_id' => $runContext->traceId,
                'reasons' => $errors,
            ]);
            return $this->validationError($errors, $requestId);
        }

        $idempotencyKey = (string) $proposal['idempotency_key'];
        $traceId = $runContext->traceId;

        // ------------------------------------------------------------------
        // Idempotency: replay returns the previously-committed result
        // ------------------------------------------------------------------
        $existing = $this->committedProposalRepository->find($idempotencyKey);
        if ($existing !== null) {
            $this->logger->info('agent.proposals.idempotent_replay', [
                'request_id' => $requestId,
                'trace_id' => $traceId,
            ]);
            return $this->success($existing, replayed: true, requestId: $requestId);
        }

        // ------------------------------------------------------------------
        // Apply via LabPdfDispatcher (S16)
        // ------------------------------------------------------------------
        if ($runContext->encounterId === null) {
            return $this->validationError('run_context_missing_encounter', $requestId);
        }

        /** @var array<string, mixed> $payload */
        $payload = $proposal['payload'];
        try {
            $dispatchResult = $this->labPdfDispatcher->dispatch(
                patientId: $runContext->patientId,
                encounterId: $runContext->encounterId,
                extracted: $this->observationToExtractedPayload($payload),
                traceId: $traceId,
            );
        } catch (IngestionFailedException $exception) {
            $this->logger->warning('agent.proposals.dispatch_failed', [
                'request_id' => $requestId,
                'trace_id' => $traceId,
                'exception' => $exception,
            ]);
            return $this->validationError('dispatch_failed', $requestId);
        } catch (Throwable $exception) {
            $this->logger->error('agent.proposals.dispatch_unexpected_error', [
                'request_id' => $requestId,
                'trace_id' => $traceId,
                'exception' => $exception,
            ]);
            return $this->internalError($requestId);
        }

        $record = new CommittedProposalRecord(
            idempotencyKey: $idempotencyKey,
            traceId: $traceId,
            committedAtUnix: ($this->clock)(),
            procedureOrderId: $dispatchResult->procedureOrderId,
            procedureReportId: $dispatchResult->procedureReportId,
            procedureResultIds: $dispatchResult->procedureResultIds,
        );

        $persisted = $this->committedProposalRepository->record($record);
        return $this->success(
            $persisted,
            replayed: !$dispatchResult->created,
            requestId: $requestId,
        );
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $proposal
     *
     * @return list<string>
     */
    private function validateProposalShape(array $proposal, CopilotRunContext $runContext): array
    {
        $errors = [];

        $kind = $proposal['proposal_kind'] ?? null;
        if (!is_string($kind) || $kind !== self::SUPPORTED_PROPOSAL_KIND) {
            $errors[] = 'proposal_kind_unsupported';
        }

        $payload = $proposal['payload'] ?? null;
        if (!is_array($payload) || array_is_list($payload) || $payload === []) {
            $errors[] = 'payload_missing_or_empty';
            $payload = [];
        }
        /** @var array<string, mixed> $payload */

        // Defence-in-depth: the sidecar is never allowed to push a payload
        // that names file paths, raw SQL, or identity claims.
        foreach (self::FORBIDDEN_PAYLOAD_KEYS as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                $errors[] = 'payload_forbidden_key:' . $forbidden;
            }
        }

        $citations = $proposal['citations'] ?? null;
        if (!is_array($citations) || !array_is_list($citations)) {
            $errors[] = 'citations_missing';
            $citations = [];
        }

        $citationFieldMap = $proposal['citation_field_map'] ?? [];
        if (!is_array($citationFieldMap) || !array_is_list($citationFieldMap)) {
            $errors[] = 'citation_field_map_invalid';
            $citationFieldMap = [];
        }

        if (count($citationFieldMap) !== count($citations)) {
            $errors[] = 'citation_field_map_length_mismatch';
        }

        $payloadFields = array_keys($payload);
        $citedFields = [];
        foreach ($citationFieldMap as $field) {
            if (!is_string($field) || $field === '') {
                $errors[] = 'citation_field_map_non_string';
                continue;
            }
            $citedFields[] = $field;
        }

        foreach ($payloadFields as $field) {
            if (!is_string($field)) {
                continue;
            }
            if (!in_array($field, $citedFields, true)) {
                $errors[] = 'payload_field_uncited:' . $field;
            }
        }

        $allowedSourceTypes = $runContext->allowedSourceTypes;
        foreach ($citations as $index => $citation) {
            if (!is_array($citation) || array_is_list($citation)) {
                $errors[] = 'citation_invalid:' . $index;
                continue;
            }
            $sourceType = $citation['source_type'] ?? null;
            if (!is_string($sourceType) || $sourceType === '') {
                $errors[] = 'citation_source_type_missing:' . $index;
                continue;
            }
            if (!in_array($sourceType, $allowedSourceTypes, true)) {
                $errors[] = 'citation_source_type_outside_scope:' . $index;
            }
        }

        $idempotencyKey = $proposal['idempotency_key'] ?? null;
        if (!is_string($idempotencyKey) || !CommittedProposalRepository::isWellFormedKey($idempotencyKey)) {
            $errors[] = 'idempotency_key_malformed';
        } elseif (!str_starts_with($idempotencyKey, $runContext->traceId . ':')) {
            $errors[] = 'idempotency_key_trace_mismatch';
        }

        $proposedAt = $proposal['proposed_at'] ?? null;
        if (!is_string($proposedAt) || $proposedAt === '') {
            $errors[] = 'proposed_at_missing';
        } else {
            $now = ($this->clock)();
            $parsed = strtotime($proposedAt);
            if ($parsed === false) {
                $errors[] = 'proposed_at_unparseable';
            } elseif ($parsed > $now) {
                $errors[] = 'proposed_at_in_future';
            } elseif ($now - $parsed > self::FRESHNESS_WINDOW_SECONDS) {
                $errors[] = 'proposed_at_stale';
            }
        }

        return $errors;
    }

    /**
     * Map the proposal payload (a flat lab observation) into the wire shape
     * the existing {@see LabPdfDispatcher} consumes (a ``LabPdf`` extraction
     * with one ``results`` row).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function observationToExtractedPayload(array $payload): array
    {
        $row = [];
        foreach (['test_name', 'value', 'unit', 'reference_range', 'collection_date', 'abnormal_flag', 'loinc_code'] as $field) {
            if (array_key_exists($field, $payload)) {
                $row[$field] = $payload[$field];
            }
        }
        $labName = $payload['lab_name'] ?? null;

        return [
            'lab_name' => $labName,
            'results' => [$row],
        ];
    }

    // ------------------------------------------------------------------
    // Response helpers
    // ------------------------------------------------------------------

    private function success(
        CommittedProposalRecord $record,
        bool $replayed,
        string $requestId,
    ): JsonResponse {
        return $this->jsonResponse(
            [
                'data' => [
                    'idempotency_key' => $record->idempotencyKey,
                    'trace_id' => $record->traceId,
                    'replayed' => $replayed,
                    'committed_at_unix' => $record->committedAtUnix,
                    'procedure_order_id' => $record->procedureOrderId,
                    'procedure_report_id' => $record->procedureReportId,
                    'procedure_result_ids' => $record->procedureResultIds,
                ],
                'errors' => [],
            ],
            Response::HTTP_OK,
            $requestId,
        );
    }

    /**
     * @param string|list<string> $reasons
     */
    private function validationError(string|array $reasons, string $requestId): JsonResponse
    {
        $reasonList = is_array($reasons) ? $reasons : [$reasons];
        return $this->jsonResponse(
            [
                'data' => [],
                'errors' => [
                    'validation' => $reasonList,
                ],
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $requestId,
        );
    }

    private function forbidden(string $reason, string $requestId): JsonResponse
    {
        return $this->jsonResponse(
            [
                'data' => [],
                'errors' => ['access' => [$reason]],
            ],
            Response::HTTP_FORBIDDEN,
            $requestId,
        );
    }

    private function unauthorized(string $reason, string $requestId): JsonResponse
    {
        return $this->jsonResponse(
            [
                'data' => [],
                'errors' => ['access' => [$reason]],
            ],
            Response::HTTP_UNAUTHORIZED,
            $requestId,
        );
    }

    private function internalError(string $requestId): JsonResponse
    {
        return $this->jsonResponse(
            [
                'data' => [],
                'errors' => [
                    'service' => ['The proposal commit endpoint is temporarily unavailable.'],
                ],
            ],
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $requestId,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(array $body, int $statusCode, string $requestId): JsonResponse
    {
        $response = new JsonResponse($body, $statusCode);
        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);
        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
