<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8DriverLabCase.php';

use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\Wp2Seed;
use Reseller\Tests\Support\Wp8DriverLabCase;
use Reseller\Tests\Support\Wp8Lab;

/**
 * WP8 S7 fail-closed tests on the real, isolated RouterOS CHR 7.24.5 (lab only): a reseller-owned customer with LEGACY
 * MikroTik-device plans (Hotspot / PPPoE) and existing local router objects. Every refusal leaves the router objects
 * (read back with the lab agent), the RADIUS tables and the application data unchanged and is audited; every case has
 * a real positive control (the same call for an unassigned customer changes the router), so "unchanged" discriminates.
 *
 *   FC-1 direct driver grants (add / sync / rename) on H / P / V for the owned customer: RESELLER_OWNED_REFUSED
 *   FC-2 name collisions: an unassigned row whose username, or whose PPPoE username (S7-F1, also as database-equal
 *        variants), is the owned customer's key - direct PPPoE / VPN grants with a live session (FC-2), the legacy
 *        recharge funnel refused before any write (FC-2c, S7-FC) and an owned RADIUS subscriber that a local secret
 *        would mask (FC-2d)
 *   FC-3 / FC-8 the permitted path (LegacyReprovisionService, AM-S7-4) works on the real router - also for an owned
 *        PPPoE identity (FC-3b); the per-call permit is gone after success AND after failure (API unavailable ->
 *        REPROVISION_UNCONFIRMED, OBS-W8-5)
 *   FC-4 Package::rechargeUser (the funnel of every legacy grant caller)
 *   FC-5 voucher-only grant with a code equal to the owned username
 *   FC-6 A5 P1 / P2 / P3 with a MikroTik plan and a LIVE local session: refused, the session survives
 *   FC-7 canonical cron expiry of the owned customer: allowed (not a bypass)
 * Plugin / hook authority is Phase B (not tested here).
 */
final class DriverFailClosedLabTest extends Wp8DriverLabCase
{
    /**
     * @return array{0:array,1:array,2:array,3:array} owned customer (+ 'key': the name of its local PPPoE secret), its
     *         Hotspot / PPPoE / VPN plans (existing default profiles)
     */
    private function owned(string $tag, array $extra = []): array
    {
        $name = self::PREFIX . 'own_' . $tag . '_' . bin2hex(random_bytes(2));
        $o = $this->localCustomer('own', $this->sid('A'), $extra + ['username' => $name, 'password' => Wp2Seed::customerPassword($name)]);
        $o['key'] = (string) ($extra['pppoe_username'] ?? '') !== '' ? $extra['pppoe_username'] : $o['username'];
        $h = $this->plan('Hotspot', 'fch', ['name_plan' => 'default']);
        $p = $this->plan('PPPOE', 'fcp', ['name_plan' => 'default']);
        $v = $this->plan('VPN', 'fcv', ['name_plan' => 'default']);
        $h['name'] = $p['name'] = $v['name'] = 'default';
        $this->recharge($o, $h, 'Hotspot');
        $this->recharge($o, $p, 'PPPOE');
        // the owned customer's existing legacy router objects (as before the reseller assignment)
        Wp8Lab::ros('/ppp/secret/add', '=name=' . $o['key'], '=password=' . $o['password'], '=service=pppoe', '=profile=default', '=comment=nuxr_wp8_local_fc');
        Wp8Lab::ros('/ip/hotspot/user/add', '=name=' . $o['username'], '=password=' . $o['password'], '=profile=default', '=comment=nuxr_wp8_local_fc');
        return [$o, $h, $p, $v];
    }

    /** the owned customer's router objects, complete (password included: compared in memory only, never recorded) */
    private static function objects(string $name, ?string $key = null): array
    {
        return ['secret' => static::find('/ppp/secret', $key ?? $name), 'hotspot' => static::find('/ip/hotspot/user', $name), 'ppp_active' => static::find('/ppp/active', $key ?? $name)];
    }

    private function removeLocal(string $name): void
    {
        foreach (['/ppp/active', '/ppp/secret', '/ip/hotspot/user'] as $path) {
            foreach (static::find($path, $name) as $r) {
                Wp8Lab::ros($path . '/remove', '=.id=' . $r['.id']);
            }
        }
    }

    /** a LIVE local PPPoE session of the owned key (client c1), on a local pool / profile of its own */
    private function live(array $o, string $tag): array
    {
        $pool = self::PREFIX . $tag . 'pool';
        $prof = self::PREFIX . $tag . 'prof';
        Wp8Lab::ros('/ip/pool/add', '=name=' . $pool, '=ranges=10.88.250.220-10.88.250.229');
        Wp8Lab::ros('/ppp/profile/add', '=name=' . $prof, '=local-address=10.88.250.2', '=remote-address=' . $pool);
        Wp8Lab::ros('/ppp/secret/set', '=.id=' . static::find('/ppp/secret', $o['key'])[0]['.id'], '=profile=' . $prof);
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $o['key'], 'password' => $o['password']]);
        $this->assertTrue($up['ok'], 'the owned customer\'s LOCAL PPPoE session is live');
        return [$pool, $prof];
    }

    private function endLive(array $o, array $live): void
    {
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $this->removeLocal($o['key']);
        $this->removeLocal($o['username']);
        foreach ([['/ppp/profile', $live[1]], ['/ip/pool', $live[0]]] as [$path, $name]) {
            foreach (static::find($path, $name) as $r) {
                Wp8Lab::ros($path . '/remove', '=.id=' . $r['.id']);
            }
        }
    }

    /** the live session of $key: [.id, address] (the same session, not a reconnect) */
    private static function session(string $key): array
    {
        return array_map(static fn(array $r): array => [$r['.id'] ?? null, $r['address'] ?? null], static::find('/ppp/active', $key));
    }

    /** an unassigned (legacy) row colliding with the owned key; its name / comment carry the local prefix (cleanup) */
    private function collider(string $tag, array $extra): array
    {
        $extra += ['fullname' => 'nuxr_wp8_local ' . $tag, 'pppoe_password' => 'Zz-collide-' . bin2hex(random_bytes(3))];
        return $this->localCustomer($tag, null, $extra) + ['pppoe_username' => (string) ($extra['pppoe_username'] ?? ''), 'pppoe_password' => $extra['pppoe_password']];
    }

    /** the secret fields the refusal must keep: password (in memory only), name, comment, profile, remote address */
    private static function secretFields(array $objects): array
    {
        return array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['.id', 'name', 'password', 'comment', 'profile', 'remote-address', 'service', 'disabled'])), $objects['secret']);
    }

    // ---------------------------------------------------------------------------------------------- FC-1

    public function testDirectDriverGrantsAreRefusedForTheResellerOwnedCustomer(): void
    {
        [$o, $h, $p, $v] = $this->owned('fc1');
        $before = self::objects($o['username']);
        $others = static::others();
        $radius = static::radiusChecksums();
        $log = strlen(static::$app->serverLog());
        $results = [];
        foreach (['MikrotikHotspot' => $h, 'MikrotikPppoe' => $p, 'MikrotikVpn' => $v] as $driver => $plan) {
            foreach (['add_customer' => "(new {$driver}())->add_customer(\$c({$o['id']}), \$p({$plan['id']}));",
                'sync_customer' => "(new {$driver}())->sync_customer(\$c({$o['id']}), \$p({$plan['id']}));",
                'change_username' => "(new {$driver}())->change_username(\$p({$plan['id']}), '{$o['username']}', '{$o['username']}x');"] as $m => $call) {
                $r = $this->app($call);
                $results["{$driver}::{$m}"] = [$r['ok'], $r['code'] ?? null];
                $this->assertSame([false, 'RESELLER_OWNED_REFUSED'], $results["{$driver}::{$m}"], "{$driver}::{$m}");
            }
        }
        // CLI context: ServiceGate::refused() records a sanitized log line (the audit table is written for web / API only)
        $this->assertSame(9, static::logged($log, 'op=deviceGrant'), 'every refusal recorded (op=deviceGrant)');
        $this->assertSame($before, self::objects($o['username']), 'the owned customer\'s router objects unchanged');
        $this->assertSame($others, static::others());
        $this->assertSame($radius, static::radiusChecksums());
        // positive control: the same grants for an unassigned customer do change the router
        $u = $this->localCustomer('fc1u', null, ['pppoe_ip' => '10.88.251.30']);
        $pos = [];
        foreach (['MikrotikHotspot' => [$h, '/ip/hotspot/user'], 'MikrotikPppoe' => [$p, '/ppp/secret']] as $driver => [$plan, $path]) {
            $this->assertSame([], static::find($path, $u['username']));
            $r = $this->app("(new {$driver}())->add_customer(\$c({$u['id']}), \$p({$plan['id']}));");
            $pos[$driver] = $r['ok'] && count(static::find($path, $u['username'])) === 1;
            $this->assertTrue($pos[$driver], "positive control {$driver}");
            $this->removeLocal($u['username']);
        }
        $this->removeLocal($o['username']);
        static::evidence('FC-1.direct-grants', ['refused' => $results, 'refusals_logged' => 9, 'positive_control' => $pos]);
    }

    // ---------------------------------------------------------------------------------------------- FC-2

    /**
     * S7-F1 regression (owner decision S7_F1_TARGETED_REMEDIATION_APPROVED). The owned customer holds a LIVE local PPPoE
     * session. Unassigned rows carrying its key - as username (a, refused before S7-F1), as PPPoE username (b, the
     * proven bypass), and as database-equal variants (upper case, trailing space) - call the PPPoE and VPN grant methods
     * (add / sync, $isChangePlan set so the driver's disconnect branch is reachable). Every call is refused and logged
     * without secrets; the owned secret (password, name, comment, profile, address), its hotspot user, its live session
     * and the RADIUS tables are unchanged, and no variant secret appears. Positive control: a non-colliding unassigned
     * customer with its own PPPoE username is provisioned under that name.
     */
    public function testNameCollisionsWithTheOwnedCustomersKey(): void
    {
        [$o, $h, $p, $v] = $this->owned('fc2');
        $live = $this->live($o, 'f2');
        try {
            $before = self::objects($o['username']);
            $this->assertArrayHasKey('password', $before['secret'][0] ?? [], 'the agent read-back includes the secret password (compared in memory only)');
            $session = self::session($o['key']);
            $this->assertCount(1, $session);
            $others = static::others();
            $radius = static::radiusChecksums();
            $log = strlen(static::$app->serverLog());
            $rows = [
                'a_same_username' => $this->collider('fc2a', ['username' => $o['username']]),
                'b_pppoe_username' => $this->collider('fc2b', ['pppoe_username' => $o['key'], 'pppoe_ip' => '10.88.251.41']),
                'e_upper_case' => $this->collider('fc2u', ['pppoe_username' => strtoupper($o['key'])]),
                'e_trailing_space' => $this->collider('fc2s', ['pppoe_username' => $o['key'] . ' ']),
            ];
            $results = [];
            $calls = 0;
            foreach ($rows as $case => $u) {
                foreach (['MikrotikPppoe' => $p, 'MikrotikVpn' => $v] as $driver => $plan) {
                    foreach (['add_customer', 'sync_customer'] as $m) {
                        $r = $this->app("global \$isChangePlan; \$isChangePlan = true; (new {$driver}())->{$m}(\$c({$u['id']}), \$p({$plan['id']}));");
                        $results[$case]["{$driver}::{$m}"] = [$r['ok'], $r['code'] ?? null];
                        $calls++;
                    }
                }
                if ($case === 'a_same_username') {
                    $r = $this->app("(new MikrotikHotspot())->add_customer(\$c({$u['id']}), \$p({$h['id']}));");
                    $results[$case]['MikrotikHotspot::add_customer'] = [$r['ok'], $r['code'] ?? null];
                    $calls++;
                }
            }
            $after = self::objects($o['username']);
            $tail = substr(static::$app->serverLog(), $log);
            $integrity = [
                'secret_unchanged' => self::secretFields($after) === self::secretFields($before),
                'hotspot_unchanged' => $after['hotspot'] === $before['hotspot'],
                'session_survived' => self::session($o['key']) === $session,
                'no_variant_secret' => static::find('/ppp/secret', strtoupper($o['key'])) === [] && static::find('/ppp/secret', $o['key'] . ' ') === [],
                'others_unchanged' => static::others() === $others,
                'radius_unchanged' => static::radiusChecksums() === $radius,
                'refusals_logged' => static::logged($log, 'op=deviceGrant'),
                'log_has_no_secret' => !str_contains($tail, $o['password']) && array_filter($rows, static fn(array $u): bool => str_contains($tail, $u['pppoe_password']) || str_contains($tail, $u['password'])) === [],
            ];
            // positive control: a non-colliding unassigned customer with its own PPPoE username is provisioned under it
            $free = $this->collider('fc2f', ['pppoe_username' => self::PREFIX . 'free_' . bin2hex(random_bytes(2))]);
            $pc = $this->app("(new MikrotikPppoe())->add_customer(\$c({$free['id']}), \$p({$p['id']}));");
            $positive = $pc['ok'] && count(static::find('/ppp/secret', $free['pppoe_username'])) === 1 && static::find('/ppp/secret', $free['username']) === [];
            $this->removeLocal($free['pppoe_username']);
            static::evidence('FC-2.collisions', ['results' => $results, 'integrity' => $integrity, 'positive_control' => $positive]);
            $refused = [false, 'RESELLER_OWNED_REFUSED'];
            foreach ($results as $case => $byCall) {
                foreach ($byCall as $call => $res) {
                    $this->assertSame($refused, $res, "{$case} {$call} must be refused");
                }
            }
            $this->assertSame(['secret_unchanged' => true, 'hotspot_unchanged' => true, 'session_survived' => true, 'no_variant_secret' => true,
                'others_unchanged' => true, 'radius_unchanged' => true, 'refusals_logged' => $calls, 'log_has_no_secret' => true], $integrity);
            $this->assertTrue($positive, 'positive control: the legacy grant of a non-colliding PPPoE username');
        } finally {
            foreach ([strtoupper($o['key']), $o['key'] . ' '] as $variant) {
                $this->removeLocal($variant);
            }
            $this->endLive($o, $live);
        }
    }

    /**
     * S7-FC regression (owner decision OD-S7-FC-1, round 2: FC-2c proved the residue): the legacy grant funnel
     * Package::rechargeUser() for an unassigned customer whose PPPoE username is the owned customer's key - a new
     * recharge on the PPPoE and the VPN plan, and the renewal of an existing active PPPoE recharge - with the owned
     * customer's session live. The call returns false before the recharge cycle: no hook, no recharge insert / update
     * (the existing expiry is kept), no transaction / invoice, no device call (no "sync manually" report), no RouterOS or
     * RADIUS change and no disconnect; one refusal record (op=deviceGrant) per call, without secrets.
     */
    public function testTheLegacyRechargeFunnelCollisionLeavesTheOwnedCustomerUntouched(): void
    {
        [$o, , $p, $v] = $this->owned('fc2c');
        $live = $this->live($o, 'f2c');
        try {
            $before = self::objects($o['username']);
            $session = self::session($o['key']);
            $radius = static::radiusChecksums();
            $calls = [];
            foreach (['pppoe_new' => [$p, false], 'pppoe_renewal' => [$p, true], 'vpn_new' => [$v, false]] as $case => [$plan, $renewal]) {
                $u = $this->collider('fc2c', ['pppoe_username' => $o['key']]);
                if ($renewal) {
                    $this->recharge($u, $plan, $plan['device'] === 'MikrotikVpn' ? 'VPN' : 'PPPOE', '+5 days');
                }
                $records = static fn(int $id): array => [
                    'recharges' => static::$db->rows('SELECT plan_id, recharged_on, recharged_time, expiration, time, status, method FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id', [$id]),
                    'transactions' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$id]),
                    'balance' => (string) static::$db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$id]),
                ];
                $u0 = $records($u['id']);
                $data = $this->snapshot();
                $log = strlen(static::$app->serverLog());
                $rc = $this->app(sprintf(<<<'PHP'
if (!function_exists('s7fc_hook')) {
    function s7fc_hook($a = null) { $GLOBALS['s7fc_hooks'][] = 'recharge_user'; }
    function s7fc_finish($a = null) { $GLOBALS['s7fc_hooks'][] = 'recharge_user_finish'; }
}
$GLOBALS['s7fc_hooks'] = [];
register_hook('recharge_user', 's7fc_hook');
register_hook('recharge_user_finish', 's7fc_finish');
return ['value' => Package::rechargeUser(%d, '%s', %d, 'Recharge', 'S7'), 'hooks' => $GLOBALS['s7fc_hooks']];
PHP, $u['id'], self::ROUTER, $plan['id']));
                $tail = substr(static::$app->serverLog(), $log);
                $calls[$case] = [
                    'ok' => $rc['ok'],
                    'value' => $rc['value']['value'] ?? 'n/a',
                    'hooks' => $rc['value']['hooks'] ?? 'n/a',
                    'unassigned_records_unchanged' => $records($u['id']) === $u0,
                    'data_unchanged' => DbProbe::changed($data, $this->snapshot()),
                    'refusals_logged' => static::logged($log, 'op=deviceGrant'),
                    'device_failure_reported' => static::logged($log, 'package-activation'),
                    'log_has_no_secret' => !str_contains($tail, $o['password']) && !str_contains($tail, $u['pppoe_password']) && !str_contains($tail, $u['password']),
                ];
            }
            $after = self::objects($o['username']);
            $integrity = [
                'secret_unchanged' => self::secretFields($after) === self::secretFields($before),
                'hotspot_unchanged' => $after['hotspot'] === $before['hotspot'],
                'session_survived' => self::session($o['key']) === $session,
                'radius_unchanged' => static::radiusChecksums() === $radius,
            ];
            static::evidence('FC-2c.recharge-funnel-collision', ['calls' => $calls, 'integrity' => $integrity]);
            $refused = ['ok' => true, 'value' => false, 'hooks' => [], 'unassigned_records_unchanged' => true, 'data_unchanged' => [], 'refusals_logged' => 1,
                'device_failure_reported' => 0, 'log_has_no_secret' => true];
            $this->assertSame(['pppoe_new' => $refused, 'pppoe_renewal' => $refused, 'vpn_new' => $refused], $calls,
                'refused before the recharge cycle: no hook, recharge, invoice or device call; one safe refusal record');
            $this->assertSame(['secret_unchanged' => true, 'hotspot_unchanged' => true, 'session_survived' => true, 'radius_unchanged' => true], $integrity);
        } finally {
            $this->endLive($o, $live);
        }
    }

    /**
     * S7-F1 (owner §4): a reseller-owned RADIUS subscriber (no local secret) is never masked by a local secret created for
     * an unassigned row carrying its key as PPPoE username (RouterOS consults RADIUS only when no local user matches).
     * The refused grant leaves no local secret, the RADIUS policy unchanged, and the subscriber still authenticates
     * through FreeRADIUS with its RADIUS password.
     */
    public function testALocalSecretNeverMasksAnOwnedRadiusSubscriber(): void
    {
        $key = $this->provisionPolicy(['username' => self::ident('s7own'), 'password' => 'S7-rad-own', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''],
            new \DateTimeImmutable('+1 day'));
        $own = $this->localCustomer('ownr', $this->sid('A'), ['username' => $key, 'password' => 'S7-rad-own']);
        $p = $this->plan('PPPOE', 'fcr', ['name_plan' => 'default']);
        $u = $this->collider('fc2r', ['pppoe_username' => $key]);
        try {
            $this->assertSame([], static::find('/ppp/secret', $key), 'the owned subscriber is a RADIUS identity (no local secret)');
            $policy = array_intersect_key(static::radiusChecksums(), array_flip(['radcheck', 'radreply', 'radusergroup']));
            $log = strlen(static::$app->serverLog());
            $res = [];
            foreach (['add_customer', 'sync_customer'] as $m) {
                $r = $this->app("(new MikrotikPppoe())->{$m}(\$c({$u['id']}), \$p({$p['id']}));");
                $res[$m] = [$r['ok'], $r['code'] ?? null];
            }
            $masked = static::find('/ppp/secret', $key) !== [];
            try {
                $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $key, 'password' => 'S7-rad-own']);
            } catch (\RuntimeException $e) {
                $up = ['ok' => false, 'error' => substr($e->getMessage(), 0, 160)];
            }
            $viaRadius = static::radiusSaw($key) !== [];
            Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
            $after = array_intersect_key(static::radiusChecksums(), array_flip(['radcheck', 'radreply', 'radusergroup']));
            static::evidence('FC-2d.radius-masking', ['results' => $res, 'local_secret_created' => $masked, 'radius_login' => $up['ok'], 'seen_by_freeradius' => $viaRadius,
                'policy_unchanged' => $after === $policy, 'refusals_logged' => static::logged($log, 'op=deviceGrant'), 'owned_id' => $own['id']]);
            $this->assertSame(['add_customer' => [false, 'RESELLER_OWNED_REFUSED'], 'sync_customer' => [false, 'RESELLER_OWNED_REFUSED']], $res);
            $this->assertFalse($masked, 'no local secret masks the RADIUS subscriber');
            $this->assertTrue($up['ok'], 'the owned subscriber still logs in with its RADIUS password');
            $this->assertTrue($viaRadius, 'authenticated by FreeRADIUS, not by a local secret');
            $this->assertSame($policy, $after, 'the RADIUS policy is unchanged');
        } finally {
            $this->removeLocal($key);
        }
    }

    // ------------------------------------------------------------------------------------- FC-3 / FC-8 (AM-S7-4)

    public function testThePermittedPathWorksAndThePermitEndsAfterSuccessAndFailure(): void
    {
        $this->permitted('fc3', [], 'FC-3.permit');
    }

    /** S7-F1 regression: the permitted path on an owned customer whose router identity is its PPPoE username */
    public function testThePermittedPathOnAnOwnedPppoeIdentityAndThePermitEnds(): void
    {
        $this->permitted('fc3p', ['pppoe_username' => self::PREFIX . 'opk_' . bin2hex(random_bytes(2)), 'pppoe_password' => ''], 'FC-3b.permit-pppoe-identity');
    }

    private function permitted(string $tag, array $extra, string $case): void
    {
        [$o, , $p] = $this->owned($tag, $extra);
        $before = self::objects($o['username'], $o['key']);
        $this->assertArrayHasKey('password', $before['secret'][0] ?? [], 'the agent read-back includes the secret password (compared in memory only)');
        $ok = $this->app(sprintf(<<<'PHP'
$ad = \Reseller\Security\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
$sync = \Reseller\Domain\LegacyReprovisionService::run($ad, %1$d, \Reseller\Domain\LegacyReprovisionService::ADMIN_SYNC);
$self = \Reseller\Security\ActorContext::forCustomer(ORM::for_table('tbl_customers')->find_one(%1$d)->as_array(), 'WEB');
$pw = \Reseller\Domain\LegacyReprovisionService::run($self, %1$d, \Reseller\Domain\LegacyReprovisionService::PASSWORD_CHANGE, ['password' => 'S7-new-%3$s']);
try {
    (new MikrotikPppoe())->add_customer($c(%1$d), $p(%2$d));
    $after = 'ALLOWED';
} catch (\Reseller\Radius\ProvisionFailure $e) {
    $after = $e->result->code;
}
return ['sync' => $sync['status'] ?? null, 'password' => $pw['status'] ?? null, 'direct_call_after_success' => $after];
PHP, $o['id'], $p['id'], bin2hex(random_bytes(3))));
        $this->assertTrue($ok['ok'], json_encode($ok));
        $this->assertSame(['sync' => 'DONE', 'password' => 'DONE'], array_intersect_key($ok['value'], ['sync' => 1, 'password' => 1]), 'the permitted runs succeeded');
        $this->assertSame('RESELLER_OWNED_REFUSED', $ok['value']['direct_call_after_success'], 'the permit ended with the successful run');
        $after = self::objects($o['username'], $o['key']);
        $this->assertCount(1, $after['secret'], 'one secret, under the owned customer\'s own identity');
        $this->assertNotSame($before['secret'][0]['password'] ?? null, $after['secret'][0]['password'] ?? null, 'the permitted password change reached the real router');
        $this->assertSame($o['key'], $after['secret'][0]['name'], 'the secret keeps the owned identity');
        // failure: the API is unavailable during the permitted run -> REPROVISION_UNCONFIRMED; the permit is gone afterwards
        $rule = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=input', '=protocol=tcp', '=dst-port=8728', '=src-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_local_drill');
        try {
            $fail = $this->app(sprintf(<<<'PHP'
$self = \Reseller\Security\ActorContext::forCustomer(ORM::for_table('tbl_customers')->find_one(%1$d)->as_array(), 'WEB');
try {
    \Reseller\Domain\LegacyReprovisionService::run($self, %1$d, \Reseller\Domain\LegacyReprovisionService::PASSWORD_CHANGE, ['password' => 'S7-fail-%3$s']);
    $run = 'SUCCESS';
} catch (\Reseller\Domain\DomainError $e) {
    $run = $e->reason;
}
try {
    (new MikrotikPppoe())->add_customer($c(%1$d), $p(%2$d));
    $after = 'ALLOWED';
} catch (\Reseller\Radius\ProvisionFailure $e) {
    $after = $e->result->code;
}
return ['run' => $run, 'direct_call_after_failure' => $after];
PHP, $o['id'], $p['id'], bin2hex(random_bytes(3))));
        } finally {
            Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $rule);
        }
        $this->assertTrue($fail['ok'], json_encode($fail));
        $this->assertSame('REPROVISION_UNCONFIRMED', $fail['value']['run'], 'OBS-W8-5: a driver exception is UNKNOWN -> REPROVISION_UNCONFIRMED (no revert, no retry)');
        $this->assertSame('RESELLER_OWNED_REFUSED', $fail['value']['direct_call_after_failure'], 'the permit ended with the failed run');
        $this->assertSame($after['secret'], self::objects($o['username'], $o['key'])['secret'], 'the failed run changed nothing on the router');
        $this->removeLocal($o['key']);
        $this->removeLocal($o['username']);
        static::evidence($case, ['identity' => $o['key'] === $o['username'] ? 'username' : 'pppoe_username', 'success' => $ok['value'], 'failure' => $fail['value'], 'failure_ms' => $fail['ms']]);
    }

    // ---------------------------------------------------------------------------------------------- FC-4

    public function testTheLegacyRechargeFunnelRefusesTheOwnedCustomer(): void
    {
        [$o, , $p] = $this->owned('fc4');
        $before = self::objects($o['username']);
        $rows = (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$o['id']]);
        $tx = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$o['id']]);
        $log = strlen(static::$app->serverLog());
        $r = $this->app(sprintf("return Package::rechargeUser(%d, '%s', %d, 'Recharge', 'S7');", $o['id'], self::ROUTER, $p['id']));
        $this->assertSame(false, $r['value'], 'refused');
        $this->assertSame(1, static::logged($log, 'op=rechargeUser customer=' . $o['id']), 'the refusal is recorded');
        $this->assertSame($before, self::objects($o['username']));
        $this->assertSame([$rows, $tx], [(int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$o['id']]),
            (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$o['id']])], 'no recharge, no transaction');
        // positive control
        $u = $this->localCustomer('fc4u', null);
        $ru = $this->app(sprintf("return Package::rechargeUser(%d, '%s', %d, 'Recharge', 'S7');", $u['id'], self::ROUTER, $p['id']));
        $this->assertTrue($ru['ok'] && $ru['value'] !== false, json_encode($ru));
        $this->assertCount(1, static::find('/ppp/secret', $u['username']), 'positive control: the unassigned recharge reaches the router');
        $this->removeLocal($u['username']);
        $this->removeLocal($o['username']);
        static::evidence('FC-4.recharge-funnel', ['owned' => $r['value'], 'unassigned' => $ru['value'] !== false]);
    }

    // ---------------------------------------------------------------------------------------------- FC-5

    public function testAVoucherOnlyGrantWithTheOwnedUsernameIsRefused(): void
    {
        [$o, $h] = $this->owned('fc5');
        $before = self::objects($o['username']);
        $mk = fn(string $code): int => static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => self::ROUTER, 'id_plan' => $h['id'], 'code' => $code,
            'user' => '0', 'status' => '0', 'generated_by' => $this->sid('ad')]);
        $mk($o['username']);
        $redeem = static fn(string $code): string => sprintf(<<<'PHP'
$out = \Reseller\Domain\VoucherRedemptionService::redeem('%1$s', \Reseller\Domain\RedemptionTarget::voucherOnly('%1$s'), \Reseller\Domain\RedemptionSource::public());
return $out['status'];
PHP, $code);
        $r = $this->app($redeem($o['username']));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertNotSame('GRANTED', $r['value'], 'a voucher-only code equal to the owned username is never granted');
        $this->assertSame($before, self::objects($o['username']));
        // positive control: a fresh voucher-only code is granted and reaches the router
        $code = self::PREFIX . 'v_' . bin2hex(random_bytes(3));
        $mk($code);
        $g = $this->app($redeem($code));
        $this->assertSame('GRANTED', $g['value'], json_encode($g));
        $this->assertCount(1, static::find('/ip/hotspot/user', $code), 'positive control: the voucher-only user exists on the router');
        $this->removeLocal($code);
        $this->removeLocal($o['username']);
        static::evidence('FC-5.voucher-only', ['owned_code_status' => $r['value'], 'fresh_code_status' => $g['value']]);
    }

    // ---------------------------------------------------------------------------------------------- FC-6

    public function testA5RemovalPathsKeepTheLiveLocalSessionOfTheOwnedCustomer(): void
    {
        [$o, , $p] = $this->owned('fc6');
        // a local pool and PPP profile so the owned customer's existing local secret gets an address (the router's default
        // profile assigns none)
        Wp8Lab::ros('/ip/pool/add', '=name=nuxr_wp8_local_fcpool', '=ranges=10.88.250.200-10.88.250.210');
        Wp8Lab::ros('/ppp/profile/add', '=name=nuxr_wp8_local_fcprof', '=local-address=10.88.250.1', '=remote-address=nuxr_wp8_local_fcpool');
        Wp8Lab::ros('/ppp/secret/set', '=.id=' . static::find('/ppp/secret', $o['username'])[0]['.id'], '=profile=nuxr_wp8_local_fcprof');
        $rid = (int) static::$db->value("SELECT id FROM tbl_user_recharges WHERE customer_id = ? AND plan_id = ?", [$o['id'], $p['id']]);
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $o['username'], 'password' => $o['password']]);
        $this->assertTrue($up['ok'], 'the owned customer\'s LOCAL PPPoE session is live');
        $admin = $this->staff('ad');
        $portal = $this->customer($o['username']);
        $token = $portal->token('home');
        $before = self::objects($o['username']);
        $data = $this->snapshot();
        $n = count($this->auditRows('service.refused'));
        $codes = [
            'P1' => $admin->postWithToken('plan/delete/' . $rid)->status,
            'P2' => $admin->postWithToken('customers/deactivate/' . $o['id'] . '/' . $p['id'])->status,
            'P3' => $portal->post('home', ['csrf_token' => $token], ['deactivate' => (string) $rid])->status,
        ];
        $this->assertSame(['P1' => 302, 'P2' => 302, 'P3' => 302], $codes);
        $this->assertSame(3, count($this->auditRows('service.refused')) - $n, 'three refusals');
        $this->assertUnchanged($data, 'A5 refusals with a MikroTik plan');
        $after = self::objects($o['username']);
        $this->assertSame($before['secret'], $after['secret'], 'the router secret unchanged');
        $this->assertCount(1, $after['ppp_active'], 'the live session survived all three refusals');
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $this->removeLocal($o['username']);
        static::evidence('FC-6.a5-paths', ['status' => $codes, 'session_survived' => true]);
    }

    // ---------------------------------------------------------------------------------------------- FC-7

    public function testCanonicalCronExpiryOfTheOwnedCustomerIsAllowed(): void
    {
        [$o, , $p] = $this->owned('fc7');
        static::$db->pdo->prepare("UPDATE tbl_user_recharges SET expiration = ?, time = '00:00:01' WHERE customer_id = ? AND plan_id = ?")
            ->execute([gmdate('Y-m-d', time() - 86400), $o['id'], $p['id']]);
        $this->assertCount(1, static::find('/ppp/secret', $o['username']));
        [$exit, $out] = static::$app->run(['cron.php'], 'system');      // cron.php includes "../init.php" from its own directory
        $this->assertSame(0, $exit, substr($out, -400));
        $this->assertSame('off', static::$db->value('SELECT status FROM tbl_user_recharges WHERE customer_id = ? AND plan_id = ?', [$o['id'], $p['id']]), 'expired');
        $this->assertSame([], static::find('/ppp/secret', $o['username']), 'expiry removed the router secret (canonical end, P4)');
        $this->removeLocal($o['username']);
        static::evidence('FC-7.cron-expiry', ['exit' => $exit, 'secret_removed' => true]);
    }
}
