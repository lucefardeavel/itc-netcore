<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;
use Reseller\Tests\Support\StubNas;

/**
 * Owner decision RENEWAL_OPTION_B_APPROVED on the legacy path (unassigned customer, Package::rechargeUser -> the Radius
 * device): the legacy device rule "a new policy takes effect at the next session" ends EVERY open session of the
 * subscriber (so the previous-period session in particular) with ONE Disconnect each, and the end is now CONFIRMED
 * (Accounting-Stop or NAK 503); an unconfirmed end is logged and never undoes the renewal (the invoice stands, the
 * policy is written); accounting history is kept. A first recharge with no session sends nothing.
 */
final class V1LegacyRenewalTest extends RadiusHttpTestCase
{
    private const SECRET = 'v1-legacy-renewal-secret';
    private static int $plan = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'v1lr', 'rate_down' => 2, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', ['name_plan' => 'v1lr_hotspot_gb', 'id_bw' => $bw, 'price' => '5', 'type' => 'Hotspot', 'typebp' => 'Limited', 'limit_type' => 'Data_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 2, 'data_unit' => 'GB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 2,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::$db->exec('DELETE FROM nas');
    }

    private function nas(string $mode): StubNas
    {
        static::$db->exec('DELETE FROM nas');
        $stub = new StubNas(self::SECRET, $mode);
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'v1lr', 'type' => 'other', 'ports' => $stub->port, 'secret' => self::SECRET, 'description' => 'V1 legacy renewal stub']);
        return $stub;
    }

    private function recharge(int $id): string
    {
        return (string) static::$app->exec('$result = Package::rechargeUser(' . $id . ', "radius", ' . self::$plan . ', "Recharge", "admin");');
    }

    private function session(string $user, int $startOffset): array
    {
        $sid = 'v1lr' . bin2hex(random_bytes(3));
        $id = static::$db->insertRow('radacct', ['acctsessionid' => $sid, 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $user, 'nasipaddress' => '127.0.0.1',
            'acctstarttime' => gmdate('Y-m-d H:i:s', time() + $startOffset), 'framedipaddress' => '10.45.0.' . random_int(2, 250)]);
        return ['id' => $id, 'sid' => $sid];
    }

    /** 'radius.disconnect' lines (unconfirmed session ends) in the application error log */
    private function logLines(): int
    {
        return substr_count((string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log'), 'radius.disconnect');
    }

    /** @return list<string> Acct-Session-Id of every Disconnect-Request the NAS received */
    private static function targets(array $received): array
    {
        $out = [];
        foreach ($received as $p) {
            foreach ($p['attributes'] as [$name, $value]) {
                if ($name === 'Acct-Session-Id') {
                    $out[] = (string) $value;
                }
            }
        }
        return array_values(array_unique($out));
    }

    public function testLegacyRenewalEndsAndConfirmsTheRunningSessions(): void
    {
        $u = 'v1lr' . bin2hex(random_bytes(3));
        $id = static::$db->insertRow('tbl_customers', ['username' => $u, 'password' => 'Pw-' . bin2hex(random_bytes(4)), 'pppoe_username' => '', 'pppoe_password' => '',
            'pppoe_ip' => '', 'fullname' => 'V1 ' . $u, 'address' => '', 'phonenumber' => '0700', 'email' => $u . '@example.invalid', 'service_type' => 'Hotspot',
            'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0]);
        // first recharge: no session, nothing to end
        $first = $this->nas('nak503');
        $this->assertStringStartsWith('INV', $this->recharge($id));
        $this->assertSame([], $first->received(), 'a first recharge without a session sends nothing');
        $this->assertSame('2000000000', static::$db->value("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Max-Data'", [$u]), 'GB plan: Max-Data (1 GB = 10^9 bytes)');

        // the session of the previous period: ONE Disconnect (no second one), confirmed by the NAS (the stub NAS answers
        // one request; several sessions are covered by the enforcer / activation tests)
        $old = $this->session($u, -86400);
        $count = (int) static::$db->value('SELECT COUNT(*) FROM radacct');
        $stub = $this->nas('nak503');
        $log = $this->logLines();
        $this->assertStringStartsWith('INV', $this->recharge($id));
        $got = $stub->received();
        $this->assertCount(1, $got, 'one Disconnect, never a second one');
        $this->assertSame([$old['sid']], self::targets($got));
        $this->assertSame($log, $this->logLines(), 'confirmed: nothing logged');
        $this->assertSame($count, (int) static::$db->value('SELECT COUNT(*) FROM radacct'), 'accounting history kept');

        // a silent NAS: the end is not confirmed, the renewal stands (invoice, policy) and the outcome is logged
        $silent = $this->nas('silent');
        $invoices = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE username = ?', [$u]);
        $log = $this->logLines();
        $this->assertStringStartsWith('INV', $this->recharge($id));
        $this->assertCount(3, $silent->received(), 'one Disconnect (+2 identical retransmissions)');
        $this->assertSame($log + 1, $this->logLines(), 'the unconfirmed end is logged');
        $this->assertSame($invoices + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE username = ?', [$u]), 'the renewal is not undone');
        $this->assertNotSame([], self::radcheck($u));
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM radacct WHERE username = ? AND acctstoptime IS NULL', [$u]), 'nothing fabricated: the row stays open until the NAS sends the Stop');
    }
}
