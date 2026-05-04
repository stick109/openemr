<?php

/**
 * InvalidFormTypeException
 *
 * Thrown when the request-time form-type string cannot be parsed into a
 * known {@see \OpenEMR\Services\Intake\IntakeFormType} value.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Exception;

final class InvalidFormTypeException extends IntakeFormException
{
}
