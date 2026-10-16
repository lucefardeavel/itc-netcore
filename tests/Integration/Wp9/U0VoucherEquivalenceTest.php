<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 R-FC-4E (owner WP9_U0_CORRECTION_REQUIRED — R_FC_4_EQUIVALENCE_VERIFICATION_AUTHORIZED): the R-FC-4 voucher state
 * guard VoucherRedemptionService::voucherOnlyServiceRefusal() matched voucher rows by exact bytes (BINARY code) and took
 * "no row" for a legacy voucher-only recharge whose voucher was deleted. The paths it guards resolve identities under the
 * column collation (utf8mb4_general_ci: case-, accent- and trailing-space-insensitive): login.php E12 finds the recharge
 * row with `username = ?`, the Radius device finds the billing period the same way and writes the policy with
 * `username = ?`; FreeRADIUS looks up radcheck the same way. A requested name that is canonically equal to - but not the
 * same bytes as - a voucher identity can therefore reach service.
 *
 * Matrix (synthetic data, Radius plan): states of the canonical identity class x requested name; entry points
 *  E12  public voucher-only login (re-activation from the recharge row; writes the RADIUS policy through the device);
 *  E15  RADIUS REST authorize (access authorization) - E16 authenticate is recorded separately, never counted as service;
 *  DEV  a direct device grant of the voucher-only row (the shared device guard of every driver).
 * Effect is measured, not only the guard's answer: RADIUS policy tables (any write), vouchers, invoices, recharges.
 */
final class U0VoucherEquivalenceTest extends RadiusHttpTestCase
{
    use U0Fixture;

    private const SECRET = 'w9u0-rfc4e-rest-endpoint-secret-32plus';
    private const RADIUS_TABLES = ['radcheck', 'radreply', 'radusergroup'];
    private const LEDGER_TABLES = ['tbl_voucher', 'tbl_transactions', 'tbl_user_recharges'];

    private const FAULT_PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): during ONE grant (hook recharge_user, before the device) and only while the flag exists,
// the RADIUS write transaction fails after its writes (rolled back): a CONFIRMED device failure -> CLAIMED_REVIEW
register_hook('recharge_user', 'wp9u0_rfc4e_fault');
function wp9u0_rfc4e_fault() {
    $flag = __DIR__ . '/../../wp2_probe/u0_rfc4e_fault';
    if (!is_file($flag)) { return; }
    unlink($flag);
    \Reseller\Radius\RadiusFaults::$hook = static function (string $p): void {
        if ($p === 'radius.after_write') { throw new \RuntimeException('w9u0 rfc4e test fault'); }
    };
}
PHP;

    protected static function restSecret(): ?string
    {
        return self::SECRET;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
        // run_hook() runs only the FIRST registered function of a hook: the U0 barrier plugin (recharge_user) is removed
        unlink(static::$app->dir . '/system/plugin/wp9u0_barrier.php');
        file_put_contents(static::$app->dir . '/system/plugin/wp9u0_rfc4e.php', self::FAULT_PLUGIN);
        $cols = static::$db->rows("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'tbl_voucher' AND COLUMN_NAME = 'code') OR (TABLE_NAME = 'tbl_user_recharges' AND COLUMN_NAME = 'username') OR (TABLE_NAME = 'radcheck' AND COLUMN_NAME = 'username')) ORDER BY TABLE_NAME");
        self::record('U0 R-FC-4E column collations', 'OBSERVED', ['columns' => $cols]);
    }

    // ------------------------------------------------------------------ fixtures

    private function reviewedReal(): string
    {
        $code = self::tag();
        $vid = $this->voucher($code);
        touch(static::$app->dir . '/wp2_probe/u0_rfc4e_fault');
        $this->assertSame('CLAIMED_REVIEW', (string) static::$app->exec(self::p6Code($code)), 'fixture: the grant ends in review');
        @unlink(static::$app->dir . '/wp2_probe/u0_rfc4e_fault');
        $this->assertSame('CLAIMED', self::vstatus($vid));
        return $code;
    }

    private function grantedReal(): string
    {
        $code = self::tag();
        $this->voucher($code);
        $this->assertSame('GRANTED', (string) static::$app->exec(self::p6Code($code)), 'fixture: a granted voucher-only identity');
        return $code;
    }

    /** a voucher row in a given state (fixture data: the state a grant / review / legacy import leaves) */
    private function voucherRow(string $code, string $status, string $user = '0'): int
    {
        $id = $this->voucher($code);
        static::$db->pdo->prepare('UPDATE tbl_voucher SET status = ?, user = ? WHERE id = ?')->execute([$status, $user, $id]);
        return $id;
    }

    private function recharge(string $username): void
    {
        static::$db->insertRow('tbl_user_recharges', ['customer_id' => 0, 'username' => $username, 'plan_id' => self::$hotspot, 'namebp' => 'w9u0',
            'recharged_on' => date('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => date('Y-m-d', time() + 86400 * 3), 'time' => '23:59:59',
            'status' => 'on', 'method' => 'Voucher - fixture', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 0]);
    }

    // ------------------------------------------------------------------ entry points

    private function e12(string $q): string
    {
        $c = $this->client();
        $body = $c->postWithToken('login/activation', ['voucher_only' => $q], [], 'login')->body . $c->get('login')->body;
        return match (true) {
            str_contains($body, 'Voucher activation success') => 'SERVED',
            str_contains($body, 'Voucher Not Valid') => 'REFUSED_NOT_VALID',
            str_contains($body, 'Voucher invalid') => 'REFUSED_INVALID',
            str_contains($body, 'Internet Voucher Expired') => 'REFUSED_EXPIRED',
            default => 'OTHER',
        };
    }

    private function restQ(string $action, string $q): int
    {
        return $this->rest($action, ['username' => $q, 'password' => $q], ['X-Nuxr-Radius-Secret: ' . self::SECRET])->status;
    }

    private function device(string $q): string
    {
        return (string) static::$app->exec(sprintf('require_once "system/devices/Radius.php"; $p = ORM::for_table("tbl_plans")->find_one(%d); try { (new Radius())->add_customer(["fullname" => "Voucher", "email" => "", "username" => %s, "password" => %s], $p); $result = "SERVED"; } catch (\Reseller\Radius\ProvisionFailure $e) { $result = "REFUSED " . $e->result->code; } catch (\Throwable $e) { $result = "ERROR " . get_class($e); }',
            self::$hotspot, var_export($q, true), var_export($q, true)));
    }

    private static function guard(string $q): string
    {
        return (string) var_export(static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::voucherOnlyServiceRefusal(%s);', var_export($q, true))), true);
    }

    /**
     * Runs E12, E16, E15 and DEV with the requested name $q and measures the effect.
     *
     * @return array{served_by:list<string>,radius_write:bool,ledger_change:list<string>,out:array<string,mixed>}
     */
    private function probe(string $case, string $q, bool $expectServed): array
    {
        $before = self::state(static::$db);
        $out = ['guard' => self::guard($q), 'E12 login' => $this->e12($q), 'E16 authenticate (authentication only)' => $this->restQ('authenticate', $q),
            'E15 authorize' => $this->restQ('authorize', $q), 'DEV device grant' => $this->device($q)];
        $d = self::delta($before, self::state(static::$db));
        $changed = array_merge($d['business'], $d['audit']);
        $served = array_keys(array_filter(['E12 login' => $out['E12 login'] === 'SERVED', 'E15 authorize' => $out['E15 authorize'] === 200, 'DEV device grant' => $out['DEV device grant'] === 'SERVED']));
        $obs = ['requested' => bin2hex($q), 'out' => $out, 'served_by' => $served, 'radius_write' => array_values(array_intersect($changed, self::RADIUS_TABLES)) !== [],
            'ledger_change' => array_values(array_intersect($changed, self::LEDGER_TABLES))];
        $ok = $expectServed ? $served !== [] : ($served === [] && !$obs['radius_write'] && $obs['ledger_change'] === []);
        self::record("U0 R-FC-4E {$case}", $expectServed ? ($ok ? 'CONTROL_PASS' : 'CONTROL_FAIL') : ($ok ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED'), $obs);
        return $obs;
    }

    private function assertNotServed(array $obs): void
    {
        $this->assertSame([], $obs['served_by'], 'no authorization / policy write for this identity ' . json_encode($obs));
        $this->assertFalse($obs['radius_write'], 'no RADIUS policy write');
        $this->assertSame([], $obs['ledger_change'], 'no voucher, invoice or recharge change');
    }

    // ------------------------------------------------------------------ equivalence cases

    public function testACaseVariantOfAVoucherInReviewIsNotServed(): void
    {
        $code = $this->reviewedReal();
        $this->assertNotServed($this->probe('CLAIMED_REVIEW (real flow), requested in another case', strtoupper($code), false));
        $this->assertNotServed($this->probe('CLAIMED_REVIEW (real flow), requested exactly (R-FC-4 control)', $code, false));
    }

    public function testATrailingWhitespaceVariantOfAVoucherInReviewIsNotServed(): void
    {
        $code = self::tag();
        $this->voucherRow($code . ' ', 'CLAIMED');
        $this->recharge($code . ' ');
        $this->assertNotServed($this->probe('CLAIMED voucher "K " + recharge "K ", requested "K"', $code, false));
    }

    public function testAnAccentVariantOfAVoucherInReviewIsNotServed(): void
    {
        $code = self::tag() . 'é';
        $this->voucherRow($code, 'CLAIMED');
        $this->recharge($code);
        $this->assertNotServed($this->probe('CLAIMED voucher "Ké" + recharge, requested "Ke"', substr($code, 0, -2) . 'e', false));
    }

    public function testAGrantedVoucherWithAnEquivalentVoucherInReviewIsNotServed(): void
    {
        $code = $this->grantedReal();
        $this->voucherRow(strtoupper($code), 'CLAIMED');
        $this->recharge(strtoupper($code));
        $this->assertNotServed($this->probe('GRANTED "K" + CLAIMED "KU" (equivalent), requested "K"', $code, false));
    }

    public function testAGrantedVoucherIsNotServedUnderAVariantName(): void
    {
        $code = $this->grantedReal();
        $this->assertNotServed($this->probe('GRANTED "K", requested "KU" (no voucher "KU")', strtoupper($code), false));
        $ctl = $this->probe('control: GRANTED "K", requested "K"', $code, true);
        $this->assertSame(['E12 login', 'E15 authorize', 'DEV device grant'], $ctl['served_by']);
    }

    public function testAnUnusedVoucherWithAnOldRechargeIsNotServedUnderAVariantName(): void
    {
        $code = self::tag();
        $this->voucherRow($code, '0');
        $this->recharge($code);
        $this->assertNotServed($this->probe('unused "K" + recharge "K", requested "KU"', strtoupper($code), false));
    }

    public function testAGrantedVoucherNextToAnEquivalentUnusedVoucher(): void
    {
        $code = $this->grantedReal();
        $this->voucherRow(strtoupper($code), '0');
        $ctl = $this->probe('control: GRANTED "K" + unused "KU", requested "K"', $code, true);
        $this->assertSame(['E12 login', 'E15 authorize', 'DEV device grant'], $ctl['served_by'], 'the own granted identity stays servable (unused codes are not identities)');
        $this->assertNotServed($this->probe('GRANTED "K" + unused "KU", requested "KU"', strtoupper($code), false));
    }

    public function testALegacyRechargeWithoutAVoucherRow(): void
    {
        $code = self::tag();
        $this->recharge($code);
        $ctl = $this->probe('control: legacy recharge "K" (no voucher row), requested "K"', $code, true);
        $this->assertSame(['E12 login', 'E15 authorize', 'DEV device grant'], $ctl['served_by'], 'legacy deleted-voucher compatibility');
        $this->assertNotServed($this->probe('legacy recharge "K" (no voucher row), requested "KU"', strtoupper($code), false));
    }

    public function testAnEmptyIdentityIsNeverAllowed(): void
    {
        $this->recharge('');
        $out = ['guard empty' => self::guard(''), 'guard blank' => self::guard("  \t"), 'E12 symbols only' => null, 'DEV empty' => $this->device('')];
        $before = self::state(static::$db);
        $out['E12 symbols only'] = $this->e12('!!!');
        $d = self::delta($before, self::state(static::$db));
        $changed = array_merge($d['business'], $d['audit']);
        static::$db->exec("DELETE FROM tbl_user_recharges WHERE username = '' AND customer_id = 0");
        $obs = ['out' => $out, 'radius_write' => array_values(array_intersect($changed, self::RADIUS_TABLES)) !== []];
        $met = !str_contains($out['guard empty'], 'NULL') && !str_contains($out['guard blank'], 'NULL') && $out['E12 symbols only'] !== 'SERVED' && $out['DEV empty'] !== 'SERVED' && !$obs['radius_write'];
        self::record('U0 R-FC-4E empty / blank identity', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        $this->assertStringNotContainsString('NULL', $out['guard empty'], 'an empty identity is refused by the guard itself');
        $this->assertStringNotContainsString('NULL', $out['guard blank']);
        $this->assertNotSame('SERVED', $out['E12 symbols only']);
        $this->assertNotSame('SERVED', $out['DEV empty']);
        $this->assertFalse($obs['radius_write']);
    }

    public function testAReadErrorFailsClosed(): void
    {
        $code = $this->grantedReal();
        static::$db->exec('RENAME TABLE tbl_voucher TO tbl_voucher_w9u0_hidden');
        try {
            $obs = $this->probe('voucher table unreadable (GRANTED "K", requested "K")', $code, false);
        } finally {
            static::$db->exec('RENAME TABLE tbl_voucher_w9u0_hidden TO tbl_voucher');
        }
        $this->assertNotServed($obs);
        $this->assertSame(200, $this->restQ('authorize', $code), 'control: served again once the table is readable');
    }

    public function testMoreEquivalentVoucherRowsThanTheBoundFailClosed(): void
    {
        $code = $this->grantedReal();
        // 1 001 unused voucher rows whose canonical form (PHP trim, utf8mb4_general_ci) equals the code
        $tails = [];
        $ws = [' ', "\t", "\n", "\r", "\x0B", "\x00"];
        foreach ($ws as $a) foreach ($ws as $b) foreach ($ws as $c) foreach ($ws as $d) {
            if (count($tails) < 1001) {
                $tails[] = $a . $b . $c . $d;
            }
        }
        $plan = self::$hotspot;
        $st = static::$db->pdo->prepare("INSERT INTO tbl_voucher (type, routers, id_plan, code, user, status, generated_by) VALUES ('Hotspot', 'radius', ?, ?, '0', '0', 1)");
        foreach ($tails as $t) {
            $st->execute([$plan, $code . $t]);
        }
        try {
            $obs = $this->probe('1 001 equivalent voucher rows (bound 1 000), GRANTED "K", requested "K"', $code, false);
        } finally {
            static::$db->pdo->prepare('DELETE FROM tbl_voucher WHERE code LIKE ? AND status = ? AND CHAR_LENGTH(code) = ?')->execute([$code . '%', '0', strlen($code) + 4]);
        }
        $this->assertNotServed($obs);
        $this->assertSame(200, $this->restQ('authorize', $code), 'control: served again under the bound');
    }
}

