<?php

/**
 * OpenAISchemaMismatchException
 *
 * Thrown when an OpenAI structured-output response does not parse as JSON
 * matching the requested schema (or the API itself reports a schema error).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\OpenAi\Exception;

final class OpenAISchemaMismatchException extends OpenAIException
{
}
