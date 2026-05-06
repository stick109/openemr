<?php

/**
 * ShadowComparator
 *
 * Builds {@see ShadowComparisonRecord} value objects for M18 shadow mode.
 * Compares the legacy PHP copilot response (an associative array shaped by
 * {@see \OpenEMR\Services\Agent\AgentEvidenceResponseBuilder}) against the
 * Python sidecar's typed {@see CopilotRunResponseDto} response, surfacing
 * only PHI-free structural signals:
 *
 *   - verification status match (passed vs not-passed)
 *   - cited source-id set equality + counts
 *   - missingness cardinality
 *   - answer-block heading sets (lowercased + trimmed for comparison; the
 *     headings themselves are short labels, not claim/body content)
 *
 * The comparator never reads claim text, evidence bodies, citation
 * snippets, or patient identifiers from either side. Anything it places on
 * a {@see ShadowComparisonRecord} is safe to log via PSR-3 context arrays.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class ShadowComparator
{
    public function __construct(
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Compare a PHP-shape copilot response and a typed sidecar response.
     *
     * @param array<string, mixed>     $phpResponse     Output of
     *                                                  ``AgentEvidenceResponseBuilder::build()``.
     * @param CopilotRunResponseDto    $sidecarResponse Decoded sidecar payload.
     * @param string                   $traceId         Correlation id used in logs.
     * @param string                   $intentId        Closed-set intent id (PHI-free).
     */
    public function compare(
        array $phpResponse,
        CopilotRunResponseDto $sidecarResponse,
        string $traceId,
        string $intentId,
    ): ShadowComparisonRecord {
        $phpCitedSourceIds = $this->extractPhpCitedSourceIds($phpResponse);
        $sidecarCitedSourceIds = $this->extractSidecarCitedSourceIds($sidecarResponse);

        $phpHeadings = $this->extractPhpHeadings($phpResponse);
        $sidecarHeadings = $this->extractSidecarHeadings($sidecarResponse);

        $phpMissingCount = $this->countPhpMissingness($phpResponse);
        $sidecarMissingCount = count($sidecarResponse->missingOrUncertain);

        $phpVerificationPassed = $this->phpVerificationPassed($phpResponse);
        $sidecarVerificationPassed = $sidecarResponse->verificationStatus === 'passed';

        return new ShadowComparisonRecord(
            traceId: $traceId,
            intentId: $intentId,
            verificationStatusMatch: $phpVerificationPassed === $sidecarVerificationPassed,
            citedSourceIdsMatch: $this->setsEqual($phpCitedSourceIds, $sidecarCitedSourceIds),
            phpCitedCount: count($phpCitedSourceIds),
            sidecarCitedCount: count($sidecarCitedSourceIds),
            missingnessShapeMatch: $phpMissingCount === $sidecarMissingCount,
            phpAnswerBlockHeadings: $phpHeadings,
            sidecarAnswerBlockHeadings: $sidecarHeadings,
            headingsMatch: $this->setsEqual(
                $this->normalizeHeadings($phpHeadings),
                $this->normalizeHeadings($sidecarHeadings),
            ),
            comparedAt: $this->now(),
        );
    }

    /**
     * @param array<string, mixed> $phpResponse
     * @return list<string>
     */
    private function extractPhpCitedSourceIds(array $phpResponse): array
    {
        $ids = [];
        $citations = $phpResponse['citations'] ?? null;
        if (is_array($citations)) {
            foreach ($citations as $citation) {
                if (is_array($citation) && isset($citation['source_id']) && is_string($citation['source_id'])) {
                    $ids[$citation['source_id']] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * @return list<string>
     */
    private function extractSidecarCitedSourceIds(CopilotRunResponseDto $sidecarResponse): array
    {
        $ids = [];
        foreach ($sidecarResponse->citations as $citation) {
            if ($citation->sourceId !== '') {
                $ids[$citation->sourceId] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $phpResponse
     * @return list<string>
     */
    private function extractPhpHeadings(array $phpResponse): array
    {
        $blocks = $phpResponse['answer']['answer_blocks'] ?? null;
        if (!is_array($blocks)) {
            return [];
        }
        $headings = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $heading = $block['heading'] ?? null;
            if (is_string($heading) && $heading !== '') {
                $headings[] = $heading;
            }
        }
        return $headings;
    }

    /**
     * @return list<string>
     */
    private function extractSidecarHeadings(CopilotRunResponseDto $sidecarResponse): array
    {
        $headings = [];
        foreach ($sidecarResponse->answerBlocks as $block) {
            if ($block->heading !== '') {
                $headings[] = $block->heading;
            }
        }
        return $headings;
    }

    /**
     * @param array<string, mixed> $phpResponse
     */
    private function countPhpMissingness(array $phpResponse): int
    {
        $missing = $phpResponse['answer']['missing_or_uncertain'] ?? null;
        if (!is_array($missing)) {
            return 0;
        }
        return count($missing);
    }

    /**
     * @param array<string, mixed> $phpResponse
     */
    private function phpVerificationPassed(array $phpResponse): bool
    {
        $verification = $phpResponse['verification'] ?? null;
        if (!is_array($verification)) {
            return false;
        }
        $status = $verification['status'] ?? null;
        return is_string($status) && $status === 'passed';
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function setsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        $left = $a;
        $right = $b;
        sort($left);
        sort($right);
        return $left === $right;
    }

    /**
     * @param list<string> $headings
     * @return list<string>
     */
    private function normalizeHeadings(array $headings): array
    {
        $normalized = [];
        foreach ($headings as $heading) {
            $value = strtolower(trim($heading));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        return $normalized;
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            return $this->clock->now();
        }
        return new DateTimeImmutable();
    }
}
