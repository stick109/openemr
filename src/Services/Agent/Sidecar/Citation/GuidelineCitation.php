<?php

/**
 * GuidelineCitation
 *
 * Read-only DTO for a `guideline` row from form_upload_intake_form_citation.
 * Wraps the chunk identifier, source URL, snippet text, and optional section
 * label that the sidecar's evidence retriever returns for clinical guidance.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar\Citation;

final readonly class GuidelineCitation
{
    public function __construct(
        public int $id,
        public int $formId,
        public string $chunkId,
        public string $sourceUrl,
        public string $snippet,
        public ?string $section,
    ) {
    }
}
