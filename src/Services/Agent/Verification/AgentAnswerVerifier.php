<?php

/**
 * AgentAnswerVerifier
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Verification;

use OpenEMR\Services\Agent\AgentAccessToken;

final class AgentAnswerVerifier
{
    private const MAX_TOTAL_TEXT_LENGTH = 4000;

    /**
     * @var list<string>
     */
    private const UNCITED_CERTAINTIES = [
        'not_found',
        'not_checked',
        'unknown',
    ];

    /**
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $packet
     */
    public function verify(array $answer, AgentAccessToken $accessToken, array $packet): AgentVerificationResult
    {
        $errors = [];
        $warnings = [];
        $sourceMap = $this->sourceMap($packet);
        $totalText = '';

        if (!is_array($answer['answer_blocks'] ?? null) || array_is_list($answer['answer_blocks']) === false) {
            $errors[] = 'answer_blocks must be a list.';
        }

        foreach (($answer['answer_blocks'] ?? []) as $blockIndex => $block) {
            if (!is_array($block)) {
                $errors[] = 'answer_blocks[' . $blockIndex . '] must be an object.';
                continue;
            }

            $heading = trim((string) ($block['heading'] ?? ''));
            $totalText .= ' ' . $heading;
            if ($heading === '') {
                $errors[] = 'answer_blocks[' . $blockIndex . '].heading is required.';
            }

            if (!is_array($block['claims'] ?? null) || array_is_list($block['claims']) === false) {
                $errors[] = 'answer_blocks[' . $blockIndex . '].claims must be a list.';
                continue;
            }

            foreach ($block['claims'] as $claimIndex => $claim) {
                $this->verifyClaim(
                    $claim,
                    $blockIndex,
                    $claimIndex,
                    $sourceMap,
                    $accessToken,
                    $errors,
                    $warnings,
                    $totalText
                );
            }
        }

        if (!is_array($answer['missing_or_uncertain'] ?? null) || array_is_list($answer['missing_or_uncertain']) === false) {
            $errors[] = 'missing_or_uncertain must be a list.';
        }

        foreach (($answer['missing_or_uncertain'] ?? []) as $itemIndex => $item) {
            $this->verifyMissingOrUncertain($item, $itemIndex, $sourceMap, $errors, $totalText);
        }

        $this->verifyToolFailures($packet, $answer, $errors);

        if (strlen($totalText) > self::MAX_TOTAL_TEXT_LENGTH) {
            $errors[] = 'answer exceeds maximum length for the 90-second workflow.';
        }

        return new AgentVerificationResult($errors === [], array_values(array_unique($errors)), array_values(array_unique($warnings)));
    }

    /**
     * @param array<string, array<string, mixed>> $sourceMap
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function verifyClaim(
        mixed $claim,
        int $blockIndex,
        int $claimIndex,
        array $sourceMap,
        AgentAccessToken $accessToken,
        array &$errors,
        array &$warnings,
        string &$totalText
    ): void {
        $path = 'answer_blocks[' . $blockIndex . '].claims[' . $claimIndex . ']';
        if (!is_array($claim)) {
            $errors[] = $path . ' must be an object.';
            return;
        }

        $text = trim((string) ($claim['text'] ?? ''));
        $certainty = trim((string) ($claim['certainty'] ?? ''));
        $citationIds = $this->stringList($claim['citation_ids'] ?? []);
        $totalText .= ' ' . $text;

        if ($text === '') {
            $errors[] = $path . '.text is required.';
            return;
        }

        if ($this->containsOutOfScopeAdvice($text)) {
            $errors[] = $path . ' contains out-of-scope clinical advice.';
        }

        if ($citationIds === []) {
            if (!in_array($certainty, self::UNCITED_CERTAINTIES, true)) {
                $errors[] = $path . ' must cite checked evidence.';
            }
            if ($this->soundsLikeMissingness($text) && !$this->usesSafeMissingness($text)) {
                $errors[] = $path . ' must phrase missingness as not found in checked evidence.';
            }
            return;
        }

        $claimSources = [];
        foreach ($citationIds as $citationId) {
            if (!isset($sourceMap[$citationId])) {
                $errors[] = $path . ' cites unknown source_id ' . $citationId . '.';
                continue;
            }

            $source = $sourceMap[$citationId];
            $claimSources[] = $source;
            $sourcePatientId = $source['patient_id'] ?? null;
            if (
                (is_int($sourcePatientId) || (is_string($sourcePatientId) && ctype_digit($sourcePatientId)))
                && (int) $sourcePatientId !== $accessToken->getPatientContext()->getPid()
            ) {
                $errors[] = $path . ' cites a source outside the current patient context.';
            }

            $this->verifyActiveStatusClaim($path, $text, $source, $errors);
        }

        if ($claimSources !== [] && !$this->claimTextSupportedBySources($text, $claimSources)) {
            $errors[] = $path . ' is not supported by cited source text.';
        }

        if ($certainty === 'conflicting') {
            $warnings[] = $path . ' is marked conflicting.';
        }
    }

    /**
     * @param array<string, array<string, mixed>> $sourceMap
     * @param list<string> $errors
     */
    private function verifyMissingOrUncertain(
        mixed $item,
        int $itemIndex,
        array $sourceMap,
        array &$errors,
        string &$totalText
    ): void {
        $path = 'missing_or_uncertain[' . $itemIndex . ']';
        if (!is_array($item)) {
            $errors[] = $path . ' must be an object.';
            return;
        }

        $text = trim((string) ($item['text'] ?? ''));
        $totalText .= ' ' . $text;
        if ($text === '') {
            $errors[] = $path . '.text is required.';
            return;
        }

        if ($this->containsOutOfScopeAdvice($text)) {
            $errors[] = $path . ' contains out-of-scope clinical advice.';
        }

        if ($this->soundsLikeMissingness($text) && !$this->usesSafeMissingness($text)) {
            $errors[] = $path . ' must phrase missingness as not found in checked evidence.';
        }

        if ($this->isCompletenessStatement($text)) {
            $errors[] = $path . ' must not contain a completeness statement; leave missing_or_uncertain empty when there are no true missing or uncertain items.';
        }

        foreach ($this->stringList($item['citation_ids'] ?? []) as $citationId) {
            if (!isset($sourceMap[$citationId])) {
                $errors[] = $path . ' cites unknown source_id ' . $citationId . '.';
            }
        }
    }

    /**
     * @param array<string, mixed> $packet
     * @param array<string, mixed> $answer
     * @param list<string> $errors
     */
    private function verifyToolFailures(array $packet, array $answer, array &$errors): void
    {
        $hasToolFailure = false;
        foreach (($packet['tool_runs'] ?? []) as $toolRun) {
            if (is_array($toolRun) && is_string($toolRun['error_class'] ?? null) && $toolRun['error_class'] !== '') {
                $hasToolFailure = true;
                break;
            }
        }

        if (!$hasToolFailure) {
            return;
        }

        $answerText = strtolower(json_encode($answer, JSON_THROW_ON_ERROR));
        if (
            !str_contains($answerText, 'unavailable')
            && !str_contains($answerText, 'not checked')
            && !str_contains($answerText, 'tool')
        ) {
            $errors[] = 'tool failure is hidden from the verified response.';
        }
    }

    /**
     * @param array<string, mixed> $packet
     * @return array<string, array<string, mixed>>
     */
    private function sourceMap(array $packet): array
    {
        $sourceMap = [];
        foreach (($packet['sources'] ?? []) as $source) {
            if (!is_array($source)) {
                continue;
            }

            $sourceId = $source['source_id'] ?? null;
            if (is_string($sourceId) && $sourceId !== '') {
                $sourceMap[$sourceId] = $source;
            }
        }

        return $sourceMap;
    }

    /**
     * @param list<array<string, mixed>> $sources
     */
    private function claimTextSupportedBySources(string $claimText, array $sources): bool
    {
        $normalizedClaimText = $this->normalizedText($claimText);
        $claimTokens = $this->significantTokens($claimText);

        foreach ($sources as $source) {
            $sourceText = (string) ($source['display'] ?? '') . ' ' . (string) ($source['excerpt'] ?? '');
            if (
                $normalizedClaimText !== ''
                && str_contains($this->normalizedText($sourceText), $normalizedClaimText)
            ) {
                return true;
            }

            if ($claimTokens === []) {
                continue;
            }

            $sourceTokens = $this->significantTokens($sourceText);
            if (array_intersect($claimTokens, $sourceTokens) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $errors
     */
    private function verifyActiveStatusClaim(string $path, string $claimText, array $source, array &$errors): void
    {
        if (!preg_match('/\bactive\b/i', $claimText)) {
            return;
        }

        $sourceType = strtolower((string) ($source['source_type'] ?? ''));
        if (!in_array($sourceType, ['medication', 'allergy', 'problem', 'result', 'event'], true)) {
            return;
        }

        $status = strtolower((string) ($source['status'] ?? ''));
        if ($status !== 'active') {
            $errors[] = $path . ' claims an active ' . $sourceType . ' without an active cited source.';
        }
    }

    private function normalizedText(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower($text)));
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $text): array
    {
        preg_match_all('/[a-z0-9][a-z0-9-]{3,}/i', strtolower($text), $matches);
        $stopWords = [
            'active' => true,
            'checked' => true,
            'claim' => true,
            'current' => true,
            'daily' => true,
            'evidence' => true,
            'listed' => true,
            'once' => true,
            'record' => true,
            'records' => true,
            'source' => true,
            'status' => true,
            'tablet' => true,
            'tablets' => true,
            'this' => true,
            'twice' => true,
        ];

        $tokens = [];
        foreach ($matches[0] ?? [] as $token) {
            if (!isset($stopWords[$token])) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    private function containsOutOfScopeAdvice(string $text): bool
    {
        return preg_match(
            '/\b(should|recommend|recommended|consider|start|stop|increase|decrease|prescribe|diagnose|treat|bill|billing code|place an order|order a)\b/i',
            $text
        ) === 1;
    }

    private function soundsLikeMissingness(string $text): bool
    {
        return preg_match('/\b(missing|not found|unavailable|not checked|unknown)\b/i', $text) === 1;
    }

    private function usesSafeMissingness(string $text): bool
    {
        return preg_match('/\bnot found in checked (evidence|records)\b/i', $text) === 1
            || preg_match('/\bnot checked\b/i', $text) === 1
            || preg_match('/\bunavailable\b/i', $text) === 1
            || preg_match('/\bunknown in checked (evidence|records)\b/i', $text) === 1;
    }

    private function isCompletenessStatement(string $text): bool
    {
        return preg_match(
            '/\bno\s+(additional|other|more)\b.*\b(found|identified|listed|seen|present)\b/i',
            $text
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $string = trim((string) $item);
                if ($string !== '') {
                    $strings[] = $string;
                }
            }
        }

        return array_values(array_unique($strings));
    }
}
