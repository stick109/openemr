<?php

/**
 * AnonymizerTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent;

use OpenEMR\Services\Agent\AgentAccessToken;
use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\AgentPatientContext;
use OpenEMR\Services\Agent\Anonymizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class AnonymizerTest extends TestCase
{
    public function testAnonymizesDirectIdentifiersInStructuredFieldsAndFreeText(): void
    {
        $anonymizer = new Anonymizer(logger: new NullLogger());
        $packet = [
            'request_id' => 'request-123',
            'intent_id' => AgentIntentCatalog::BASIC_PATIENT_DATA,
            'sources' => [
                [
                    'source_id' => 'demographics:patient_data:123',
                    'source_type' => 'demographics',
                    'data_class' => 'demographics',
                    'table' => 'patient_data',
                    'record_id' => '123',
                    'patient_name' => 'Jane Doe',
                    'patient_uuid' => '6f9462d8-3a8a-4b76-a048-c58d9be42fbb',
                    'pubpid' => 'ZX-9912',
                    'dob' => '1974-04-15',
                    'address' => '123 Main St Apt 4',
                    'phone_home' => '(555) 123-4567',
                    'email' => 'jane.doe@example.test',
                    'ssn' => '123-45-6789',
                    'insurance_id' => 'ABC123456',
                    'display' => 'name: Jane Doe; preferred name: Janie; date of birth: 1974-04-15; address: 123 Main St Apt 4; SSN 123-45-6789; phone (555) 123-4567; email jane.doe@example.test; insurance ID ABC123456; public patient id: ZX-9912.',
                    'excerpt' => 'Jane Doe reported public ID P1234 during intake.',
                ],
            ],
        ];

        $anonymized = $anonymizer->anonymizeEvidencePacket($this->accessToken('token-a'), $packet);
        $encoded = json_encode($anonymized, JSON_THROW_ON_ERROR);

        $this->assertSame('demographics:patient_data:123', $anonymized['sources'][0]['source_id']);
        $this->assertStringNotContainsString('Jane Doe', $encoded);
        $this->assertStringNotContainsString('123 Main St', $encoded);
        $this->assertStringNotContainsString('1974-04-15', $encoded);
        $this->assertStringNotContainsString('123-45-6789', $encoded);
        $this->assertStringNotContainsString('(555) 123-4567', $encoded);
        $this->assertStringNotContainsString('jane.doe@example.test', $encoded);
        $this->assertStringNotContainsString('ABC123456', $encoded);
        $this->assertStringNotContainsString('ZX-9912', $encoded);
        $this->assertStringContainsString('[PATIENT_NAME]', $encoded);
        $this->assertStringContainsString('[PATIENT_ADDRESS_1]', $encoded);
        $this->assertStringContainsString('[PATIENT_DOB_1]', $encoded);
        $this->assertStringContainsString('[PATIENT_SSN]', $encoded);
        $this->assertStringContainsString('[PATIENT_PHONE_1]', $encoded);
        $this->assertStringContainsString('[PATIENT_EMAIL_1]', $encoded);
        $this->assertStringContainsString('[INSURANCE_ID_1]', $encoded);
        $this->assertStringContainsString('[REDACTED_IDENTIFIER_1]', $encoded);
        $this->assertGreaterThanOrEqual(8, $anonymizer->placeholderCount($this->accessToken('token-a')));
        $this->assertSame('evidence_packet', $anonymizer->getLastMetrics()['mode']);
        $this->assertSame('request-123', $anonymizer->getLastMetrics()['request_id']);
        $this->assertGreaterThanOrEqual(8, $anonymizer->getLastMetrics()['replacement_count']);
        $this->assertArrayHasKey('patient_name', $anonymizer->getLastMetrics()['category_counts']);
    }

    public function testPlaceholdersAreStableWithinTokenAndResetAcrossTokens(): void
    {
        $anonymizer = new Anonymizer(logger: new NullLogger());

        $first = $anonymizer->anonymizePayload($this->accessToken('token-a'), [
            'text' => 'Call 555-111-2222.',
        ]);
        $second = $anonymizer->anonymizePayload($this->accessToken('token-a'), [
            'text' => 'Call 555-333-4444.',
        ]);
        $third = $anonymizer->anonymizePayload($this->accessToken('token-b'), [
            'text' => 'Call 555-333-4444.',
        ]);

        $this->assertSame('Call [PATIENT_PHONE_1].', $first['text']);
        $this->assertSame('Call [PATIENT_PHONE_2].', $second['text']);
        $this->assertSame('Call [PATIENT_PHONE_1].', $third['text']);
    }

    public function testPurgesServerSidePlaceholderScopeAfterTokenLifetime(): void
    {
        $anonymizer = new Anonymizer(tokenLifetimeSeconds: 1, logger: new NullLogger());
        $token = $this->accessToken('token-a');

        $anonymizer->anonymizePayload($token, ['text' => 'Email jane.doe@example.test.']);

        $this->assertSame(1, $anonymizer->placeholderCount($token));

        $anonymizer->purgeExpired(time() + 2);

        $this->assertSame(0, $anonymizer->placeholderCount($token));
    }

    private function accessToken(string $tokenId): AgentAccessToken
    {
        return new AgentAccessToken(
            $tokenId,
            AgentIntentCatalog::BASIC_PATIENT_DATA,
            new AgentPatientContext(123),
            ['demographics'],
            [AgentIntentCatalog::BASIC_PATIENT_DATA],
            [],
            time()
        );
    }
}
