<?php

/**
 * IntakeFormClassifierPrompt
 *
 * Pure prompt-construction helper for the intake-form auto-classifier. Given
 * an OpenAI Files-API file id (uploaded earlier via {@see
 * \OpenEMR\Services\Intake\OpenAi\OpenAIClient::uploadPdf()}), it returns a
 * fully-populated chat completion request payload — model, messages,
 * response_format with a strict JSON schema. There is no HTTP and no I/O,
 * which makes the prompt straightforward to test in isolation.
 *
 * The same JSON schema is exposed via {@see classifierSchema()} so callers
 * (and tests) can introspect the strict shape independently of the request
 * payload.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Classifier;

final class IntakeFormClassifierPrompt
{
    public const DEFAULT_MODEL = 'gpt-4o-mini';
    public const SCHEMA_NAME = 'intake_form_classifier';

    public const SYSTEM_PROMPT = 'You classify clinical-intake PDF forms. '
        . 'Choose exactly one of: Demographics, MedicalHistory, Consent. '
        . 'Return strict JSON.';

    public const USER_PROMPT = 'Identify the form type for the attached PDF. '
        . 'Provide a confidence score in [0, 1].';

    /**
     * Build the chat-completion request payload for classifying the PDF
     * referenced by `$fileId`.
     *
     * @param non-empty-string $fileId OpenAI Files-API file id
     * @param non-empty-string $model
     * @return array<string, mixed>
     */
    public static function buildRequest(string $fileId, string $model = self::DEFAULT_MODEL): array
    {
        return [
            'model' => $model,
            'temperature' => 0.0,
            'max_tokens' => 200,
            'messages' => self::buildMessages($fileId),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => self::SCHEMA_NAME,
                    'strict' => true,
                    'schema' => self::classifierSchema(),
                ],
            ],
        ];
    }

    /**
     * Build only the messages portion of the request. Exposed separately so
     * tests (and callers that talk to a different endpoint) can re-use the
     * exact prompt without depending on the full request envelope.
     *
     * @param non-empty-string $fileId
     * @return list<array<string, mixed>>
     */
    public static function buildMessages(string $fileId): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::SYSTEM_PROMPT,
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::USER_PROMPT],
                    ['type' => 'file', 'file' => ['file_id' => $fileId]],
                ],
            ],
        ];
    }

    /**
     * Strict JSON schema the classifier asks OpenAI to produce.
     *
     * @return array<string, mixed>
     */
    public static function classifierSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['form_type', 'confidence'],
            'properties' => [
                'form_type' => [
                    'type' => 'string',
                    'enum' => ['Demographics', 'MedicalHistory', 'Consent'],
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
            ],
        ];
    }
}
