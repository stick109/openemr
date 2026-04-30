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

    public function __construct(
        private readonly AgentIntentCatalog $intentCatalog = new AgentIntentCatalog(),
        private readonly AgentAccessBroker $accessBroker = new AgentAccessBroker(),
        private readonly AgentEvidenceResponseBuilder $responseBuilder = new AgentEvidenceResponseBuilder(),
        private readonly LoggerInterface $logger = new SystemLogger()
    ) {
    }

    public function postIntent(HttpRestRequest $request): JsonResponse
    {
        $request->attributes->set('skipResponseLogging', true);

        $decodeResult = $this->decodePayload($request);
        if (isset($decodeResult['errors'])) {
            $this->logger->warning('agent.intent.invalid_payload', [
                'stage' => 'decode',
                'fields' => array_keys($decodeResult['errors']),
            ]);
            return $this->badRequest($decodeResult['errors']);
        }

        return $this->handlePayload($decodeResult['payload'], $request);
    }

    /**
     * @param array<mixed> $payload
     */
    public function handlePayload(array $payload, HttpRestRequest $request): JsonResponse
    {
        $intentIdInput = is_string($payload['intent_id'] ?? null) ? $payload['intent_id'] : null;
        $conversationId = is_string($payload['conversation_id'] ?? null) ? $payload['conversation_id'] : null;
        $this->logger->info('agent.intent.received', [
            'intent_id' => $intentIdInput,
            'conversation_id' => $conversationId,
            'has_source_id' => isset($payload['source_id']),
            'has_active_patient_context' => isset($payload['active_patient_context']),
        ]);

        $validationErrors = $this->validatePayload($payload);
        if ($validationErrors !== []) {
            $this->logger->warning('agent.intent.invalid_payload', [
                'stage' => 'validate',
                'intent_id' => $intentIdInput,
                'fields' => array_keys($validationErrors),
            ]);
            return $this->badRequest($validationErrors);
        }

        $intent = $this->intentCatalog->get($payload['intent_id']);
        if ($intent === null) {
            $this->logger->warning('agent.intent.invalid_payload', [
                'stage' => 'catalog',
                'intent_id' => $intentIdInput,
                'fields' => ['intent_id'],
            ]);
            return $this->badRequest(['intent_id' => ['Unknown agent intent_id.']]);
        }

        $accessDecision = $this->accessBroker->authorize($request, $intent['intent_id'], $payload);
        if (!$accessDecision->isAllowed()) {
            $this->logger->warning('agent.intent.access_denied', [
                'intent_id' => $intent['intent_id'],
                'reason_code' => $accessDecision->getReasonCode(),
            ]);
            return $this->accessDenied($accessDecision);
        }

        $accessToken = $accessDecision->getAccessToken();
        if ($accessToken === null) {
            $this->logger->error('agent.intent.evidence_denied', [
                'intent_id' => $intent['intent_id'],
                'reason' => 'missing_access_token',
            ]);
            return $this->evidenceDenied('Agent access token was not available.');
        }

        try {
            $agentResponse = $this->responseBuilder->build(
                $intent['intent_id'],
                $accessToken,
                $this->sourceIdFromPayload($payload)
            );
            $request->attributes->set('agentAnonymizedPayloadLog', $this->responseBuilder->getLastLogPayload());
        } catch (AgentEvidenceAccessException $exception) {
            $this->logger->warning('agent.intent.evidence_denied', [
                'intent_id' => $intent['intent_id'],
                'reason_code' => $exception->getReasonCode(),
            ]);
            return $this->evidenceDenied($exception->getPublicMessage());
        }

        $this->logger->info('agent.intent.completed', [
            'intent_id' => $intent['intent_id'],
            'status' => is_string($agentResponse['status'] ?? null) ? $agentResponse['status'] : 'unknown',
            'citation_count' => is_array($agentResponse['citations'] ?? null) ? count($agentResponse['citations']) : 0,
            'claim_count' => $this->countClaims($agentResponse),
        ]);

        return new JsonResponse([
            'validationErrors' => [],
            'internalErrors' => [],
            'data' => array_merge([
                'intent_id' => $intent['intent_id'],
                'button_label' => $intent['button_label'],
            ], $agentResponse),
        ], Response::HTTP_OK);
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
    private function badRequest(array $validationErrors): JsonResponse
    {
        return new JsonResponse([
            'validationErrors' => $validationErrors,
            'internalErrors' => [],
            'data' => [],
        ], Response::HTTP_BAD_REQUEST);
    }

    private function accessDenied(AgentAccessDecision $accessDecision): JsonResponse
    {
        return new JsonResponse([
            'validationErrors' => [],
            'internalErrors' => [
                'access' => [$accessDecision->getPublicMessage()],
            ],
            'data' => [],
        ], Response::HTTP_FORBIDDEN);
    }

    private function evidenceDenied(string $publicMessage): JsonResponse
    {
        return new JsonResponse([
            'validationErrors' => [],
            'internalErrors' => [
                'access' => [$publicMessage],
            ],
            'data' => [],
        ], Response::HTTP_FORBIDDEN);
    }
}
