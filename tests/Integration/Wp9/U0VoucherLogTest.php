<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 final hardening, N-07: a voucher code is a credential (voucher-only username = password = code). After the
 * redemption paths ran - public HTTP voucher-only login, the service with PUBLIC and RADIUS sources (granted, refused for
 * an identity in use, not valid, reseller-owned), the customer-portal path (audited) and the voucher + new username path -
 * neither the code nor its former log reference (sha256 prefix) appears in the PHP error log, tbl_logs, the reseller audit
 * table or the HTTP responses. Control: the outcomes themselves are unchanged and the log still names the outcome / reason.
 */
final class U0VoucherLogTest extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
    }

    public function testNoVoucherCodeOrItsShortHashIsLoggedOrEchoed(): void
    {
        $codes = [];
        $bodies = '';
        // public HTTP voucher-only activation (login.php E12)
        $httpCode = 'W9U0L' . strtoupper(bin2hex(random_bytes(5)));
        $this->voucher($httpCode);
        $codes[] = $httpCode;
        $c = $this->client();
        $r = $c->postWithToken('login/activation', ['voucher_only' => $httpCode], [], 'login');
        $bodies .= $r->body;
        $bodies .= $c->get('login')->body;   // the page that shows the flash message of the redirect
        $httpStatus = self::vstatus((int) static::$db->value('SELECT id FROM tbl_voucher WHERE code = ?', [$httpCode]));
        // the service, each outcome
        $out = [];
        foreach (['granted public' => false, 'granted radius' => true] as $k => $radius) {
            $code = self::tag();
            $this->voucher($code);
            $codes[] = $code;
            $out[$k] = (string) static::$app->exec(self::p6Code($code, $radius));
        }
        $taken = self::tag();
        $this->person($taken);
        $this->voucher($taken);
        $codes[] = $taken;
        $out['refused identity in use'] = (string) static::$app->exec(self::p6Code($taken));
        $unknown = self::tag();
        $codes[] = $unknown;
        $out['not valid'] = (string) static::$app->exec(self::p6Code($unknown));
        $owned = $this->person(self::tag(), '', 'A');
        $this->voucher($owned['username']);
        $codes[] = $owned['username'];
        $out['refused reseller-owned'] = (string) static::$app->exec(self::p6Code($owned['username']));
        $p3 = 'W9U0N' . strtoupper(bin2hex(random_bytes(4)));
        $this->voucher($p3);
        $codes[] = $p3;
        $out['new username'] = (string) static::$app->exec(self::p3Code($p3, self::tag()));
        // customer portal (audited source)
        $portal = 'W9U0C' . strtoupper(bin2hex(random_bytes(4)));
        $this->voucher($portal, static::$seed->planId);
        static::$db->exec("UPDATE tbl_voucher SET routers = 'wp2_router' WHERE code = '{$portal}'");
        $codes[] = $portal;
        $cust = $this->customer('cNULL2');
        $rp = $cust->postWithToken('voucher/activation-post', ['code' => $portal], [], 'voucher/activation');
        $bodies .= $rp->body;
        $out['customer portal'] = self::vstatus((int) static::$db->value('SELECT id FROM tbl_voucher WHERE code = ?', [$portal]));

        $log = (string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log');
        $tables = json_encode(static::$db->rows('SELECT * FROM tbl_logs')) . json_encode(static::$db->rows('SELECT * FROM tbl_reseller_audit_events'));
        $leaks = [];
        foreach ($codes as $code) {
            foreach (['code' => $code, 'sha256 prefix' => substr(hash('sha256', $code), 0, 16)] as $what => $needle) {
                foreach (['php_error.log' => $log, 'tbl_logs / audit' => $tables, 'http responses' => $bodies] as $where => $hay) {
                    if (str_contains($hay, $needle)) {
                        $leaks[] = "{$what} in {$where}";
                    }
                }
            }
        }
        $redeemLines = array_values(array_filter(explode("\n", $log), static fn(string $l): bool => str_contains($l, 'event=voucher.redeem')));
        $obs = ['outcomes' => $out + ['http voucher-only' => $httpStatus], 'leaks' => array_values(array_unique($leaks)), 'redeem_log_lines' => count($redeemLines),
            'reasons_logged' => ['IDENTITY_IN_USE' => str_contains($log, 'outcome=REFUSED:IDENTITY_IN_USE'), 'NOT_VALID' => str_contains($log, 'outcome=NOT_VALID'), 'GRANTED' => str_contains($log, 'outcome=GRANTED')]];
        self::record('U0 N-07 voucher code logging', $leaks === [] ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        $this->assertSame(['granted public' => 'GRANTED', 'granted radius' => 'GRANTED', 'refused identity in use' => 'REFUSED', 'not valid' => 'NOT_VALID',
            'refused reseller-owned' => 'REFUSED', 'new username' => 'GRANTED', 'customer portal' => '1'], $out, 'control: outcomes unchanged');
        $this->assertSame('1', $httpStatus, 'control: the HTTP voucher-only activation granted');
        $this->assertSame([], $obs['leaks'], 'no code and no short code hash in logs, audit or responses');
        $this->assertSame(['IDENTITY_IN_USE' => true, 'NOT_VALID' => true, 'GRANTED' => true], $obs['reasons_logged'], 'the diagnostics keep the outcome / reason');
    }

    /**
     * Final closure (owner §3, N-07 complete audit): the voucher-only login gate (login.php E12 -> ServiceGate, WP2 / OD-11)
     * recorded the typed name - the voucher code - in its refusal record (PHP log line and audit snapshot) when the code
     * equals a reseller-owned customer's name, and for EVERY attempt while the reseller schema is not ready. The refusal
     * itself and its record stay; the code does not.
     */
    public function testTheVoucherOnlyLoginGateRefusalDoesNotRecordTheCode(): void
    {
        $owned = $this->person(self::tag(), '', 'A');
        $code = $owned['username'];
        $vid = $this->voucher($code);
        $logFile = static::$app->dir . '/wp2_probe/php_error.log';
        $log0 = strlen((string) @file_get_contents($logFile));
        $audit0 = (int) static::$db->value('SELECT COALESCE(MAX(id), 0) FROM tbl_reseller_audit_events');
        $r = $this->client()->postWithToken('login/activation', ['voucher_only' => $code], [], 'login');
        $lines = array_values(array_filter(explode("\n", substr((string) @file_get_contents($logFile), $log0)), static fn(string $l): bool => str_contains($l, 'op=loginVoucherOnly')));
        $audit = static::$db->rows("SELECT target_snapshot, after_json FROM tbl_reseller_audit_events WHERE id > ? AND event_type = 'service.refused'", [$audit0]);
        $obs = ['http' => $r->status, 'voucher_status' => self::vstatus($vid), 'refusal_log_lines' => count($lines), 'refusal_audit_rows' => count($audit),
            'code_in_refusal_log' => (bool) array_filter($lines, static fn(string $l): bool => str_contains($l, $code)),
            'code_in_refusal_audit' => str_contains(json_encode($audit), $code), 'code_in_response' => str_contains($r->body, $code)];
        self::record('U0 N-07 voucher-only login gate refusal record', $obs['code_in_refusal_log'] || $obs['code_in_refusal_audit'] ? 'DEFECT_REPRODUCED' : 'CONTRACT_MET', $obs);
        $this->assertSame('0', $obs['voucher_status'], 'control: refused, the voucher is untouched');
        $this->assertSame(1, $obs['refusal_log_lines'], 'control: the refusal is still recorded');
        $this->assertSame(1, $obs['refusal_audit_rows'], 'control: the refusal is still audited');
        $this->assertFalse($obs['code_in_refusal_log'], 'no voucher code in the refusal log line');
        $this->assertFalse($obs['code_in_refusal_audit'], 'no voucher code in the refusal audit record');
        $this->assertFalse($obs['code_in_response']);
    }
}
