<?php

/**
 * AnswerBlockDto
 *
 * Read-only DTO mirroring the Python ``AnswerBlock`` schema in
 * ``agent-service/agent_service/schemas/copilot.py``. Used by the PHP UI
 * proxy (M17) to render copilot responses without re-parsing the wire
 * payload at every layer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

final readonly class AnswerBlockDto
{
    /**
     * @param string                              $type             Legacy renderer hint.  Kept for backward compatibility
     *                                                              with pre-M14 callers; M14 introduced ``heading``+``claims``.
     * @param string                              $content          Legacy renderer text.  Kept for backward compatibility.
     * @param list<int>                           $citationIndices  Legacy indices into ``citations``.
     * @param string                              $heading          Block heading from the sidecar wire shape (M14).
     * @param list<array<string, mixed>>          $claims           Structured claims from the sidecar (M14):
     *                                                              ``[{ text, citation_ids: list<string>, certainty }]``.
     *                                                              The UI renderer reads these to build cited bullets.
     * @param string|null                         $bodyMarkdown     Optional inline markdown body (M14).
     */
    public function __construct(
        public string $type,
        public string $content,
        public array $citationIndices,
        public string $heading = '',
        public array $claims = [],
        public ?string $bodyMarkdown = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data Decoded ``answer_blocks[i]`` payload.
     */
    public static function fromArray(array $data): self
    {
        $rawIndices = $data['citation_indices'] ?? [];
        $indices = [];
        if (is_array($rawIndices)) {
            foreach ($rawIndices as $index) {
                if (is_int($index)) {
                    $indices[] = $index;
                }
            }
        }

        $rawClaims = $data['claims'] ?? [];
        $claims = [];
        if (is_array($rawClaims)) {
            foreach ($rawClaims as $claim) {
                if (!is_array($claim)) {
                    continue;
                }
                $text = is_string($claim['text'] ?? null) ? $claim['text'] : '';
                $rawCitationIds = $claim['citation_ids'] ?? [];
                $citationIds = [];
                if (is_array($rawCitationIds)) {
                    foreach ($rawCitationIds as $cid) {
                        if (is_string($cid)) {
                            $citationIds[] = $cid;
                        }
                    }
                }
                $certainty = is_string($claim['certainty'] ?? null) ? $claim['certainty'] : 'unknown';
                $claims[] = [
                    'text' => $text,
                    'citation_ids' => $citationIds,
                    'certainty' => $certainty,
                ];
            }
        }

        $bodyMarkdown = $data['body_markdown'] ?? null;
        if ($bodyMarkdown !== null && !is_string($bodyMarkdown)) {
            $bodyMarkdown = null;
        }

        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : '',
            content: is_string($data['content'] ?? null) ? $data['content'] : '',
            citationIndices: $indices,
            heading: is_string($data['heading'] ?? null) ? $data['heading'] : '',
            claims: $claims,
            bodyMarkdown: $bodyMarkdown,
        );
    }
}
