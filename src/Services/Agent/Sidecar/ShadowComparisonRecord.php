<?php

/**
 * ShadowComparisonRecord
 *
 * Sanitized result of comparing a legacy PHP copilot response with the
 * Python sidecar's response while shadow mode (M18) is active. The record
 * deliberately captures only structural/cardinality information and a
 * short list of answer-block headings -- it never carries claim text,
 * citation snippets, or any other PHI-bearing content.
 *
 * Shape consumed by ``ShadowComparator`` and the M18 logger; emitted
 * via PSR-3 context arrays in ``AgentIntentRestController``.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use DateTimeImmutable;

final readonly class ShadowComparisonRecord
{
    /**
     * @param string       $traceId                     Correlation id shared with the sidecar.
     * @param string       $intentId                    Closed-set intent id (PHI-free).
     * @param bool         $verificationStatusMatch     True iff PHP and sidecar agree on
     *                                                  passed-vs-non-passed verification.
     * @param bool         $citedSourceIdsMatch         True iff the set of cited
     *                                                  ``source_id`` values is equal.
     * @param int          $phpCitedCount               Distinct citations the PHP path returned.
     * @param int          $sidecarCitedCount           Distinct citations the sidecar returned.
     * @param bool         $missingnessShapeMatch       True iff PHP and sidecar produced the
     *                                                  same number of missingness notes.
     * @param list<string> $phpAnswerBlockHeadings      Headings only -- never claim text.
     * @param list<string> $sidecarAnswerBlockHeadings  Headings only -- never claim text.
     * @param bool         $headingsMatch               Set equality on lowercased+trimmed headings.
     */
    public function __construct(
        public string $traceId,
        public string $intentId,
        public bool $verificationStatusMatch,
        public bool $citedSourceIdsMatch,
        public int $phpCitedCount,
        public int $sidecarCitedCount,
        public bool $missingnessShapeMatch,
        public array $phpAnswerBlockHeadings,
        public array $sidecarAnswerBlockHeadings,
        public bool $headingsMatch,
        public DateTimeImmutable $comparedAt,
    ) {
    }
}
