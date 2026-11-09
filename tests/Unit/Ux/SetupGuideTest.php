<?php
namespace Reseller\Tests\Unit\Ux;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Ui\SetupGuide;

/**
 * V1.0.1 "Get started" card: every step links to the page where it is done, the card leaves the dashboard once the
 * required steps are done (reseller prices are optional), and only administrators see it.
 */
final class SetupGuideTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/SetupGuide.php';
    }

    public function testStepsUntilEveryRequiredStepIsDone(): void
    {
        $fresh = SetupGuide::build([]);
        $this->assertSame(['company', 'logo', 'network', 'plans', 'resale'], array_column($fresh, 'key'));
        $this->assertSame([false, false, false, false, true], array_column($fresh, 'optional'));
        $this->assertNotEmpty(SetupGuide::build(['company' => true, 'logo' => true, 'network' => true]), 'plans still missing');
        $partial = SetupGuide::build(['company' => true, 'logo' => true, 'network' => true, 'resale' => true]);
        $this->assertSame([true, true, true, false, true], array_column($partial, 'done'));
        $this->assertSame([], SetupGuide::build(['company' => true, 'logo' => true, 'network' => true, 'plans' => true]),
            'gone without the optional step');
    }

    public function testEveryStepOpensAMappedPageAndTheCardIsForAdministrators(): void
    {
        foreach (SetupGuide::build([]) as $s) {
            $controller = explode('/', explode('&', $s['route'])[0])[0];
            $this->assertArrayHasKey($controller, \Reseller\Security\RouteMap::ROUTES, $s['route']);
        }
        $dash = (string) file_get_contents(dirname(__DIR__, 3) . '/system/controllers/dashboard.php');
        $this->assertStringContainsString('Capability::SYSTEM_ADMIN) ? \\Reseller\\Ui\\SetupGuide::build(', $dash);
        $tpl = (string) file_get_contents(dirname(__DIR__, 3) . '/ui/ui/admin/dashboard.tpl');
        $this->assertStringContainsString('{if $nuxr_setup|default:[]}{include file="admin/nuxr/setup-guide.tpl"}{/if}', $tpl);
    }
}
