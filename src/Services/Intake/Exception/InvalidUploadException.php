<?php

/**
 * InvalidUploadException
 *
 * Thrown when the uploaded file fails the intake-form pre-flight checks
 * (wrong MIME type, exceeds the size cap, missing on disk, etc.).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Exception;

final class InvalidUploadException extends IntakeFormException
{
}
