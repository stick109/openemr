<?php

/**
 * AgentLiveLlmEvalTest
 *
 * Live end-to-end eval that drives the real retrieval->prompt->LLM->verifier
 * pipeline against a configured provider. Skipped when the provider is not
 * configured, so a default `composer phpunit-isolated` run is unaffected. To
 * run the live evals, configure the agent LLM env vars and invoke phpunit
 * with `--group live-llm` (see `composer phpunit-live-llm`).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent;

use JsonException;
use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\AgentLlmOrchestrator;
use OpenEMR\Services\Agent\AgentPatientContext;
use OpenEMR\Services\Agent\Llm\AgentLlmProviderFactory;
use OpenEMR\Services\Agent\Llm\AgentLlmProviderInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
#[Group('live-llm')]
class AgentLiveLlmEvalTest extends TestCase
{
    private const FIXTURES_PATH = __DIR__ . '/../../../Fixtures/Agent/agent-live-llm-fixtures.json';

    private AgentLlmProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = (new AgentLlmProviderFactory())->create();

        if (!$this->provider->isConfigured()) {
            self::markTestSkipped(
                'Agent LLM provider is not configured (' . ($this->provider->getConfigurationIssue() ?? 'unknown') . '). '
                . 'Set OPENEMR_AGENT_LLM_PROVIDER, OPENEMR_AGENT_LLM_MODEL, OPENEMR_AGENT_LLM_API_KEY, and '
                . 'OPENEMR_AGENT_LLM_EXTERNAL_CALLS_ENABLED=true to run live LLM evals.'
            );
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('liveCases')]
    public function testLiveLlmAnswerPassesVerification(string $caseId, array $case): void
    {
        $intentId = (string) $case['intent_id'];
        $intent = (new AgentIntentCatalog())->get($intentId);
        $this->assertNotNull($intent, 'Unknown intent_id in fixture ' . $caseId);

        $orchestrator = new AgentLlmOrchestrator(
            provider: $this->provider,
            logger: new NullLogger()
        );

        $response = $orchestrator->buildVerifiedAnswer(
            $intent,
            $this->accessToken($case),
            $case['packet'],
            $this->placeholderDeterministicAnswer($intent)
        );

        $this->assertSame(
            'llm_structured_verified',
            $response['response_generation'],
            $this->failureMessage($caseId, 'response_generation', $response)
        );
        $this->assertSame(
            'passed',
            $response['verification']['status'],
            $this->failureMessage($caseId, 'verification.status', $response)
        );
        $this->assertTrue(
            (bool) ($response['llm']['used'] ?? false),
            $this->failureMessage($caseId, 'llm.used', $response)
        );

        $expected = $case['expected'] ?? [];
        $substrings = is_array($expected['contains_substring'] ?? null) ? $expected['contains_substring'] : [];
        if ($substrings === []) {
            return;
        }

        $answerText = $this->renderAnswerText($response['answer']);
        foreach ($substrings as $needle) {
            $this->assertStringContainsStringIgnoringCase(
                (string) $needle,
                $answerText,
                'Live LLM answer for ' . $caseId . ' is missing expected substring: ' . (string) $needle
                . PHP_EOL . 'Answer: ' . $answerText
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function liveCases(): array
    {
        $path = realpath(self::FIXTURES_PATH);
        if ($path === false) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded) || !is_array($decoded['cases'] ?? null)) {
            return [];
        }

        $providers = [];
        foreach ($decoded['cases'] as $case) {
            if (!is_array($case)) {
                continue;
            }
            $id = (string) ($case['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $providers[$id] = [$id, $case];
        }

        return $providers;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function accessToken(array $case): AgentAccessToken
    {
        $grantedDataClasses = is_array($case['granted_data_classes'] ?? null)
            ? array_values(array_map(strval(...), $case['granted_data_classes']))
            : [];
        $grantedTools = is_array($case['granted_tools'] ?? null)
            ? array_values(array_map(strval(...), $case['granted_tools']))
            : [];

        return new AgentAccessToken(
            'live-eval-token',
            (string) $case['intent_id'],
            new AgentPatientContext(123),
            $grantedDataClasses,
            $grantedTools,
            [],
            1234567890
        );
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function placeholderDeterministicAnswer(array $intent): array
    {
        return [
            'answer_blocks' => [
                [
                    'heading' => (string) ($intent['button_label'] ?? 'Clinical Co-Pilot'),
                    'claims' => [
                        [
                            'text' => 'A verified answer is not available from the checked evidence for this request.',
                            'citation_ids' => [],
                            'certainty' => 'not_checked',
                        ],
                    ],
                ],
            ],
            'missing_or_uncertain' => [],
        ];
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function renderAnswerText(array $answer): string
    {
        $parts = [];
        foreach (($answer['answer_blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (is_string($block['heading'] ?? null)) {
                $parts[] = $block['heading'];
            }
            foreach (($block['claims'] ?? []) as $claim) {
                if (is_array($claim) && is_string($claim['text'] ?? null)) {
                    $parts[] = $claim['text'];
                }
            }
        }
        foreach (($answer['missing_or_uncertain'] ?? []) as $item) {
            if (is_array($item) && is_string($item['text'] ?? null)) {
                $parts[] = $item['text'];
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function failureMessage(string $caseId, string $field, array $response): string
    {
        try {
            $serialized = json_encode($response, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $serialized = '[unserializable response]';
        }

        return 'Live LLM eval ' . $caseId . ' failed on ' . $field . '. Response: ' . $serialized;
    }
}
