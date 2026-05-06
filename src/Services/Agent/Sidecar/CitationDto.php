<?php

/**
 * CitationDto
 *
 * Read-only DTO mirroring the Python ``Citation`` schema in
 * ``agent-service/agent_service/schemas/copilot.py``. Carries the source
 * pointer backing one or more ``AnswerBlockDto`` entries.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

final readonly class CitationDto
{
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $label,
        public ?string $url,
        public ?string $snippet,
    ) {
    }

    /**
     * @param array<string, mixed> $data Decoded ``citations[i]`` payload.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceType: is_string($data['source_type'] ?? null) ? $data['source_type'] : '',
            sourceId: is_string($data['source_id'] ?? null) ? $data['source_id'] : '',
            label: is_string($data['label'] ?? null) ? $data['label'] : '',
            url: isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
            snippet: isset($data['snippet']) && is_string($data['snippet']) ? $data['snippet'] : null,
        );
    }
}
