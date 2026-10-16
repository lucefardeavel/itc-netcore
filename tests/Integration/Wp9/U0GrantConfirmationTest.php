<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 final closure, owner §5: VoucherRedemptionService::grant must not treat "some RADIUS row exists under the code's
 * name" as proof that the voucher-only service was granted. Package::rechargeUser (pinned) swallows every device failure
 * and still returns an invoice, so the evidence must be tied to the requested service.
 *
 * Negative cases (rows that exist but are not the voucher's policy, written outside the application during the grant by a
 * test plugin on the recharge_user hook, i.e. after the claim and before the device step):
 *  C1 stale rows of a colliding identity (a foreign password) + the device refuses (a duplicate owner appears);
 *  C2 partial rows (only a group row) + the device refuses;
 *  C3 a CONFIRMED device failure (the RADIUS write rolled back) while foreign rows exist;
 *  C4 an UNKNOWN device outcome (RADIUS commit unconfirmed) - the rows may well be right, but nothing confirmed them.
 * Contract: CLAIMED_REVIEW (voucher stays CLAIMED: never redeemable again, listed for review), never GRANTED; replay
 * NOT_VALID; one transaction row per grant (no second settlement). Claim-time protection (C0): foreign rows that exist
 * BEFORE the claim refuse the redemption untouched. Concurrency (C5): parallel grants of distinct codes (InnoDB deadlocks
 * retried by the legacy device) all end GRANTED with their own policy. Controls: a normal grant; a customer's voucher.
 * Isolation: REPEATABLE READ by default, READ COMMITTED with WP9_ISOLATION.
 */
final class U0GrantConfirmationTest extends RadiusHttpTestCase
{
    use U0Fixture;

    private static string $isolation = 'REPEATABLE-READ';

    private const BYPASS_PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): during ONE voucher grant (hook recharge_user: after the claim, before the device) and only
// while the flag exists - direct-DB writes outside the application and RADIUS write faults
register_hook('recharge_user', 'wp9u0_grant_bypass');
function wp9u0_grant_bypass() {
    $flag = __DIR__ . '/../../wp2_probe/u0_grant.json';
    if (!is_file($flag)) { return; }
    $j = json_decode((string) file_get_contents($flag), true);
    unlink($flag);
    $db = ORM::get_db();
    $key = $j['code'];
    if (!empty($j['duplicate'])) {
        $db->prepare("INSERT INTO tbl_customers (username, password, pppoe_username, pppoe_password, pppoe_ip, fullname, address, phonenumber, email, service_type, account_type, coordinates, status, balance, created_by, auto_renewal) VALUES (?, 'x', '', '', '', 'W9 bypass', '', '0700', 'w9u0@example.invalid', 'Hotspot', 'Personal', '', 'Active', 0, 1, 0)")->execute([strtoupper($key)]);
    }
    if (!empty($j['foreign_password'])) {
        $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$key, $j['foreign_password']]);
    }
    if (!empty($j['group_only'])) {
        $db->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$key, $j['group_only']]);
    }
    if (!empty($j['fault'])) {
        $point = $j['fault'];
        \Reseller\Radius\RadiusFaults::$hook = static function (string $p) use ($point): void {
            if ($p === $point) { throw new \RuntimeException('w9u0 test fault ' . $p); }
        };
    }
    @file_put_contents(__DIR__ . '/../../wp2_probe/u0_grant.done', 'ran');
}
PHP;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
        // run_hook() calls only the FIRST registered function of a hook: the U0 barrier plugin (also on recharge_user) is
        // removed from this app copy, so the bypass plugin is the only recharge_user hook
        unlink(static::$app->dir . '/system/plugin/wp9u0_barrier.php');
        file_put_contents(static::$app->dir . '/system/plugin/wp9u0_grant.php', self::BYPASS_PLUGIN);
        if (getenv('WP9_ISOLATION') === 'READ-COMMITTED') {
            static::$db->pdo->exec('SET GLOBAL TRANSACTION ISOLATION LEVEL READ COMMITTED');
            self::$isolation = 'READ-COMMITTED';
        }
    }

    public static function tearDownAfterClass(): void
    {
        static::$db?->pdo->exec('SET GLOBAL TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        parent::tearDownAfterClass();
    }

    private static function log(): string
    {
        return (string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log');
    }

    /** one voucher-only redemption with the given bypass actions during the grant */
    private function grantCase(string $case, array $actions): array
    {
        $code = self::tag();
        $vid = $this->voucher($code);
        $tx0 = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions');
        $log0 = self::log();
        if ($actions !== []) {
            file_put_contents(static::$app->dir . '/wp2_probe/u0_grant.json', json_encode(['code' => $code] + $actions));
        }
        $status = (string) static::$app->exec(self::p6Code($code));
        $ran = is_file(static::$app->dir . '/wp2_probe/u0_grant.done');
        @unlink(static::$app->dir . '/wp2_probe/u0_grant.done');
        @unlink(static::$app->dir . '/wp2_probe/u0_grant.json');
        $replay = (string) static::$app->exec(self::p6Code($code));
        $newLog = substr(self::log(), strlen($log0));
        preg_match_all('/event=voucher\.redeem [^\n]*outcome=(\S+)/', $newLog, $m);
        $obs = ['isolation' => self::$isolation, 'status' => $status, 'voucher_status' => self::vstatus($vid), 'bypass_ran' => $ran,
            'radius_rows' => count(self::exact($code)), 'password_is_the_code' => self::password($code) === $code, 'replay' => $replay,
            'transactions_added' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions') - $tx0, 'logged_outcomes' => $m[1],
            'drift_lists_voucher' => (bool) array_filter((array) static::$app->exec('$result = \Reseller\Activation\DriftReport::collect(false);'),
                static fn($r): bool => is_array($r) && ($r['kind'] ?? '') === 'VOUCHER_CLAIMED' && (int) ($r['voucher_id'] ?? 0) === $vid)];
        $falseGranted = $status === 'GRANTED' && !$obs['password_is_the_code'];
        $unconfirmedGranted = $status === 'GRANTED' && $actions !== [];
        self::record("U0 grant confirmation {$case}", $falseGranted ? 'DEFECT_REPRODUCED' : ($unconfirmedGranted ? 'UNCONFIRMED_GRANTED' : 'CONTRACT_MET'), $obs);
        return $obs;
    }

    private function assertReview(array $obs, string $reason): void
    {
        $this->assertTrue($obs['bypass_ran'], 'control: the out-of-application writes / fault ran during the grant');
        $this->assertSame('CLAIMED_REVIEW', $obs['status'], 'never GRANTED without a confirmed policy ' . json_encode($obs));
        $this->assertSame('CLAIMED', $obs['voucher_status'], 'never redeemable again');
        $this->assertSame('NOT_VALID', $obs['replay'], 'no second grant');
        $this->assertSame(1, $obs['transactions_added'], 'one transaction row (Package wrote its invoice), no second settlement');
        $this->assertTrue($obs['drift_lists_voucher'], 'listed for operator review');
        $this->assertContains('CLAIMED_REVIEW:' . $reason, $obs['logged_outcomes']);
    }

    public function testStaleForeignRowsOfACollidingIdentityAreNotAGrant(): void
    {
        $this->assertReview($this->grantCase('C1 stale foreign rows + device refused', ['duplicate' => true, 'foreign_password' => 'w9u0-stale-foreign']), 'NOT_CONFIRMED');
    }

    public function testPartialRowsAreNotAGrant(): void
    {
        $this->assertReview($this->grantCase('C2 partial (group-only) rows + device refused', ['duplicate' => true, 'group_only' => 'w9u0_stale_group']), 'NOT_CONFIRMED');
    }

    public function testAConfirmedDeviceFailureWithPreExistingRowsIsNotAGrant(): void
    {
        $obs = $this->grantCase('C3 confirmed device failure + foreign rows', ['foreign_password' => 'w9u0-stale-foreign', 'fault' => 'radius.after_write']);
        $this->assertReview($obs, 'DEVICE_FAILED');
        $this->assertFalse($obs['password_is_the_code'], 'the failed write was rolled back; only the foreign row remains');
    }

    public function testAnUnknownDeviceOutcomeIsNotAGrant(): void
    {
        $obs = $this->grantCase('C4 device outcome UNKNOWN (commit unconfirmed)', ['fault' => 'radius.commit_lost']);
        $this->assertReview($obs, 'DEVICE_UNKNOWN');
        $this->assertTrue($obs['password_is_the_code'], 'the commit happened; only its confirmation was lost (review, not release)');
    }

    public function testForeignRowsBeforeTheClaimRefuseTheRedemptionUntouched(): void
    {
        $out = [];
        foreach (['exact key' => static fn(string $c): string => $c, 'equivalent key' => static fn(string $c): string => strtoupper($c)] as $k => $f) {
            $code = self::tag();
            $vid = $this->voucher($code);
            static::$db->insertRow('radcheck', ['username' => $f($code), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'w9u0-stale-foreign']);
            $before = static::$db->rows('SELECT * FROM radcheck WHERE username = ? ORDER BY id', [$code]);
            $out[$k] = ['status' => (string) static::$app->exec(self::p6Code($code)), 'voucher_status' => self::vstatus($vid),
                'rows_unchanged' => static::$db->rows('SELECT * FROM radcheck WHERE username = ? ORDER BY id', [$code]) === $before];
        }
        self::record('U0 grant confirmation C0 foreign rows before the claim', 'OBSERVED', ['isolation' => self::$isolation] + $out);
        $this->assertSame(['exact key' => ['status' => 'REFUSED', 'voucher_status' => '0', 'rows_unchanged' => true],
            'equivalent key' => ['status' => 'REFUSED', 'voucher_status' => '0', 'rows_unchanged' => true]], $out);
    }

    public function testParallelGrantsWithDeadlockRetryAllEndGrantedWithTheirOwnPolicy(): void
    {
        $log0 = self::log();
        $bad = [];
        $n = 0;
        for ($round = 0; $round < 3; $round++) {
            $codes = [];
            for ($i = 0; $i < 6; $i++) {
                $codes[$i] = self::tag();
                $this->voucher($codes[$i]);
            }
            $hs = array_map(static fn(string $c): array => static::$app->execStart(self::p6Code($c)), $codes);
            foreach ($hs as $i => $h) {
                $status = (string) static::$app->execFinish($h);
                $n++;
                if ($status !== 'GRANTED' || self::password($codes[$i]) !== $codes[$i]) {
                    $bad[] = ['status' => $status, 'own_policy' => self::password($codes[$i]) === $codes[$i]];
                }
            }
        }
        $new = substr(self::log(), strlen($log0));
        $obs = ['isolation' => self::$isolation, 'grants' => $n, 'not_granted_or_wrong_policy' => $bad, 'deadlock_retries_logged' => substr_count($new, 'radius.deadlock_retry'),
            'review_outcomes' => substr_count($new, 'outcome=CLAIMED_REVIEW')];
        self::record('U0 grant confirmation C5 parallel grants', $bad === [] ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        $this->assertSame([], $bad, json_encode($obs));
    }

    public function testControlsAreUnchanged(): void
    {
        $obs = $this->grantCase('control normal grant', []);
        $this->assertSame('GRANTED', $obs['status']);
        $this->assertSame('1', $obs['voucher_status']);
        $this->assertTrue($obs['password_is_the_code']);
        $this->assertSame('NOT_VALID', $obs['replay']);
        $this->assertSame(1, $obs['transactions_added']);
        $this->assertFalse($obs['drift_lists_voucher']);
        // a customer's voucher (customer target): unchanged WP5B semantics
        $c = $this->person(self::tag());
        $code = 'W9U0C' . strtoupper(bin2hex(random_bytes(4)));
        $vid = $this->voucher($code);
        $status = (string) static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, new \Reseller\Domain\RedemptionTarget(%d, %s), \Reseller\Domain\RedemptionSource::public())["status"];',
            var_export($code, true), $c['id'], var_export($c['username'], true)));
        self::record('U0 grant confirmation control customer voucher', 'OBSERVED', ['status' => $status, 'voucher_status' => self::vstatus($vid), 'own_policy' => self::password($c['username']) === $c['password']]);
        $this->assertSame('GRANTED', $status);
        $this->assertSame('1', self::vstatus($vid));
        $this->assertSame($c['password'], self::password($c['username']));
    }
}
