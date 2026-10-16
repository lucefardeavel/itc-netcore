<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\VoucherRedemptionService;
use Reseller\Finance\IntegrityCheck;
use Reseller\Radius\AdmissionRecovery;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FixtureBuilder;

/**
 * WP9-OPS (owner §3 operational integrity): the operator recovery tools on synthetic data.
 *  - finance-check (IntegrityCheck): consistent database -> no issue; a tampered ledger / wallet is detected;
 *  - voucher review: confirm only with complete grant evidence, deny suspends (nothing deleted), release only when
 *    nothing of the grant exists; every decision exactly once and audited without the code;
 *  - admission release: only an old PENDING claim with that token and no open session; refusals audited; the audit
 *    carries the database-attested account.
 */
final class W9RecoveryToolsTest extends ActivationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        (new FixtureBuilder(dirname(__DIR__, 3)))->loadSqlFile(static::$db->pdo, dirname(__DIR__, 3) . '/install/freeradius/nuxr_admission.sql');
        static::$db->strictSession();
        $GLOBALS['DEVICE_PATH'] = dirname(__DIR__, 3) . '/system/devices';
        $GLOBALS['_app_stage'] = 'Live';
    }

    public function testFinanceCheckIsCleanAndDetectsTampering(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $this->assertSame('COMPLETED', $this->activate($leaf, $this->customer($leaf), $plan, 'NEW')['status']);
        $this->assertSame([], IntegrityCheck::current(), 'consistent database');
        // a tamper that the row CHECK (ck_ledger_math) still accepts: amount and balance_after moved together
        $e = static::$db->rows('SELECT id, amount, balance_after FROM tbl_manager_ledger ORDER BY id DESC LIMIT 1')[0];
        static::$db->exec("UPDATE tbl_manager_ledger SET amount = amount + 1, balance_after = IF(direction = 'CREDIT', balance_after + 1, balance_after - 1) WHERE id = " . (int) $e['id']);
        try {
            $this->assertNotSame([], IntegrityCheck::current(), 'a tampered ledger entry is detected');
        } finally {
            static::$db->exec('UPDATE tbl_manager_ledger SET amount = ' . static::$db->db()->pdo->quote((string) $e['amount']) . ', balance_after = ' . static::$db->db()->pdo->quote((string) $e['balance_after']) . ' WHERE id = ' . (int) $e['id']);
        }
        $w = static::$db->rows('SELECT manager_id, balance FROM tbl_manager_wallets WHERE manager_id = ?', [$leaf])[0];
        static::$db->exec("UPDATE tbl_manager_wallets SET balance = balance + 5 WHERE manager_id = {$leaf}");
        try {
            $this->assertNotSame([], IntegrityCheck::current(), 'a wallet changed outside the ledger is detected');
        } finally {
            static::$db->exec('UPDATE tbl_manager_wallets SET balance = ' . static::$db->db()->pdo->quote((string) $w['balance']) . " WHERE manager_id = {$leaf}");
        }
        $this->assertSame([], IntegrityCheck::current(), 'restored');
    }

    /** a CLAIMED voucher-only voucher with the chosen grant evidence; returns [voucher id, code] */
    private function claimedVoucher(bool $recharge, bool $invoice, bool $radius): array
    {
        $plan = $this->radiusPlan();
        $code = 'W9REV' . strtoupper(bin2hex(random_bytes(4)));
        $id = static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'radius', 'id_plan' => $plan, 'code' => $code, 'user' => '0', 'status' => 'CLAIMED', 'generated_by' => 0]);
        if ($recharge) {
            static::$db->insertRow('tbl_user_recharges', ['customer_id' => 0, 'username' => $code, 'plan_id' => $plan, 'namebp' => 'w9', 'recharged_on' => date('Y-m-d'), 'recharged_time' => date('H:i:s'),
                'expiration' => date('Y-m-d', time() + 86400 * 30), 'time' => '00:00:00', 'status' => 'on', 'method' => 'Voucher - ' . $code, 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 0]);
        }
        if ($invoice) {
            static::$db->insertRow('tbl_transactions', ['invoice' => 'INV-W9' . $id, 'username' => $code, 'user_id' => 0, 'plan_name' => 'w9', 'price' => '1', 'recharged_on' => date('Y-m-d'), 'recharged_time' => date('H:i:s'),
                'expiration' => date('Y-m-d', time() + 86400 * 30), 'time' => '00:00:00', 'method' => 'Voucher - ' . $code, 'routers' => 'radius', 'type' => 'Hotspot']);
        }
        if ($radius) {
            static::$db->insertRow('radcheck', ['username' => $code, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $code]);
        }
        return [$id, $code];
    }

    private function voucherStatus(int $id): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_voucher WHERE id = ?', [$id]);
    }

    public function testVoucherReviewDecisionsFollowTheEvidence(): void
    {
        // complete grant: confirm; never release or deny-twice
        [$a, $codeA] = $this->claimedVoucher(true, true, true);
        $this->expectReason('VOUCHER_REVIEW_EVIDENCE', fn() => VoucherRedemptionService::reviewResolve($a, 'release', 'noc', 'operator review test'));
        $this->assertSame('CLAIMED', $this->voucherStatus($a));
        $listed = array_column(VoucherRedemptionService::reviewList(), null, 'voucher_id');
        $this->assertSame(['recharges' => 1, 'recharges_on' => 1, 'voucher_only' => true, 'transactions' => 1, 'radius_rows' => 1],
            array_intersect_key($listed[$a], array_flip(['recharges', 'recharges_on', 'voucher_only', 'transactions', 'radius_rows'])));
        $this->assertStringNotContainsString($codeA, json_encode(VoucherRedemptionService::reviewList()), 'the code is never listed');
        $this->assertSame('CONFIRMED', VoucherRedemptionService::reviewResolve($a, 'confirm', 'noc', 'grant verified on RADIUS')['outcome']);
        $this->assertSame('1', $this->voucherStatus($a));
        $this->expectReason('ACTIVATION_STATE_CONFLICT', fn() => VoucherRedemptionService::reviewResolve($a, 'confirm', 'noc', 'grant verified on RADIUS'), 'exactly once');

        // incomplete grant (no RADIUS rows): confirm refused; deny suspends nothing that is absent but turns the period off
        [$b, $codeB] = $this->claimedVoucher(true, true, false);
        $this->expectReason('VOUCHER_REVIEW_EVIDENCE', fn() => VoucherRedemptionService::reviewResolve($b, 'confirm', 'noc', 'grant verified on RADIUS'));
        // deny with RADIUS rows: suspended (random password), recharge off, voucher used, invoice kept
        [$c, $codeC] = $this->claimedVoucher(true, true, true);
        $this->assertSame('DENIED', VoucherRedemptionService::reviewResolve($c, 'deny', 'noc', 'device state unknown, service withdrawn')['outcome']);
        $this->assertNotSame($codeC, (string) static::$db->value("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'", [$codeC]), 'suspended');
        $this->assertSame('off', static::$db->value('SELECT status FROM tbl_user_recharges WHERE username = ?', [$codeC]));
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE username = ?', [$codeC]), 'history kept');
        $this->assertSame('1', $this->voucherStatus($c));

        // nothing started: release makes it redeemable again
        [$d] = $this->claimedVoucher(false, false, false);
        $this->expectReason('VOUCHER_REVIEW_EVIDENCE', fn() => VoucherRedemptionService::reviewResolve($d, 'deny', 'noc', 'nothing to deny here'));
        $this->assertSame('RELEASED', VoucherRedemptionService::reviewResolve($d, 'release', 'noc', 'claim never started')['outcome']);
        $this->assertSame('0', $this->voucherStatus($d));

        $audit = static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'voucher.review_resolve'");
        $this->assertCount(3, $audit, 'every applied decision audited once (refusals change nothing): ' . json_encode(array_column($audit, 'after_json')));
        foreach ([$codeA, $codeB, $codeC] as $code) {
            $this->assertStringNotContainsString($code, json_encode($audit), 'no code in the audit');
        }
        $this->expectReason('INVALID_INPUT', fn() => VoucherRedemptionService::reviewResolve($b, 'confirm', '', 'x'));
    }

    private function expectReason(string $reason, callable $fn, string $label = ''): void
    {
        try {
            $fn();
            $this->fail("expected {$reason} {$label}");
        } catch (DomainError $e) {
            $this->assertSame($reason, $e->reason, $label . ' ' . $e->getMessage());
        }
    }

    private function claim(string $user, int $ageSeconds): string
    {
        $token = bin2hex(random_bytes(16));
        static::$db->exec("INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, attach_count, created_at, updated_at)
            VALUES (" . static::$db->db()->pdo->quote($user) . ", 'PENDING', '{$token}', '10.0.0.1', '81a0', 'aa:bb', 'ether1', '" . str_repeat('c', 32) . "', " . static::$db->db()->pdo->quote($user) . ", 0, UTC_TIMESTAMP(6) - INTERVAL {$ageSeconds} SECOND, UTC_TIMESTAMP(6))");
        return $token;
    }

    public function testAdmissionReleaseIsSafetyGatedAndAudited(): void
    {
        $old = 'w9old' . bin2hex(random_bytes(2));
        $young = 'w9yng' . bin2hex(random_bytes(2));
        $live = 'w9liv' . bin2hex(random_bytes(2));
        $tOld = $this->claim($old, 1200);
        $tYoung = $this->claim($young, 30);
        $tLive = $this->claim($live, 1200);
        static::$db->insertRow('radacct', ['acctsessionid' => 'x1', 'acctuniqueid' => substr(md5($live), 0, 32), 'username' => $live, 'nasipaddress' => '10.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s')]);
        $pending = array_column(AdmissionRecovery::pending(), null, 'key_hex');
        $this->assertTrue($pending[strtoupper(bin2hex($old))]['releasable']);
        $this->assertFalse($pending[strtoupper(bin2hex($young))]['releasable'], 'too young');
        $this->assertFalse($pending[strtoupper(bin2hex($live))]['releasable'], 'open session');
        $ev = 'NAS rebooted 10:02 UTC, log line attached';
        $this->expectReason('ACTIVATION_STATE_CONFLICT', fn() => AdmissionRecovery::release(bin2hex($young), $tYoung, 'noc', 'user locked out', $ev));
        $this->expectReason('ACTIVATION_STATE_CONFLICT', fn() => AdmissionRecovery::release(bin2hex($live), $tLive, 'noc', 'user locked out', $ev));
        $this->expectReason('ACTIVATION_STATE_CONFLICT', fn() => AdmissionRecovery::release(bin2hex($old), str_repeat('d', 32), 'noc', 'user locked out', $ev), 'wrong token');
        $this->assertSame('RELEASED', AdmissionRecovery::release(bin2hex($old), $tOld, 'noc', 'user locked out', $ev)['outcome']);
        $this->assertSame('ENDED', static::$db->value('SELECT state FROM nuxr_admission WHERE username = ?', [$old]));
        $this->assertSame('PENDING', static::$db->value('SELECT state FROM nuxr_admission WHERE username = ?', [$young]));
        $this->assertSame('PENDING', static::$db->value('SELECT state FROM nuxr_admission WHERE username = ?', [$live]));
        $audit = static::$db->rows('SELECT outcome, evidence FROM nuxr_admission_audit ORDER BY id');
        $this->assertSame(['REFUSED', 'REFUSED', 'REFUSED', 'RELEASED'], array_column($audit, 'outcome'), 'every decision audited');
        $this->assertMatchesRegularExpression('/^\[db_account=root@[^ ]+ connection=[0-9]+ tool=reconcile\.php\] NAS rebooted/', (string) $audit[3]['evidence']);
    }
}
