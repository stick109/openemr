<?php

/**
 * AgentIntentRouteTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestRoutes;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class AgentIntentRouteTest extends TestCase
{
    private string $routeContent = '';

    protected function setUp(): void
    {
        $resolved = realpath(__DIR__ . '/../../../../apis/routes/_rest_routes_standard.inc.php');
        if (!is_string($resolved)) {
            $this->markTestSkipped('Route file not found');
        }

        $content = file_get_contents($resolved);
        if ($content === false) {
            $this->markTestSkipped('Failed to read route file');
        }

        $this->routeContent = $content;
    }

    public function testAgentIntentRouteIsRegisteredThroughStandardApi(): void
    {
        $this->assertStringContainsString('use OpenEMR\RestControllers\Agent\AgentIntentRestController;', $this->routeContent);
        $this->assertStringContainsString('"POST /api/agent/intent"', $this->routeContent);
        $this->assertStringContainsString('RestConfig::request_authorization_check($request, "patients", "demo")', $this->routeContent);
        $this->assertStringContainsString('(new AgentIntentRestController())->postIntent($request)', $this->routeContent);
    }
}
