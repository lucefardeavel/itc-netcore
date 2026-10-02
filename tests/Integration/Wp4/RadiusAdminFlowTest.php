<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * The real admin flows with the Radius device (owner decisions Q5, C17; SEC-17):
 *  - a recharge through Package::rechargeUser provisions the SAVED billing expiry (the device now runs after
 *    the recharge row is saved) and keeps the accounting history; so do a renewal and an expiry edit;
 *  - a RADIUS plan delete (PlanGuard-serialized) removes the plan's group rows;
 *  - the NAS edit form never renders the secret; NAS input is validated.
 */
final class RadiusAdminFlowTest extends RadiusHttpTestCase
{
    private static int $plan;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp4_flow', 'rate_down' => 6, 'rate_down_unit' => 'Mbps', 'rate_up' => 3, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp4_flow_plan', 'id_bw' => $bw, 'price' => '1000', 'type' => 'Hotspot', 'typebp' => 'Unlimited',
            'limit_type' => 'Time_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB',
            'validity' => 7, 'validity_unit' => 'Days', 'shared_users' => 1, 'routers' => 'radius', 'is_radius' => 1,
            'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
    }

    private function expectedExpiration(int $customerId): string
    {
        $row = static::$db->rows("SELECT expiration, time FROM tbl_user_recharges WHERE customer_id = ? AND plan_id = ? AND status = 'on' ORDER BY id DESC", [$customerId, self::$plan])[0];
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['expiration'] . ' ' . $row['time'])->format('d M Y H:i:s');
    }

    public function testRechargeRenewalAndEditProvisionTheSavedBillingExpiry(): void
    {
        $cid = static::$seed->customers['cNULL2'];
        static::radacct('cNULL2', 'hist-1', gmdate('Y-m-d H:i:s', time() - 3600));
        static::radacct('cNULL2', 'hist-2', null);
        $history = static::radacctChecksum();
        $sa = $this->staff('sa');
        $recharge = fn() => $sa->postWithToken('plan/recharge-post', ['id_customer' => $cid, 'server' => 'radius', 'plan' => self::$plan, 'using' => 'cash']);

        $this->assertContains($recharge()->status, [200, 302]);
        $first = $this->expectedExpiration($cid);
        $check = static::radcheck('cNULL2');
        $this->assertSame($first, $check['Expiration'] ?? null, 'activation: the saved billing expiry');
        $this->assertSame('cpw-cNULL2', $check['Cleartext-Password']);
        $this->assertSame(['plan_' . self::$plan], array_column(static::$db->rows('SELECT groupname FROM radusergroup WHERE username = ?', ['cNULL2']), 'groupname'));
        $this->assertSame($history, static::radacctChecksum(), 'activation keeps the accounting history');

        $this->assertContains($recharge()->status, [200, 302]);
        $second = $this->expectedExpiration($cid);
        $this->assertSame($second, static::radcheck('cNULL2')['Expiration'], 'renewal: the saved (possibly extended) billing expiry');
        $this->assertSame($history, static::radacctChecksum(), 'renewal keeps the accounting history');

        $rid = (int) static::$db->value("SELECT id FROM tbl_user_recharges WHERE customer_id = ? AND plan_id = ? AND status = 'on' ORDER BY id DESC", [$cid, self::$plan]);
        $r = $sa->postWithToken('plan/edit-post', ['id' => $rid, 'id_plan' => self::$plan, 'recharged_on' => gmdate('Y-m-d'), 'expiration' => '2032-02-02', 'time' => '10:00:00']);
        $this->assertSame(302, $r->status);
        $this->assertSame('02 Feb 2032 10:00:00', static::radcheck('cNULL2')['Expiration'], 'an expiry edit now reaches RADIUS');
        $this->assertSame($history, static::radacctChecksum());
    }

    public function testRadiusPlanDeleteRemovesTheGroupRows(): void
    {
        $plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp4_del_plan', 'id_bw' => 1, 'price' => '1', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 1, 'validity_unit' => 'Days',
            'shared_users' => 1, 'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        static::$db->insertRow('radgroupreply', ['groupname' => 'plan_' . $plan, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => '1M/1M', 'plan_id' => $plan]);
        $sa = $this->staff('sa');
        $r = $sa->postWithToken('services/delete/' . $plan, [], ['token' => $sa->token()]);
        $this->assertSame(302, $r->status);
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_plans WHERE id = ?', [$plan]), 'plan deleted');
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM radgroupreply WHERE groupname = ?', ['plan_' . $plan]), 'RADIUS group removed through the device');
    }

    public function testNasSecretIsNeverRenderedAndInputIsValidated(): void
    {
        $sa = $this->staff('sa');
        $count = (int) static::$db->value('SELECT COUNT(*) FROM nas');
        foreach ([['nasname' => 'bad host!', 'secret' => 's'], ['nasname' => '192.0.2.10', 'secret' => ''], ['nasname' => '192.0.2.10', 'secret' => 's', 'ports' => '70000']] as $bad) {
            $sa->postWithToken('radius/nas-add-post', $bad + ['shortname' => 'wp4nas', 'description' => 'x']);
        }
        $this->assertSame($count, (int) static::$db->value('SELECT COUNT(*) FROM nas'), 'invalid NAS input refused');
        $secret = 'Nas-Secret-' . bin2hex(random_bytes(5));
        $sa->postWithToken('radius/nas-add-post', ['shortname' => 'wp4nas', 'nasname' => '192.0.2.10', 'secret' => $secret, 'ports' => '3799', 'description' => 'x']);
        $id = (int) static::$db->value("SELECT id FROM nas WHERE nasname = '192.0.2.10'");
        $this->assertGreaterThan(0, $id);
        $page = $sa->get('radius/nas-edit/' . $id);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('192.0.2.10', $page->body);
        $this->assertStringNotContainsString($secret, $page->body, 'the stored secret is never sent back');
        $sa->postWithToken('radius/nas-edit-post/' . $id, ['shortname' => 'wp4nas2', 'nasname' => '192.0.2.10', 'secret' => '', 'ports' => '3799', 'description' => 'x']);
        $this->assertSame(['wp4nas2', $secret], array_values(static::$db->rows('SELECT shortname, secret FROM nas WHERE id = ?', [$id])[0]), 'empty secret keeps the stored one');
        $sa->postWithToken('radius/nas-edit-post/' . $id, ['shortname' => 'wp4nas2', 'nasname' => '192.0.2.10', 'secret' => 'rotated-secret', 'ports' => '3799', 'description' => 'x']);
        $this->assertSame('rotated-secret', static::$db->value('SELECT secret FROM nas WHERE id = ?', [$id]));
    }
}
