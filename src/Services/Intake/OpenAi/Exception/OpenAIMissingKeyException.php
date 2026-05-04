<?php

/**
 * OpenAIMissingKeyException
 *
 * Thrown when the OpenAI client cannot locate the OPENAI_API_KEY environment
 * variable required to authenticate calls to the OpenAI API.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\OpenAi\Exception;

final class OpenAIMissingKeyException extends OpenAIException
{
}
