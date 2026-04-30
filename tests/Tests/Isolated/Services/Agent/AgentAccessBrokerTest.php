<?php

/**
 * AgentAccessBrokerTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Services\Agent\AgentAccessBroker;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[Group('isolated')]
#[Group('agent')]
class AgentAccessBrokerTest extends TestCase
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $auditEvents = [];

    protected function setUp(): void
    {
        $this->auditEvents = [];
    }

    public function testAllowsAuthenticatedSessionAndBakesAccessSetIntoToken(): void
    {
        $broker = $this->createBroker([
            'patients/demo' => true,
            'patients/med' => true,
            'patients/appt' => true,
            'patients/notes' => false,
        ]);
        $payload = $this->payload(AgentIntentCatalog::CURRENT_MEDICATIONS);

        $decision = $broker->authorize(
            $this->requestForPayload($payload),
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            $payload
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(123, $decision->getPatientContext()?->getPid());
        $this->assertSame('allowed', $decision->getReasonCode());
        $this->assertSame('test-token-current_medications-123', $decision->getAccessToken()?->getTokenId());
        $this->assertSame([
            'demographics',
            'recent_events',
            'medications',
            'allergies',
            'appointments',
        ], $decision->getAccessToken()?->getGrantedDataClasses());
        $this->assertSame([
            AgentIntentCatalog::BASIC_PATIENT_DATA,
            AgentIntentCatalog::RECENT_EVENTS,
            AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT,
            AgentIntentCatalog::SHOW_SOURCE,
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
        ], $decision->getAccessToken()?->getGrantedTools());
        $this->assertSame([
            ['section' => 'patients', 'value' => 'demo', 'permission' => ''],
            ['section' => 'patients', 'value' => 'med', 'permission' => ''],
            ['section' => 'patients', 'value' => 'appt', 'permission' => ''],
        ], $decision->getAccessToken()?->getGrantedAclPolicies());
        $this->assertSame(1, $this->auditEvents[0]['success']);
        $this->assertSame('agent-access', $this->auditEvents[0]['event']);
        $this->assertSame('agent_intent decision=allow intent=current_medications reason=allowed', $this->auditEvents[0]['comments']);
        $this->assertSame(123, $this->auditEvents[0]['patient_id']);
    }

    public function testAllowsIntentWhenOptionalAccessPolicyIsMissing(): void
    {
        $broker = $this->createBroker([
            'patients/demo' => true,
            'patients/med' => false,
        ]);
        $payload = $this->payload(AgentIntentCatalog::CURRENT_MEDICATIONS);

        $decision = $broker->authorize(
            $this->requestForPayload($payload),
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            $payload
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame([
            'demographics',
            'recent_events',
        ], $decision->getAccessToken()?->getGrantedDataClasses());
        $this->assertNotContains(AgentIntentCatalog::CURRENT_MEDICATIONS, $decision->getAccessToken()?->getGrantedTools());
        $this->assertSame(1, $this->auditEvents[0]['success']);
        $this->assertSame('agent_intent decision=allow intent=current_medications reason=allowed', $this->auditEvents[0]['comments']);
    }

    public function testDeniesWhenBaseAclPolicyFails(): void
    {
        $broker = $this->createBroker([
            'patients/demo' => false,
            'patients/med' => true,
        ]);
        $payload = $this->payload(AgentIntentCatalog::BASIC_PATIENT_DATA);

        $decision = $broker->authorize(
            $this->requestForPayload($payload),
            AgentIntentCatalog::BASIC_PATIENT_DATA,
            $payload
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('acl_denied', $decision->getReasonCode());
        $this->assertSame('Organization policy does not permit agent access.', $decision->getPublicMessage());
        $this->assertSame(0, $this->auditEvents[0]['success']);
        $this->assertSame('agent_intent decision=deny intent=basic_patient_data reason=acl_denied', $this->auditEvents[0]['comments']);
        $this->assertSame(123, $this->auditEvents[0]['patient_id']);
    }

    public function testDeniesAmbiguousCurrentPatientContext(): void
    {
        $broker = $this->createBroker([
            'patients/demo' => true,
        ]);
        $payload = $this->payload(AgentIntentCatalog::BASIC_PATIENT_DATA);

        $decision = $broker->authorize(
            $this->requestForPayload($payload, [123, 456]),
            AgentIntentCatalog::BASIC_PATIENT_DATA,
            $payload
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('ambiguous_patient', $decision->getReasonCode());
        $this->assertSame('Current patient context is ambiguous.', $decision->getPublicMessage());
        $this->assertSame(0, $this->auditEvents[0]['success']);
        $this->assertSame(null, $this->auditEvents[0]['patient_id']);
    }

    public function testDeniesTamperedPatientContextPayload(): void
    {
        $broker = $this->createBroker([
            'patients/demo' => true,
        ]);
        $payload = $this->payload(AgentIntentCatalog::BASIC_PATIENT_DATA) + [
            'patient_id' => 999,
        ];

        $decision = $broker->authorize(
            $this->requestForPayload($payload),
            AgentIntentCatalog::BASIC_PATIENT_DATA,
            $payload
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('patient_context_tampered', $decision->getReasonCode());
        $this->assertSame(
            'Patient context must come from the authenticated server session.',
            $decision->getPublicMessage()
        );
        $this->assertSame(0, $this->auditEvents[0]['success']);
        $this->assertSame('agent_intent decision=deny intent=basic_patient_data reason=patient_context_tampered', $this->auditEvents[0]['comments']);
    }

    public function testDeniesMissingApiCsrfToken(): void
    {
        $broker = $this->createBroker([
            'patients/demo' => true,
        ]);
        $payload = $this->payload(AgentIntentCatalog::BASIC_PATIENT_DATA);
        $request = $this->requestForPayload($payload);
        $request->headers->remove('APICSRFTOKEN');

        $decision = $broker->authorize($request, AgentIntentCatalog::BASIC_PATIENT_DATA, $payload);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('missing_csrf', $decision->getReasonCode());
        $this->assertSame('Agent access requires a valid API CSRF token.', $decision->getPublicMessage());
        $this->assertSame(0, $this->auditEvents[0]['success']);
    }

    /**
     * @param array<string, bool> $aclGrants
     */
    private function createBroker(array $aclGrants): AgentAccessBroker
    {
        return new AgentAccessBroker(
            aclChecker: static function (
                string $section,
                string $value,
                string $user,
                string $permission
            ) use ($aclGrants): bool {
                return $aclGrants[$section . '/' . $value] ?? false;
            },
            auditLogger: function (
                string $event,
                string $user,
                string $groupname,
                int $success,
                string $comments,
                ?int $patientId
            ): void {
                $this->auditEvents[] = [
                    'event' => $event,
                    'user' => $user,
                    'groupname' => $groupname,
                    'success' => $success,
                    'comments' => $comments,
                    'patient_id' => $patientId,
                ];
            },
            tokenIdFactory: static function (string $intentId, $patientContext, array $accessSet): string {
                return 'test-token-' . $intentId . '-' . $patientContext->getPid();
            },
            logger: new NullLogger()
        );
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $intentId): array
    {
        return [
            'intent_id' => $intentId,
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestForPayload(array $payload, mixed $pid = 123): HttpRestRequest
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('authUser', 'admin');
        $session->set('authUserID', 1);
        $session->set('authProvider', 'Default');
        $session->set('pid', $pid);
        CsrfUtils::setupCsrfKey($session);

        $request = new HttpRestRequest(content: json_encode($payload, JSON_THROW_ON_ERROR));
        $request->setSession($session);
        $request->headers->set('APICSRFTOKEN', CsrfUtils::collectCsrfToken($session, 'api'));

        return $request;
    }
}
