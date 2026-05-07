<?php

/**
 * AgentAccessBroker
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Throwable;

final class AgentAccessBroker
{
    private const AUDIT_EVENT = 'agent-access';

    /**
     * @var array{section: string, value: string, permission: string}
     */
    private const REQUIRED_BASE_POLICY = ['section' => 'patients', 'value' => 'demo', 'permission' => ''];

    /**
     * @var list<array{
     *     section: string,
     *     value: string,
     *     permission: string,
     *     data_classes: list<string>,
     *     tools: list<string>
     * }>
     */
    /**
     * Sidecar tool names (Python registry) granted by each access policy.
     *
     * The legacy ``tools`` field used :class:`AgentIntentCatalog` intent IDs
     * (e.g. ``current_medications``) -- but the Python sidecar's tool
     * registry exposes real tool names (e.g. ``get_current_medications``).
     * The intersection between ``context.allowed_tools`` and
     * ``registry.list_names()`` was empty whenever the controller minted a
     * run-context off these policies, so the LLM saw zero tools and
     * always returned a refusal envelope.
     *
     * Each policy now grants the full set of sidecar tool names that
     * its data classes require; per-intent restriction still happens
     * inside the agent loop via the M7 ``IntentCatalog`` (each intent
     * limits to a small subset, e.g. ``current_medications`` only
     * exposes ``get_current_medications`` + ``get_source_detail``).
     */
    private const ACCESS_POLICIES = [
        [
            'section' => 'patients',
            'value' => 'demo',
            'permission' => '',
            'data_classes' => ['demographics', 'recent_events'],
            'tools' => [
                'get_basic_patient_data',
                'get_recent_events',
                'get_changes_since_last_visit',
                'get_source_detail',
            ],
        ],
        [
            'section' => 'patients',
            'value' => 'med',
            'permission' => '',
            'data_classes' => ['medications', 'allergies'],
            'tools' => [
                'get_current_medications',
                'get_active_allergies',
                'get_source_detail',
            ],
        ],
        [
            'section' => 'patients',
            'value' => 'appt',
            'permission' => '',
            'data_classes' => ['appointments'],
            'tools' => [],
        ],
    ];

    /**
     * @var callable(string, SessionInterface): bool
     */
    private $csrfVerifier;

    /**
     * @var callable(string, string, string, string): bool
     */
    private $aclChecker;

    /**
     * @var callable(string, string, string, int, string, ?int): void
     */
    private $auditLogger;

    /**
     * @var callable(string, AgentPatientContext, array<string, list<mixed>>): string
     */
    private $tokenIdFactory;

    public function __construct(
        private readonly AgentIntentCatalog $intentCatalog = new AgentIntentCatalog(),
        private readonly AgentCurrentPatientResolver $patientResolver = new AgentCurrentPatientResolver(),
        ?callable $csrfVerifier = null,
        ?callable $aclChecker = null,
        ?callable $auditLogger = null,
        ?callable $tokenIdFactory = null,
        private readonly LoggerInterface $logger = new SystemLogger()
    ) {
        $this->csrfVerifier = $csrfVerifier ?? static function (string $token, SessionInterface $session): bool {
            return CsrfUtils::verifyCsrfToken($token, $session, 'api');
        };
        $this->aclChecker = $aclChecker ?? static function (
            string $section,
            string $value,
            string $user,
            string $permission
        ): bool {
            return AclMain::aclCheckCore($section, $value, $user, $permission) === true;
        };
        $this->auditLogger = $auditLogger ?? static function (
            string $event,
            string $user,
            string $groupname,
            int $success,
            string $comments,
            ?int $patientId
        ): void {
            EventAuditLogger::getInstance()->newEvent($event, $user, $groupname, $success, $comments, $patientId);
        };
        $this->tokenIdFactory = $tokenIdFactory ?? static function (
            string $intentId,
            AgentPatientContext $patientContext,
            array $accessSet
        ): string {
            return bin2hex(random_bytes(16));
        };
    }

    /**
     * @param array<mixed> $payload
     */
    public function authorize(HttpRestRequest $request, string $intentId, array $payload): AgentAccessDecision
    {
        $this->logger->debug('agent.access.evaluating', [
            'intent_id' => $this->safeAuditValue($intentId),
        ]);

        if (!$this->intentCatalog->has($intentId)) {
            return $this->denyAndAudit(
                $request,
                $intentId,
                null,
                'unknown_intent',
                'Unknown agent intent.'
            );
        }

        if (!$request->hasSession()) {
            return $this->denyAndAudit(
                $request,
                $intentId,
                null,
                'missing_session',
                'Agent access requires an authenticated server session.'
            );
        }

        try {
            $session = $request->getSession();
        } catch (Throwable) {
            return $this->denyAndAudit(
                $request,
                $intentId,
                null,
                'missing_session',
                'Agent access requires an authenticated server session.'
            );
        }

        if (!$this->hasAuthenticatedSession($session)) {
            return $this->denyAndAudit(
                $request,
                $intentId,
                null,
                'unauthenticated_session',
                'Agent access requires an authenticated server session.'
            );
        }

        $csrfToken = $request->headers->get('APICSRFTOKEN');
        if (!is_string($csrfToken) || $csrfToken === '') {
            return $this->denyAndAudit(
                $request,
                $intentId,
                null,
                'missing_csrf',
                'Agent access requires a valid API CSRF token.'
            );
        }

        try {
            $csrfValid = ($this->csrfVerifier)($csrfToken, $session);
        } catch (Throwable) {
            $csrfValid = false;
        }

        if (!$csrfValid) {
            return $this->denyAndAudit(
                $request,
                $intentId,
                null,
                'invalid_csrf',
                'Agent access requires a valid API CSRF token.'
            );
        }

        $patientResolution = $this->patientResolver->resolve($request, $payload);
        if (!$patientResolution->isAllowed()) {
            return $this->denyAndAudit(
                $request,
                $intentId,
                null,
                $patientResolution->getReasonCode(),
                $patientResolution->getPublicMessage()
            );
        }

        $patientContext = $patientResolution->getPatientContext();
        if ($patientContext === null) {
            return $this->denyAndAudit(
                $request,
                $intentId,
                null,
                'missing_patient',
                'Agent access requires exactly one current patient.'
            );
        }

        if (!$this->checkAcl(self::REQUIRED_BASE_POLICY, $session)) {
            return $this->denyAndAudit(
                $request,
                $intentId,
                $patientContext,
                'acl_denied',
                'Organization policy does not permit agent access.'
            );
        }

        $accessSet = $this->resolveAccessSet($session);
        $accessToken = new AgentAccessToken(
            $this->makeTokenId($intentId, $patientContext, $accessSet),
            $intentId,
            $patientContext,
            $accessSet['data_classes'],
            $accessSet['tools'],
            $accessSet['acl_policies'],
            time()
        );
        $decision = AgentAccessDecision::allowed($intentId, $accessToken);
        $this->auditDecision($request, $decision);
        $this->logger->info('agent.access.allowed', [
            'intent_id' => $this->safeAuditValue($intentId),
            'data_class_count' => count($accessSet['data_classes']),
            'tool_count' => count($accessSet['tools']),
        ]);

        return $decision;
    }

    private function hasAuthenticatedSession(SessionInterface $session): bool
    {
        return $this->hasNonEmptySessionValue($session, 'authUser')
            && $this->hasNonEmptySessionValue($session, 'authUserID')
            && $this->hasNonEmptySessionValue($session, 'authProvider');
    }

    private function hasNonEmptySessionValue(SessionInterface $session, string $key): bool
    {
        $value = $session->get($key);
        return (is_int($value) || is_string($value)) && trim((string) $value) !== '';
    }

    /**
     * @param array{section: string, value: string, permission: string} $policy
     */
    private function checkAcl(array $policy, SessionInterface $session): bool
    {
        $user = (string) $session->get('authUser', '');

        try {
            return ($this->aclChecker)($policy['section'], $policy['value'], $user, $policy['permission']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{
     *     data_classes: list<string>,
     *     tools: list<string>,
     *     acl_policies: list<array{section: string, value: string, permission: string}>
     * }
     */
    private function resolveAccessSet(SessionInterface $session): array
    {
        $dataClasses = [];
        $tools = [];
        $aclPolicies = [];

        foreach (self::ACCESS_POLICIES as $policy) {
            if (!$this->checkAcl($policy, $session)) {
                continue;
            }

            foreach ($policy['data_classes'] as $dataClass) {
                $dataClasses[] = $dataClass;
            }

            foreach ($policy['tools'] as $tool) {
                $tools[] = $tool;
            }

            $aclPolicies[] = [
                'section' => $policy['section'],
                'value' => $policy['value'],
                'permission' => $policy['permission'],
            ];
        }

        return [
            'data_classes' => array_values(array_unique($dataClasses)),
            'tools' => array_values(array_unique($tools)),
            'acl_policies' => $aclPolicies,
        ];
    }

    /**
     * @param array{
     *     data_classes: list<string>,
     *     tools: list<string>,
     *     acl_policies: list<array{section: string, value: string, permission: string}>
     * } $accessSet
     */
    private function makeTokenId(string $intentId, AgentPatientContext $patientContext, array $accessSet): string
    {
        try {
            $tokenId = ($this->tokenIdFactory)($intentId, $patientContext, $accessSet);
        } catch (Throwable) {
            $tokenId = '';
        }

        if (!is_string($tokenId) || $tokenId === '') {
            $encodedAccessSet = json_encode($accessSet);
            return hash(
                'sha256',
                $intentId . ':' . $patientContext->getPid() . ':' . (is_string($encodedAccessSet) ? $encodedAccessSet : '')
            );
        }

        return $tokenId;
    }

    private function denyAndAudit(
        HttpRestRequest $request,
        string $intentId,
        ?AgentPatientContext $patientContext,
        string $reasonCode,
        string $publicMessage
    ): AgentAccessDecision {
        $decision = AgentAccessDecision::denied($intentId, $reasonCode, $publicMessage, $patientContext);
        $this->auditDecision($request, $decision);
        $this->logger->warning('agent.access.denied', [
            'intent_id' => $this->safeAuditValue($intentId),
            'reason_code' => $this->safeAuditValue($reasonCode),
        ]);

        return $decision;
    }

    private function auditDecision(HttpRestRequest $request, AgentAccessDecision $decision): void
    {
        $comments = sprintf(
            'agent_intent decision=%s intent=%s reason=%s',
            $decision->isAllowed() ? 'allow' : 'deny',
            $this->safeAuditValue($decision->getIntentId()),
            $this->safeAuditValue($decision->getReasonCode())
        );

        try {
            ($this->auditLogger)(
                self::AUDIT_EVENT,
                $this->sessionValue($request, 'authUser'),
                $this->sessionValue($request, 'authProvider'),
                $decision->isAllowed() ? 1 : 0,
                $comments,
                $decision->getPatientContext()?->getPid()
            );
        } catch (Throwable) {
            // Audit failures should not disclose details through the agent response.
        }
    }

    private function sessionValue(HttpRestRequest $request, string $key): string
    {
        if (!$request->hasSession()) {
            return '';
        }

        try {
            $value = $request->getSession()->get($key, '');
        } catch (Throwable) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function safeAuditValue(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value);
        return $safe === null || $safe === '' ? 'unknown' : $safe;
    }
}
