<?php

/**
 * AgentPatientContext
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

final class AgentPatientContext
{
    public function __construct(private readonly int $pid)
    {
    }

    public function getPid(): int
    {
        return $this->pid;
    }
}
