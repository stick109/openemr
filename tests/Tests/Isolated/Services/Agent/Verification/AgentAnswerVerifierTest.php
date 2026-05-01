<?php

/**
 * AgentAnswerVerifierTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Verification;

use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\AgentPatientContext;
use OpenEMR\Services\Agent\Verification\AgentAnswerVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class AgentAnswerVerifierTest extends TestCase
{
    public function testAcceptsSourceGroundedClaims(): void
    {
        $result = (new AgentAnswerVerifier())->verify($this->supportedAnswer(), $this->accessToken(), $this->packet());

        $this->assertTrue($result->passed());
        $this->assertSame('passed', $result->toArray()['status']);
    }

    public function testRejectsFabricatedCitationIds(): void
    {
        $answer = $this->supportedAnswer();
        $answer['answer_blocks'][0]['claims'][0]['citation_ids'] = ['medication:lists_medication:999'];

        $result = (new AgentAnswerVerifier())->verify($answer, $this->accessToken(), $this->packet());

        $this->assertFalse($result->passed());
        $this->assertStringContainsString('unknown source_id', implode(' ', $result->errors()));
    }

    public function testRejectsUnsupportedClaimText(): void
    {
        $answer = $this->supportedAnswer();
        $answer['answer_blocks'][0]['claims'][0]['text'] = 'Lisinopril 10 mg daily is listed as active.';

        $result = (new AgentAnswerVerifier())->verify($answer, $this->accessToken(), $this->packet());

        $this->assertFalse($result->passed());
        $this->assertStringContainsString('not supported by cited source text', implode(' ', $result->errors()));
    }

    public function testRejectsOutOfScopeClinicalAdvice(): void
    {
        $answer = $this->supportedAnswer();
        $answer['answer_blocks'][0]['claims'][0]['text'] = 'The clinician should increase Metformin today.';

        $result = (new AgentAnswerVerifier())->verify($answer, $this->accessToken(), $this->packet());

        $this->assertFalse($result->passed());
        $this->assertStringContainsString('out-of-scope clinical advice', implode(' ', $result->errors()));
    }

    public function testRejectsCompletenessStatementInMissingOrUncertain(): void
    {
        $answer = $this->supportedAnswer();
        $answer['missing_or_uncertain'] = [
            [
                'text' => 'No additional current medications were found in checked evidence.',
                'citation_ids' => ['medication:lists_medication:77'],
            ],
        ];

        $result = (new AgentAnswerVerifier())->verify($answer, $this->accessToken(), $this->packet());

        $this->assertFalse($result->passed());
        $this->assertStringContainsString('must not contain a completeness statement', implode(' ', $result->errors()));
    }

    /**
     * @return array<string, mixed>
     */
    private function supportedAnswer(): array
    {
        return [
            'answer_blocks' => [
                [
                    'heading' => 'Current medications',
                    'claims' => [
                        [
                            'text' => 'Metformin 500 mg twice daily is listed in the checked medication record.',
                            'citation_ids' => ['medication:lists_medication:77'],
                            'certainty' => 'supported',
                        ],
                    ],
                ],
            ],
            'missing_or_uncertain' => [
                [
                    'text' => 'Medication verification date was not found in checked evidence.',
                    'citation_ids' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return [
            'request_id' => 'request-123',
            'intent_id' => AgentIntentCatalog::CURRENT_MEDICATIONS,
            'sources' => [
                [
                    'source_id' => 'medication:lists_medication:77',
                    'source_type' => 'medication',
                    'data_class' => 'medications',
                    'status' => 'active',
                    'display' => 'Metformin 500 mg twice daily',
                    'excerpt' => 'Metformin 500 mg twice daily',
                    'patient_id' => 123,
                ],
            ],
            'tool_runs' => [
                [
                    'tool' => 'get_current_medications',
                    'source_count' => 1,
                    'latency_ms' => 1,
                    'error_class' => null,
                ],
            ],
        ];
    }

    private function accessToken(): AgentAccessToken
    {
        return new AgentAccessToken(
            'token',
            AgentIntentCatalog::CURRENT_MEDICATIONS,
            new AgentPatientContext(123),
            ['medications'],
            [AgentIntentCatalog::CURRENT_MEDICATIONS, AgentIntentCatalog::SHOW_SOURCE],
            [],
            1234567890
        );
    }
}
