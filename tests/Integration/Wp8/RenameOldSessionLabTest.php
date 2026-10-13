<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8ActivationLabCase.php';

use Reseller\Activation\DriftReport;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Tests\Support\Wp8ActivationLabCase;
use Reseller\Tests\Support\Wp8Lab;

/**
 * WP8 A3 (R-WP8-CARRY-02) on the real RouterOS CHR 7.24.5 (CHR1 10.88.0.2, CHR2 10.88.0.3): an identity rename through
 * LegacyReprovisionService ends the live sessions of the OLD identity (one Disconnect per captured accounting row, to
 * its own NAS, after the tree lock is released) and reports old_session separately from the re-provision status.
 * Needs the lab with CHR2 (lab.sh up-chr2), Option G shipped and the CHR2 Hotspot (h2_gate.py setup).
 */
final class RenameOldSessionLabTest extends Wp8ActivationLabCase
{
    /** @var list<string> */
    private array $rules = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (Wp8Lab::enabled()) {
            $root = dirname(__DIR__, 3);
            $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
            $GLOBALS['_app_stage'] = 'Live';
            foreach (['Package', 'Text'] as $c) {
                require_once $root . "/system/autoload/{$c}.php";
            }
            require_once $root . '/system/devices/Radius.php';
        }
    }

    protected function tearDown(): void
    {
        if (Wp8Lab::enabled()) {
            foreach ($this->rules as $id) {
                Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
            }
            $this->rules = [];
            Wp8Lab::ros('/radius/incoming/set', '=accept=yes', '=port=3799');
            foreach (['c1', 'c3', 'c6'] as $c) {
                Wp8Lab::agent('pppoe_down', ['client' => $c]);
            }
            foreach (['c2', 'c7'] as $c) {
                Wp8Lab::agent('hs_logout', ['client' => $c]);
            }
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> the last 'rename.old_session' audit payload of a customer */
    private function confirmation(int $c): array
    {
        $rows = static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'rename.old_session' AND target_customer_id = ? ORDER BY id DESC LIMIT 1", [$c]);
        $this->assertNotSame([], $rows, 'a rename.old_session event');
        return json_decode((string) $rows[0]['after_json'], true);
    }

    /** @return list<array<string,mixed>> open radacct rows of an exact spelling */
    private function openRows(string $user): array
    {
        $st = static::labRadius()->prepare('SELECT radacctid, nasipaddress, acctsessionid FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid');
        $st->execute([$user]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function pppoeCustomer(string $password, array $extra = []): array
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h);
        $cust = $this->labCustomer($h['C'], $h['sales'], $password, 'PPPoE', $extra);
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        return [$h, $cust];
    }

    // ------------------------------------------------------------------ C1 PPPoE

    public function testPppoeRenameEndsTheOldSessionAndTheNewNameWorks(): void
    {
        [, $cust] = $this->pppoeCustomer('A3-pass-1');
        $old = $this->username($cust);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $old, 'password' => 'A3-pass-1'])['ok']);
        $this->assertNotNull(Wp8Lab::until(fn() => $this->openRows($old) ?: null, 20, 1), 'Accounting-Start recorded');
        $new = self::ident('a3ren');
        $t0 = microtime(true);
        $r = R::run($this->ctx('ad'), $cust, R::IDENTITY_EDIT, ['username' => $new]);
        $this->customersChanged();
        $runSeconds = round(microtime(true) - $t0, 2);
        $gone = Wp8Lab::until(fn() => Wp8Lab::pppActive($old) === [] ? true : null, 20, 0.5);
        $goneSeconds = round(microtime(true) - $t0, 2);
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']]);
        $this->assertTrue($gone, 'the old-identity session ended on the NAS');
        $this->assertSame([], $this->openRows($old), 'its Accounting-Stop was written');
        $conf = $this->confirmation($cust);
        $this->assertSame('10.88.0.2', $conf['identities'][0]['sessions'][0]['nas']);
        $this->assertTrue($conf['identities'][0]['sessions'][0]['stop_seen']);
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $new, 'password' => 'A3-pass-1']);
        $this->assertTrue($up['ok'], 'the new name logs in');
        $this->assertSame([], Wp8Lab::pppActive($old), 'never two live sessions after the Disconnect');
        $this->assertCount(1, Wp8Lab::pppActive($new));
        Wp8Lab::evidence('A3.C1.pppoe_rename', ['status' => $r['status'], 'old_session' => $r['old_session'], 'run_seconds' => $runSeconds,
            'old_gone_seconds_from_run_start' => $goneSeconds, 'confirmation' => $conf, 'new_login' => $up['ok']]);
    }

    // ------------------------------------------------------------------ C2 Hotspot on CHR1 and CHR2

    public function testHotspotOldSessionsOnTwoNasAreBothEnded(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h, type: 'Hotspot', extra: ['shared_users' => 2]);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'A3-hs-pass', 'Hotspot');
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        $old = $this->username($cust);
        foreach (['c2', 'c7'] as $c) {
            $this->assertTrue(Wp8Lab::agent('hs_dhcp', ['client' => $c])['ok'], "{$c} lease");
            $login = Wp8Lab::agent('hs_login', ['client' => $c, 'user' => $old, 'password' => 'A3-hs-pass']);
            $this->assertTrue($login['ok'], "{$c}: " . $login['message']);
        }
        $rows = Wp8Lab::until(fn() => count($this->openRows($old)) === 2 ? $this->openRows($old) : null, 30, 1);
        $this->assertIsArray($rows, 'two Accounting-Starts');
        $this->assertSame(['10.88.0.2', '10.88.0.3'], array_values(array_unique(array_column($rows, 'nasipaddress'))));
        $new = self::ident('a3hs');
        $r = R::run($this->ctx('ad'), $cust, R::IDENTITY_EDIT, ['username' => $new]);
        $this->customersChanged();
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']]);
        $this->assertTrue(Wp8Lab::until(fn() => Wp8Lab::hotspotActive($old) === [] ? true : null, 20, 0.5), 'no Hotspot session of the old name left');
        $conf = $this->confirmation($cust);
        $nas = array_column($conf['identities'][0]['sessions'], 'nas');
        sort($nas);
        $this->assertSame(['10.88.0.2', '10.88.0.3'], $nas, 'one Disconnect per session, each to its own NAS');
        Wp8Lab::evidence('A3.C2.hotspot_two_nas', ['old_session' => $r['old_session'], 'confirmation' => $conf]);
    }

    // ------------------------------------------------------------------ C3 PPPoE username transitions

    public function testPppoeUsernameSetAndClearedEndTheFormerKeySession(): void
    {
        [, $cust] = $this->pppoeCustomer('A3-pass-3');
        $u = $this->username($cust);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $u, 'password' => 'A3-pass-3'])['ok']);
        $this->assertNotNull(Wp8Lab::until(fn() => $this->openRows($u) ?: null, 20, 1));
        $p = self::ident('a3pp');
        $r = R::run($this->ctx('ad'), $cust, R::IDENTITY_EDIT, ['username' => $u, 'pppoe_username' => $p]);
        $this->customersChanged();
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']], 'empty -> set: the username-keyed session ended');
        $this->assertTrue(Wp8Lab::until(fn() => Wp8Lab::pppActive($u) === [] ? true : null, 20, 0.5));
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $p, 'password' => 'A3-pass-3'])['ok'], 'the PPPoE key logs in');
        $this->assertNotNull(Wp8Lab::until(fn() => $this->openRows($p) ?: null, 20, 1));
        $r2 = R::run($this->ctx('ad'), $cust, R::IDENTITY_EDIT, ['username' => $u, 'pppoe_username' => '']);
        $this->customersChanged();
        $this->assertSame(['DONE', 'CONFIRMED'], [$r2['status'], $r2['old_session']], 'set -> empty: the PPPoE-keyed session ended');
        $this->assertTrue(Wp8Lab::until(fn() => Wp8Lab::pppActive($p) === [] ? true : null, 20, 0.5));
        Wp8Lab::evidence('A3.C3.pppoe_key_transitions', ['set' => $r['old_session'], 'cleared' => $r2['old_session']]);
    }

    // ------------------------------------------------------------------ C4 equivalent (case-only) rename

    public function testCaseOnlyRenameEndsThePreRenameSessionAndKeepsThePolicy(): void
    {
        [, $cust] = $this->pppoeCustomer('A3-pass-4');
        $lower = $this->username($cust);
        $upper = strtoupper($lower);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $lower, 'password' => 'A3-pass-4'])['ok']);
        $this->assertNotNull(Wp8Lab::until(fn() => $this->openRows($lower) ?: null, 20, 1));
        $r = R::run($this->ctx('ad'), $cust, R::IDENTITY_EDIT, ['username' => $upper]);
        $this->customersChanged();
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']]);
        $this->assertTrue($this->confirmation($cust)['identities'][0]['equivalent']);
        $this->assertTrue(Wp8Lab::until(fn() => Wp8Lab::pppActive($lower) === [] ? true : null, 20, 0.5), 'the pre-rename session ended');
        $this->assertSame('A3-pass-4', $this->policy($upper)['check']['Cleartext-Password'] ?? null, 'the policy is kept under the new bytes');
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $upper, 'password' => 'A3-pass-4'])['ok'], 'a session under the new spelling starts');
        sleep(8);
        $this->assertCount(1, Wp8Lab::pppActive($upper), 'and survives (the rename never targets it)');
        Wp8Lab::evidence('A3.C4.case_only_rename', ['old_session' => $r['old_session'], 'confirmation' => $this->confirmation($cust)]);
    }

    // ------------------------------------------------------------------ C5 CoA not answered

    public function testCoaNotAcceptedIsUnconfirmedAndVisibleInDrift(): void
    {
        [, $cust] = $this->pppoeCustomer('A3-pass-5');
        $old = $this->username($cust);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $old, 'password' => 'A3-pass-5'])['ok']);
        $this->assertNotNull(Wp8Lab::until(fn() => $this->openRows($old) ?: null, 20, 1));
        Wp8Lab::ros('/radius/incoming/set', '=accept=no');
        $r = R::run($this->ctx('ad'), $cust, R::IDENTITY_EDIT, ['username' => self::ident('a3coa')]);
        $this->customersChanged();
        Wp8Lab::ros('/radius/incoming/set', '=accept=yes', '=port=3799');
        $this->assertSame(['DONE', 'UNCONFIRMED'], [$r['status'], $r['old_session']]);
        $this->assertSame('COA_NO_RESPONSE', $this->confirmation($cust)['identities'][0]['sessions'][0]['state']);
        $this->assertCount(1, Wp8Lab::pppActive($old), 'the old session survives: no retry, surfaced');
        $drift = array_values(array_filter(DriftReport::collect(false), static fn(array $f): bool => $f['kind'] === 'OLD_SESSION_UNCONFIRMED' && (int) $f['customer_id'] === $cust));
        $this->assertSame([['kind' => 'OLD_SESSION_UNCONFIRMED', 'customer_id' => $cust, 'state' => 'UNCONFIRMED']], $drift);
        Wp8Lab::evidence('A3.C5.coa_not_accepted', ['old_session' => $r['old_session'], 'drift' => $drift]);
    }

    // ------------------------------------------------------------------ C6 lost Accounting-Start

    public function testLostAccountingStartIsNoAccountingEvidenceNotConfirmed(): void
    {
        [, $cust] = $this->pppoeCustomer('A3-pass-6');
        $old = $this->username($cust);
        $this->rules[] = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_drill');
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $old, 'password' => 'A3-pass-6'])['ok']);
        sleep(5);
        $this->assertSame([], $this->openRows($old), 'no Accounting-Start reached FreeRADIUS');
        $r = R::run($this->ctx('ad'), $cust, R::IDENTITY_EDIT, ['username' => self::ident('a3nost')]);
        $this->customersChanged();
        $this->assertSame(['DONE', 'NO_ACCOUNTING_EVIDENCE'], [$r['status'], $r['old_session']], 'never CONFIRMED without evidence');
        $live = Wp8Lab::pppActive($old);
        $this->assertCount(1, $live, 'the residual (L-2): the live old session cannot be targeted without its accounting row');
        Wp8Lab::evidence('A3.C6.lost_accounting_start', ['old_session' => $r['old_session'], 'live_on_nas' => count($live)]);
    }
}
