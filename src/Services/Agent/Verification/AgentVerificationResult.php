<?php

/**
 * AgentVerificationResult
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Verification;

final class AgentVerificationResult
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        private readonly bool $passed,
        private readonly array $errors = [],
        private readonly array $warnings = []
    ) {
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array{status: string, errors: list<string>, warnings: list<string>, unsupported_claim_count: int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->passed ? 'passed' : 'failed',
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'unsupported_claim_count' => count($this->errors),
        ];
    }
}
