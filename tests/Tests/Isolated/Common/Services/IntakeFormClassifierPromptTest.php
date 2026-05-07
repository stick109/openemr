<?php

/**
 * IntakeFormClassifierPromptTest
 *
 * Verifies the form-type classifier prompt is constructed correctly. Asserts
 * the model, messages array, and `response_format.json_schema` strict
 * schema produced by {@see IntakeFormClassifierPrompt}.
 *
 * The prompt helper has zero side effects (no HTTP, no I/O), so the test
 * runs in the isolated suite without any database or fake network client.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

use OpenEMR\Services\Intake\Classifier\IntakeFormClassifierPrompt;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('intake-forms')]
class IntakeFormClassifierPromptTest extends TestCase
{
    public function testRequestUsesConfiguredModel(): void
    {
        $request = IntakeFormClassifierPrompt::buildRequest('file-abc123');

        $this->assertArrayHasKey('model', $request);
        $this->assertSame('gpt-4o-mini', $request['model']);
    }

    public function testRequestSupportsCustomModel(): void
    {
        $request = IntakeFormClassifierPrompt::buildRequest('file-abc123', 'gpt-5o-pro');

        $this->assertSame('gpt-5o-pro', $request['model']);
    }

    public function testRequestContainsSystemAndUserMessages(): void
    {
        $request = IntakeFormClassifierPrompt::buildRequest('file-abc123');

        $messages = $this->messagesArray($request);
        $this->assertGreaterThanOrEqual(2, count($messages));

        $roles = [];
        foreach ($messages as $message) {
            $roles[] = is_string($message['role'] ?? null) ? $message['role'] : '';
        }
        $this->assertContains('system', $roles);
        $this->assertContains('user', $roles);
    }

    public function testSystemMessageMentionsThreeFormTypes(): void
    {
        $request = IntakeFormClassifierPrompt::buildRequest('file-abc123');

        $systemMessage = '';
        foreach ($this->messagesArray($request) as $message) {
            if (($message['role'] ?? null) === 'system') {
                $content = $message['content'] ?? null;
                $systemMessage = is_string($content) ? $content : (string) json_encode($content);
                break;
            }
        }

        $this->assertNotSame('', $systemMessage, 'system message must be present');
        $this->assertStringContainsString('Demographics', $systemMessage);
        $this->assertStringContainsString('MedicalHistory', $systemMessage);
        $this->assertStringContainsString('Consent', $systemMessage);
    }

    public function testUserMessageReferencesUploadedFileId(): void
    {
        $request = IntakeFormClassifierPrompt::buildRequest('file-abc123');

        $combined = (string) json_encode($this->messagesArray($request));
        $this->assertStringContainsString('file-abc123', $combined);
    }

    public function testBuildMessagesProducesSameMessagesAsBuildRequest(): void
    {
        $request = IntakeFormClassifierPrompt::buildRequest('file-abc123');
        $messages = IntakeFormClassifierPrompt::buildMessages('file-abc123');

        $this->assertSame($messages, $request['messages']);
    }

    public function testResponseFormatIsStrictJsonSchema(): void
    {
        $request = IntakeFormClassifierPrompt::buildRequest('file-abc123');

        $this->assertArrayHasKey('response_format', $request);
        $responseFormat = $request['response_format'];
        $this->assertIsArray($responseFormat);
        $this->assertSame('json_schema', $responseFormat['type'] ?? null);

        $schemaWrapper = $responseFormat['json_schema'] ?? null;
        $this->assertIsArray($schemaWrapper);
        $this->assertTrue((bool) ($schemaWrapper['strict'] ?? false), 'classifier schema must be strict');

        $schema = $schemaWrapper['schema'] ?? null;
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertFalse($schema['additionalProperties'] ?? true);
    }

    public function testResponseSchemaRequiresFormTypeAndConfidence(): void
    {
        $schema = IntakeFormClassifierPrompt::classifierSchema();

        $required = $schema['required'] ?? [];
        $this->assertIsArray($required);
        $this->assertContains('form_type', $required);
        $this->assertContains('confidence', $required);

        $properties = $schema['properties'] ?? [];
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('form_type', $properties);
        $this->assertArrayHasKey('confidence', $properties);

        $formType = $properties['form_type'];
        $this->assertIsArray($formType);
        $formTypeEnum = $formType['enum'] ?? null;
        $this->assertIsArray($formTypeEnum);
        $this->assertSame(['Demographics', 'MedicalHistory', 'Consent'], $formTypeEnum);

        $confidenceProp = $properties['confidence'];
        $this->assertIsArray($confidenceProp);
        $this->assertSame('number', $confidenceProp['type'] ?? null);
        $this->assertSame(0, $confidenceProp['minimum'] ?? null);
        $this->assertSame(1, $confidenceProp['maximum'] ?? null);
    }

    public function testClassifierSchemaRejectsUnknownAsAFormType(): void
    {
        // The prior version of the classifier accepted "Unknown" as a fourth
        // case; now the form-type vocabulary is closed (Defect 2). Make
        // sure the schema reflects that.
        $schema = IntakeFormClassifierPrompt::classifierSchema();
        $properties = $schema['properties'] ?? [];
        $this->assertIsArray($properties);
        $formType = $properties['form_type'] ?? [];
        $this->assertIsArray($formType);
        $enum = $formType['enum'] ?? [];
        $this->assertIsArray($enum);

        $this->assertNotContains('Unknown', $enum);
        $this->assertNotContains('Auto', $enum);
    }

    /**
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>>
     */
    private function messagesArray(array $request): array
    {
        $this->assertArrayHasKey('messages', $request);
        $messages = $request['messages'];
        $this->assertIsArray($messages);
        $typed = [];
        foreach ($messages as $message) {
            $this->assertIsArray($message);
            /** @var array<string, mixed> $message */
            $typed[] = $message;
        }
        return $typed;
    }
}
