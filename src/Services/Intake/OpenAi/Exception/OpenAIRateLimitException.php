<?php

/**
 * OpenAIRateLimitException
 *
 * Thrown when the OpenAI API responds with HTTP 429 (rate limited).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\OpenAi\Exception;

final class OpenAIRateLimitException extends OpenAIException
{
}
