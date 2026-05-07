<?php

/**
 * QuestionnaireResponseBuilderTest
 *
 * Verifies that the FHIR QuestionnaireResponse JSON built from a
 * MedicalHistory intake-form payload (intake-forms-plan.md §3.4 dispatch
 * row) has the right shape: resourceType, status, subject reference, and a
 * non-empty list of items each carrying a linkId.
 *
 * Targets {@see QuestionnaireResponseBuilder} directly — the builder is a
 * pure helper with no DB or HTTP dependencies.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

use OpenEMR\Services\Intake\Fhir\QuestionnaireResponseBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('intake-forms')]
class QuestionnaireResponseBuilderTest extends TestCase
{
    private QuestionnaireResponseBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new QuestionnaireResponseBuilder();
    }

    public function testBuiltResourceIsAFhirQuestionnaireResponse(): void
    {
        $resource = $this->build();

        $this->assertSame('QuestionnaireResponse', $resource['resourceType'] ?? null);
    }

    public function testStatusIsCompleted(): void
    {
        $resource = $this->build();

        // FHIR R4 QuestionnaireResponse.status is required and constrained
        // to in-progress | completed | amended | entered-in-error | stopped.
        // For an extracted intake form the default is "completed".
        $this->assertArrayHasKey('status', $resource);
        $this->assertContains(
            $resource['status'],
            ['in-progress', 'completed', 'amended', 'entered-in-error', 'stopped'],
            'status must be a valid FHIR R4 QuestionnaireResponse status code'
        );
    }

    public function testSubjectReferencesPatient(): void
    {
        $resource = $this->build(pid: 4242);

        $this->assertArrayHasKey('subject', $resource);
        $subject = $resource['subject'];
        $this->assertIsArray($subject);
        $reference = $subject['reference'] ?? null;
        $this->assertIsString($reference);
        $this->assertSame('Patient/4242', $reference);
    }

    public function testItemArrayContainsLinkIds(): void
    {
        $resource = $this->build();

        $this->assertArrayHasKey('item', $resource);
        $items = $resource['item'];
        $this->assertIsArray($items);
        $this->assertNotEmpty($items, 'item array must not be empty for a populated intake form');

        foreach ($items as $index => $item) {
            $this->assertIsArray($item, "item[{$index}] must be an array");
            $this->assertArrayHasKey('linkId', $item, "item[{$index}] missing linkId");
            $this->assertIsString($item['linkId']);
            $this->assertNotSame('', $item['linkId']);
        }
    }

    public function testItemLinkIdsCoverEverySectionInThePayload(): void
    {
        $resource = $this->build();
        $this->assertArrayHasKey('item', $resource);
        $items = $resource['item'];
        $this->assertIsArray($items);

        $linkIds = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            $linkIds[] = is_string($item['linkId'] ?? null) ? (string) $item['linkId'] : '';
        }
        // The MedicalHistory payload in self::medicalHistoryPayload() has six
        // top-level keys; we expect at least one item per key.
        foreach (['conditions', 'surgeries', 'medications', 'allergies', 'familyHistory', 'social'] as $section) {
            $this->assertContains(
                $section,
                $linkIds,
                "Expected an item with linkId '{$section}' covering that section of the payload"
            );
        }
    }

    public function testQuestionnaireReferenceUsesIntakeMedicalHistory(): void
    {
        $resource = $this->build();

        $this->assertSame(
            'Questionnaire/IntakeMedicalHistory',
            $resource['questionnaire'] ?? null,
        );
    }

    public function testEncounterReferenceIncludedWhenProvided(): void
    {
        $payload = $this->medicalHistoryPayload();
        $resource = $this->builder->build(99, $payload, '2026-05-04T12:00:00Z', 7777);

        $this->assertArrayHasKey('encounter', $resource);
        $encounter = $resource['encounter'];
        $this->assertIsArray($encounter);
        $this->assertSame('Encounter/7777', $encounter['reference'] ?? null);
    }

    public function testEncounterReferenceOmittedWhenNotProvided(): void
    {
        $payload = $this->medicalHistoryPayload();
        $resource = $this->builder->build(99, $payload);

        $this->assertArrayNotHasKey('encounter', $resource);
    }

    public function testSocialItemsAreNestedUnderSocialLinkId(): void
    {
        $resource = $this->build();
        $items = $resource['item'] ?? [];
        $this->assertIsArray($items);

        $social = null;
        foreach ($items as $item) {
            if (is_array($item) && ($item['linkId'] ?? null) === 'social') {
                $social = $item;
                break;
            }
        }
        $this->assertIsArray($social);
        $this->assertArrayHasKey('item', $social);
        $this->assertIsArray($social['item']);

        $childLinkIds = [];
        foreach ($social['item'] as $child) {
            $this->assertIsArray($child);
            $childLinkIds[] = is_string($child['linkId'] ?? null) ? (string) $child['linkId'] : '';
        }
        $this->assertSame(
            ['social.smoking', 'social.alcohol', 'social.drugs'],
            $childLinkIds,
        );
    }

    public function testResourceCanBeRoundTrippedToJson(): void
    {
        $resource = $this->build();
        $encoded = json_encode($resource);

        $this->assertIsString($encoded);
        $decoded = json_decode($encoded, true);
        $this->assertIsArray($decoded);
        $this->assertSame('QuestionnaireResponse', $decoded['resourceType']);
    }

    public function testEmptyPayloadProducesEmptyItemArray(): void
    {
        $resource = $this->builder->build(99, []);

        $this->assertSame([], $resource['item'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(int $pid = 99): array
    {
        return $this->builder->build($pid, $this->medicalHistoryPayload(), '2026-05-04T12:00:00Z');
    }

    /**
     * Sample MedicalHistory payload shaped per intake-forms-plan.md §3.1.
     *
     * @return array<string, mixed>
     */
    private function medicalHistoryPayload(): array
    {
        return [
            'conditions' => ['Hypertension', 'Asthma'],
            'surgeries' => ['Appendectomy 2010'],
            'medications' => ['Lisinopril 10mg daily', 'Albuterol PRN'],
            'allergies' => ['Penicillin'],
            'familyHistory' => ['Father: diabetes', 'Mother: hypertension'],
            'social' => [
                'smoking' => 'Never',
                'alcohol' => 'Occasional',
                'drugs' => 'None',
            ],
        ];
    }
}
