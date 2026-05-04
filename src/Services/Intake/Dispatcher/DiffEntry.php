<?php

/**
 * DiffEntry
 *
 * One row of the field-level diff preview shown to the user after an
 * intake-form ingestion. `applied` distinguishes fields that were actually
 * written (true) from fields that were skipped because the existing column
 * already had a value (false, in fill-only-empty mode).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Dispatcher;

final readonly class DiffEntry
{
    public function __construct(
        public string $field,
        public ?string $oldValue,
        public ?string $newValue,
        public bool $applied,
        public ?string $reason = null,
    ) {
    }

    /**
     * @return array{field: string, old: ?string, new: ?string, applied: bool, reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'old' => $this->oldValue,
            'new' => $this->newValue,
            'applied' => $this->applied,
            'reason' => $this->reason,
        ];
    }
}
