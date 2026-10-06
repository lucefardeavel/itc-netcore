<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8ActivationLabCase.php';

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationService;
use Reseller\Activation\ReconciliationService;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\Wp8ActivationLabCase;
use Reseller\Tests\Support\Wp8Lab;

/**
 * WP8 S6 — controlled failure / uncertain-outcome drills on the real lab (RouterOS CHR 7.24.5, FreeRADIUS 3.2.8, the
 * application in the FreeRADIUS network namespace). Failures are produced by lab-only means (FreeRADIUS pause, NAS link
 * down, RouterOS firewall rules / incoming setting, the accepted RADIUS fault hooks) and are always restored. After
 * every drill the full state is inspected and the invariants asserted: no double settlement, no lost reservation, no
 * blind business mutation retry, no fabricated success, UNKNOWN stays UNKNOWN / reconciliation, accounting history
 * never deleted. (The finance / activation invariants of the fixture are also asserted after every test.)
 */
final class FailureDrillsTest extends Wp8ActivationLabCase
{
    /** @var list<string> */
    private array $rules = [];

    protected function tearDown(): void
    {
        if (Wp8Lab::enabled()) {
            foreach ($this->rules as $id) {
                Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
            }
            $this->rules = [];
            Wp8Lab::ros('/radius/incoming/set', '=accept=yes', '=port=3799');
            Wp8Lab::agent('nas_link', ['state' => 'up']);
            Wp8Lab::agent('fr', ['action' => 'unpause']);
            RadiusFaults::$hook = null;
        }
        parent::tearDown();
    }

    private function block(string ...$words): string
    {
        return $this->rules[] = Wp8Lab::rosAdd('/ip/firewall/filter/add', ...[...$words, '=action=drop', '=comment=nuxr_wp8_drill']);
    }

    private function unblockAll(): void
    {
        foreach ($this->rules as $id) {
            Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
        }
        $this->rules = [];
    }

    /** the full post-drill inspection */
    private function inspect(string $reference, string $user, array $h): array
    {
        $act = $this->act($reference);
        $id = (int) $act['id'];
        $legs = $this->activationLedger($id);
        return [
            'status' => $act['status'], 'error_code' => $act['error_code'], 'timeline' => $this->timeline($reference),
            'reservations' => array_map(static fn($r) => [$r['status'], $r['amount']], $this->reservations($id)),
            'wallets' => ['A' => $this->balance($h['A']), 'B' => $this->balance($h['B']), 'C' => $this->balance($h['C'])],
            'ledger' => array_map(static fn($l) => [(int) $l['manager_id'], $l['entry_type'], $l['amount']], $legs),
            'provider_settlement' => $act['provider_settlement_amount'],
            'policy' => $this->policy($user), 'radacct' => array_map(static fn($r) => [$r['acctsessionid'], $r['acctstoptime'], $r['acctterminatecause']], $this->acct($user)),
        ];
    }

    /** invariants after every drill */
    private function invariants(array $s): void
    {
        $debits = count(array_filter($s['ledger'], static fn($l) => $l[1] === 'ACTIVATION_DEBIT'));
        $this->assertLessThanOrEqual(1, $debits, 'no double settlement');
        $res = array_column($s['reservations'], 0);
        $this->assertCount(1, $res, 'exactly one reservation per activation');
        $expected = match ($s['status']) {
            'COMPLETED' => 'COMMITTED', 'FAILED' => 'RELEASED', 'RECONCILIATION_REQUIRED', 'RETRY_PENDING', 'FUNDS_RESERVED', 'VERIFIED' => 'ACTIVE',
            default => null,
        };
        $this->assertNotNull($expected, 'known state ' . $s['status']);
        $this->assertSame($expected, $res[0], "reservation consistent with {$s['status']} (none lost)");
        $this->assertSame($s['status'] === 'COMPLETED' ? 1 : 0, $debits, 'a ledger leg exists only for a completed activation');
    }

    private function onSession(array $h, int $plan, string $password, string $client = 'c1'): array
    {
        $cust = $this->labCustomer($h['C'], $h['sales'], $password);
        $user = $this->username($cust);
        $new = $this->activate($h['sales'], $cust, $plan, 'NEW');
        $this->assertSame('COMPLETED', $new['status']);
        $up = Wp8Lab::agent('pppoe_up', ['client' => $client, 'user' => $user, 'password' => $password]);
        $this->assertTrue($up['ok'], json_encode($up['log']));
        Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1);
        return [$cust, $user, $new['reference']];
    }

    private function annotation(string $reference): ?array
    {
        $m = static::$db->value("SELECT e.metadata FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ? AND e.event_type = 'ANNOTATION' ORDER BY e.sequence_no DESC LIMIT 1", [$reference]);
        return $m === null ? null : json_decode((string) $m, true);
    }

    /** drill 1 + 6: FreeRADIUS unreachable before provisioning, and the resulting authentication timeout */
    public function testDrill1and6FreeRadiusUnreachableAndAuthenticationTimeout(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'D1-pass');
        $user = $this->username($cust);
        $timeouts0 = (int) Wp8Lab::ros('/radius/monitor', '=numbers=*1', '=once=')[0]['timeouts'];
        $this->assertTrue(Wp8Lab::agent('fr', ['action' => 'pause'])['ok']);
        // provisioning targets the RADIUS database, not the FreeRADIUS process: the activation is unaffected
        $out = $this->activate($h['sales'], $cust, $plan, 'NEW');
        $s = $this->inspect($out['reference'], $user, $h);
        $this->assertSame('COMPLETED', $s['status']);
        $this->invariants($s);
        // drill 6: the subscriber cannot authenticate while FreeRADIUS does not answer
        $auth = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D1-pass', 'wait' => 30]);
        $this->assertFalse($auth['ok'], 'no session without an answer from FreeRADIUS');
        $timeouts1 = (int) Wp8Lab::ros('/radius/monitor', '=numbers=*1', '=once=')[0]['timeouts'];
        $this->assertGreaterThan($timeouts0, $timeouts1, 'RouterOS counted RADIUS timeouts');
        $this->assertSame($s, $this->inspect($out['reference'], $user, $h), 'no application state change during the timeout');
        // drill 10: path restored
        $this->assertTrue(Wp8Lab::agent('fr', ['action' => 'unpause'])['ok']);
        $back = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D1-pass']);
        $this->assertTrue($back['ok'], 'service after FreeRADIUS returned');
        Wp8Lab::evidence('S6.D1+D6.freeradius-unreachable', ['activation' => $s, 'auth_during_outage' => $auth['log'], 'routeros_timeouts' => [$timeouts0, $timeouts1], 'after_restore' => $back['ip']]);
    }

    /** drill 2: RADIUS database failure before COMMIT (confirmed failure, and a transient one) */
    public function testDrill2RadiusDatabaseFailureBeforeCommit(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'D2-pass');
        $user = $this->username($cust);
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.after_write' && ++$calls === 2) { // the subscriber policy write (the 1st is the plan group)
                throw new \PDOException('Table nuxr_radius.radcheck is marked as crashed');
            }
        };
        $out = $this->activate($h['sales'], $cust, $plan, 'NEW');
        RadiusFaults::$hook = null;
        $s = $this->inspect($out['reference'], $user, $h);
        $this->assertSame('FAILED', $s['status'], 'confirmed pre-commit failure -> FAILED');
        $this->assertSame(['check' => [], 'reply' => [], 'groups' => []], $s['policy'], 'rolled back: no partial subscriber policy');
        $this->assertSame(['A' => '700.00', 'B' => '200.00', 'C' => '100.00'], $s['wallets'], 'reservation released, no money moved');
        $this->invariants($s);
        $this->assertFalse(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D2-pass', 'wait' => 25])['ok'], 'no service');

        // transient (MySQL 2006) on the first subscriber write: bounded automatic retry of the idempotent policy write
        $cust2 = $this->labCustomer($h['C'], $h['sales'], 'D2b-pass');
        $user2 = $this->username($cust2);
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.after_write' && ++$calls === 2) {
                $e = new \PDOException('MySQL server has gone away');
                $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
                throw $e;
            }
        };
        $out2 = $this->activate($h['sales'], $cust2, $plan, 'NEW');
        RadiusFaults::$hook = null;
        if ($out2['status'] === 'RETRY_PENDING') {
            ActivationService::execute((int) $this->act($out2['reference'])['id'], ActivationActor::system('wp8-drill'));
        }
        $s2 = $this->inspect($out2['reference'], $user2, $h);
        $this->assertSame('COMPLETED', $s2['status']);
        $this->assertNotEmpty(preg_grep('/RETRY_PENDING/', $s2['timeline']), 'the transient failure is visible in the timeline');
        $this->invariants($s2);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user2, 'password' => 'D2b-pass'])['ok']);
        Wp8Lab::evidence('S6.D2.radius-db-failure', ['confirmed' => $s, 'transient' => ['first_status' => $out2['status'], 'final' => $s2]]);
    }

    /** drill 3: lost / uncertain RADIUS commit -> UNKNOWN -> reconciliation, never a blind retry or double settlement */
    public function testDrill3UncertainRadiusCommitGoesToReconciliation(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'D3-pass');
        $user = $this->username($cust);
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.commit_lost' && ++$calls === 2) {
                throw new \PDOException('Lost connection to MySQL server during query');
            }
        };
        $out = $this->activate($h['sales'], $cust, $plan, 'NEW');
        RadiusFaults::$hook = null;
        $s = $this->inspect($out['reference'], $user, $h);
        $this->assertSame(['RECONCILIATION_REQUIRED', 'RADIUS_COMMIT_UNKNOWN'], [$s['status'], $s['error_code']], 'UNKNOWN stays UNKNOWN');
        $this->assertSame([['ACTIVE', '17.00']], $s['reservations'], 'funds stay reserved, not settled, not released');
        $this->assertSame([], $s['ledger']);
        $this->invariants($s);
        $this->assertSame(1, count(preg_grep('/PROVISIONING_RESULT/', $s['timeline'])), 'no blind retry of the provisioning');
        // the commit had in fact landed: the policy exists and the NAS serves it (financially still unsettled)
        $this->assertSame('D3-pass', $s['policy']['check']['Cleartext-Password'] ?? null);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D3-pass'])['ok']);
        // reconciliation (accepted service) verifies the RADIUS state and settles exactly once
        $r = ReconciliationService::reconcile(ActivationActor::system('wp8-drill'), $out['reference'], 0);
        $this->assertSame('COMPLETED', $r['status']);
        $s2 = $this->inspect($out['reference'], $user, $h);
        $this->invariants($s2);
        $this->assertSame(['A' => '703.00', 'B' => '202.00', 'C' => '83.00'], $s2['wallets']);
        try {
            ReconciliationService::reconcile(ActivationActor::system('wp8-drill'), $out['reference'], 0);
            $second = 'ACCEPTED';
        } catch (\Reseller\Domain\DomainError $e) {
            $second = $e->reason;
        }
        $this->assertNotSame('ACCEPTED', $second, 'a completed activation cannot be reconciled again');
        $this->assertSame($s2, $this->inspect($out['reference'], $user, $h), 'and nothing changed');
        Wp8Lab::evidence('S6.D3.uncertain-commit', ['after_failure' => $s, 'after_reconcile' => $s2, 'second_reconcile' => $second]);
    }

    /** drill 4: NAS unavailable for the Disconnect of a CHANGE_PLAN */
    public function testDrill4NasUnavailableForDisconnect(): void
    {
        $h = $this->hierarchy('1000.00', '300.00', '200.00');
        $plan1 = $this->chainPlan($h);
        $plan2 = $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00', 'PPPOE', ['pool' => 'nuxr_wp8_pool_dyn2']);
        [$cust, $user] = $this->onSession($h, $plan1, 'D4-pass');
        $sid = Wp8Lab::pppActive($user)[0]['session-id'];
        Wp8Lab::agent('nas_link', ['state' => 'down']);
        $out = $this->activate($h['sales'], $cust, $plan2, 'CHANGE_PLAN');
        Wp8Lab::agent('nas_link', ['state' => 'up']);
        $s = $this->inspect($out['reference'], $user, $h);
        $this->assertSame('COMPLETED', $s['status'], 'the disconnect is best effort: the paid change completes');
        $this->assertSame(['disconnect' => ['UNKNOWN:COA_NO_RESPONSE']], $this->annotation($out['reference']), 'UNKNOWN recorded, nothing fabricated');
        $this->invariants($s);
        $this->assertSame($sid, Wp8Lab::pppActive($user)[0]['session-id'] ?? null, 'the old session (old policy) is still up on the NAS');
        // drill 10: path restored -> the operator repeats the disconnect explicitly (never retried automatically)
        $redo = array_map(static fn($r) => $r->toArray(), $this->coa()->disconnect($user));
        $this->assertSame(['SUCCESS', 'COA_ACK'], [$redo[0]['status'], $redo[0]['code']]);
        Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1']);
        $re = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D4-pass']);
        $this->assertMatchesRegularExpression('/^10\.88\.10\.(1[5-9]\d)$/', (string) $re['ip'], 'the new plan after re-authentication');
        Wp8Lab::evidence('S6.D4.nas-unavailable', ['activation' => $s, 'annotation' => $this->annotation($out['reference']), 'old_session_survived' => $sid, 'manual_disconnect_after_restore' => $redo, 'new_ip' => $re['ip']]);
    }

    /** drill 5: incoming CoA disabled on the NAS */
    public function testDrill5CoaIncomingBlocked(): void
    {
        $h = $this->hierarchy('1000.00', '300.00', '200.00');
        $plan1 = $this->chainPlan($h);
        $plan2 = $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00', 'PPPOE', ['pool' => 'nuxr_wp8_pool_dyn2']);
        [$cust, $user] = $this->onSession($h, $plan1, 'D5-pass');
        Wp8Lab::ros('/radius/incoming/set', '=accept=no');
        Wp8Lab::agent('capture_start', ['name' => 's6-d5']);
        $out = $this->activate($h['sales'], $cust, $plan2, 'CHANGE_PLAN');
        $pk = array_values(array_filter(Wp8Lab::agent('capture_stop', ['name' => 's6-d5'])['packets'], static fn($p) => str_starts_with($p['code'], 'Disconnect')));
        Wp8Lab::ros('/radius/incoming/set', '=accept=yes', '=port=3799');
        $s = $this->inspect($out['reference'], $user, $h);
        $this->assertSame('COMPLETED', $s['status']);
        $ann = $this->annotation($out['reference']);
        $this->assertSame(['disconnect' => ['UNKNOWN:COA_NO_RESPONSE']], $ann, 'the NAS does not answer: UNKNOWN');
        $this->assertCount(3, $pk, 'original + 2 identical retransmissions, no new request');
        $this->assertCount(1, array_unique(array_column($pk, 'id')));
        $this->invariants($s);
        $this->assertCount(1, Wp8Lab::pppActive($user), 'nothing fabricated: the session is still up');
        $this->assertSame('SUCCESS', $this->coa()->disconnect($user)[0]->status, 'path restored');
        Wp8Lab::evidence('S6.D5.coa-incoming-blocked', ['activation' => $s, 'annotation' => $ann, 'packets' => $pk]);
    }

    /**
     * drill 7 (owner S6 special blocker): accounting interrupted while the subscriber disconnects -> the Stop is lost.
     * Investigates whether the open radacct row locks the subscriber out (Simultaneous-Use 1) and which EXISTING
     * recovery mechanisms there are. Nothing is deleted, no acctstoptime is set by hand, nothing is bypassed.
     */
    public function testDrill7LostAccountingStopAndSimultaneousUse(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h);
        [$cust, $user, $ref] = $this->onSession($h, $plan, 'D7-pass');
        $open = $this->acct($user);
        $this->assertCount(1, $open);
        $this->assertNull($open[0]['acctstoptime'], '1-2. real session, radacct open');
        $this->block('=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10');   // 3.
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);                                                  // 4. the subscriber disconnects
        $this->assertTrue((bool) Wp8Lab::until(fn() => Wp8Lab::pppActive($user) === [], 20, 1), '5. the NAS session is gone');
        sleep(8);                                                                                          // RouterOS gives up the Stop
        $this->unblockAll();                                                                               // 6. accounting path restored
        sleep(75);                                                                                         // > one interim period
        $row = $this->acct($user);
        $this->assertCount(1, $row);
        $this->assertNull($row[0]['acctstoptime'], '7-8. the Stop was not resent: radacct stays OPEN');
        $relogin = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D7-pass', 'wait' => 25]);
        $reply = self::lastReply($user);
        $this->assertFalse($relogin['ok']);
        $this->assertSame('Access-Reject', $reply['code'], '9-10. the real re-login is rejected (Simultaneous-Use 1 counts the stale row)');
        $simul = Wp8Lab::frLog('[Mm]ultiple logins|Simultaneous|[Cc]heckrad|assuming user is logged in|session: ');
        // 11. existing mechanisms: application (reports only, CoA finds no session), FreeRADIUS (no NAS verification)
        $stale = (new \Reseller\Radius\AccountingService(static::labRadius()))->staleSessions(new \DateTimeImmutable('now'), 60);
        $staleListed = in_array($row[0]['acctsessionid'], array_column($stale, 'acctsessionid'), true);
        $coa = $this->coa()->disconnect($user)[0]->toArray();
        $this->assertSame(['FAILED', 'COA_NAK', 406], [$coa['status'], $coa['code'], $coa['metadata']['error_cause'] ?? null], 'the application CoA cannot clear it (no session on the NAS)');
        $this->assertNull($this->acct($user)[0]['acctstoptime'], 'nothing in the application closes the row (accepted WP4 Q8: report only)');
        sleep(60);
        $this->assertFalse(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D7-pass', 'wait' => 25])['ok'], 'still locked out minutes later');
        // 12. the only existing recovery: the NAS-level Accounting-On (RouterOS restart) -> stock accounting-on query
        //     closes every open row of that NAS (UPDATE, NAS-Reboot; history kept)
        try {
            Wp8Lab::ros('/system/reboot');
        } catch (\RuntimeException) {
            // the API connection drops while the router restarts
        }
        sleep(20);
        $this->assertTrue((bool) Wp8Lab::until(static function () {
            try {
                return Wp8Lab::ros('/system/identity/print') !== [];
            } catch (\RuntimeException) {
                return false;
            }
        }, 240, 5), 'the CHR came back');
        $afterBoot = Wp8Lab::until(fn() => $this->acct($user)[0]['acctstoptime'] !== null ? $this->acct($user)[0] : null, 60, 3);
        $trigger = null;
        if ($afterBoot === null) {
            // Accounting-On only precedes the NAS's first accounting record: start one other session to trigger it
            $other = $this->labCustomer($h['C'], $h['sales'], 'D7-other');
            $this->assertSame('COMPLETED', $this->activate($h['sales'], $other, $plan, 'NEW')['status']);
            $trigger = Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $this->username($other), 'password' => 'D7-other'])['ok'];
            $afterBoot = Wp8Lab::until(fn() => $this->acct($user)[0]['acctstoptime'] !== null ? $this->acct($user)[0] : null, 30, 2);
        }
        $this->assertNotNull($afterBoot, 'Accounting-On closed the stale row');
        $this->assertSame('NAS-Reboot', $afterBoot['acctterminatecause']);
        $back = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D7-pass']);
        $this->assertTrue($back['ok'], 'unlocked only after the NAS-level Accounting-On');
        $this->assertCount(2, $this->acct($user), 'history kept: the closed stale row + the new session');
        $this->invariants($this->inspect($ref, $user, $h));
        Wp8Lab::evidence('S6.D7.lost-stop', ['open_row_after_restore' => $row[0], 'relogin' => ['reply' => $reply, 'client' => $relogin['log']],
            'freeradius_simultaneous_use_log' => array_slice($simul, -8), 'application_stale_report_lists_it' => $staleListed, 'application_coa' => $coa,
            'locked_after_minutes' => true, 'recovery' => ['mechanism' => 'RouterOS restart -> Accounting-On -> stock accounting-on query', 'needed_other_session_to_trigger' => $trigger,
            'closed_row' => $afterBoot, 'relogin_after' => $back['ok']]]);
    }

    /** drill 8: RouterOS API unavailable - not on the FreeRADIUS-first activation path */
    public function testDrill8RouterOsApiUnavailable(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h);
        $this->block('=chain=input', '=protocol=tcp', '=dst-port=8728', '=in-interface=ether2');
        $prev = error_reporting(E_ALL & ~E_DEPRECATED);
        require_once dirname(__DIR__, 3) . '/system/autoload/PEAR2/Autoload.php';
        $old = ini_set('default_socket_timeout', '8');
        $t = microtime(true);
        try {
            // exactly how the application builds its client (system/autoload/Mikrotik.php getClient: no timeout argument)
            new \PEAR2\Net\RouterOS\Client(Wp8Lab::CHR, 'admin', (string) getenv('NUXR_WP8_CHR_PASSWORD'), null);
            $api = 'CONNECTED';
        } catch (\Throwable $e) {
            $api = get_class($e) . ': ' . $e->getMessage();
        } finally {
            ini_set('default_socket_timeout', (string) $old);
            error_reporting($prev);
        }
        $elapsed = round(microtime(true) - $t, 1);
        $this->assertNotSame('CONNECTED', $api, 'the API is unreachable');
        // the reseller activation path does not use the RouterOS API at all
        [$cust, $user, $ref] = $this->onSession($h, $plan, 'D8-pass');
        $s = $this->inspect($ref, $user, $h);
        $this->assertSame('COMPLETED', $s['status']);
        $this->invariants($s);
        $this->unblockAll();
        Wp8Lab::evidence('S6.D8.api-unavailable', ['pear2' => $api, 'seconds' => $elapsed, 'timeout_source' => 'no explicit timeout in the application: default_socket_timeout (8 s in the drill, PHP default 60 s)', 'activation_unaffected' => $s['status']]);
    }

    /** drill 9: the Disconnect reaches the NAS but the answer is lost */
    public function testDrill9DisconnectProcessedButResponseLost(): void
    {
        $h = $this->hierarchy('1000.00', '300.00', '200.00');
        $plan1 = $this->chainPlan($h);
        $plan2 = $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00', 'PPPOE', ['pool' => 'nuxr_wp8_pool_dyn2']);
        [$cust, $user] = $this->onSession($h, $plan1, 'D9-pass');
        $this->block('=chain=output', '=protocol=udp', '=src-port=3799', '=dst-address=10.88.0.10');
        $out = $this->activate($h['sales'], $cust, $plan2, 'CHANGE_PLAN');
        $this->unblockAll();
        $s = $this->inspect($out['reference'], $user, $h);
        $this->assertSame('COMPLETED', $s['status']);
        $this->assertSame(['disconnect' => ['UNKNOWN:COA_NO_RESPONSE']], $this->annotation($out['reference']), 'no answer -> UNKNOWN, even though the NAS acted');
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down'], 'the NAS did disconnect the subscriber');
        $stop = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 30, 2);
        $this->assertSame('NAS-Request', $stop['acctterminatecause'] ?? null, 'Stop accounting written');
        $this->invariants($s);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'D9-pass'])['ok'], 'path restored, re-authentication on the new plan');
        Wp8Lab::evidence('S6.D9.response-lost', ['activation' => $s, 'annotation' => $this->annotation($out['reference']), 'stop' => $stop]);
    }

    /** drill 10: everything restored */
    public function testDrill10PathRecoveryLeavesNoDrillResidue(): void
    {
        $this->assertSame([], Wp8Lab::ros('/ip/firewall/filter/print', '?comment=nuxr_wp8_drill'), 'no drill rule left');
        $this->assertSame(['true', '3799'], [Wp8Lab::ros('/radius/incoming/print')[0]['accept'], Wp8Lab::ros('/radius/incoming/print')[0]['port']]);
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h);
        [$cust, $user, $ref] = $this->onSession($h, $plan, 'D10-pass');
        $res = $this->coa()->disconnect($user)[0];
        $this->assertSame(['SUCCESS', 'COA_ACK'], [$res->status, $res->code], 'CoA path healthy');
        $this->invariants($this->inspect($ref, $user, $h));
        Wp8Lab::evidence('S6.D10.recovery', ['firewall_rules' => 0, 'incoming' => 'accept=yes port=3799', 'auth' => 'ok', 'coa' => 'ACK']);
    }
}
