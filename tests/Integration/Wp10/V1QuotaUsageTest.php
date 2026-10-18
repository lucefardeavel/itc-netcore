<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\SettlementReadModel;
use Reseller\Radius\QuotaUsage;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * Option G (owner V1_OWNER_DECISIONS_APPROVED, N1 = A + G): actual network usage and billable usage are different numbers.
 *  - actual = the raw accounting of the period (unchanged), billable = min(actual, quota), overshoot = the rest;
 *  - rows of a previous period are not part of the current one (the overshoot is not carried over);
 *  - reading the position changes nothing (radacct and radcheck byte-identical);
 *  - an overshoot creates no debt, no wallet / ledger movement; a renewal after an overshoot settles exactly once, as without it;
 *  - no finance class reads accounting at all (source check).
 */
final class V1QuotaUsageTest extends ActivationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RadiusFaults::$hook = null;
        static::$db->exec("DELETE FROM radcheck WHERE username LIKE 'v1g%'");
        static::$db->exec("DELETE FROM radacct WHERE username LIKE 'v1g%'");
    }

    private function row(string $user, int $in, int $out, int $time = 60, bool $open = false): int
    {
        return static::$db->insertRow('radacct', ['acctsessionid' => 'v1g' . bin2hex(random_bytes(3)), 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $user,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 600), 'acctupdatetime' => gmdate('Y-m-d H:i:s', time() - 5),
            'acctstoptime' => $open ? null : gmdate('Y-m-d H:i:s', time() - 5), 'acctsessiontime' => $time, 'acctinputoctets' => $in, 'acctoutputoctets' => $out, 'framedipaddress' => '10.42.0.5']);
    }

    private function check(string $user, array $rows): void
    {
        foreach ($rows as $a => $v) {
            static::$db->insertRow('radcheck', ['username' => $user, 'attribute' => $a, 'op' => ':=', 'value' => (string) $v]);
        }
    }

    private function usage(string $user): array
    {
        return (new QuotaUsage(static::$db->pdo))->current($user);
    }

    private function sum(): string
    {
        return md5(json_encode([static::$db->rows('SELECT * FROM radacct ORDER BY radacctid'), static::$db->rows('SELECT * FROM radcheck ORDER BY id')]));
    }

    public function testActualBillableAndOvershootAreSeparate(): void
    {
        $u = 'v1g' . bin2hex(random_bytes(3));
        $this->check($u, ['Max-Data' => 1000, 'Max-All-Session' => 100, 'Nuxr-Period-Start' => 1]);
        $this->row($u, 600, 500, 130);   // 1100 octets, 130 s: both quotas passed
        $by = array_column($this->usage($u), null, 'quota');
        $this->assertSame(['quota' => 'Max-Data', 'limit' => '1000', 'actual' => '1100', 'billable' => '1000', 'overshoot' => '100', 'over' => true, 'period_start' => 1, 'period_acct_id' => null], $by['Max-Data']);
        $this->assertSame(['130', '100', '30'], [$by['Max-All-Session']['actual'], $by['Max-All-Session']['billable'], $by['Max-All-Session']['overshoot']]);
    }

    public function testBelowTheQuotaBillableEqualsActualAndExactAtTheQuota(): void
    {
        $u = 'v1g' . bin2hex(random_bytes(3));
        $this->check($u, ['Max-Data' => 1000, 'Nuxr-Period-Start' => 1]);
        $this->row($u, 300, 200);
        $this->assertSame(['500', '500', '0', false], array_map(fn($k) => $this->usage($u)[0][$k], ['actual', 'billable', 'overshoot', 'over']));
        $this->row($u, 250, 250);   // exactly the quota
        $this->assertSame(['1000', '1000', '0', false], array_map(fn($k) => $this->usage($u)[0][$k], ['actual', 'billable', 'overshoot', 'over']));
        $this->assertSame([], $this->usage('v1g-nobody'), 'no quota row (unlimited / not provisioned): nothing to report');
    }

    public function testSixtyFourBitNumbersAreExact(): void
    {
        $u = 'v1g' . bin2hex(random_bytes(3));
        $this->check($u, ['Max-Data' => '5000000000000', 'Nuxr-Period-Start' => 1]);
        $this->row($u, 3000000000000, 2000000000007);
        $r = $this->usage($u)[0];
        $this->assertSame(['5000000000007', '5000000000000', '7'], [$r['actual'], $r['billable'], $r['overshoot']]);
    }

    public function testThePreviousPeriodsRowsAreNotPartOfTheCurrentOne(): void
    {
        $u = 'v1g' . bin2hex(random_bytes(3));
        $old = $this->row($u, 900, 900);                       // overshoot of the previous period
        $this->check($u, ['Max-Data' => 1000, 'Nuxr-Period-Start' => 1, 'Nuxr-Period-Acct-Id' => $old]);   // the new period starts after it
        $before = $this->sum();
        $r = $this->usage($u)[0];
        $this->assertSame(['0', '0', '0', false], [$r['actual'], $r['billable'], $r['overshoot'], $r['over']], 'the overshoot is not carried over');
        $this->assertSame($before, $this->sum(), 'reading changes nothing');
        $this->row($u, 100, 50);                               // arrives after the boundary: counted
        $this->assertSame('150', $this->usage($u)[0]['actual']);
    }

    public function testMalformedQuotaRowsReportNothing(): void
    {
        $u = 'v1g' . bin2hex(random_bytes(3));
        $this->check($u, ['Max-Data' => 'abc', 'Nuxr-Period-Start' => 1]);
        $this->assertSame([], $this->usage($u));
    }

    public function testAnOvershootCreatesNoDebtAndARenewalSettlesExactlyOnce(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        static::$db->exec("UPDATE tbl_plans SET typebp = 'Limited', limit_type = 'Data_Limit', data_limit = 1, data_unit = 'MB' WHERE id = {$plan}");
        $c = $this->customer($leaf);
        $ledger0 = (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger');
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $perActivation = (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger') - $ledger0;
        $user = $this->username($c);
        $quota = (int) static::$db->value("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Max-Data'", [$user]);
        $this->assertGreaterThan(0, $quota);
        $old = $this->row($user, (int) ($quota * 0.8), (int) ($quota * 0.7));   // 150 % of the quota: an overshoot
        $r = $this->usage($user)[0];
        $this->assertTrue($r['over']);
        $this->assertSame((string) $quota, $r['billable']);
        $wallets = static::$db->rows('SELECT * FROM tbl_manager_wallets ORDER BY manager_id');
        $ledger = (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger');
        $this->assertSame([], SettlementReadModel::integrity(), 'the overshoot alone moved no money');
        $this->assertSame($wallets, static::$db->rows('SELECT * FROM tbl_manager_wallets ORDER BY manager_id'));
        // renewal: one settlement, as the NEW did; the new period starts from zero; the raw rows stay
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'RENEW')['status']);
        $this->assertSame($perActivation, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger') - $ledger, 'exactly one settlement for the renewal, nothing for the overshoot');
        $this->assertSame([], SettlementReadModel::integrity());
        $n = $this->usage($user)[0];
        $this->assertSame(['0', '0', false], [$n['actual'], $n['overshoot'], $n['over']], 'the overshoot of the previous period is not carried into the renewal');
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM radacct WHERE radacctid = ?', [$old]), 'raw accounting kept');
    }

    public function testNoFinanceClassReadsAccounting(): void
    {
        $root = dirname(__DIR__, 3) . '/system/autoload/Reseller';
        $hits = [];
        foreach (['Finance', 'Activation'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS)) as $f) {
                $src = (string) file_get_contents((string) $f);
                if (preg_match('/QuotaUsage|acctinputoctets|acctoutputoctets|overshoot/i', $src)) {
                    $hits[] = $dir . '/' . basename((string) $f);
                }
            }
        }
        $this->assertSame([], $hits, 'billing code never reads usage or overshoot');
    }
}
