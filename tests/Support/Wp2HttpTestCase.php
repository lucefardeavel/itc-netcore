<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Migration\Outcome;

/**
 * Base class for WP2 HTTP tests: one disposable database (V2 legacy variant + R0001–R0006 applied by
 * the strict runner + Wp2Seed) and one disposable app server per test class.
 */
abstract class Wp2HttpTestCase extends TestCase
{
    protected static ?TestDatabase $db = null;
    protected static ?AppServer $app = null;
    protected static ?Wp2Seed $seed = null;

    /** Override to start from an unmigrated database (schema-readiness tests). */
    protected static function migrate(): bool
    {
        return true;
    }

    /** Override to configure the app copy (e.g. no api_secret). */
    protected static function appOptions(): array
    {
        return [];
    }

    public static function setUpBeforeClass(): void
    {
        $db = new TestDatabase('h');
        $db->build(FixtureBuilder::V2);
        if (static::migrate()) {
            $r = RunnerProcess::run($db, 'migrate');
            if ($r['exit'] !== Outcome::OK) {
                $db->drop();
                throw new \RuntimeException('migration failed: ' . $r['raw']);
            }
            static::$seed = Wp2Seed::seed($db)->attach($db);
        }
        static::$db = $db;
        static::$app = AppServer::create($db, static::appOptions());
        static::$app->start();
    }

    public static function tearDownAfterClass(): void
    {
        static::$app?->destroy();
        static::$db?->drop();
        static::$app = null;
        static::$db = null;
        static::$seed = null;
    }

    protected function client(): HttpClient
    {
        return static::$app->client();
    }

    /** A logged-in staff browser session. */
    protected function staff(string $username): HttpClient
    {
        $c = $this->client();
        $r = $c->loginStaff($username, Wp2Seed::password($username));
        $this->assertStringNotContainsString('Invalid Username or Password', $r->body, "login {$username}: " . $r->excerpt());
        return $c;
    }

    protected function customer(string $username): HttpClient
    {
        $c = $this->client();
        $c->loginCustomer($username, Wp2Seed::customerPassword($username));
        return $c;
    }

    /** a2 API token obtained through the real API login. */
    protected function apiToken(string $username, ?string $password = null): string
    {
        $r = $this->client()->api('POST', 'admin/post', ['username' => $username, 'password' => $password ?? Wp2Seed::password($username)]);
        $j = $r->json();
        $this->assertTrue(($j['success'] ?? false) === true, 'API login ' . $username . ': ' . $r->excerpt());
        $this->assertMatchesRegularExpression('/^a2\.\d+\.\d+\.[0-9a-f]{64}$/', (string) ($j['result']['token'] ?? ''));
        return (string) $j['result']['token'];
    }

    /** Rows of the append-only audit table for an event type. */
    protected function auditRows(string $eventType): array
    {
        return static::$db->rows('SELECT * FROM tbl_reseller_audit_events WHERE event_type = ? ORDER BY id', [$eventType]);
    }

    protected function sid(string $username): int
    {
        return static::$seed->staff[$username];
    }

    protected function cid(string $username): int
    {
        return static::$seed->customers[$username];
    }

    /** @return array<string,string> */
    protected function snapshot(array $exclude = DbProbe::DEFAULT_EXCLUDE): array
    {
        return DbProbe::checksums(static::$db, $exclude);
    }

    protected function assertUnchanged(array $before, string $message = '', array $exclude = DbProbe::DEFAULT_EXCLUDE): void
    {
        $this->assertSame([], DbProbe::changed($before, $this->snapshot($exclude)), 'database changed: ' . $message);
    }

    protected function assertDenied(HttpResponse $r, string $message = ''): void
    {
        $denied = in_array($r->status, [401, 403, 404, 405], true)
            || ($r->status === 302 && str_contains((string) $r->location(), 'login'))
            || ($r->status === 200 && ($j = $r->json()) !== null && ($j['success'] ?? null) === false);
        $this->assertTrue($denied, 'expected denial: ' . $message . ' got ' . $r->excerpt());
        $this->assertFalse($r->leaksInternals(), 'internals leaked: ' . $message . ' ' . $r->excerpt(800));
    }
}
