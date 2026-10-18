<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\RunnerProcess;

/**
 * D7 (owner V1_OWNER_DECISIONS_APPROVED): the OBS-1 collision report is READ ONLY and does not choose a winner.
 *  - it reports a canonical key held by two customers (username of one = PPPoE username of the other, one owned and one unassigned);
 *  - nothing is changed (customers, radcheck, ledger byte-identical before / after);
 *  - the shipped survey is byte-identical to the one the tests of the survey use, and contains only SELECT statements;
 *  - the report does not contain a "keep / drop" decision.
 */
final class V1IdentityReportTest extends ActivationTestCase
{
    private static ?AppServer $app = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$app = AppServer::create(static::$db, []);
    }

    public static function tearDownAfterClass(): void
    {
        self::$app?->destroy();
        self::$app = null;
        parent::tearDownAfterClass();
    }

    private function snapshot(): string
    {
        return md5(json_encode([static::$db->rows('SELECT * FROM tbl_customers ORDER BY id'), static::$db->rows('SELECT * FROM radcheck ORDER BY id'),
            static::$db->rows('SELECT * FROM tbl_manager_ledger ORDER BY id'), static::$db->rows('SELECT * FROM tbl_user_recharges ORDER BY id')]));
    }

    public function testTheReportListsACollisionAndChangesNothing(): void
    {
        [, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $owned = $this->customer($leaf);
        $key = 'obs1' . bin2hex(random_bytes(4));
        $legacy = static::$db->insertRow('tbl_customers', ['username' => 'legacy' . $key, 'password' => 'x', 'fullname' => 'Legacy', 'address' => '', 'phonenumber' => '0700',
            'email' => $key . '@example.invalid', 'service_type' => 'PPPoE', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0,
            'created_by' => $this->sid('ad'), 'auto_renewal' => 0, 'manager_id' => null, 'pppoe_username' => $key]);
        static::$db->exec('UPDATE tbl_customers SET username = ' . static::$db->pdo->quote($key) . ' WHERE id = ' . $owned);
        $this->customersChanged();   // the test created the collision on purpose; the report itself must change nothing
        $before = $this->snapshot();
        $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=/dev/null', self::$app->dir . '/system/reseller/identity_report.php', '--json']);
        $this->assertSame(0, $r['exit'], $r['raw']);
        $report = json_decode((string) preg_replace('/^.*?(\{"summary")/s', '$1', $r['raw']), true);
        $this->assertIsArray($report, $r['raw']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['collisions']);
        $this->assertSame(0, $report['summary']['statements_failed'], json_encode($report['sections']));
        $rows = array_filter($report['sections'][2]['rows'], static fn(array $x): bool => $x['k'] === $key);
        $this->assertCount(1, $rows, 'the colliding key is listed once');
        $row = array_values($rows)[0];
        $this->assertSame(2, (int) $row['holders']);
        $this->assertStringContainsString("customer:{$owned}(owned)", $row['identities']);
        $this->assertStringContainsString("customer:{$legacy}(unassigned)", $row['identities']);
        $this->assertSame($before, $this->snapshot(), 'the report changed nothing');
        $this->assertDoesNotMatchRegularExpression('/\b(keep|drop|winner|delete)\b/i', $r['raw'], 'the report states facts, it does not choose');
    }

    public function testTheShippedSurveyIsTheTestedOneAndOnlyReads(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertSame(file_get_contents($root . '/dev/wp9/identity_survey.sql'), file_get_contents($root . '/system/reseller/identity_survey.sql'), 'one survey, two locations');
        $sql = (string) preg_replace('/^--[^\n]*$/m', '', (string) file_get_contents($root . '/system/reseller/identity_survey.sql'));
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
            $this->assertMatchesRegularExpression('/^SELECT\b/i', $stmt);
        }
        $cli = (string) file_get_contents($root . '/system/reseller/identity_report.php');
        $this->assertStringContainsString('SET SESSION TRANSACTION READ ONLY', $cli);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE)\s/i', preg_replace('#/\*.*?\*/#s', '', $cli));
    }
}
