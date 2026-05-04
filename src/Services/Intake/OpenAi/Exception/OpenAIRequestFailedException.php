<?php

/**
 * OpenAIRequestFailedException
 *
 * Thrown for OpenAI API failures that do not match a more specific exception
 * (network errors, non-429 HTTP errors, malformed JSON envelopes, etc.).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\OpenAi\Exception;

final class OpenAIRequestFailedException extends OpenAIException
{
}
