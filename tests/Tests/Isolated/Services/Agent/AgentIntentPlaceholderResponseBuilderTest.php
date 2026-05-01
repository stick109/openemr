<?php

/**
 * AgentIntentPlaceholderResponseBuilderTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent;

use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\AgentIntentPlaceholderResponseBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class AgentIntentPlaceholderResponseBuilderTest extends TestCase
{
    public function testEveryCatalogIntentHasDeterministicPlaceholder(): void
    {
        $catalog = new AgentIntentCatalog();
        $builder = new AgentIntentPlaceholderResponseBuilder();

        foreach ($catalog->intentIds() as $intentId) {
            $first = $builder->build($intentId);
            $second = $builder->build($intentId);

            $this->assertTrue($builder->hasPlaceholderFor($intentId));
            $this->assertSame($first, $second);
            $this->assertSame('placeholder', $first['status']);
            $this->assertSame('deterministic_placeholder', $first['response_generation']);
            $this->assertSame([], $first['citations']);
            $this->assertSame([], $first['checked_evidence']);
        }
    }
}
