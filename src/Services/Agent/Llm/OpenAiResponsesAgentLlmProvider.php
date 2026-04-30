<?php

/**
 * OpenAiResponsesAgentLlmProvider
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use SensitiveParameter;

final class OpenAiResponsesAgentLlmProvider implements AgentLlmProviderInterface
{
    private readonly ClientInterface $client;

    public function __construct(
        private readonly AgentLlmConfig $config,
        ?ClientInterface $client = null
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => $config->getBaseUri(),
            'timeout' => $config->getTimeoutSeconds(),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    public function getProviderName(): string
    {
        return AgentLlmConfig::PROVIDER_OPENAI;
    }

    public function getModelName(): string
    {
        return $this->config->getModel();
    }

    public function getConfigurationIssue(): ?string
    {
        return $this->config->getConfigurationIssue();
    }

    public function complete(AgentLlmRequest $request): AgentLlmResponse
    {
        if (!$this->isConfigured()) {
            throw new AgentLlmProviderException('OpenAI LLM provider is not configured.');
        }

        try {
            $response = $this->client->request('POST', 'responses', [
                'headers' => [
                    'Authorization' => $this->authorizationHeader($this->config->getApiKey()),
                ],
                'json' => [
                    'model' => $this->config->getModel(),
                    'instructions' => $request->getSystemInstructions(),
                    'input' => $request->getUserInput(),
                    'store' => false,
                    'temperature' => 0.1,
                    'max_output_tokens' => 1200,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'openemr_agent_answer',
                            'strict' => true,
                            'schema' => $request->getJsonSchema(),
                        ],
                    ],
                    'metadata' => [
                        'openemr_component' => 'clinical_copilot',
                        'intent_id' => $request->getIntentId(),
                    ],
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new AgentLlmProviderException('OpenAI response was not a JSON object.');
            }

            $outputText = $this->extractOutputText($payload);
            $answer = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($answer)) {
                throw new AgentLlmProviderException('OpenAI structured output was not a JSON object.');
            }

            return new AgentLlmResponse(
                answer: $answer,
                providerName: $this->getProviderName(),
                modelName: is_string($payload['model'] ?? null) ? $payload['model'] : $this->config->getModel(),
                usage: is_array($payload['usage'] ?? null) ? $payload['usage'] : [],
                providerResponseId: is_string($payload['id'] ?? null) ? $payload['id'] : null
            );
        } catch (GuzzleException | JsonException $exception) {
            throw new AgentLlmProviderException('OpenAI LLM request failed.', $exception);
        }
    }

    private function authorizationHeader(#[SensitiveParameter] string $apiKey): string
    {
        return 'Bearer ' . $apiKey;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractOutputText(array $payload): string
    {
        if (is_string($payload['output_text'] ?? null) && trim($payload['output_text']) !== '') {
            return trim($payload['output_text']);
        }

        $parts = [];
        foreach ($payload['output'] ?? [] as $outputItem) {
            if (!is_array($outputItem)) {
                continue;
            }

            foreach ($outputItem['content'] ?? [] as $contentItem) {
                if (
                    is_array($contentItem)
                    && ($contentItem['type'] ?? null) === 'output_text'
                    && is_string($contentItem['text'] ?? null)
                ) {
                    $parts[] = $contentItem['text'];
                }
            }
        }

        $text = trim(implode('', $parts));
        if ($text === '') {
            throw new AgentLlmProviderException('OpenAI response did not include output text.');
        }

        return $text;
    }
}
