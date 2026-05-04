<?php

/**
 * OpenAIStructuredRequest
 *
 * Value object describing a single structured-output OpenAI chat completion
 * request: model, system instructions, user messages (which may reference
 * uploaded file ids), and the strict JSON schema the response must match.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\OpenAi;

final readonly class OpenAIStructuredRequest
{
    /**
     * @param non-empty-string $model
     * @param non-empty-string $systemPrompt
     * @param non-empty-string $userPrompt
     * @param list<string> $fileIds OpenAI Files API ids to attach as user_data inputs
     * @param non-empty-string $schemaName
     * @param array<string, mixed> $schema JSON Schema (object form) for the response
     * @param int<1, max> $maxTokens
     */
    public function __construct(
        public string $model,
        public string $systemPrompt,
        public string $userPrompt,
        public array $fileIds,
        public string $schemaName,
        public array $schema,
        public float $temperature = 0.0,
        public int $maxTokens = 1500,
    ) {
    }
}
