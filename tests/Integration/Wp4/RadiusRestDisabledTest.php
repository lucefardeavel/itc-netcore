<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/** SEC-01 / Q3: without $radius_rest_secret the rlm_rest endpoint refuses every action, whatever is sent. */
final class RadiusRestDisabledTest extends RadiusHttpTestCase
{
    public function testEveryActionIsRefused(): void
    {
        $before = $this->snapshot();
        foreach (['authorize', 'authenticate', 'accounting', ''] as $action) {
            foreach ([[], ['X-Nuxr-Radius-Secret: anything-at-all-anything-at-all-123'], ['X-FreeRADIUS-Section: ' . $action]] as $headers) {
                $r = $this->rest($action, ['username' => 'cNULL', 'password' => 'cpw-cNULL', 'acctStatusType' => 'Start'], $headers);
                $this->assertSame(401, $r->status, $action);
                $this->assertStringContainsString('Unauthorized', $r->body);
            }
        }
        $this->assertUnchanged($before, 'disabled endpoint');
    }
}
