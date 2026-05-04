<?php

/**
 * AmbiguousFormException
 *
 * Thrown when the auto-classifier is unable to decide which intake form
 * type the uploaded PDF represents with sufficient confidence (default
 * threshold is 0.6, see {@see \OpenEMR\Services\Intake\IntakeFormIngestService}).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Exception;

final class AmbiguousFormException extends IntakeFormException
{
}
