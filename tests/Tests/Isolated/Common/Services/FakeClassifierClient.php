<?php

/**
 * FakeClassifierClient
 *
 * Test double for the classifier client used by IntakeFormClassifier
 * (intake-forms-plan.md §3.4). Records the request payload it was given so
 * the test can inspect it, and returns a canned response.
 *
 * Implements OpenEMR\Services\IntakeForm\IntakeFormClassifierClientInterface
 * via duck-typing — we don't `implements` it explicitly because that
 * interface lives in a sibling worktree that may not be present yet.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

final class FakeClassifierClient
{
    /** @var array<string, mixed> */
    public array $lastRequest = [];

    /**
     * @param array<string, mixed> $cannedResponse
     */
    public function __construct(private readonly array $cannedResponse)
    {
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function classify(array $request): array
    {
        $this->lastRequest = $request;
        return $this->cannedResponse;
    }
}
