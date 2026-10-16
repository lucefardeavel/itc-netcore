<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;
use Reseller\Tests\Support\StubNas;

/**
 * WP9 / W8-17 (R2 audit / UI): the Admin rename of an UNASSIGNED legacy customer (customers/edit-post) ends the old
 * name's open sessions like the reseller path and now also records the outcome: a 'rename.old_session' audit event
 * (operation LEGACY_RENAME, per-session result, no secret) and the operator sees a warning when the end of the old
 * sessions is not confirmed (the drift report lists it as OLD_SESSION_UNCONFIRMED). The Disconnect behaviour itself is
 * unchanged (1 + 2 identical sends to a silent NAS). Positive control: a NAS that answers NAK 503 is CONFIRMED.
 */
final class W9LegacyRenameAuditTest extends RadiusHttpTestCase
{
    private const SECRET = 'w9-legacy-rename-secret';
    private static int $plan = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'w9lr', 'rate_down' => 2, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9lr_hotspot', 'id_bw' => $bw, 'price' => '5', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::$db->exec('DELETE FROM nas');
    }

    /** an unassigned legacy customer with an active Radius plan and an open accounting row of its name */
    private function served(): array
    {
        $u = 'w9lr' . bin2hex(random_bytes(3));
        $id = static::$db->insertRow('tbl_customers', ['username' => $u, 'password' => 'Pw-' . bin2hex(random_bytes(4)), 'pppoe_username' => '', 'pppoe_password' => '',
            'pppoe_ip' => '', 'fullname' => 'W9 ' . $u, 'address' => '', 'phonenumber' => '0700', 'email' => $u . '@example.invalid', 'service_type' => 'Hotspot',
            'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active', 'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0]);
        $inv = static::$app->exec('$result = Package::rechargeUser(' . $id . ', "radius", ' . self::$plan . ', "Recharge", "admin");');
        $this->assertStringStartsWith('INV', (string) $inv);
        $this->assertNotSame([], self::radcheck($u));
        $acct = static::$db->insertRow('radacct', ['acctsessionid' => 'w9lr' . bin2hex(random_bytes(2)), 'acctuniqueid' => substr(md5($u . microtime()), 0, 32), 'username' => $u,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 600), 'framedipaddress' => '10.44.0.' . random_int(2, 250)]);
        return ['id' => $id, 'username' => $u, 'radacctid' => $acct];
    }

    private function nas(string $mode): StubNas
    {
        $stub = new StubNas(self::SECRET, $mode);
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'w9lr', 'type' => 'other', 'ports' => $stub->port, 'secret' => self::SECRET, 'description' => 'W9 stub']);
        return $stub;
    }

    private function rename(array $c, string $new): \Reseller\Tests\Support\HttpResponse
    {
        $sa = $this->staff('sa');
        $r = $sa->postWithToken('customers/edit-post', ['id' => $c['id'], 'username' => $new, 'fullname' => 'W9 ' . $c['username'], 'account_type' => 'Personal', 'password' => '',
            'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '', 'email' => $c['username'] . '@example.invalid', 'address' => '', 'phonenumber' => '0700',
            'service_type' => 'Hotspot', 'coordinates' => '', 'status' => 'Active']);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame($new, static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$c['id']]), 'renamed');
        return $sa->get('customers/view/' . $c['id']);
    }

    /** @return list<array<string,mixed>> */
    private function confirmations(int $c): array
    {
        return array_map(static fn(array $r): array => json_decode((string) $r['after_json'], true),
            static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'rename.old_session' AND target_customer_id = ? ORDER BY id", [$c]));
    }

    public function testUnconfirmedLegacyRenameIsAuditedAndShownAsAWarning(): void
    {
        $c = $this->served();
        $stub = $this->nas('silent');
        $new = 'w9lrn' . bin2hex(random_bytes(3));
        $page = $this->rename($c, $new);
        $this->assertCount(3, $stub->received(), 'the old session was targeted: 1 + 2 identical sends, unchanged');
        $this->assertSame([], self::radcheck($c['username']));
        $this->assertNotSame([], self::radcheck($new));
        $conf = $this->confirmations($c['id']);
        $this->assertCount(1, $conf, 'one rename.old_session audit event');
        $this->assertSame('UNCONFIRMED', $conf[0]['old_session']);
        $this->assertSame('LEGACY_RENAME', $conf[0]['operation']);
        $this->assertSame([$c['radacctid']], array_map(static fn(array $s): int => (int) $s['radacctid'], $conf[0]['identities'][0]['sessions']));
        $this->assertStringNotContainsString(self::SECRET, json_encode($conf));
        $this->assertStringContainsString('could not be confirmed', $page->body, 'the operator is warned');
        $drift = static::$app->exec('$result = array_values(array_filter(\Reseller\Activation\DriftReport::collect(false), fn($f) => $f["kind"] === "OLD_SESSION_UNCONFIRMED" && (int) $f["customer_id"] === ' . $c['id'] . '));');
        $this->assertCount(1, $drift, 'the drift report lists it');
    }

    public function testConfirmedLegacyRenameIsAuditedAsConfirmed(): void
    {
        $c = $this->served();
        $stub = $this->nas('nak503');
        $page = $this->rename($c, 'w9lrc' . bin2hex(random_bytes(3)));
        $this->assertCount(1, $stub->received());
        $conf = $this->confirmations($c['id']);
        $this->assertSame(['CONFIRMED', 'LEGACY_RENAME'], [$conf[0]['old_session'] ?? null, $conf[0]['operation'] ?? null]);
        $this->assertStringContainsString('The sessions of the old name were ended.', $page->body);
    }

    public function testAnEditWithoutRenameRecordsNothing(): void
    {
        $c = $this->served();
        $stub = $this->nas('silent');
        $this->rename($c, $c['username']);
        $this->assertSame([], $this->confirmations($c['id']));
        $this->assertSame([], $stub->received(), "nothing sent");
    }
}
