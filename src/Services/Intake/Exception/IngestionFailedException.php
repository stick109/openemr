<?php

/**
 * IngestionFailedException
 *
 * Thrown when extraction succeeds but writing the parsed data into OpenEMR
 * fails for a domain reason (missing patient, no encounter, document store
 * write failure, etc.).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Exception;

final class IngestionFailedException extends IntakeFormException
{
}
