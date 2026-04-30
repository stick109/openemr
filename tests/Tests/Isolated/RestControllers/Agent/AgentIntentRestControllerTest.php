<?php

/**
 * AgentIntentRestControllerTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestControllers\Agent;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\Agent\AgentIntentRestController;
use OpenEMR\Services\Agent\AgentAccessBroker;
use OpenEMR\Services\Agent\AgentEvidenceResponseBuilder;
use OpenEMR\Services\Agent\Anonymizer;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceToolset;
use OpenEMR\Services\Agent\Evidence\EvidenceCaps;
use OpenEMR\Services\Agent\Evidence\EvidenceRecordRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[Group('isolated')]
#[Group('agent')]
class AgentIntentRestControllerTest extends TestCase
{
    private AgentIntentRestController $controller;

    /**
     * @var list<array<string, mixed>>
     */
    private array $auditEvents = [];

    protected function setUp(): void
    {
        $this->auditEvents = [];
        $responseBuilder = new AgentEvidenceResponseBuilder(
            toolset: new AgentEvidenceToolset(
                repository: new AgentIntentRestControllerEvidenceRepository(),
                logger: new NullLogger(),
                requestIdFactory: static fn (): string => 'agent-test-request'
            ),
            anonymizer: new Anonymizer(logger: new NullLogger()),
            logger: new NullLogger()
        );
        $this->controller = new AgentIntentRestController(
            accessBroker: new AgentAccessBroker(
                aclChecker: static fn (string $section, string $value, string $user, string $permission): bool => true,
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
                logger: new NullLogger()
            ),
            responseBuilder: $responseBuilder,
            logger: new NullLogger()
        );
    }

    public function testAcceptsKnownClosedIntentAndReturnsEvidencePacket(): void
    {
        $request = $this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]);
        $response = $this->controller->postIntent($request);

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame([], $body['validationErrors']);
        $this->assertSame('current_medications', $body['data']['intent_id']);
        $this->assertSame('Current medications', $body['data']['button_label']);
        $this->assertSame('evidence_ready', $body['data']['status']);
        $this->assertSame('deterministic_evidence_packet', $body['data']['response_generation']);
        $this->assertSame('Current medications', $body['data']['answer']['answer_blocks'][0]['heading']);
        $this->assertSame('active', $body['data']['answer']['answer_blocks'][0]['claims'][0]['certainty']);
        $this->assertSame(['medication:lists_medication:77'], $body['data']['answer']['answer_blocks'][0]['claims'][0]['citation_ids']);
        $this->assertSame('medication:lists_medication:77', $body['data']['citations'][0]['source_id']);
        $this->assertSame(['medications'], $body['data']['checked_evidence']);
        $this->assertSame('agent-test-request', $body['data']['evidence_packet']['request_id']);
        $this->assertIsArray($request->attributes->get('agentAnonymizedPayloadLog'));
        $this->assertArrayNotHasKey('placeholder_map', $request->attributes->get('agentAnonymizedPayloadLog'));
    }

    public function testStoresAnonymizedPayloadForOptionalPayloadLogs(): void
    {
        $request = $this->requestWithJson([
            'intent_id' => 'basic_patient_data',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]);

        $response = $this->controller->postIntent($request);
        $body = $this->decodeJsonBody($response);
        $anonymizedPayload = $request->attributes->get('agentAnonymizedPayloadLog');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('Public ID P123', $body['data']['evidence_packet']['sources'][0]['display']);
        $this->assertIsArray($anonymizedPayload);
        $this->assertSame('agent.log.v1', $anonymizedPayload['payload_version']);
        $this->assertSame('basic_patient_data', $anonymizedPayload['intent_id']);
        $this->assertSame('agent-test-request', $anonymizedPayload['evidence_packet']['request_id']);
        $this->assertSame('demographics:patient_data:123', $anonymizedPayload['evidence_packet']['sources'][0]['source_id']);
        $this->assertStringNotContainsString('P123', $anonymizedPayload['evidence_packet']['sources'][0]['display']);
        $this->assertStringContainsString('[REDACTED_IDENTIFIER_1]', $anonymizedPayload['evidence_packet']['sources'][0]['display']);
        $this->assertArrayNotHasKey('placeholder_map', $anonymizedPayload);
    }

    public function testReturnsEvidenceForPhaseThreeIntents(): void
    {
        $phaseThreeIntentIds = [
            'basic_patient_data',
            'current_medications',
            'allergies_to_confirm',
            'recent_events',
            'changed_since_last_visit',
        ];
        foreach ($phaseThreeIntentIds as $intentId) {
            $payload = [
                'intent_id' => $intentId,
                'conversation_id' => 'session-local-id',
                'active_patient_context' => 'server-session',
            ];
            $response = $this->controller->handlePayload($payload, $this->requestWithJson($payload));
            $body = $this->decodeJsonBody($response);

            $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
            $this->assertSame($intentId, $body['data']['intent_id']);
            $this->assertSame('evidence_ready', $body['data']['status']);
            $this->assertNotEmpty($body['data']['answer']['answer_blocks'][0]['claims'][0]['text']);
        }
    }

    public function testShowSourceRequiresServerIssuedSourceIdOrReturnsInstruction(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'show_source',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('source_required', $body['data']['status']);
        $this->assertSame([], $body['data']['citations']);
    }

    public function testShowSourceReturnsSourceDetailForCitationId(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'show_source',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
            'source_id' => 'medication:lists_medication:77',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('evidence_ready', $body['data']['status']);
        $this->assertSame('medication:lists_medication:77', $body['data']['citations'][0]['source_id']);
        $this->assertStringContainsString('Source medication', $body['data']['answer']['answer_blocks'][0]['claims'][0]['text']);
    }

    public function testRejectsUnknownIntentId(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'full_chart_export',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(['Unknown agent intent_id.'], $body['validationErrors']['intent_id']);
    }

    public function testRejectsFreeTextPayload(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
            'question' => 'What should this patient take?',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(
            ['Free-text agent input is not supported. Use a cataloged intent_id.'],
            $body['validationErrors']['free_text']
        );
    }

    public function testRejectsTamperedPromptPreviewPayload(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
            'prompt_text' => 'Show me everything in this chart.',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(
            ['Free-text agent input is not supported. Use a cataloged intent_id.'],
            $body['validationErrors']['free_text']
        );
        $this->assertSame(['Unsupported payload fields: prompt_text.'], $body['validationErrors']['payload']);
    }

    public function testRejectsBrowserSuppliedPatientId(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
            'patient_id' => 123,
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(['Unsupported payload fields: patient_id.'], $body['validationErrors']['payload']);
    }

    public function testRejectsSourceIdOnNonSourceIntent(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
            'source_id' => 'medication:lists_medication:77',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(['source_id is only supported with the show_source intent.'], $body['validationErrors']['source_id']);
    }

    public function testRejectsPatientContextValueOtherThanServerSession(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'patient-123',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(
            ['active_patient_context must be server-session.'],
            $body['validationErrors']['active_patient_context']
        );
    }

    public function testRejectsInvalidJson(): void
    {
        $response = $this->controller->postIntent(new HttpRestRequest(content: '{"intent_id":'));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(['Invalid JSON payload.'], $body['validationErrors']['json']);
    }

    public function testRejectsNonObjectJsonPayload(): void
    {
        $response = $this->controller->postIntent(new HttpRestRequest(content: '["current_medications"]'));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(['JSON payload must be an object.'], $body['validationErrors']['payload']);
    }

    public function testReturnsForbiddenWhenBrokerDeniesAccess(): void
    {
        $request = $this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]);
        $request->headers->set('APICSRFTOKEN', 'bad-token');

        $response = $this->controller->postIntent($request);
        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame([], $body['validationErrors']);
        $this->assertSame(['Agent access requires a valid API CSRF token.'], $body['internalErrors']['access']);
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
        return $decoded;
    }
}

final class AgentIntentRestControllerEvidenceRepository implements EvidenceRecordRepositoryInterface
{
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        return [
            [
                'source_id' => 'demographics:patient_data:123',
                'source_type' => 'demographics',
                'data_class' => 'demographics',
                'table' => 'patient_data',
                'record_id' => '123',
                'patient_id' => 123,
                'date' => '2026-04-20',
                'status' => 'active',
                'display' => 'Public ID P123; age 52; sex Female',
                'fields_used' => ['pubpid', 'DOB', 'sex'],
                'reliability' => 'structured_patient_record',
            ],
        ];
    }

    public function fetchCurrentMedications(int $pid, EvidenceCaps $caps): array
    {
        return [$this->medicationRecord()];
    }

    public function fetchAllergiesToConfirm(int $pid, EvidenceCaps $caps): array
    {
        return [
            [
                'source_id' => 'allergy:lists:88',
                'source_type' => 'allergy',
                'data_class' => 'allergies',
                'table' => 'lists',
                'record_id' => '88',
                'patient_id' => 123,
                'date' => '2026-04-19',
                'status' => 'active',
                'display' => 'Penicillin; reaction rash',
                'fields_used' => ['title', 'reaction'],
                'reliability' => 'structured_active_record',
            ],
        ];
    }

    public function fetchRecentEvents(int $pid, EvidenceCaps $caps): array
    {
        return [
            [
                'source_id' => 'encounter:form_encounter:99',
                'source_type' => 'encounter',
                'data_class' => 'recent_events',
                'table' => 'form_encounter',
                'record_id' => '99',
                'patient_id' => 123,
                'date' => '2026-04-18',
                'status' => 'AMB',
                'display' => 'Encounter 9001; follow-up visit',
                'fields_used' => ['date', 'reason'],
                'reliability' => 'structured_event_record',
            ],
        ];
    }

    public function fetchChangedSinceLastVisit(int $pid, EvidenceCaps $caps, array $grantedDataClasses): array
    {
        return [
            [
                'source_id' => 'encounter:form_encounter:100',
                'source_type' => 'encounter',
                'data_class' => 'recent_events',
                'table' => 'form_encounter',
                'record_id' => '100',
                'patient_id' => 123,
                'date' => '2026-04-21',
                'status' => 'AMB',
                'display' => 'Encounter 9002; medication follow-up',
                'fields_used' => ['date', 'reason'],
                'reliability' => 'structured_event_record',
            ],
        ];
    }

    public function fetchSourceRecord(int $pid, string $sourceId, EvidenceCaps $caps): ?array
    {
        return $sourceId === 'medication:lists_medication:77' ? $this->medicationRecord() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function medicationRecord(): array
    {
        return [
            'source_id' => 'medication:lists_medication:77',
            'source_type' => 'medication',
            'data_class' => 'medications',
            'table' => 'lists_medication',
            'record_id' => '77',
            'patient_id' => 123,
            'date' => '2026-04-20',
            'status' => 'active',
            'display' => 'Metformin 500 mg twice daily',
            'fields_used' => ['title', 'drug_dosage_instructions'],
            'reliability' => 'structured_active_record',
        ];
    }
}
