<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Activation\DriftReport;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Domain\TreeSerialization;
use Reseller\Finance\FinanceTx;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\StubNas;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP8 A3 (R-WP8-CARRY-02): a username / PPPoE username rename of a Radius-device subscriber ends the live sessions of
 * the OLD identity, targeted per captured radacct row, outside the tree lock, and surfaces the outcome separately from
 * the re-provision status:
 *   old_session = CONFIRMED (Disconnect-ACK + Accounting-Stop seen within the bound, or NAK 503)
 *               | UNCONFIRMED (anything else, an ambiguous identity, an unconfirmed rename)
 *               | NO_ACCOUNTING_EVIDENCE (no open accounting row: NOT proof that it is offline)
 *               | NOT_APPLICABLE (no RADIUS key renamed).
 * Never a rollback, never a retry beyond the CoA client's identical retransmissions, never a status change. The
 * confirmation event is 'rename.old_session'; a rename whose confirmation is missing or UNCONFIRMED is reported by
 * the drift report (OLD_SESSION_UNCONFIRMED).
 */
final class RenameOldSessionTest extends ActivationTestCase
{
    private const SECRET = 'a3-old-session-secret';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $root = dirname(__DIR__, 3);
        $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
        $GLOBALS['_app_stage'] = 'Live';
        foreach (['Package', 'Text'] as $c) {
            require_once $root . "/system/autoload/{$c}.php";
        }
        require_once $root . '/system/devices/Radius.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::$db->exec('DELETE FROM nas');
    }

    // ------------------------------------------------------------------ fixtures

    /** @return array{0:int,1:int,2:int} plan, leaf, customer (a COMPLETED Hotspot activation) */
    private function activated(array $extra = []): array
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, $extra);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        return [$plan, $leaf, $c];
    }

    private function stub(string $mode): StubNas
    {
        $stub = new StubNas(self::SECRET, $mode);
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'a3stub', 'type' => 'other', 'ports' => $stub->port,
            'secret' => self::SECRET, 'description' => 'A3 test NAS']);
        return $stub;
    }

    /** An open accounting row (as rlm_sql writes it); returns radacctid. */
    private function open(string $username, string $session, string $nas = '127.0.0.1'): int
    {
        return static::$db->insertRow('radacct', [
            'acctsessionid' => $session, 'acctuniqueid' => substr(md5($username . $session . microtime()), 0, 32), 'username' => $username,
            'nasipaddress' => $nas, 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 600), 'framedipaddress' => '10.33.0.' . random_int(2, 250),
        ]);
    }

    /** The NAS's Accounting-Stop for these rows arrives right after the Disconnect was sent. */
    private function stopAfterSend(array $ids): void
    {
        ActivationFaults::$hook = static function (string $p) use ($ids): void {
            if ($p === 'reprovision.old_session_after_send' && $ids !== []) {
                static::$db->exec('UPDATE radacct SET acctstoptime = UTC_TIMESTAMP() WHERE radacctid IN (' . implode(',', array_map('intval', $ids)) . ')');
            }
        };
    }

    /** @return list<array<string,mixed>> the 'rename.old_session' audit payloads of a customer */
    private function confirmations(int $c): array
    {
        return array_map(static fn(array $r): array => json_decode((string) $r['after_json'], true),
            static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'rename.old_session' AND target_customer_id = ? ORDER BY id", [$c]));
    }

    /** @return array<string,mixed> the last main re-provision audit payload */
    private function lastMain(int $c, string $op): array
    {
        $rows = static::$db->rows('SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = ? AND target_customer_id = ? ORDER BY id DESC LIMIT 1', ['reprovision.' . strtolower($op), $c]);
        return json_decode((string) $rows[0]['after_json'], true);
    }

    /** @return list<int> radacctids that were targeted */
    private static function targeted(array $confirmation): array
    {
        $ids = [];
        foreach ($confirmation['identities'] as $i) {
            foreach ($i['sessions'] as $s) {
                $ids[] = (int) $s['radacctid'];
            }
        }
        return $ids;
    }

    private function failWrite(int $n): void
    {
        $count = 0;
        RadiusFaults::$hook = static function (string $p) use (&$count, $n): void {
            if ($p === 'radius.after_write' && ++$count === $n) {
                throw new \RuntimeException('injected: write rejected');
            }
        };
    }

    private function drift(int $c): array
    {
        return array_values(array_filter(DriftReport::collect(false), static fn(array $f): bool => $f['kind'] === 'OLD_SESSION_UNCONFIRMED' && (int) $f['customer_id'] === $c));
    }

    // ------------------------------------------------------------------ I1-I5: the basic outcomes

    public function testAckWithStopIsConfirmedAndOnlyTheOldRowIsTargeted(): void
    {
        [, , $c] = $this->activated();
        $old = $this->username($c);
        $new = 'a3n' . bin2hex(random_bytes(4));
        $stub = $this->stub('ack');
        $row = $this->open($old, 'a3old01');
        // a session of the NEW name: never targeted by the old-session step. It sits on another NAS address because the
        // accepted WP4 policy refresh (add_customer -> provision -> disconnect of the NEW key) contacts it inside the
        // re-provision, and the single-answer stub must stay free for the old-session Disconnect
        $newRow = $this->open($new, 'a3new01', '192.0.2.98');
        $this->stopAfterSend([$row]);
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']);
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']]);
        $got = $stub->received();
        $this->assertCount(1, $got, 'exactly one Disconnect-Request');
        $a = [];
        foreach ($got[0]['attributes'] as [$n, $v]) {
            $a[$n] = $v;
        }
        $this->assertSame([$old, 'a3old01'], [$a['User-Name'], $a['Acct-Session-Id']], 'the captured old-identity session, by its own User-Name and Acct-Session-Id');
        $conf = $this->confirmations($c);
        $this->assertCount(1, $conf);
        $this->assertSame(['CONFIRMED', 'IDENTITY_EDIT', 'DONE'], [$conf[0]['old_session'], $conf[0]['operation'], $conf[0]['reprovision_outcome']]);
        $this->assertSame([$row], self::targeted($conf[0]));
        $this->assertNotContains($newRow, self::targeted($conf[0]));
        $this->assertSame('PENDING', $this->lastMain($c, R::IDENTITY_EDIT)['old_session'], 'the main audit announces the pending confirmation');
        $this->assertSame([], $this->drift($c), 'confirmed: no drift');
        $this->customersChanged();
    }

    public function testAckWithoutStopIsUnconfirmedAfterTheBoundAndStatusStaysDone(): void
    {
        [, , $c] = $this->activated();
        $old = $this->username($c);
        $stub = $this->stub('ack');
        $this->open($old, 'a3old02');
        $t0 = microtime(true);
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']);
        $elapsed = microtime(true) - $t0;
        $stub->received();
        $this->assertSame(['DONE', 'UNCONFIRMED'], [$r['status'], $r['old_session']], 'an ACK alone is not proof');
        $conf = $this->confirmations($c)[0];
        $this->assertSame('ack_without_stop', $conf['identities'][0]['sessions'][0]['state']);
        $this->assertGreaterThanOrEqual(5.5, $elapsed, 'waited for the Accounting-Stop');
        $this->assertLessThan(15.0, $elapsed, 'bounded');
        $this->assertSame([['kind' => 'OLD_SESSION_UNCONFIRMED', 'customer_id' => $c, 'state' => 'UNCONFIRMED']], $this->drift($c));
        $this->customersChanged();
    }

    public function testNak503IsConfirmedForThatSessionAndNak406IsNot(): void
    {
        [, , $c] = $this->activated();
        $stub = $this->stub('nak503');
        $this->open($this->username($c), 'a3old03');
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']);
        $stub->received();
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']], 'NAK 503: the NAS has no such session');
        $this->customersChanged();

        [, , $c2] = $this->activated();
        static::$db->exec('DELETE FROM nas');
        $stub = $this->stub('nak');   // Error-Cause 502 from the stub: any NAK except 503 is not proof
        $this->open($this->username($c2), 'a3old03b');
        $r = R::run($this->ctx('ad'), $c2, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']);
        $stub->received();
        $this->assertSame(['DONE', 'UNCONFIRMED'], [$r['status'], $r['old_session']]);
        $this->assertSame('COA_NAK', $this->confirmations($c2)[0]['identities'][0]['sessions'][0]['state']);
        $this->customersChanged();
    }

    public function testNoAnswerAndUnknownNasAreUnconfirmedWithoutRetryOrRevert(): void
    {
        [, , $c] = $this->activated();
        $old = $this->username($c);
        $new = 'a3n' . bin2hex(random_bytes(4));
        $stub = $this->stub('silent');
        $this->open($old, 'a3old04');
        $this->open($old, 'a3old04x', '192.0.2.77');   // a second NAS that is not configured
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']);
        $got = $stub->received();
        $this->assertSame(['DONE', 'UNCONFIRMED'], [$r['status'], $r['old_session']]);
        $this->assertCount(3, $got, '1 + 2 identical retransmissions, never a new request');
        $this->assertCount(1, array_unique(array_map(static fn(array $g): string => $g['id'] . '/' . $g['auth'], $got)), 'identical (same identifier and authenticator)');
        $states = array_column($this->confirmations($c)[0]['identities'][0]['sessions'], 'state');
        $this->assertSame(['COA_NO_RESPONSE', 'NAS_UNKNOWN'], $states, 'one result per captured row, each to its own NAS');
        $this->assertSame($new, $this->username($c), 'no rollback of the identity');
        $this->customersChanged();
    }

    public function testNoOpenRowIsNoAccountingEvidenceAndNothingIsSent(): void
    {
        [, , $c] = $this->activated();
        $stub = $this->stub('silent');
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']);
        $this->assertSame([], $stub->received(), 'nothing to target');
        $this->assertSame(['DONE', 'NO_ACCOUNTING_EVIDENCE'], [$r['status'], $r['old_session']], 'not CONFIRMED: no proof that it is offline');
        $this->assertSame([], $this->drift($c), 'drift reports UNCONFIRMED / missing confirmations only');
        $this->customersChanged();
    }

    // ------------------------------------------------------------------ I7 / I8: PPPoE usernames and deduplication

    /** @return array{0:int,1:int} customer, PPPoE plan (a Hotspot activation + a PPPoE recharge, provisioned) */
    private function withPppoe(string $pppoe, bool $hotspotToo = false): array
    {
        [, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, ['pppoe_username' => $pppoe]);
        if ($hotspotToo) {
            $this->recharge($c, $this->radiusPlan('20', 'Hotspot'), '2031-01-01', '10:00:00');
        }
        $plan = $this->radiusPlan('20', 'PPPOE');
        $this->recharge($c, $plan, '2031-01-01', '10:00:00', 'on', 'PPPOE');
        $this->assertSame('DONE', R::run($this->ctx('ad'), $c, R::ADMIN_SYNC)['status']);
        return [$c, $plan];
    }

    public function testPppoeUsernameOldToNewTargetsTheOldPppoeKey(): void
    {
        $oldP = 'a3p' . bin2hex(random_bytes(4));
        [$c] = $this->withPppoe($oldP);
        $this->assertNotSame([], $this->radcheck($oldP));
        $row = $this->open($oldP, 'a3ppp01');
        $stub = $this->stub('ack');
        $this->stopAfterSend([$row]);
        $newP = 'a3q' . bin2hex(random_bytes(4));
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $this->username($c), 'password' => '', 'pppoe_username' => $newP]);
        $stub->received();
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']]);
        $conf = $this->confirmations($c)[0];
        $this->assertSame([[$oldP, $newP]], array_map(static fn(array $i): array => [$i['from'], $i['to']], $conf['identities']));
        $this->customersChanged();
    }

    public function testPppoeUsernameEmptyToSetAndSetToEmptyTargetTheFormerPppoeKey(): void
    {
        // empty -> set: the PPPoE key so far is the username
        [$c] = $this->withPppoe('');
        $u = $this->username($c);
        $this->open($u, 'a3ppp02');
        $newP = 'a3q' . bin2hex(random_bytes(4));
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $u, 'password' => '', 'pppoe_username' => $newP]);
        $conf = $this->confirmations($c);
        $this->assertCount(1, $conf, 'one confirmation event (H-T1: a clean failure when it is missing)');
        $this->assertSame([[$u, $newP]], array_map(static fn(array $i): array => [$i['from'], $i['to']], end($conf)['identities']));
        $this->assertSame('UNCONFIRMED', $r['old_session'], 'no NAS configured for the row: not confirmed');
        $this->assertSame('NAS_UNKNOWN', end($conf)['identities'][0]['sessions'][0]['state']);
        $this->customersChanged();

        // set -> empty: the former PPPoE key is renamed back to the username
        $this->open($newP, 'a3ppp03');
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $u, 'password' => '', 'pppoe_username' => '']);
        $conf = $this->confirmations($c);
        $this->assertCount(2, $conf, 'a second confirmation event (H-T1)');
        $this->assertSame([[$newP, $u]], array_map(static fn(array $i): array => [$i['from'], $i['to']], end($conf)['identities']));
        $this->assertSame(['a3ppp03'], array_column(end($conf)['identities'][0]['sessions'], 'acct_session_id'));
        $this->customersChanged();
    }

    /**
     * Both plans of this customer are renamed in one run. PRE-EXISTING (not changed by A3, reported): the second plan's
     * rename of the same username deletes the rows the first plan just provisioned under the new key
     * (RadiusRepository::renameUser deletes the target first), so the re-provision ends REPROVISION_UNCONFIRMED. The
     * old-session result must be recorded all the same (owner condition "Failure Audit").
     */
    public function testUsernameAndPppoeTogetherGivePerIdentityResultsAndTheWorstWins(): void
    {
        $oldP = 'a3p' . bin2hex(random_bytes(4));
        [$c] = $this->withPppoe($oldP, true);
        $u = $this->username($c);
        $rowU = $this->open($u, 'a3both1');         // Hotspot session under the username
        $stub = $this->stub('ack');
        $this->stopAfterSend([$rowU]);
        $newU = 'a3n' . bin2hex(random_bytes(4));
        $newP = 'a3q' . bin2hex(random_bytes(4));
        $status = $this->runKeepingOutcome($c, ['username' => $newU, 'password' => '', 'pppoe_username' => $newP]);
        $this->assertCount(1, $stub->received(), 'one Disconnect: the username key once, the PPPoE key had no session');
        $all = $this->confirmations($c);
        $this->assertCount(1, $all);
        $byFrom = [];
        foreach ($all[0]['identities'] as $i) {
            $byFrom[$i['from']] = $i['state'];
        }
        $this->assertSame(['CONFIRMED', 'NO_ACCOUNTING_EVIDENCE'], [$byFrom[$u], $byFrom[$oldP]]);
        $this->assertSame('NO_ACCOUNTING_EVIDENCE', $all[0]['old_session'], 'worst of CONFIRMED and NO_ACCOUNTING_EVIDENCE');
        $this->assertSame($status, $all[0]['reprovision_outcome']);
        $this->customersChanged();
    }

    public function testTheSameOldKeyIsDisconnectedOnceAcrossPlans(): void
    {
        // Hotspot on the username, PPPoE on its own key: the username pair is applied for both plans (fixture corrected
        // 2026-10-13: two plans on the SAME key cannot verify even in an ADMIN_SYNC, a pre-existing property)
        [$c] = $this->withPppoe('a3p' . bin2hex(random_bytes(4)), true);
        $u = $this->username($c);
        $row = $this->open($u, 'a3dup01');
        $stub = $this->stub('ack');
        $this->stopAfterSend([$row]);
        $pppoe = (string) static::$db->value('SELECT pppoe_username FROM tbl_customers WHERE id = ?', [$c]);
        $this->runKeepingOutcome($c, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '', 'pppoe_username' => $pppoe]);
        $this->assertCount(1, $stub->received(), 'one Disconnect for the one old session');
        $conf = $this->confirmations($c)[0];
        $this->assertCount(1, $conf['identities'], 'one result per old identity');
        $this->assertSame([$row], self::targeted($conf));
        $this->assertSame('CONFIRMED', $conf['old_session']);
        $this->customersChanged();
    }

    /** IDENTITY_EDIT as admin; returns the re-provision outcome (DONE, or the reason of the DomainError). */
    private function runKeepingOutcome(int $c, array $requested): string
    {
        try {
            return R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, $requested)['status'];
        } catch (\Reseller\Domain\DomainError $e) {
            return $e->reason;
        }
    }

    // ------------------------------------------------------------------ I9-I11: exact identity

    public function testEquivalentRenameTargetsOnlyTheSessionsOpenBeforeTheRename(): void
    {
        $lower = 'a3e' . bin2hex(random_bytes(4));
        [, , $c] = $this->activated(['username' => $lower]);
        $before = $this->open($lower, 'a3eq01');
        $later = 0;
        // a session that starts while the rename runs (recorded under the new spelling): must never be targeted
        RadiusFaults::$hook = static function (string $p) use (&$later, $lower): void {
            if ($p === 'radius.after_write' && $later === 0) {
                $other = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword(), static::$db->name);
                $other->prepare("INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime) VALUES ('a3eq02', ?, ?, '127.0.0.1', UTC_TIMESTAMP())")
                    ->execute([substr(md5('a3eq02' . microtime()), 0, 32), strtoupper($lower)]);
                $later = (int) $other->lastInsertId();
            }
        };
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => strtoupper($lower), 'password' => '']);
        RadiusFaults::$hook = null;
        $this->assertGreaterThan(0, $later);
        $conf = $this->confirmations($c)[0];
        $this->assertTrue($conf['identities'][0]['equivalent']);
        $this->assertSame([$before], self::targeted($conf), 'only the snapshot row; the later session is not targeted');
        $this->assertSame('DONE', $r['status']);
        $this->customersChanged();
    }

    public function testNonEquivalentRenameTargetsEveryRecordedSpellingOfTheOldNameOnly(): void
    {
        $old = 'a3s' . bin2hex(random_bytes(4));
        [, , $c] = $this->activated(['username' => $old]);
        $new = 'a3n' . bin2hex(random_bytes(4));
        $a = $this->open($old, 'a3sp01');
        $b = $this->open(strtoupper($old), 'a3sp02');   // the NAS recorded another spelling of the same subscriber
        $n = $this->open($new, 'a3sp03');
        R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']);
        $conf = $this->confirmations($c)[0];
        $this->assertFalse($conf['identities'][0]['equivalent']);
        $this->assertSame([$a, $b], self::targeted($conf));
        $this->assertNotContains($n, self::targeted($conf));
        $this->customersChanged();
    }

    public function testAnotherSubscriberWithAnEquivalentNameMeansNothingIsSent(): void
    {
        $old = 'a3a' . bin2hex(random_bytes(4));
        [, , $c] = $this->activated(['username' => $old]);
        // legacy S-1 data: a different RADIUS key that the collation treats as equal
        static::$db->insertRow('radcheck', ['username' => strtoupper($old), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'other']);
        $this->open($old, 'a3amb01');
        $stub = $this->stub('ack');
        // OD-A3R-9 (2026-10-12; updated from the rev. 2 expectation): an ambiguous rename is refused BEFORE any write, so
        // there is no rename, no capture, no Disconnect and no confirmation event
        $before = $this->username($c);
        $this->expectExceptionReason('RENAME_AMBIGUOUS_IDENTITY', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']));
        $this->assertSame([], $stub->received(), 'no Disconnect: the session could belong to the other subscriber');
        $this->assertSame($before, $this->username($c), 'nothing written');
        $this->assertSame([], $this->confirmations($c));
        static::$db->exec("DELETE FROM radcheck WHERE value = 'other'");
    }

    public function testAnotherCustomerHoldingTheOldNameMeansNothingIsSent(): void
    {
        $old = 'a3b' . bin2hex(random_bytes(4));
        [$plan, $leaf, $c] = $this->activated(['username' => $old]);
        $this->customer(null, null, ['pppoe_username' => strtoupper($old)]);   // legacy data: an equivalent PPPoE username elsewhere
        $this->open($old, 'a3amb02');
        $stub = $this->stub('ack');
        // WP9 U0 final hardening (owner D-U0-R1, 2026-10-16): the old key has two logical owners (this customer and the
        // legacy PPPoE holder), so its RADIUS rows are never moved - the rename is refused before any write. Before
        // D-U0-R1 (WP8 A3) the rename ran and the old session was left UNCONFIRMED; "nothing is sent" is unchanged
        $before = static::$db->rows('SELECT username FROM tbl_customers WHERE id = ?', [$c]);
        $this->expectExceptionReason('RENAME_AMBIGUOUS_IDENTITY', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']));
        $this->assertSame([], $stub->received());
        $this->assertSame($before, static::$db->rows('SELECT username FROM tbl_customers WHERE id = ?', [$c]), 'nothing written');
    }

    // ------------------------------------------------------------------ I12 / I13: partial failure keeps the audit

    public function testRenameFailedIsRevertedAndNothingIsSent(): void
    {
        [, , $c] = $this->activated();
        $this->open($this->username($c), 'a3f01');
        $stub = $this->stub('ack');
        $this->failWrite(1);
        $this->expectExceptionReason('REPROVISION_FAILED', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']));
        RadiusFaults::$hook = null;
        $this->assertSame([], $stub->received(), 'the old identity is still the current one');
        $this->assertSame([], $this->confirmations($c));
        $this->assertSame('NOT_APPLICABLE', $this->lastMain($c, R::IDENTITY_EDIT)['old_session']);
    }

    public function testRenameAppliedThenAddFailedStillRecordsTheOldSession(): void
    {
        [, , $c] = $this->activated();
        $row = $this->open($this->username($c), 'a3f02');
        $stub = $this->stub('ack');
        $this->stopAfterSend([$row]);
        $this->failWrite(2);
        $this->expectExceptionReason(R::UNCONFIRMED, fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']));
        RadiusFaults::$hook = null;
        $this->assertCount(1, $stub->received());
        $conf = $this->confirmations($c);
        $this->assertCount(1, $conf, 'recorded although the re-provision is REPROVISION_UNCONFIRMED');
        $this->assertSame(['CONFIRMED', R::UNCONFIRMED], [$conf[0]['old_session'], $conf[0]['reprovision_outcome']]);
        $main = $this->lastMain($c, R::IDENTITY_EDIT);
        $this->assertSame([R::UNCONFIRMED, 'PENDING'], [$main['outcome'], $main['old_session']], 'the re-provision outcome rules are unchanged');
        $this->customersChanged();
    }

    public function testUnconfirmedRenameIsAnUnconfirmedOldSession(): void
    {
        [, , $c] = $this->activated();
        $this->open($this->username($c), 'a3f03');
        $stub = $this->stub('ack');
        RadiusFaults::$hook = static function (string $p): void {
            if ($p === 'radius.commit_lost') {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $this->expectExceptionReason(R::UNCONFIRMED, fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']));
        RadiusFaults::$hook = null;
        $this->assertSame([], $stub->received(), 'the rename is not confirmed: nothing targeted');
        $conf = $this->confirmations($c);
        $this->assertSame(['UNCONFIRMED', 'rename_not_confirmed'], [$conf[0]['identities'][0]['state'], $conf[0]['identities'][0]['reason']]);
        $this->assertCount(1, $this->drift($c));
        $this->customersChanged();
    }

    public function testAMissingConfirmationEventIsReportedByTheDriftReport(): void
    {
        [, , $c] = $this->activated();
        $this->open($this->username($c), 'a3f04');
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'reprovision.old_session_before_send') {
                throw new \RuntimeException('injected: process ended before the confirmation');
            }
        };
        try {
            R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'a3n' . bin2hex(random_bytes(4)), 'password' => '']);
            $this->fail('the injected crash must surface');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('injected', $e->getMessage());
        }
        ActivationFaults::$hook = null;
        $this->assertSame([], $this->confirmations($c));
        $this->assertSame('PENDING', $this->lastMain($c, R::IDENTITY_EDIT)['old_session']);
        $this->assertSame([['kind' => 'OLD_SESSION_UNCONFIRMED', 'customer_id' => $c, 'state' => 'CONFIRMATION_MISSING']], $this->drift($c));
        $this->customersChanged();
    }

    // ------------------------------------------------------------------ I15: lock safety and concurrency

    public function testDisconnectsRunWithoutTheTreeLockOrATransactionAndKeepTheCapturedIdentity(): void
    {
        [, , $c] = $this->activated();
        $old = $this->username($c);
        $new = 'a3n' . bin2hex(random_bytes(4));
        $row = $this->open($old, 'a3lk01');
        $stub = $this->stub('ack');
        $pdo = FinanceTx::pdo();
        $other = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword(), static::$db->name);
        $seen = [];
        $late = [];
        ActivationFaults::$hook = static function (string $p) use (&$seen, &$late, $pdo, $other, $old, $new, $row): void {
            if ($p === 'reprovision.old_session_before_send') {
                $st = $other->prepare('SELECT GET_LOCK(?, 0)');
                $st->execute([TreeSerialization::name($pdo)]);
                $seen = ['tree_lock_free' => (string) $st->fetchColumn() === '1', 'in_transaction' => $pdo->inTransaction()];
                $other->query('SELECT RELEASE_ALL_LOCKS()')->fetchAll();
                // between the capture and the send: a new session of the new name AND a new session of the old name
                foreach ([[$new, 'a3lk02'], [$old, 'a3lk03']] as [$u, $s]) {
                    $other->prepare("INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime) VALUES (?, ?, ?, '127.0.0.1', UTC_TIMESTAMP())")
                        ->execute([$s, substr(md5($s . microtime()), 0, 32), $u]);
                    $late[] = (int) $other->lastInsertId();
                }
            }
            if ($p === 'reprovision.old_session_after_send') {
                static::$db->exec('UPDATE radacct SET acctstoptime = UTC_TIMESTAMP() WHERE radacctid = ' . (int) $row);
            }
        };
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']);
        $this->assertSame(['tree_lock_free' => true, 'in_transaction' => false], $seen, 'no tree lock and no transaction while the NAS is contacted');
        $got = $stub->received();
        $this->assertCount(1, $got);
        $this->assertSame([$row], self::targeted($this->confirmations($c)[0]), 'only the captured row; sessions that appeared later are never targeted');
        $this->assertSame('CONFIRMED', $r['old_session']);
        static::$db->exec('DELETE FROM radacct WHERE radacctid IN (' . implode(',', $late) . ')');
        $this->customersChanged();
    }

    // ------------------------------------------------------------------ I14 / I16: legacy path and password change

    public function testLegacyUnassignedRenameAlsoEndsTheOldSession(): void
    {
        $plan = $this->radiusPlan('20', 'Hotspot');
        $c = $this->customer(null);
        $this->recharge($c, $plan, '2031-01-01', '10:00:00');
        $cust = static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$c])[0];
        $p = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [$plan])[0];
        (new \Radius())->add_customer($cust, $p);
        $old = $cust['username'];
        $new = 'a3l' . bin2hex(random_bytes(4));
        $this->open($old, 'a3leg01');
        $stub = $this->stub('silent');
        // the legacy controller order: the customer row is saved first, then the device calls
        static::$db->exec('UPDATE tbl_customers SET username = ' . static::$db->pdo->quote($new) . ' WHERE id = ' . $c);
        (new \Radius())->change_username($p, $old, $new);   // never throws because of the NAS
        $got = $stub->received();
        $this->assertCount(3, $got, 'the old session was targeted (no answer: identical retransmissions only)');
        $this->assertSame([], $this->radcheck($old));
        $this->assertNotSame([], $this->radcheck($new));
        $this->assertSame([], $this->confirmations($c), 'the legacy path keeps its behaviour: no new audit');
        $this->customersChanged();
    }

    public function testPasswordChangeIsNotApplicable(): void
    {
        [, , $c] = $this->activated();
        $this->open($this->username($c), 'a3pw01');
        $r = R::run($this->self($c), $c, R::PASSWORD_CHANGE, ['password' => 'a3-new-password']);
        $this->assertSame(['DONE', 'NOT_APPLICABLE'], [$r['status'], $r['old_session']]);
        $this->assertSame([], $this->confirmations($c));
        $this->customersChanged();
    }

    private function self(int $c): \Reseller\Security\ActorContext
    {
        return \Reseller\Security\ActorContext::forCustomer(static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$c])[0], 'WEB');
    }
}
