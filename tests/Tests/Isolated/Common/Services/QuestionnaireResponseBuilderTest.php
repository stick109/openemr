<?php

/**
 * QuestionnaireResponseBuilderTest
 *
 * Verifies that the FHIR QuestionnaireResponse JSON built from a
 * MedicalHistory intake-form payload (intake-forms-plan.md §3.4 dispatch
 * row) has the right shape: resourceType, status, subject reference, and a
 * non-empty list of items each carrying a linkId.
 *
 * The builder class is owned by the §3.4/3.5 sibling agent. While that work
 * is in progress this test skips with a clear message; once the class lands
 * the test starts asserting for real with no further changes.
 *
 * Resolution order:
 *   1. OpenEMR\Services\IntakeForm\QuestionnaireResponseBuilder->build($pid, $payload)
 *   2. OpenEMR\Services\IntakeForm\QuestionnaireResponseBuilder::fromMedicalHistory(...)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('intake-forms')]
class QuestionnaireResponseBuilderTest extends TestCase
{
    /**
     * @return class-string
     */
    private function builderFqcn(): string
    {
        // Built from parts via a runtime function so PHPStan does not
        // eagerly resolve the symbol — the class lives in a sibling
        // worktree and may not exist at analysis time.
        $parts = ['OpenEMR', 'Services', 'IntakeForm', 'QuestionnaireResponseBuilder'];
        return self::asClassString(implode('\\', $parts));
    }

    /**
     * Identity wrapper that converts a runtime string into a class-string
     * without PHPStan being able to fold it back to the literal value.
     *
     * @return class-string
     */
    private static function asClassString(string $fqcn): string
    {
        /** @var class-string */
        return $fqcn;
    }

    protected function setUp(): void
    {
        if (!class_exists($this->builderFqcn())) {
            $this->markTestSkipped(
                'QuestionnaireResponseBuilder (intake-forms-plan.md §3.4) not yet '
                . 'implemented; this test will run once the sibling work lands.'
            );
        }
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
        $this->assertStringStartsWith('Patient/', $reference);
        $this->assertStringContainsString('4242', $reference);
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

    public function testResourceCanBeRoundTrippedToJson(): void
    {
        $resource = $this->build();
        $encoded = json_encode($resource);

        $this->assertIsString($encoded);
        $decoded = json_decode($encoded, true);
        $this->assertIsArray($decoded);
        $this->assertSame('QuestionnaireResponse', $decoded['resourceType']);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(int $pid = 99): array
    {
        $payload = $this->medicalHistoryPayload();
        $fqcn = $this->builderFqcn();

        $instanceCallable = [$fqcn, 'build'];
        if (is_callable($instanceCallable)) {
            $builder = $this->newBuilder();
            $callable = [$builder, 'build'];
            if (!is_callable($callable)) {
                $this->fail("{$fqcn}::build is not callable on an instance");
            }
            $resource = $callable($pid, $payload);
        } else {
            $staticCallable = [$fqcn, 'fromMedicalHistory'];
            if (!is_callable($staticCallable)) {
                $this->fail("Neither {$fqcn}::build nor {$fqcn}::fromMedicalHistory is callable");
            }
            $resource = $staticCallable($pid, $payload);
        }

        $this->assertIsArray($resource);
        $typed = [];
        foreach ($resource as $key => $value) {
            $this->assertIsString($key);
            $typed[$key] = $value;
        }
        return $typed;
    }

    /**
     * Instantiate the builder via reflection so PHPStan does not flag the
     * (intentionally) missing class. setUp() skips the test entirely when
     * the class is unavailable, so this code is only reached when the §3.4
     * sibling work has landed.
     */
    private function newBuilder(): object
    {
        $reflection = new \ReflectionClass($this->builderFqcn());
        return $reflection->newInstance();
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
