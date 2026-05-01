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
use OpenEMR\Services\Agent\AgentEvidenceResponseBuilder;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceAccessException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AgentIntentRestController
{
    private const REQUEST_ID_HEADER = 'X-OpenEMR-Agent-Request-Id';
    private const SERVER_PATIENT_CONTEXT = 'server-session';

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

    public function __construct(
        private readonly AgentIntentCatalog $intentCatalog = new AgentIntentCatalog(),
        private readonly AgentAccessBroker $accessBroker = new AgentAccessBroker(),
        private readonly AgentEvidenceResponseBuilder $responseBuilder = new AgentEvidenceResponseBuilder(),
        private readonly LoggerInterface $logger = new SystemLogger(),
        ?callable $requestIdFactory = null
    ) {
        $this->requestIdFactory = $requestIdFactory ?? static fn (): string => bin2hex(random_bytes(16));
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
