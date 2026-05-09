<?php

/**
 * DemographicsDispatcherEmergencyContactTest
 *
 * Regression tests for the emergency-contact mapping in
 * {@see \OpenEMR\Services\Intake\Dispatcher\DemographicsDispatcher}.
 *
 * Background: an earlier version of the dispatcher contained
 *
 *     'emergencyContact.name' => 'phone_contact',
 *
 * which wrote the contact's *name* into a column whose semantics are the
 * contact's *phone* (set by `phonekeyup` formatting in
 * `interface/new/new_comprehensive.php`). The same map omitted
 * `emergencyContact.phone` entirely, so the phone half was silently
 * discarded during ingestion.
 *
 * The fix combines name + phone into a single `phone_contact` value via
 * {@see DemographicsDispatcher::composePhoneContact()} (since
 * `patient_data` has no dedicated column for the contact's name) and
 * removes the `emergencyContact.name` => 'phone_contact' map entry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

use OpenEMR\Services\Intake\Dispatcher\DemographicsDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Group('isolated')]
#[Group('intake-forms')]
final class DemographicsDispatcherEmergencyContactTest extends TestCase
{
    public function testFieldMapDoesNotMapEmergencyContactNameToPhoneContact(): void
    {
        $reflection = new ReflectionClass(DemographicsDispatcher::class);
        $constants = $reflection->getConstants();
        self::assertArrayHasKey('PATIENT_FIELD_MAP', $constants);
        $map = $constants['PATIENT_FIELD_MAP'];
        self::assertIsArray($map);

        self::assertArrayNotHasKey(
            'emergencyContact.name',
            $map,
            'emergencyContact.name must NOT be mapped to a column directly: '
            . 'phone_contact (the only column that ever held it) is the '
            . 'contact\'s *phone*, not name. The name + phone are combined '
            . 'into phone_contact via composePhoneContact() so both halves '
            . 'survive.'
        );
    }

    public function testFieldMapDoesNotPointAnyContactFieldAtPhoneContact(): void
    {
        $reflection = new ReflectionClass(DemographicsDispatcher::class);
        $map = $reflection->getConstants()['PATIENT_FIELD_MAP'];
        self::assertIsArray($map);

        $columnsForContactJsonPaths = [];
        foreach ($map as $jsonPath => $column) {
            if (str_starts_with($jsonPath, 'emergencyContact.')) {
                $columnsForContactJsonPaths[$jsonPath] = $column;
            }
        }

        self::assertNotContains(
            'phone_contact',
            $columnsForContactJsonPaths,
            'No emergencyContact.* JSON path may point at phone_contact in '
            . 'PATIENT_FIELD_MAP — that column is filled by '
            . 'composePhoneContact() during dispatch() instead, so a single '
            . 'OpenAI extraction never overwrites both halves.'
        );
    }

    public function testFieldMapDoesIncludeContactRelationship(): void
    {
        $reflection = new ReflectionClass(DemographicsDispatcher::class);
        $map = $reflection->getConstants()['PATIENT_FIELD_MAP'];
        self::assertIsArray($map);

        self::assertArrayHasKey(
            'emergencyContact.relationship',
            $map,
            'emergencyContact.relationship must be mapped — contact_relationship '
            . 'is a 1:1 column with the right semantics.'
        );
        self::assertSame(
            'contact_relationship',
            $map['emergencyContact.relationship'],
        );
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function emergencyContactProvider(): array
    {
        return [
            'both name and phone present' => [
                'Jane Smith',
                '555-123-4567',
                'Jane Smith <555-123-4567>',
            ],
            'name only' => [
                'Jane Smith',
                null,
                'Jane Smith',
            ],
            'phone only' => [
                null,
                '555-123-4567',
                '555-123-4567',
            ],
            'both null' => [
                null,
                null,
                null,
            ],
            'both empty strings collapse to null' => [
                '',
                '',
                null,
            ],
            'empty name + valid phone yields phone' => [
                '',
                '555-123-4567',
                '555-123-4567',
            ],
            'valid name + empty phone yields name' => [
                'Jane Smith',
                '',
                'Jane Smith',
            ],
        ];
    }

    #[DataProvider('emergencyContactProvider')]
    public function testComposePhoneContactCombinesNameAndPhone(
        ?string $name,
        ?string $phone,
        ?string $expected,
    ): void {
        self::assertSame(
            $expected,
            DemographicsDispatcher::composePhoneContact($name, $phone),
        );
    }
}
