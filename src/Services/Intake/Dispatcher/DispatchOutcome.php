<?php

/**
 * DispatchOutcome
 *
 * Result of a single dispatcher run: an optional inserted/updated row id
 * and a diff preview the UI can render.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Dispatcher;

final readonly class DispatchOutcome
{
    /**
     * @param ?int $insertedRowId The primary id of the row created or updated
     *                            by the dispatcher; null if the dispatcher
     *                            performed no row-level write.
     * @param list<DiffEntry> $diffPreview Field-level diff entries the UI
     *                                     can render to the user.
     */
    public function __construct(
        public ?int $insertedRowId,
        public array $diffPreview,
    ) {
    }
}
