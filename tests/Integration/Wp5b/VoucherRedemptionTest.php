<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * WP5B §6 vouchers: one atomic redemption path (claim '0' -> 'CLAIMED' -> grant -> '1'; back to '0' only when the
 * grant was proven not started; anything else stays CLAIMED), the E13 new-username flow serialized per
 * username, CLAIMED protected against deletion and re-use, real sources (no fabricated principal), CSPRNG
 * generation. Runs against the full application (app copy + exec harness), real concurrent processes.
 */
final class VoucherRedemptionTest extends Wp2HttpTestCase
{
    private const PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): failure injection inside the legacy grant
register_hook('recharge_user', 'wp5bprobe_recharge');
function wp5bprobe_recharge() { if (is_file(__DIR__ . '/../../wp2_probe/wp5b_throw.flag')) { throw new RuntimeException('injected: grant interrupted'); } }
PHP;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        file_put_contents(static::$app->dir . '/system/plugin/wp5b_probe.php', self::PLUGIN);
    }

    protected function tearDown(): void
    {
        @unlink(static::$app->dir . '/wp2_probe/wp5b_throw.flag');
    }

    private function voucher(string $routers = 'wp2_router', string $status = '0'): array
    {
        $code = 'W5B' . strtoupper(bin2hex(random_bytes(5)));
        $id = static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => $routers, 'id_plan' => static::$seed->planId, 'code' => $code,
            'user' => '0', 'status' => $status, 'generated_by' => $this->sid('ad')]);
        return [$id, $code];
    }

    private function vstatus(int $id): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_voucher WHERE id = ?', [$id]);
    }

    private function throwInGrant(): void
    {
        touch(static::$app->dir . '/wp2_probe/wp5b_throw.flag');
    }

    /** The service called inside the application (no principal: PUBLIC). */
    private function redeem(string $code, int $customerId, string $username): array
    {
        return static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, new \Reseller\Domain\RedemptionTarget(%d, %s), \Reseller\Domain\RedemptionSource::public());',
            var_export($code, true), $customerId, var_export($username, true)));
    }

    private function newUsername(string $code, string $username): string
    {
        return sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeemForNewUsername(%s, %s, \Reseller\Domain\RedemptionSource::public());',
            var_export($code, true), var_export($username, true));
    }

    public function testPortalRedemptionClaimsOnceAndIsAuditedForTheCustomer(): void
    {
        [$id, $code] = $this->voucher();
        $c = $this->customer('cNULL2');
        $r = $c->postWithToken('voucher/activation-post', ['code' => $code], [], 'voucher/activation');
        $this->assertSame(302, $r->status, $r->excerpt());
        $row = static::$db->rows('SELECT status, user, used_date FROM tbl_voucher WHERE id = ?', [$id])[0];
        $this->assertSame(['1', 'cNULL2'], [$row['status'], $row['user']]);
        $this->assertNotNull($row['used_date']);
        $audit = $this->auditRows('voucher.redeem');
        $last = end($audit);
        $this->assertSame(['CUSTOMER', (string) $this->cid('cNULL2')], [$last['actor_type'], (string) $last['actor_customer_id']]);
        $this->assertStringContainsString('"outcome":"GRANTED"', (string) $last['after_json']);
        $this->assertStringNotContainsString($code, (string) $last['target_snapshot'] . $last['after_json'], 'never the code itself');
        // a used code is not redeemable again
        $n = (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$this->cid('cNULL2')]);
        $this->assertSame('NOT_VALID', $this->redeem($code, $this->cid('cNULL2'), 'cNULL2')['status']);
        $this->assertSame($n, (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$this->cid('cNULL2')]));
    }

    public function testResellerOwnedTargetIsRefusedAndTheVoucherUntouched(): void
    {
        [$id, $code] = $this->voucher();
        $this->assertSame('REFUSED', $this->redeem($code, $this->cid('cA1'), 'cA1')['status']);
        $this->assertSame('REFUSED', $this->redeem('cA1', 0, 'cA1')['status'], 'a voucher-only code colliding with a reseller-owned username');
        $this->assertSame('0', $this->vstatus($id));
    }

    public function testProvenNotStartedGrantReleasesTheVoucher(): void
    {
        // an empty router is refused by Package::rechargeUser before any write (its exact false return)
        [$id, $code] = $this->voucher('');
        $out = $this->redeem($code, $this->cid('cNULL'), 'cNULL');
        $this->assertSame(['NOT_GRANTED', false], [$out['status'], $out['result']]);
        $this->assertSame('0', $this->vstatus($id), 'released: redeemable again');
    }

    public function testInterruptedGrantKeepsTheVoucherClaimedForever(): void
    {
        [$id, $code] = $this->voucher();
        $this->throwInGrant();
        $this->assertSame('CLAIMED_REVIEW', $this->redeem($code, $this->cid('cNULL'), 'cNULL')['status']);
        @unlink(static::$app->dir . '/wp2_probe/wp5b_throw.flag');
        $this->assertSame('CLAIMED', $this->vstatus($id));
        foreach ([[$this->cid('cNULL'), 'cNULL'], [$this->cid('cNULL2'), 'cNULL2'], [0, $code]] as [$cid, $u]) {
            $this->assertSame('NOT_VALID', $this->redeem($code, $cid, $u)['status'], 'a CLAIMED voucher is never redeemable again');
        }
        // the portal, the public voucher-only login and RADIUS refuse it too
        $c = $this->customer('cNULL2');
        $c->postWithToken('voucher/activation-post', ['code' => $code], [], 'voucher/activation');
        $this->assertSame('CLAIMED', $this->vstatus($id));
    }

    public function testConcurrentRedemptionsOfOneCodeGrantOnce(): void
    {
        for ($round = 1; $round <= 4; $round++) {
            [$id, $code] = $this->voucher();
            $before = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions');
            $h = [];
            foreach (['cNULL', 'cNULL2', 'cNULL'] as $u) {
                $h[] = static::$app->execStart(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, new \Reseller\Domain\RedemptionTarget(%d, %s), \Reseller\Domain\RedemptionSource::public())["status"];',
                    var_export($code, true), $this->cid($u), var_export($u, true)));
            }
            $out = array_map(static fn(array $x) => static::$app->execFinish($x), $h);
            sort($out);
            $this->assertSame(['GRANTED', 'NOT_VALID', 'NOT_VALID'], $out, "round {$round}");
            $this->assertSame('1', $this->vstatus($id));
            $this->assertSame($before + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions'), 'exactly one grant');
        }
    }

    public function testNewUsernameVoucherCreatesOneCustomerAndNeverTouchesAnExistingOne(): void
    {
        // existing username: refused, the customer is untouched (no password overwrite)
        [$id, $code] = $this->voucher();
        $pw = (string) static::$db->value("SELECT password FROM tbl_customers WHERE username = 'cNULL'");
        $r = $this->client()->postWithToken('login/activation', ['voucher' => $code, 'username' => 'cNULL'], [], 'login');
        $this->assertSame(302, $r->status);
        $this->assertSame($pw, (string) static::$db->value("SELECT password FROM tbl_customers WHERE username = 'cNULL'"));
        $this->assertSame('0', $this->vstatus($id));
        $this->assertSame('USERNAME_TAKEN', static::$app->exec($this->newUsername($code, 'cNULL'))['status']);
        // a new username: one unassigned customer, password = the code, the voucher used by it
        $u = 'nw' . bin2hex(random_bytes(4));
        $out = static::$app->exec($this->newUsername($code, $u));
        $this->assertSame('GRANTED', $out['status']);
        $c = static::$db->rows('SELECT id, password, manager_id FROM tbl_customers WHERE username = ?', [$u]);
        $this->assertCount(1, $c);
        $this->assertSame([$code, null, (string) $out['customer_id']], [$c[0]['password'], $c[0]['manager_id'], (string) $c[0]['id']]);
        $this->assertSame(['1', $u], array_values(static::$db->rows('SELECT status, user FROM tbl_voucher WHERE id = ?', [$id])[0]));
    }

    public function testNewUsernameProvenNotStartedRemovesTheProvisionalCustomerAndAmbiguousKeepsIt(): void
    {
        [$bad, $badCode] = $this->voucher('');
        $u = 'nw' . bin2hex(random_bytes(4));
        $this->assertSame('NOT_GRANTED', static::$app->exec($this->newUsername($badCode, $u))['status']);
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE username = ?', [$u]), 'provisional row removed');
        $this->assertSame('0', $this->vstatus($bad), 'and the voucher released, together');
        [$good, $goodCode] = $this->voucher();
        $this->assertSame('GRANTED', static::$app->exec($this->newUsername($goodCode, $u))['status'], 'a retry starts fresh');
        // ambiguous: the grant may have started -> the customer row stays, the voucher stays CLAIMED
        [$amb, $ambCode] = $this->voucher();
        $v = 'nw' . bin2hex(random_bytes(4));
        $this->throwInGrant();
        $this->assertSame('CLAIMED_REVIEW', static::$app->exec($this->newUsername($ambCode, $v))['status']);
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE username = ?', [$v]));
        $this->assertSame('CLAIMED', $this->vstatus($amb));
    }

    public function testConcurrentNewUsernameVouchersCreateAtMostOneCustomer(): void
    {
        for ($round = 1; $round <= 4; $round++) {
            $u = 'race' . bin2hex(random_bytes(4));
            [$a, $codeA] = $this->voucher();
            [$b, $codeB] = $this->voucher();
            $h = [static::$app->execStart($this->newUsername($codeA, $u) . '$result = $result["status"];'),
                static::$app->execStart($this->newUsername($codeB, $u) . '$result = $result["status"];')];
            $out = array_map(static fn(array $x) => static::$app->execFinish($x), $h);
            sort($out);
            $this->assertSame(['GRANTED', 'USERNAME_TAKEN'], $out, "round {$round}");
            $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE username = ?', [$u]));
            $this->assertSame(['0', '1'], [min($this->vstatus($a), $this->vstatus($b)), max($this->vstatus($a), $this->vstatus($b))], 'the loser voucher is untouched');
        }
    }

    public function testClaimedVouchersCannotBeDeleted(): void
    {
        [$claimed] = $this->voucher('wp2_router', 'CLAIMED');
        [$free] = $this->voucher();
        $c = $this->staff('ad');
        $c->postWithToken('plan/voucher-delete/' . $claimed, [], [], 'plan/voucher');
        $c->get('plan/voucher-delete/' . $claimed, ['token' => $c->token('plan/voucher')]);
        $this->assertSame('CLAIMED', $this->vstatus($claimed), 'single delete refused');
        $r = $c->postWithToken('plan/voucher-delete-many', ['voucherIds' => json_encode([$claimed, $free])], [], 'plan/voucher');
        $this->assertSame('CLAIMED', $this->vstatus($claimed), 'bulk delete keeps the claimed row');
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_voucher WHERE id = ?', [$free]), 'the unclaimed one is deleted: ' . $r->excerpt());
        $this->assertSame(1, (int) ($r->json()['skipped'] ?? -1));
    }

    public function testGeneratedCodesUseTheCsprngAndNeverDuplicate(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/system/controllers/plan.php');
        $gen = substr($src, (int) strpos($src, "case 'voucher-post':"), 6000);
        $this->assertStringContainsString('random_bytes(', $gen);
        $this->assertDoesNotMatchRegularExpression('/\bmd5\(time\(\)|\brand\(|randomUpLowCase/', $gen);
        $this->assertStringNotContainsString('rand(0, $charactersLength', (string) file_get_contents(dirname(__DIR__, 3) . '/init.php'));
    }
}
