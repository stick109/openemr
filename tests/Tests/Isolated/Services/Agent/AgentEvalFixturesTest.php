<?php

/**
 * AgentEvalFixturesTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent;

use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentPatientContext;
use OpenEMR\Services\Agent\Evidence\EvidencePacketNormalizer;
use OpenEMR\Services\Agent\Verification\AgentAnswerVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class AgentEvalFixturesTest extends TestCase
{
    public function testFixturesCoverPhaseSixScenarios(): void
    {
        $scenarios = array_values(array_unique(array_column($this->cases(), 'scenario')));
        sort($scenarios);

        $this->assertSame([
            'conflicting',
            'duplicate',
            'missing',
            'prompt_injection',
            'stale',
            'unauthorized',
        ], $scenarios);
    }

    public function testVerifierOutcomesMatchFixtureExpectations(): void
    {
        $verifier = new AgentAnswerVerifier();

        foreach ($this->cases() as $case) {
            $expectedStatus = $case['expected']['verification_status'] ?? null;
            if (!is_string($expectedStatus)) {
                continue;
            }

            $result = $verifier->verify(
                $case['answer'],
                $this->accessToken($case),
                $case['packet']
            );

            $this->assertSame(
                $expectedStatus,
                $result->toArray()['status'],
                'Unexpected verifier status for eval fixture ' . $case['id']
            );

            $warningContains = $case['expected']['warning_contains'] ?? null;
            if (is_string($warningContains)) {
                $this->assertStringContainsString($warningContains, implode(' ', $result->toArray()['warnings']));
            }
        }
    }

    public function testUnauthorizedFixtureDocumentsDeniedDataClass(): void
    {
        $case = $this->caseById('unauthorized_billing_source');
        $source = $case['packet']['sources'][0];

        $this->assertNotContains($source['data_class'], $case['granted_data_classes']);
        $this->assertSame('denied_data_class', $case['expected']['access']);
        $this->assertSame(
            'OpenEMR\Services\Agent\Evidence\AgentEvidenceAccessException',
            $case['packet']['tool_runs'][0]['error_class']
        );
    }

    public function testDuplicateFixtureNormalizesToOneSource(): void
    {
        $case = $this->caseById('duplicate_recent_event_records');
        $normalizer = new EvidencePacketNormalizer();
        $sources = $normalizer->normalize($this->accessToken($case), $case['raw_records']);

        $this->assertCount($case['expected']['normalized_source_count'], $sources);
        $this->assertSame('encounter:form_encounter:701', $sources[0]['source_id']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cases(): array
    {
        $path = realpath(__DIR__ . '/../../../Fixtures/Agent/agent-eval-fixtures.json');
        $this->assertIsString($path);
        $json = file_get_contents($path);
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('agent-evals.v1', $decoded['version']);
        $this->assertIsArray($decoded['cases']);

        return $decoded['cases'];
    }

    /**
     * @return array<string, mixed>
     */
    private function caseById(string $id): array
    {
        foreach ($this->cases() as $case) {
            if (($case['id'] ?? null) === $id) {
                return $case;
            }
        }

        $this->fail('Missing eval fixture ' . $id);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function accessToken(array $case): AgentAccessToken
    {
        return new AgentAccessToken(
            'eval-token',
            (string) $case['intent_id'],
            new AgentPatientContext(123),
            $case['granted_data_classes'],
            $case['granted_tools'],
            [],
            1234567890
        );
    }
}
