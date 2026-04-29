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

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\Agent\AgentIntentRestController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[Group('isolated')]
#[Group('agent')]
class AgentIntentRestControllerTest extends TestCase
{
    private AgentIntentRestController $controller;

    protected function setUp(): void
    {
        $this->controller = new AgentIntentRestController();
    }

    public function testAcceptsKnownClosedIntent(): void
    {
        $response = $this->controller->postIntent($this->requestWithJson([
            'intent_id' => 'current_medications',
            'conversation_id' => 'session-local-id',
            'active_patient_context' => 'server-session',
        ]));

        $body = $this->decodeJsonBody($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame([], $body['validationErrors']);
        $this->assertSame('current_medications', $body['data']['intent_id']);
        $this->assertSame('Current medications', $body['data']['button_label']);
        $this->assertSame('placeholder', $body['data']['status']);
        $this->assertSame('deterministic_placeholder', $body['data']['response_generation']);
        $this->assertSame('Current medications', $body['data']['answer']['answer_blocks'][0]['heading']);
        $this->assertSame('not_checked', $body['data']['answer']['answer_blocks'][0]['claims'][0]['certainty']);
        $this->assertSame([], $body['data']['citations']);
        $this->assertSame([], $body['data']['checked_evidence']);
    }

    public function testReturnsPlaceholderForEachKnownClosedIntent(): void
    {
        $intentIds = [
            'basic_patient_data',
            'current_medications',
            'allergies_to_confirm',
            'recent_events',
            'intake_checklist',
            'changed_since_last_visit',
            'intake_handoff',
            'show_source',
        ];

        foreach ($intentIds as $intentId) {
            $response = $this->controller->handlePayload([
                'intent_id' => $intentId,
                'conversation_id' => 'session-local-id',
                'active_patient_context' => 'server-session',
            ]);
            $body = $this->decodeJsonBody($response);

            $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
            $this->assertSame($intentId, $body['data']['intent_id']);
            $this->assertSame('placeholder', $body['data']['status']);
            $this->assertNotEmpty($body['data']['answer']['answer_blocks'][0]['claims'][0]['text']);
            $this->assertSame([], $body['data']['answer']['answer_blocks'][0]['claims'][0]['citation_ids']);
        }
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

    /**
     * @param array<string, mixed> $payload
     */
    private function requestWithJson(array $payload): HttpRestRequest
    {
        return new HttpRestRequest(content: json_encode($payload, JSON_THROW_ON_ERROR));
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
