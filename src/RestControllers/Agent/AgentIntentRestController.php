<?php

/**
 * AgentIntentRestController
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers\Agent;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\Agent\AgentAccessBroker;
use OpenEMR\Services\Agent\AgentAccessDecision;
use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentEvidenceResponseBuilder;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\Copilot\CopilotRunContext;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceAccessException;
use OpenEMR\Services\Agent\Sidecar\CopilotRunRequestDto;
use OpenEMR\Services\Agent\Sidecar\CopilotRunResponseDto;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarClient;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarException;
use OpenEMR\Services\Agent\Sidecar\ShadowComparator;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AgentIntentRestController
{
    private const REQUEST_ID_HEADER = 'X-OpenEMR-Agent-Request-Id';
    private const SERVER_PATIENT_CONTEXT = 'server-session';

    /**
     * Run-context expiry window in seconds. The sidecar's verifier rejects
     * tokens whose ``expires_at`` is in the past, so we mint a short-lived
     * authority token covering the duration of a single sidecar invocation.
     */
    private const RUN_CONTEXT_TTL_SECONDS = 60;

    /**
     * Default key version used for HMAC signing of CopilotRunContext tokens.
     * The Python sidecar (M3/M4) currently maps ``v1`` to the shared secret
     * configured via ``OPENEMR_AGENT_SIDECAR_SECRET`` / ``AGENT_SHARED_SECRET``.
     */
    private const RUN_CONTEXT_KEY_VERSION = 'v1';

    private const ALLOWED_PAYLOAD_FIELDS = [
        'intent_id',
        'conversation_id',
        'active_patient_context',
        'source_id',
    ];

    private const FREE_TEXT_FIELDS = [
        'free_text',
        'input',
        'llm_user_text',
        'message',
        'prompt',
        'prompt_text',
        'query',
        'question',
        'text',
        'user_text',
    ];

    /**
     * @var callable(): string
     */
    private $requestIdFactory;

    /**
     * @var callable(): int
     */
    private $clock;

    /**
     * @var callable(): bool
     */
    private $sidecarProxyEnabled;

    /**
     * @var callable(): bool
     */
    private $sidecarShadowEnabled;

    /**
     * @var callable(): string
     */
    private $sharedSecretProvider;

    private readonly ShadowComparator $shadowComparator;

    public function __construct(
        private readonly AgentIntentCatalog $intentCatalog = new AgentIntentCatalog(),
        private readonly AgentAccessBroker $accessBroker = new AgentAccessBroker(),
        private readonly AgentEvidenceResponseBuilder $responseBuilder = new AgentEvidenceResponseBuilder(),
        private readonly LoggerInterface $logger = new SystemLogger(),
        ?callable $requestIdFactory = null,
        private readonly ?CopilotSidecarClient $copilotSidecarClient = null,
        ?callable $sidecarProxyEnabled = null,
        ?callable $sharedSecretProvider = null,
        ?callable $clock = null,
        ?callable $sidecarShadowEnabled = null,
        ?ShadowComparator $shadowComparator = null,
    ) {
        $this->requestIdFactory = $requestIdFactory ?? static fn (): string => bin2hex(random_bytes(16));
        $this->clock = $clock ?? static fn (): int => time();
        $this->sidecarProxyEnabled = $sidecarProxyEnabled ?? static function (): bool {
            $flag = getenv('OPENEMR_COPILOT_SIDECAR_PROXY_ENABLED');
            if (!is_string($flag) || $flag === '') {
                return false;
            }
            return in_array(strtolower(trim($flag)), ['1', 'true', 'on', 'yes'], true);
        };
        $this->sidecarShadowEnabled = $sidecarShadowEnabled ?? static function (): bool {
            $flag = getenv('OPENEMR_COPILOT_SIDECAR_SHADOW_ENABLED');
            if (!is_string($flag) || $flag === '') {
                return false;
            }
            return in_array(strtolower(trim($flag)), ['1', 'true', 'on', 'yes'], true);
        };
        $this->sharedSecretProvider = $sharedSecretProvider ?? static function (): string {
            $secret = getenv('OPENEMR_AGENT_SIDECAR_SECRET');
            if (is_string($secret) && $secret !== '') {
                return $secret;
            }
            $fallback = getenv('AGENT_SHARED_SECRET');
            return is_string($fallback) ? $fallback : '';
        };
        $this->shadowComparator = $shadowComparator ?? new ShadowComparator();
    }

    public function postIntent(HttpRestRequest $request): JsonResponse
    {
        $requestId = $this->ensureRequestId($request);
        $request->attributes->set('skipResponseLogging', true);
        $request->attributes->set('agentRouteRawResponseLoggingDisabled', true);

        $decodeResult = $this->decodePayload($request);
        if (isset($decodeResult['errors'])) {
            $this->logger->warning('agent.intent.invalid_payload', [
                'request_id' => $requestId,
                'stage' => 'decode',
                'fields' => array_keys($decodeResult['errors']),
            ]);
            return $this->badRequest($decodeResult['errors'], $requestId);
        }

        return $this->handlePayload($decodeResult['payload'], $request);
    }

    /**
     * @param array<mixed> $payload
     */
    public function handlePayload(array $payload, HttpRestRequest $request): JsonResponse
    {
        $requestId = $this->ensureRequestId($request);
        $intentIdInput = is_string($payload['intent_id'] ?? null) ? $payload['intent_id'] : null;
        $conversationId = is_string($payload['conversation_id'] ?? null) ? $payload['conversation_id'] : null;
        $request->attributes->set('skipResponseLogging', true);
        $request->attributes->set('agentRouteRawResponseLoggingDisabled', true);
        $this->logger->info('agent.intent.received', [
            'request_id' => $requestId,
            'intent_id' => $intentIdInput,
            'conversation_id' => $conversationId,
            'has_source_id' => isset($payload['source_id']),
            'has_active_patient_context' => isset($payload['active_patient_context']),
        ]);

        $validationErrors = $this->validatePayload($payload);
        if ($validationErrors !== []) {
            $this->logger->warning('agent.intent.invalid_payload', [
                'request_id' => $requestId,
                'stage' => 'validate',
                'intent_id' => $intentIdInput,
                'fields' => array_keys($validationErrors),
            ]);
            return $this->badRequest($validationErrors, $requestId);
        }

        $intent = $this->intentCatalog->get($payload['intent_id']);
        if ($intent === null) {
            $this->logger->warning('agent.intent.invalid_payload', [
                'request_id' => $requestId,
                'stage' => 'catalog',
                'intent_id' => $intentIdInput,
                'fields' => ['intent_id'],
            ]);
            return $this->badRequest(['intent_id' => ['Unknown agent intent_id.']], $requestId);
        }

        $accessDecision = $this->accessBroker->authorize($request, $intent['intent_id'], $payload);
        if (!$accessDecision->isAllowed()) {
            $this->logger->warning('agent.intent.access_denied', [
                'request_id' => $requestId,
                'intent_id' => $intent['intent_id'],
                'reason_code' => $accessDecision->getReasonCode(),
            ]);
            return $this->accessDenied($accessDecision, $requestId);
        }

        $accessToken = $accessDecision->getAccessToken();
        if ($accessToken === null) {
            $this->logger->error('agent.intent.evidence_denied', [
                'request_id' => $requestId,
                'intent_id' => $intent['intent_id'],
                'reason' => 'missing_access_token',
            ]);
            return $this->evidenceDenied('Agent access token was not available.', $requestId);
        }

        if ($this->shouldProxyToSidecar()) {
            return $this->proxyIntentToSidecar(
                $intent,
                $accessToken,
                $conversationId,
                $requestId
            );
        }

        try {
            $agentResponse = $this->responseBuilder->build(
                $intent['intent_id'],
                $accessToken,
                $this->sourceIdFromPayload($payload),
                $requestId
            );
            $request->attributes->set('agentAnonymizedPayloadLog', $this->responseBuilder->getLastLogPayload());
        } catch (AgentEvidenceAccessException $exception) {
            $this->logger->warning('agent.intent.evidence_denied', [
                'request_id' => $requestId,
                'intent_id' => $intent['intent_id'],
                'reason_code' => $exception->getReasonCode(),
            ]);
            return $this->evidenceDenied($exception->getPublicMessage(), $requestId);
        }

        $claimCount = $this->countClaims($agentResponse);
        $citationCount = is_array($agentResponse['citations'] ?? null) ? count($agentResponse['citations']) : 0;
        $this->logger->info('agent.intent.completed', [
            'request_id' => $requestId,
            'intent_id' => $intent['intent_id'],
            'status' => is_string($agentResponse['status'] ?? null) ? $agentResponse['status'] : 'unknown',
            'citation_count' => $citationCount,
            'claim_count' => $claimCount,
        ]);
        $this->logger->info('agent.response.rendered', [
            'request_id' => $requestId,
            'intent_id' => $intent['intent_id'],
            'answer_block_count' => $this->countAnswerBlocks($agentResponse),
            'citation_count' => $citationCount,
        ]);

        if ($this->shouldShadowCompare()) {
            $this->runShadowComparison($intent, $accessToken, $agentResponse, $requestId);
        }

        return $this->jsonResponse([
            'validationErrors' => [],
            'internalErrors' => [],
            'data' => array_merge([
                'intent_id' => $intent['intent_id'],
                'button_label' => $intent['button_label'],
                'trace' => [
                    'request_id' => $requestId,
                    'conversation_id' => $conversationId,
                    'response_payload_logging' => 'anonymized_or_disabled',
                ],
            ], $agentResponse),
        ], Response::HTTP_OK, $requestId);
    }

    private function shouldProxyToSidecar(): bool
    {
        if ($this->copilotSidecarClient === null) {
            return false;
        }

        return ($this->sidecarProxyEnabled)() === true;
    }

    private function shouldShadowCompare(): bool
    {
        if ($this->copilotSidecarClient === null) {
            return false;
        }

        // Shadow comparison is irrelevant when the sidecar is already
        // authoritative -- the user is seeing the sidecar response.
        if (($this->sidecarProxyEnabled)() === true) {
            return false;
        }

        return ($this->sidecarShadowEnabled)() === true;
    }

    /**
     * @param array{
     *     intent_id: string,
     *     button_label: string,
     *     prompt_text: string,
     *     primary_users: list<string>,
     *     use_case_traces: list<string>,
     *     max_records: int,
     *     max_documents: int,
     *     lookback_days: int
     * } $intent
     * @param array<string, mixed> $phpResponse
     */
    private function runShadowComparison(
        array $intent,
        AgentAccessToken $accessToken,
        array $phpResponse,
        string $requestId
    ): void {
        if ($this->copilotSidecarClient === null) {
            return;
        }

        try {
            $sidecarRequest = $this->buildSidecarRequest($intent, $accessToken, $requestId);
        } catch (\DomainException | \InvalidArgumentException | RandomException $e) {
            $this->logger->warning('agent.copilot.sidecar.shadow_context_failed', [
                'request_id' => $requestId,
                'intent_id' => $intent['intent_id'],
                'exception' => $e,
            ]);
            return;
        }

        try {
            $sidecarResponse = $this->copilotSidecarClient->runCopilot($sidecarRequest);
        } catch (CopilotSidecarException $e) {
            $this->logger->warning('agent.copilot.sidecar.shadow_failed', [
                'request_id' => $requestId,
                'intent_id' => $intent['intent_id'],
                'reason' => $e->reason,
                'http_status' => $e->httpStatus,
            ]);
            return;
        }

        $record = $this->shadowComparator->compare(
            $phpResponse,
            $sidecarResponse,
            $sidecarResponse->traceId !== '' ? $sidecarResponse->traceId : $requestId,
            $intent['intent_id'],
        );

        $this->logger->info('Sidecar shadow comparison', [
            'trace_id' => $record->traceId,
            'intent_id' => $record->intentId,
            'verification_status_match' => $record->verificationStatusMatch,
            'cited_source_ids_match' => $record->citedSourceIdsMatch,
            'php_cited_count' => $record->phpCitedCount,
            'sidecar_cited_count' => $record->sidecarCitedCount,
            'missingness_shape_match' => $record->missingnessShapeMatch,
            'headings_match' => $record->headingsMatch,
            'php_answer_block_headings' => $record->phpAnswerBlockHeadings,
            'sidecar_answer_block_headings' => $record->sidecarAnswerBlockHeadings,
        ]);
    }

    /**
     * @param array{
     *     intent_id: string,
     *     button_label: string,
     *     prompt_text: string,
     *     primary_users: list<string>,
     *     use_case_traces: list<string>,
     *     max_records: int,
     *     max_documents: int,
     *     lookback_days: int
     * } $intent
     *
     * @throws \DomainException
     * @throws \InvalidArgumentException
     * @throws RandomException
     */
    private function buildSidecarRequest(
        array $intent,
        AgentAccessToken $accessToken,
        string $requestId
    ): CopilotRunRequestDto {
        $patientId = $accessToken->getPatientContext()->getPid();
        $now = ($this->clock)();
        $secret = ($this->sharedSecretProvider)();
        if ($secret === '') {
            throw new \DomainException('Sidecar shared secret is not configured.');
        }

        $intentId = $intent['intent_id'];
        $allowedTools = $accessToken->getGrantedTools();
        if ($allowedTools === []) {
            throw new \DomainException('Access token does not grant any sidecar tools.');
        }

        $allowedSourceTypes = $accessToken->getGrantedDataClasses();
        if ($allowedSourceTypes === []) {
            $allowedSourceTypes = [$intentId];
        }

        $maxRows = max(1, $intent['max_records']);
        $lookbackDays = max(1, $intent['lookback_days']);

        $traceId = $this->generateUuidV4();
        $minterRequestId = $this->generateUuidV4();
        $userIdentity = $this->resolveUserIdentity($accessToken);

        $claims = [
            'user_id' => $userIdentity['user_id'],
            'username' => $userIdentity['username'],
            'patient_id' => $patientId,
            'encounter_id' => null,
            'allowed_tools' => $allowedTools,
            'allowed_source_types' => $allowedSourceTypes,
            'max_rows' => $maxRows,
            'lookback_days' => $lookbackDays,
            'expires_at' => $now + self::RUN_CONTEXT_TTL_SECONDS,
            'request_id' => $minterRequestId,
            'trace_id' => $traceId,
        ];

        $wireToken = CopilotRunContext::mint($claims, $secret, self::RUN_CONTEXT_KEY_VERSION);

        return new CopilotRunRequestDto(
            runContext: $wireToken,
            intentId: $intentId,
            userGoal: null,
            requestId: $minterRequestId,
        );
    }

    /**
     * @param array{
     *     intent_id: string,
     *     button_label: string,
     *     prompt_text: string,
     *     primary_users: list<string>,
     *     use_case_traces: list<string>,
     *     max_records: int,
     *     max_documents: int,
     *     lookback_days: int
     * } $intent
     */
    private function proxyIntentToSidecar(
        array $intent,
        AgentAccessToken $accessToken,
        ?string $conversationId,
        string $requestId
    ): JsonResponse {
        if ($this->copilotSidecarClient === null) {
            return $this->serviceUnavailable($requestId);
        }

        try {
            $patientId = $accessToken->getPatientContext()->getPid();
            $now = ($this->clock)();
            $secret = ($this->sharedSecretProvider)();
            if ($secret === '') {
                $this->logger->error('agent.copilot.sidecar.secret_missing', [
                    'request_id' => $requestId,
                ]);
                return $this->serviceUnavailable($requestId);
            }

            $intentId = $intent['intent_id'];
            $allowedTools = $accessToken->getGrantedTools();
            if ($allowedTools === []) {
                $this->logger->warning('agent.copilot.sidecar.no_granted_tools', [
                    'request_id' => $requestId,
                    'intent_id' => $intentId,
                ]);
                return $this->serviceUnavailable($requestId);
            }

            $allowedSourceTypes = $accessToken->getGrantedDataClasses();
            if ($allowedSourceTypes === []) {
                $allowedSourceTypes = [$intentId];
            }

            $maxRows = max(1, $intent['max_records']);
            $lookbackDays = max(1, $intent['lookback_days']);

            $traceId = $this->generateUuidV4();
            $minterRequestId = $this->generateUuidV4();

            $userIdentity = $this->resolveUserIdentity($accessToken);

            $claims = [
                'user_id' => $userIdentity['user_id'],
                'username' => $userIdentity['username'],
                'patient_id' => $patientId,
                'encounter_id' => null,
                'allowed_tools' => $allowedTools,
                'allowed_source_types' => $allowedSourceTypes,
                'max_rows' => $maxRows,
                'lookback_days' => $lookbackDays,
                'expires_at' => $now + self::RUN_CONTEXT_TTL_SECONDS,
                'request_id' => $minterRequestId,
                'trace_id' => $traceId,
            ];

            $wireToken = CopilotRunContext::mint($claims, $secret, self::RUN_CONTEXT_KEY_VERSION);

            $sidecarRequest = new CopilotRunRequestDto(
                runContext: $wireToken,
                intentId: $intentId,
                userGoal: null,
                requestId: $minterRequestId,
            );
        } catch (\DomainException | \InvalidArgumentException | RandomException $e) {
            $this->logger->error('agent.copilot.sidecar.context_mint_failed', [
                'request_id' => $requestId,
                'intent_id' => $intent['intent_id'],
                'exception' => $e,
            ]);
            return $this->serviceUnavailable($requestId);
        }

        try {
            $response = $this->copilotSidecarClient->runCopilot($sidecarRequest);
        } catch (CopilotSidecarException $e) {
            $this->logger->warning('agent.copilot.sidecar.failed', [
                'request_id' => $requestId,
                'intent_id' => $intent['intent_id'],
                'reason' => $e->reason,
                'http_status' => $e->httpStatus,
            ]);
            return $this->serviceUnavailable($requestId);
        }

        $this->logger->info('agent.copilot.sidecar.proxy_completed', [
            'request_id' => $requestId,
            'intent_id' => $intent['intent_id'],
            'verification_status' => $response->verificationStatus,
            'citation_count' => count($response->citations),
            'answer_block_count' => count($response->answerBlocks),
        ]);

        return $this->jsonResponse([
            'validationErrors' => [],
            'internalErrors' => [],
            'data' => [
                'intent_id' => $intent['intent_id'],
                'button_label' => $intent['button_label'],
                'response_generation' => 'sidecar_proxy',
                'trace' => [
                    'request_id' => $requestId,
                    'conversation_id' => $conversationId,
                    'response_payload_logging' => 'anonymized_or_disabled',
                    'sidecar_trace_id' => $response->traceId,
                ],
                'answer' => [
                    'answer_blocks' => $this->serializeAnswerBlocks($response),
                    'missing_or_uncertain' => $response->missingOrUncertain,
                ],
                'citations' => $this->serializeCitations($response),
                'verification' => [
                    'status' => $response->verificationStatus,
                ],
                'cost_usd' => $response->costUsd,
                'latency_ms_per_step' => $response->latencyMsPerStep,
            ],
        ], Response::HTTP_OK, $requestId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeAnswerBlocks(CopilotRunResponseDto $response): array
    {
        $rows = [];
        foreach ($response->answerBlocks as $block) {
            $rows[] = [
                'type' => $block->type,
                'content' => $block->content,
                'citation_indices' => $block->citationIndices,
            ];
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeCitations(CopilotRunResponseDto $response): array
    {
        $rows = [];
        foreach ($response->citations as $citation) {
            $rows[] = [
                'source_type' => $citation->sourceType,
                'source_id' => $citation->sourceId,
                'label' => $citation->label,
                'url' => $citation->url,
                'snippet' => $citation->snippet,
            ];
        }
        return $rows;
    }

    /**
     * @return array{user_id: int, username: string}
     */
    private function resolveUserIdentity(AgentAccessToken $accessToken): array
    {
        // The access broker has already verified an authenticated session;
        // we synthesize a stable, deterministic user_id off the access token's
        // hash so context minting cannot fail at the boundary. The Python
        // verifier accepts any positive user_id; the field is for traceability
        // only and is not used as authority anywhere in the sidecar.
        $intentId = $accessToken->getIntentId();
        $hash = substr(hash('sha256', $intentId . ':' . $accessToken->getTokenId()), 0, 8);
        $rawValue = hexdec($hash);
        if (!is_int($rawValue)) {
            $rawValue = 1;
        }
        $userId = ($rawValue % 0x7FFFFFFE) + 1;

        return [
            'user_id' => $userId,
            'username' => 'session-user',
        ];
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function serviceUnavailable(string $requestId): JsonResponse
    {
        return $this->jsonResponse([
            'validationErrors' => [],
            'internalErrors' => [
                'service' => ['The clinical co-pilot is temporarily unavailable. Please try again shortly.'],
            ],
            'data' => [],
        ], Response::HTTP_SERVICE_UNAVAILABLE, $requestId);
    }

    /**
     * @param array<string, mixed> $agentResponse
     */
    private function countClaims(array $agentResponse): int
    {
        $blocks = $agentResponse['answer']['answer_blocks'] ?? null;
        if (!is_array($blocks)) {
            return 0;
        }
        $total = 0;
        foreach ($blocks as $block) {
            if (is_array($block) && is_array($block['claims'] ?? null)) {
                $total += count($block['claims']);
            }
        }
        return $total;
    }

    /**
     * @param array<string, mixed> $agentResponse
     */
    private function countAnswerBlocks(array $agentResponse): int
    {
        $blocks = $agentResponse['answer']['answer_blocks'] ?? null;
        return is_array($blocks) ? count($blocks) : 0;
    }

    /**
     * @return array{payload: array<mixed>}|array{errors: array<string, list<string>>}
     */
    private function decodePayload(HttpRestRequest $request): array
    {
        $content = trim($request->getContent());
        if ($content === '') {
            return ['payload' => []];
        }

        $payload = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'errors' => [
                    'json' => ['Invalid JSON payload.'],
                ],
            ];
        }

        if (!is_array($payload) || array_is_list($payload)) {
            return [
                'errors' => [
                    'payload' => ['JSON payload must be an object.'],
                ],
            ];
        }

        return ['payload' => $payload];
    }

    /**
     * @param array<mixed> $payload
     * @return array<string, list<string>>
     */
    private function validatePayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return [
                'payload' => ['JSON payload must be an object.'],
            ];
        }

        $validationErrors = [];
        $payloadKeys = array_keys($payload);
        $unsupportedFields = array_values(array_diff($payloadKeys, self::ALLOWED_PAYLOAD_FIELDS));
        $freeTextFields = array_values(array_intersect($payloadKeys, self::FREE_TEXT_FIELDS));

        if ($freeTextFields !== []) {
            $validationErrors['free_text'] = ['Free-text agent input is not supported. Use a cataloged intent_id.'];
        }

        if ($unsupportedFields !== []) {
            $validationErrors['payload'] = ['Unsupported payload fields: ' . implode(', ', $unsupportedFields) . '.'];
        }

        if (!array_key_exists('intent_id', $payload)) {
            $validationErrors['intent_id'] = ['intent_id is required.'];
        } elseif (!is_string($payload['intent_id']) || $payload['intent_id'] === '') {
            $validationErrors['intent_id'] = ['intent_id must be a non-empty string.'];
        } elseif (!$this->intentCatalog->has($payload['intent_id'])) {
            $validationErrors['intent_id'] = ['Unknown agent intent_id.'];
        }

        if (array_key_exists('conversation_id', $payload)) {
            if (!is_string($payload['conversation_id']) || !preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $payload['conversation_id'])) {
                $validationErrors['conversation_id'] = ['conversation_id must be a stable session-local identifier.'];
            }
        }

        if (array_key_exists('active_patient_context', $payload) && $payload['active_patient_context'] !== self::SERVER_PATIENT_CONTEXT) {
            $validationErrors['active_patient_context'] = ['active_patient_context must be server-session.'];
        }

        if (array_key_exists('source_id', $payload)) {
            if (($payload['intent_id'] ?? null) !== AgentIntentCatalog::SHOW_SOURCE) {
                $validationErrors['source_id'] = ['source_id is only supported with the show_source intent.'];
            } elseif (!is_string($payload['source_id']) || !preg_match('/\A[A-Za-z0-9_]+:[A-Za-z0-9_]+:[0-9]{1,20}\z/', $payload['source_id'])) {
                $validationErrors['source_id'] = ['source_id must identify a server-issued citation source.'];
            }
        }

        return $validationErrors;
    }

    /**
     * @param array<mixed> $payload
     */
    private function sourceIdFromPayload(array $payload): ?string
    {
        return isset($payload['source_id']) && is_string($payload['source_id'])
            ? $payload['source_id']
            : null;
    }

    /**
     * @param array<string, list<string>> $validationErrors
     */
    private function badRequest(array $validationErrors, string $requestId): JsonResponse
    {
        return $this->jsonResponse([
            'validationErrors' => $validationErrors,
            'internalErrors' => [],
            'data' => [],
        ], Response::HTTP_BAD_REQUEST, $requestId);
    }

    private function accessDenied(AgentAccessDecision $accessDecision, string $requestId): JsonResponse
    {
        return $this->jsonResponse([
            'validationErrors' => [],
            'internalErrors' => [
                'access' => [$accessDecision->getPublicMessage()],
            ],
            'data' => [],
        ], Response::HTTP_FORBIDDEN, $requestId);
    }

    private function evidenceDenied(string $publicMessage, string $requestId): JsonResponse
    {
        return $this->jsonResponse([
            'validationErrors' => [],
            'internalErrors' => [
                'access' => [$publicMessage],
            ],
            'data' => [],
        ], Response::HTTP_FORBIDDEN, $requestId);
    }

    private function ensureRequestId(HttpRestRequest $request): string
    {
        $existing = $request->attributes->get('agentRequestId');
        if (is_string($existing) && $this->isSafeRequestId($existing)) {
            return $existing;
        }

        $requestId = ($this->requestIdFactory)();
        if (!is_string($requestId) || !$this->isSafeRequestId($requestId)) {
            $requestId = bin2hex(random_bytes(16));
        }

        $request->attributes->set('agentRequestId', $requestId);

        return $requestId;
    }

    private function isSafeRequestId(string $requestId): bool
    {
        return preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $requestId) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $statusCode, string $requestId): JsonResponse
    {
        $response = new JsonResponse($payload, $statusCode);
        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);

        return $response;
    }
}
