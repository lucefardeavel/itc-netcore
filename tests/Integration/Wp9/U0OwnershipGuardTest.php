<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 final hardening, D-U0-R1 "TARGETED FAIL-CLOSED PROTECTION": a RADIUS login policy (radcheck / radreply /
 * radusergroup rows of one subscriber key) is never written, suspended or removed while the key has MORE THAN ONE
 * logical owner (grandfathered data: two customers, a customer and a live voucher identity, cross-field, canonical
 * variants). The ownership check runs under the shared identity lock; a refusal changes no RADIUS policy, sends no
 * disconnect, writes no recharge / invoice and cleans nothing up. Legacy paths (application code, separate processes):
 * Admin recharge (Package::rechargeUser pre-write guard), voucher-vs-customer, deactivation / expiry
 * (remove_customer -> suspend), the public Radius::customerAddPlan (N-01), the legacy rename. Controls: single owners.
 */
final class U0OwnershipGuardTest extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
    }

    /** an unassigned customer with its own legitimate RADIUS policy (provisioned BEFORE the duplicate exists) */
    private function provisioned(string $username, string $pppoe = '', ?int $plan = null): array
    {
        $c = $this->person($username, $pppoe);
        $this->assertMatchesRegularExpression('/^INV-/', $this->provision($c['id'], $plan), 'fixture: the first owner is provisioned');
        return $c;
    }

    /** the grandfathered duplicate (inserted as data, not through a writer) */
    private function duplicate(string $username, string $pppoe = '', ?string $manager = null): array
    {
        return $this->person($username, $pppoe, $manager);
    }

    private function deviceCall(string $method, array $c, int $plan): string
    {
        return (string) static::$app->exec(sprintf('require_once "system/devices/Radius.php"; $c = ORM::for_table("tbl_customers")->find_one(%d); $p = ORM::for_table("tbl_plans")->find_one(%d)->as_array(); try { %s; $result = "APPLIED"; } catch (\Reseller\Radius\ProvisionFailure $e) { $result = "REFUSED " . $e->result->code; } catch (\Throwable $e) { $result = "ERROR " . get_class($e); }',
            $c['id'], $plan, $method));
    }

    private function disconnectLogLines(): int
    {
        return substr_count((string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log'), 'radius.disconnect');
    }

    /** @return array{0:bool,1:array} [met, observation] */
    private function guarded(string $case, callable $act, array $protectedKeys, bool $expectRefused = true): array
    {
        $policies = array_combine($protectedKeys, array_map(static fn(string $k): array => self::exact($k), $protectedKeys));
        // the protected owner is online: a suspend / provision of its key would try to disconnect this session (the test
        // NAS is unknown, so every attempt leaves a radius.disconnect line in the log); accounting history must not change
        foreach ($protectedKeys as $k) {
            self::radacct($k, 'w9u0s' . bin2hex(random_bytes(4)), null);
        }
        $before = self::state(static::$db);
        $disc = $this->disconnectLogLines();
        $outcome = $act();
        $d = self::delta($before, self::state(static::$db));
        $intact = true;
        foreach ($policies as $k => $rows) {
            $intact = $intact && self::exact($k) === $rows;
        }
        $obs = ['outcome' => $outcome, 'state_delta' => $d, 'protected_policies_unchanged' => $intact, 'disconnect_log_lines_added' => $this->disconnectLogLines() - $disc];
        $met = $expectRefused ? ($intact && $d['business'] === [] && $obs['disconnect_log_lines_added'] === 0) : $intact;
        self::record("U0 ownership guard {$case}", $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        return [$met, $obs];
    }

    public static function duplicates(): array
    {
        return ['customer vs customer (other case)' => ['{T}', ''], 'customer vs customer (stored leading space)' => [' {t}', ''],
            'customer vs customer (trailing TAB)' => ["{t}\t", ''], 'customer vs customer (accent)' => ['{t}é', ''], 'cross-field (duplicate PPPoE = username)' => ['w9u0n{r}', '{t}']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('duplicates')]
    public function testLegacyRechargeOfAGrandfatheredDuplicateIsRefusedBeforeAnyWrite(string $u, string $p): void
    {
        $t = self::tag();
        $f = static fn(string $s): string => str_replace(['{t}', '{T}', '{r}'], [$t, strtoupper($t), bin2hex(random_bytes(2))], $s);
        $name = $u === "{t}\t" || $u === ' {t}' ? $t : ($u === '{t}é' ? $t . 'e' : $t);
        $first = $this->provisioned($u === '{t}é' ? $t . 'e' : $t);
        $dup = $this->duplicate($f($u), $f($p));
        [$met, $obs] = $this->guarded('recharge ' . $this->dataName(), fn(): string => var_export(static::$app->exec(self::provisionCode($dup['id'])), true), [$name]);
        $this->assertSame('false', $obs['outcome'], 'Package::rechargeUser returns false (pre-write refusal)');
        $this->assertSame([], $obs['state_delta']['business'], 'no recharge, invoice or RADIUS change');
        $this->assertTrue($obs['protected_policies_unchanged'], "the first owner's policy is unchanged");
        $this->assertTrue($met);
    }

    public function testLegacyRechargeOfACustomerOverALiveVoucherIdentityIsRefused(): void
    {
        $code = self::tag();
        $this->voucher($code);
        $this->assertSame('GRANTED', (string) static::$app->exec(self::p6Code($code)));
        $c = $this->duplicate(strtoupper($code));
        [$met, $obs] = $this->guarded('recharge customer over voucher identity', fn(): string => var_export(static::$app->exec(self::provisionCode($c['id'])), true), [$code]);
        $this->assertSame('false', $obs['outcome']);
        $this->assertTrue($met, json_encode($obs));
    }

    public function testDeactivationOfAGrandfatheredDuplicateNeverSuspendsTheOtherOwner(): void
    {
        $t = self::tag();
        $this->provisioned($t);
        $dup = $this->duplicate(strtoupper($t));
        [$met, $obs] = $this->guarded('deactivate / expiry (remove_customer -> suspend)', fn(): string => $this->deviceCall('(new Radius())->remove_customer($c, $p)', $dup, self::$hotspot), [$t]);
        $this->assertSame('REFUSED RADIUS_IDENTITY_AMBIGUOUS', $obs['outcome']);
        $this->assertTrue($met, json_encode($obs));
    }

    public function testCustomerAddPlanOnAnAmbiguousKeyIsRefused(): void
    {
        $t = self::tag();
        $this->provisioned($t);
        $dup = $this->duplicate(strtoupper($t));
        [$met, $obs] = $this->guarded('customerAddPlan (N-01)', fn(): string => $this->deviceCall('(new Radius())->customerAddPlan($c, $p, "")', $dup, self::$hotspot), [$t]);
        $this->assertSame('REFUSED RADIUS_IDENTITY_AMBIGUOUS', $obs['outcome']);
        $this->assertTrue($met, json_encode($obs));
    }

    public function testLegacyRenameOfAnAmbiguousKeyIsRefusedBeforeTheSave(): void
    {
        $t = self::tag();
        $this->provisioned($t);
        // an EXACT duplicate (same bytes): the WP8 A3 equivalent-key check sees only "own" rows, so only the ownership
        // check can tell that the rows of this key also belong to the first customer
        $dup = $this->provisionedDuplicateWithPlan($t);
        $y = self::tag();
        $sa = $this->staff('sa');
        [$met, $obs] = $this->guarded('legacy rename of the ambiguous key', fn(): string => 'HTTP ' . $this->p4($dup, ['username' => $y], $sa)
            . ' renamed=' . var_export(self::customerRow($dup['id'])['username'] === $y, true), [$t]);
        $this->assertStringEndsWith('renamed=false', $obs['outcome'], 'the rename is refused before the customer row is saved');
        $this->assertTrue($met, json_encode($obs));
    }

    /** a grandfathered duplicate WITH an active recharge row on the Radius plan (fixture data, no RADIUS write) */
    private function provisionedDuplicateWithPlan(string $username): array
    {
        $c = $this->duplicate($username);
        static::$db->insertRow('tbl_user_recharges', ['customer_id' => $c['id'], 'username' => $username, 'plan_id' => self::$hotspot, 'namebp' => 'w9u0',
            'recharged_on' => date('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => date('Y-m-d', time() + 86400), 'time' => '23:59:59',
            'status' => 'on', 'method' => 'Admin - fixture', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 1]);
        return $c;
    }

    public function testOwnershipCheckRunsUnderTheIdentityLockAndFailsClosed(): void
    {
        $c = $this->person(self::tag());
        $holder = self::secondConnection();
        $this->assertSame('1', (string) $holder->query("SELECT GET_LOCK('" . self::identityLockName() . "', 0)")->fetchColumn());
        try {
            $t0 = microtime(true);
            [$met, $obs] = $this->guarded('lock busy (single owner, check cannot run)', fn(): string => var_export(static::$app->exec(self::provisionCode($c['id'])), true), []);
            $waited = round(microtime(true) - $t0, 1);
        } finally {
            $holder->query("SELECT RELEASE_LOCK('" . self::identityLockName() . "')");
        }
        $after = var_export(static::$app->exec(self::provisionCode($c['id'])), true);
        self::record('U0 ownership guard lock-busy timing', 'OBSERVED', ['waited_s' => $waited, 'after_release' => $after]);
        $this->assertSame('false', $obs['outcome'], 'the ownership check could not take the identity lock: refused, nothing written');
        $this->assertSame([], $obs['state_delta']['business']);
        $this->assertGreaterThanOrEqual(2.5, $waited);
        $this->assertMatchesRegularExpression("/^'INV-/", $after, 'control: the same recharge succeeds once the lock is free');
    }

    public function testSingleOwnersAreUnchanged(): void
    {
        $t = self::tag();
        $out = [];
        $c = $this->person($t);
        $out['recharge'] = $this->provision($c['id']);
        $out['password_is_own'] = self::password($t) === $c['password'];
        $out['deactivate'] = $this->deviceCall('(new Radius())->remove_customer($c, $p)', $c, self::$hotspot);
        $same = $this->person(self::tag() . 's');
        static::$db->pdo->prepare('UPDATE tbl_customers SET pppoe_username = username WHERE id = ?')->execute([$same['id']]);
        $out['username_equals_own_pppoe'] = $this->provision($same['id'], self::$pppoe);
        $v = self::tag();
        $this->voucher($v);
        $out['voucher_only_grant'] = (string) static::$app->exec(self::p6Code($v));
        $owned = $this->person(self::tag(), '', 'A');
        $out['owned_legacy_recharge_still_refused_by_wp8_guard'] = var_export(static::$app->exec(self::provisionCode($owned['id'])), true);
        $ok = str_starts_with($out['recharge'], 'INV-') && $out['password_is_own'] && $out['deactivate'] === 'APPLIED' && str_starts_with($out['username_equals_own_pppoe'], 'INV-')
            && $out['voucher_only_grant'] === 'GRANTED' && $out['owned_legacy_recharge_still_refused_by_wp8_guard'] === 'false';
        self::record('U0 ownership guard controls', $ok ? 'CONTROL_PASS' : 'CONTROL_FAIL', $out);
        $this->assertTrue($ok, json_encode($out));
    }
}
