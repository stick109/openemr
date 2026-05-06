<?php

/**
 * MedicalHistoryDispatcherFormsRegressionTest
 *
 * Regression test for the duplicate-encounter-row bug: the dispatcher used
 * to insert a row into the `forms` table itself (with `formdir =
 * 'upload_intake_form'`), and `interface/forms/upload_intake_form/save.php`
 * also inserts one via {@see \OpenEMR\Services\FormService::addForm()}.
 * The two writes produced TWO encounter-timeline rows per upload — one with
 * a numeric stringified `user` column ("1") and a `form_id` pointing into
 * `form_questionnaire_assessments`, and the other (the legitimate one) with
 * `user='admin'` and `form_id` pointing into `form_upload_intake_form`.
 *
 * The fix is to remove the dispatcher's `INSERT INTO forms` entirely. This
 * test guards against re-introduction by asserting the source file no
 * longer contains a write to the `forms` table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

use OpenEMR\Services\Intake\Dispatcher\MedicalHistoryDispatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Group('isolated')]
#[Group('intake-forms')]
final class MedicalHistoryDispatcherFormsRegressionTest extends TestCase
{
    public function testDispatcherSourceDoesNotInsertIntoFormsTable(): void
    {
        $reflection = new ReflectionClass(MedicalHistoryDispatcher::class);
        $filename = $reflection->getFileName();
        self::assertIsString($filename, 'MedicalHistoryDispatcher source file should be locatable.');

        $source = file_get_contents($filename);
        self::assertIsString($source, 'MedicalHistoryDispatcher source must be readable.');

        // Permissive case-insensitive match for any INSERT into the `forms`
        // table — with or without backticks, with single or multiple spaces.
        $pattern = '/INSERT\s+INTO\s+`?forms`?\s/i';
        self::assertSame(
            0,
            preg_match($pattern, $source),
            'MedicalHistoryDispatcher must not write to the encounter `forms` table directly. '
            . 'That row is registered exclusively by upload_intake_form/save.php via '
            . 'FormService::addForm() — duplicating it here produced an extra timeline row '
            . 'with stale form_id and a stringified numeric user column.'
        );
    }

    public function testDispatcherSourceDoesNotReferenceUploadIntakeFormDirectory(): void
    {
        $reflection = new ReflectionClass(MedicalHistoryDispatcher::class);
        $filename = $reflection->getFileName();
        self::assertIsString($filename);

        $source = file_get_contents($filename);
        self::assertIsString($source);

        // Hard-coding `upload_intake_form` in the dispatcher implies a
        // formdir write — the dispatcher's job is FHIR/questionnaire data
        // only; the formdir is owned by save.php.
        $pattern = "/'upload_intake_form'/";
        self::assertSame(
            0,
            preg_match($pattern, $source),
            'MedicalHistoryDispatcher must not reference the upload_intake_form formdir constant. '
            . 'The encounter timeline registration is owned by save.php.'
        );
    }
}
