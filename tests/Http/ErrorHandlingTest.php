<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * OD-14 error handling: no role — SuperAdmin included — receives a stack trace, SQL error, filesystem
 * path or secret in a web or API response. Generic message + reference id; sanitized server log;
 * Telegram gets only a summary with the reference. display_errors is forced off even though the
 * test config.php turns it on.
 */
final class ErrorHandlingTest extends Wp2HttpTestCase
{
    private function assertGenericError(\Reseller\Tests\Support\HttpResponse $r, string $who): string
    {
        $this->assertSame(500, $r->status, "{$who}: HTTP 500 (" . $r->excerpt() . ')');
        $this->assertFalse($r->leaksInternals(), "{$who}: " . $r->excerpt(1000));
        $this->assertStringNotContainsString((string) getenv('WP1_DB_ROOT_PASSWORD'), $r->body, "{$who}: db password");
        $this->assertStringNotContainsString('secretdb', $r->body);
        $this->assertStringNotContainsString(static::$app->dir, $r->body);
        $this->assertMatchesRegularExpression('/reference ([0-9a-f]{12}-[0-9a-f]{6})/i', $r->body, "{$who}: reference id shown");
        preg_match('/reference ([0-9a-f]{12}-[0-9a-f]{6})/i', $r->body, $m);
        return $m[1];
    }

    public function testStaffOfEveryRoleGetsGenericErrorWithReference(): void
    {
        foreach (['sa', 'ad', 'A', 'A1', 'rp'] as $u) {
            $c = $this->staff($u);
            static::$app->clearProbes();
            foreach (['plugin/wp2probe_fail_pdo', 'plugin/wp2probe_fail_type'] as $route) {
                $ref = $this->assertGenericError($c->get($route), "{$u} {$route}");
                $log = static::$app->serverLog();
                $this->assertStringContainsString('ref=' . $ref, $log, 'detailed record stays server-side under the same reference');
                $this->assertStringNotContainsString((string) getenv('WP1_DB_ROOT_PASSWORD'), $log, 'server log is sanitized');
            }
            $tg = static::$app->probe('telegram');
            $this->assertNotEmpty($tg);
            foreach ($tg as $msg) {
                $this->assertMatchesRegularExpression('/^System error ref=[0-9a-f-]+ class=[A-Za-z\\\\]+ route=[A-Za-z0-9_\/-]+$/', (string) $msg, 'telegram: summary only');
            }
        }
    }

    public function testCustomerAndAnonymousGetGenericError(): void
    {
        $c = $this->customer('cNULL');
        $this->assertGenericError($c->get('plugin/wp2probe_fail_customer'), 'customer');
        $r = $this->client()->get('plugin/wp2probe_fail_pdo');
        $this->assertSame(404, $r->status, 'anonymous cannot even reach a staff plugin endpoint');
    }

    public function testApiErrorsAreGeneric(): void
    {
        // a hook that throws inside an API-reachable controller (test plugin, flag file)
        file_put_contents(static::$app->dir . '/wp2_probe/throw_in_hook.flag', '1');
        try {
            $token = $this->apiToken('sa');
            $r = $this->client()->api('GET', 'customers/view/' . $this->cid('cNULL'), [], $token);
            $j = $r->json();
            $this->assertFalse($j['success']);
            $this->assertStringContainsString('reference', $j['message']);
            $this->assertNotEmpty($j['meta']['ref'] ?? null);
            $this->assertFalse($r->leaksInternals(), $r->body);
            $this->assertStringNotContainsString((string) getenv('WP1_DB_ROOT_PASSWORD'), $r->body);
        } finally {
            @unlink(static::$app->dir . '/wp2_probe/throw_in_hook.flag');
        }
    }

    public function testDisplayErrorsForcedOffEvenIfConfigEnablesIt(): void
    {
        $sa = $this->staff('sa');
        foreach (['dashboard', 'customers/list', 'settings/users', 'reseller/tree', 'plan/list'] as $route) {
            $r = $sa->get($route);
            $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error):/', $r->body, $route);
        }
        foreach (['login', 'register', 'admin'] as $route) {
            $r = $this->client()->get($route);
            $this->assertSame(200, $r->status, $route);
            $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error):/', $r->body, $route);
        }
    }
}
