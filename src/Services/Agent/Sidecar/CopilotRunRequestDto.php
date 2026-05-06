<?php

/**
 * CopilotRunRequestDto
 *
 * Read-only DTO mirroring the Python ``CopilotRunRequest`` schema in
 * ``agent-service/agent_service/schemas/copilot.py``. The PHP UI proxy
 * (M17) constructs one of these and serializes it via ``toArray()``
 * before posting to ``POST /api/copilot/run`` on the sidecar.
 *
 * Validation here is intentionally minimal: the wire schema is the
 * source of truth, and the sidecar will reject malformed payloads
 * with HTTP 422. PHP enforces only the constraints needed to avoid
 * sending obviously invalid requests over the network.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use InvalidArgumentException;

final readonly class CopilotRunRequestDto
{
    public const USER_GOAL_MAX_CHARS = 4000;

    /**
     * @param string                    $runContext         Signed wire token minted by PHP for this run.
     * @param string|null               $intentId           Closed-set intent ID; required if $userGoal is null.
     * @param string|null               $userGoal           Free-form clinician goal; required if $intentId is null.
     * @param string                    $requestId          Caller-supplied UUID for idempotency/correlation.
     * @param array<string, mixed>|null $conversationState  Opaque round-trip state echoed by the sidecar.
     */
    public function __construct(
        public string $runContext,
        public ?string $intentId,
        public ?string $userGoal,
        public string $requestId,
        public ?array $conversationState = null,
    ) {
        if ($runContext === '') {
            throw new InvalidArgumentException('runContext must not be empty.');
        }

        if ($requestId === '') {
            throw new InvalidArgumentException('requestId must not be empty.');
        }

        $intentPresent = $intentId !== null && trim($intentId) !== '';
        $goalPresent = $userGoal !== null && trim($userGoal) !== '';

        if (!$intentPresent && !$goalPresent) {
            throw new InvalidArgumentException(
                'CopilotRunRequestDto requires at least one of intentId or userGoal.',
            );
        }

        if ($userGoal !== null && mb_strlen($userGoal) > self::USER_GOAL_MAX_CHARS) {
            throw new InvalidArgumentException(
                'userGoal exceeds maximum length of ' . self::USER_GOAL_MAX_CHARS . ' characters.',
            );
        }
    }

    /**
     * Serialize this DTO into the snake_case wire shape expected by the
     * Python sidecar. Null fields are still emitted so the contract
     * stays explicit; the Pydantic model accepts ``null`` for the
     * optional fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_context' => $this->runContext,
            'intent_id' => $this->intentId,
            'user_goal' => $this->userGoal,
            'request_id' => $this->requestId,
            'conversation_state' => $this->conversationState,
        ];
    }
}
