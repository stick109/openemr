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
     * @param string         $type             Renderer hint, e.g. ``paragraph``, ``list``, ``table``.
     * @param string         $content          Rendered text or structured payload (renderer-specific).
     * @param list<int>      $citationIndices  Indices into the response-level ``citations`` list.
     */
    public function __construct(
        public string $type,
        public string $content,
        public array $citationIndices,
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

        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : '',
            content: is_string($data['content'] ?? null) ? $data['content'] : '',
            citationIndices: $indices,
        );
    }
}
