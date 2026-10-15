<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp9Evidence.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9-A1 (owner: WP9_A1_V1_BOUNDED_REMEDIATION_AUTHORIZED) - regression of OBS-W9-V1, written test-first.
 *
 * Security contract: a voucher-only redemption is refused when its effective RADIUS identity (the code after the
 * RadiusPolicyBuilder::key() trim, compared under the RADIUS collation utf8mb4_general_ci) equals the username or the
 * PPPoE username (same canonical rule) of ANY existing customer, owned or unassigned - before the voucher is claimed and
 * before any recharge / invoice / RADIUS write; the voucher stays redeemable. Legitimate voucher-only grants, the
 * existing-customer voucher path and the reseller-owned refusal (V3) are unchanged; voucher generation never issues a
 * code equal to a customer identity. The check-to-grant window that remains (no shared identity lock with customer
 * creation; AM-W9-1 / U0 not authorized) is characterised, not hidden.
 *
 * Disposable application copy + database, synthetic data, no FreeRADIUS process, no device beyond the Radius tables,
 * no SQL fault injection. Each case records its observation (Wp9Evidence) BEFORE asserting the contract, so the
 * pre-fix run documents the defect.
 */
final class A1VoucherIdentityGuardTest extends RadiusHttpTestCase
{
    use Wp9Evidence;

    private static int $hotspot = 0;
    private static int $pppoe = 0;

    private const RACE_PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): WP9-A1 race characterisation. Inside Package::rechargeUser (hook 'recharge_user',
// before the device call) and only while the flag file exists, commit a customer and its RADIUS password under the
// voucher code: a creation + grant that lands between the voucher identity check and the voucher grant.
register_hook('recharge_user', 'wp9a1_race');
function wp9a1_race() {
    $flag = __DIR__ . '/../../wp2_probe/wp9a1_race.json';
    if (!is_file($flag)) { return; }
    $j = json_decode((string) file_get_contents($flag), true);
    unlink($flag);
    $pdo = ORM::get_db();
    $pdo->prepare("INSERT INTO tbl_customers (username, password, fullname, address, email, phonenumber) VALUES (?, ?, 'W9 race', '', '', '0700')")->execute([$j['code'], $j['password']]);
    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$j['code'], $j['password']]);
}
PHP;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        self::$hotspot = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9a1_radius_hotspot', 'type' => 'Hotspot', 'device' => 'Radius',
            'routers' => 'radius', 'is_radius' => 1] + $seed);
        self::$pppoe = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9a1_radius_pppoe', 'type' => 'PPPOE', 'device' => 'Radius',
            'routers' => 'radius', 'is_radius' => 1] + $seed);
        file_put_contents(static::$app->dir . '/system/plugin/wp9a1_race.php', self::RACE_PLUGIN);
    }

    protected function tearDown(): void
    {
        @unlink(static::$app->dir . '/wp2_probe/wp9a1_race.json');
    }

    private function person(string $username, string $pppoe = '', ?string $manager = null): array
    {
        $row = ['username' => $username, 'password' => 'Pw-' . bin2hex(random_bytes(4)), 'pppoe_username' => $pppoe,
            'pppoe_password' => $pppoe !== '' ? 'Pp-' . bin2hex(random_bytes(4)) : '', 'pppoe_ip' => '', 'fullname' => 'W9 A1', 'address' => '',
            'phonenumber' => '0700', 'email' => 'w9a1@example.invalid', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '',
            'status' => 'Active', 'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0, 'manager_id' => $manager !== null ? $this->sid($manager) : null];
        $row['id'] = static::$db->insertRow('tbl_customers', $row);
        return $row;
    }

    /** the customer's own legitimate grant (writes its RADIUS policy under its RADIUS key) */
    private function provision(array $c, int $plan): void
    {
        $r = static::$app->exec(sprintf('$result = Package::rechargeUser(%d, "radius", %d, "Recharge", "admin");', $c['id'], $plan));
        $this->assertMatchesRegularExpression('/^INV-/', (string) $r, 'control: the customer is provisioned');
    }

    private function voucher(string $code, int $plan, string $routers = 'radius'): int
    {
        return static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => $routers, 'id_plan' => $plan, 'code' => $code,
            'user' => '0', 'status' => '0', 'generated_by' => $this->sid('ad')]);
    }

    private function vstatus(int $id): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_voucher WHERE id = ?', [$id]);
    }

    private function redeemVoucherOnly(string $code): string
    {
        return (string) static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, \Reseller\Domain\RedemptionTarget::voucherOnly(%s), \Reseller\Domain\RedemptionSource::public())["status"];',
            var_export($code, true), var_export($code, true)));
    }

    /** exact-byte RADIUS rows of one key (attribute, op, value) */
    private static function exact(string $key): array
    {
        return static::$db->rows('SELECT attribute, op, value FROM radcheck WHERE BINARY username = ? ORDER BY attribute, id', [$key]);
    }

    /** @return array<string,array{0:string,1:string,2:string,3:string}> stored username, stored pppoe, provisioned plan, voucher code ({t} = tag) */
    public static function collisions(): array
    {
        return [
            'V1 reproduced: code = username (exact)' => ['{t}', '', 'hotspot', '{t}'],
            'code = username (upper case)' => ['{t}', '', 'hotspot', 'UP:{t}'],
            'stored username with a leading space' => [' {t}', '', 'hotspot', '{t}'],
            'stored username with a trailing space' => ['{t} ', '', 'hotspot', '{t}'],
            'stored username with a trailing TAB' => ["{t}\t", '', 'hotspot', '{t}'],
            'accent (stored é, code e)' => ['{t}é', '', 'hotspot', '{t}e'],
            'code with a trailing space = username' => ['{t}', '', 'hotspot', '{t} '],
            'cross-field: code = PPPoE username' => ['{t}u', '{t}p', 'pppoe', '{t}p'],
            'cross-field: code = PPPoE username (upper case)' => ['{t}u', '{t}p', 'pppoe', 'UP:{t}p'],
            'cross-field: stored PPPoE username with a leading space' => ['{t}u', ' {t}p', 'pppoe', '{t}p'],
        ];
    }

    #[DataProvider('collisions')]
    public function testVoucherOnlyRedemptionCollidingWithACustomerIdentityIsRefusedBeforeAnyWrite(string $u, string $p, string $plan, string $code): void
    {
        $t = 'w9a1' . bin2hex(random_bytes(3));
        $f = static fn(string $s): string => str_starts_with($s, 'UP:') ? strtoupper(str_replace('{t}', $t, substr($s, 3))) : str_replace('{t}', $t, $s);
        $c = $this->person($f($u), $f($p));
        $this->provision($c, $plan === 'pppoe' ? self::$pppoe : self::$hotspot);
        $key = trim($plan === 'pppoe' ? $c['pppoe_username'] : $c['username']);
        $mine = self::exact($key);
        $this->assertNotEmpty($mine, 'control: the customer holds its RADIUS policy');
        $code = $f($code);
        $vid = $this->voucher($code, self::$hotspot);
        $before = self::state(static::$db);
        $status = $this->redeemVoucherOnly($code);
        $d = self::delta($before, self::state(static::$db));
        $obs = ['customer_key' => $key, 'code' => $code, 'status' => $status, 'voucher_status_after' => $this->vstatus($vid), 'state_delta' => $d,
            'customer_radius_policy_unchanged' => self::exact($key) === $mine];
        $met = $status === 'REFUSED' && $obs['voucher_status_after'] === '0' && $d['business'] === [] && $obs['customer_radius_policy_unchanged'];
        self::record('A1 ' . $this->dataName(), $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        $this->assertSame('REFUSED', $status, 'refused');
        $this->assertSame('0', $obs['voucher_status_after'], 'the voucher stays redeemable (never claimed)');
        $this->assertSame([], $d['business'], 'no business / finance / identity / RADIUS state change');
        $this->assertTrue($obs['customer_radius_policy_unchanged'], 'the customer RADIUS policy is unchanged');
    }

    public function testLegitimateVoucherOnlyGrantIsUnchanged(): void
    {
        $code = 'w9a1ok' . bin2hex(random_bytes(3));
        $vid = $this->voucher($code, self::$hotspot);
        $status = $this->redeemVoucherOnly($code);
        $obs = ['status' => $status, 'voucher_status_after' => $this->vstatus($vid), 'radcheck_rows' => count(self::exact($code))];
        self::record('A1 control: legitimate voucher-only grant', $status === 'GRANTED' ? 'CONTROL_PASS' : 'CONTROL_FAIL', $obs);
        $this->assertSame('GRANTED', $status);
        $this->assertSame('1', $obs['voucher_status_after']);
        $this->assertGreaterThan(0, $obs['radcheck_rows']);
    }

    public function testExistingCustomerVoucherPathIsUnchangedEvenWhenTheCodeEqualsAnotherCustomersName(): void
    {
        $t = 'w9a1x' . bin2hex(random_bytes(3));
        $other = $this->person("{$t}o");
        $this->provision($other, self::$hotspot);
        $otherRows = self::exact("{$t}o");
        $me = $this->person("{$t}m");
        $vid = $this->voucher("{$t}o", self::$hotspot);
        $status = (string) static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, new \Reseller\Domain\RedemptionTarget(%d, %s), \Reseller\Domain\RedemptionSource::public())["status"];',
            var_export("{$t}o", true), $me['id'], var_export($me['username'], true)));
        $obs = ['status' => $status, 'voucher_status_after' => $this->vstatus($vid), 'other_customer_policy_unchanged' => self::exact("{$t}o") === $otherRows,
            'target_customer_rows' => count(self::exact("{$t}m"))];
        $ok = $status === 'GRANTED' && $obs['other_customer_policy_unchanged'] && $obs['target_customer_rows'] > 0;
        self::record('A1 control: existing-customer voucher path', $ok ? 'CONTROL_PASS' : 'CONTROL_FAIL', $obs);
        $this->assertSame('GRANTED', $status, 'the customer voucher path is not over-blocked');
        $this->assertTrue($obs['other_customer_policy_unchanged']);
        $this->assertGreaterThan(0, $obs['target_customer_rows']);
    }

    public function testResellerOwnedRefusalIsUnchanged(): void
    {
        $o = $this->person('w9a1v3' . bin2hex(random_bytes(3)), '', 'A');
        $vid = $this->voucher($o['username'], self::$hotspot);
        $before = self::state(static::$db);
        $status = $this->redeemVoucherOnly($o['username']);
        $d = self::delta($before, self::state(static::$db));
        $obs = ['status' => $status, 'voucher_status_after' => $this->vstatus($vid), 'state_delta' => $d];
        self::record('A1 control: V3 reseller-owned refusal', $status === 'REFUSED' && $d['business'] === [] ? 'CONTROL_PASS' : 'CONTROL_FAIL', $obs);
        $this->assertSame('REFUSED', $status);
        $this->assertSame('0', $obs['voucher_status_after']);
        $this->assertSame([], $d['business']);
    }

    public function testARefusedVoucherStaysRedeemableAndIsGrantedOnceTheIdentityIsFree(): void
    {
        $u = $this->person('w9a1r' . bin2hex(random_bytes(3)));
        $this->provision($u, self::$hotspot);
        $vid = $this->voucher($u['username'], self::$hotspot);
        $first = $this->redeemVoucherOnly($u['username']);
        $second = $this->redeemVoucherOnly($u['username']);
        // the colliding customer is renamed away (test fixture): the same code is now a free identity
        static::$db->pdo->prepare('UPDATE tbl_customers SET username = ? WHERE id = ?')->execute([$u['username'] . 'z', $u['id']]);
        static::$db->pdo->prepare('UPDATE radcheck SET username = ? WHERE BINARY username = ?')->execute([$u['username'] . 'z', $u['username']]);
        $third = $this->redeemVoucherOnly($u['username']);
        $fourth = $this->redeemVoucherOnly($u['username']);
        $obs = ['first' => $first, 'replay' => $second, 'after_rename' => $third, 'after_grant_replay' => $fourth, 'voucher_status_after' => $this->vstatus($vid)];
        $met = [$first, $second, $third, $fourth] === ['REFUSED', 'REFUSED', 'GRANTED', 'NOT_VALID'];
        self::record('A1 refused voucher stays redeemable; replay', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        $this->assertSame(['REFUSED', 'REFUSED', 'GRANTED', 'NOT_VALID'], [$first, $second, $third, $fourth]);
        $this->assertSame('1', $obs['voucher_status_after']);
    }

    public function testVoucherGenerationNeverIssuesACodeEqualToACustomerIdentity(): void
    {
        $sa = $this->staff('sa');
        // positive control: an unrelated prefix generates the requested batch
        $okPrefix = 'w9gok' . bin2hex(random_bytes(2));
        $sa->postWithToken('plan/voucher-post', ['type' => 'Hotspot', 'plan' => self::$hotspot, 'server' => 'radius', 'voucher_format' => 'up',
            'prefix' => $okPrefix, 'numbervoucher' => '3', 'lengthcode' => '6', 'print_now' => 'no']);
        $okCodes = array_column(static::$db->rows('SELECT code FROM tbl_voucher WHERE code LIKE ?', [$okPrefix . '%']), 'code');
        // every possible code of the colliding batch (prefix + 1 hex character) equals a customer username
        $px = 'w9gx' . bin2hex(random_bytes(2));
        foreach (str_split('0123456789abcdef') as $ch) {
            $this->person($px . $ch);
        }
        $sa->postWithToken('plan/voucher-post', ['type' => 'Hotspot', 'plan' => self::$hotspot, 'server' => 'radius', 'voucher_format' => 'up',
            'prefix' => $px, 'numbervoucher' => '1', 'lengthcode' => '1', 'print_now' => 'no']);
        $bad = array_column(static::$db->rows('SELECT code FROM tbl_voucher WHERE code LIKE ?', [$px . '%']), 'code');
        $obs = ['control_codes' => count($okCodes), 'control_unique' => count(array_unique($okCodes)) === count($okCodes),
            'colliding_batch_codes_created' => count($bad)];
        self::record('A1 voucher generation', count($okCodes) === 3 && $bad === [] ? 'CONTRACT_MET' : (count($okCodes) === 3 ? 'DEFECT_REPRODUCED' : 'CONTROL_FAIL'), $obs);
        $this->assertCount(3, $okCodes, 'control: the batch is generated');
        $this->assertTrue($obs['control_unique'], 'control: batch uniqueness');
        $this->assertSame([], $bad, 'no voucher whose code equals a customer identity is issued');
    }

    /** characterisation (not a contract): the window between the identity check and the grant */
    public function testRemainingCheckToGrantWindowIsCharacterised(): void
    {
        $code = 'w9a1race' . bin2hex(random_bytes(3));
        $pw = 'Pw-' . bin2hex(random_bytes(4));
        $vid = $this->voucher($code, self::$hotspot);
        file_put_contents(static::$app->dir . '/wp2_probe/wp9a1_race.json', json_encode(['code' => $code, 'password' => $pw]));
        $status = $this->redeemVoucherOnly($code);
        $hookRan = !is_file(static::$app->dir . '/wp2_probe/wp9a1_race.json');
        $rows = self::exact($code);
        $pwRows = array_values(array_filter($rows, static fn(array $r): bool => $r['attribute'] === 'Cleartext-Password'));
        $overwritten = $pwRows !== [] && $pwRows[0]['value'] !== $pw;
        self::record('A1 remaining check-to-grant window', $overwritten ? 'RACE_REMAINS' : 'OBSERVED', ['status' => $status, 'hook_ran' => $hookRan,
            'voucher_status_after' => $this->vstatus($vid), 'concurrent_customer_password_overwritten' => $overwritten,
            'detail' => 'a customer + RADIUS password committed after the identity check and before the voucher grant (barrier in Package::rechargeUser hook recharge_user)']);
        $this->assertTrue($hookRan, 'control: the barrier ran inside the grant');
    }
}
