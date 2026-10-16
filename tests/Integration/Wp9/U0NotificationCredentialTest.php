<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 final closure, N-07 complete credential exposure audit (owner WP9_U0_HARDENING_CONDITIONALLY_ACCEPTED, limited
 * Package.php exception): a voucher code is a credential (voucher-only username = password = code). Every notification
 * Package::rechargeUser (pinned) emits - the Telegram "System Error ... sync manually", "#recharge" and "#buy" messages and
 * the customer invoice (Message::sendInvoice -> SMS / WhatsApp / e-mail, tbl_message_logs) - is captured at the
 * application's own notification hooks (send_telegram / send_sms / send_whatsapp run before any delivery; the harness
 * probe plugin records them) and searched for the code and its former short hash, together with the PHP error log, tbl_logs and the reseller
 * audit table.
 *
 * Paths: voucher-only #buy, voucher-only #recharge (an existing voucher-only recharge row), voucher-only device failure
 * (a code the RADIUS key rules refuse), the end-to-end voucher-only redemption service. A customer's Voucher recharge
 * (channel = the code, already spent: never redeemable again, not a login credential) is recorded, not judged.
 * Positive controls: an ordinary customer recharge (#buy, #recharge) and an ordinary customer's device failure keep their messages byte-for-byte in the parts N-07 does not touch (#u<username>, Channel,
 * Customer: u<username>), and the customer invoice is still sent. Finance controls: the return values and the
 * tbl_transactions / tbl_user_recharges rows (including the compatibility fields that keep the code) are unchanged.
 */
final class U0NotificationCredentialTest extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
        // the customer invoice goes out by WhatsApp; the gateway is a closed local port (refused at once, nothing leaves the
        // container) so Message::sendWhatsapp also writes tbl_message_logs as it does on a real delivery failure
        foreach (['user_notification_payment' => 'wa', 'wa_url' => 'http://127.0.0.1:9/send?n=[number]&t=[text]'] as $k => $v) {
            static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = '{$k}'");
            static::$db->insertRow('tbl_appconfig', ['setting' => $k, 'value' => $v]);
        }
    }

    /**
     * The notifications captured since the last call: the harness probe plugin of every app copy is the first (and so the
     * only) function on the send_telegram / send_sms / send_whatsapp hooks (run_hook() calls only the first one); it logs
     * the Telegram text to wp2_probe/telegram.log and the SMS / WhatsApp [recipient, text] to wp2_probe/sms.log.
     *
     * @return list<array{kind:string,text:string,to:string}>
     */
    private static function notifications(): array
    {
        $out = [];
        foreach (['telegram' => 'telegram.log', 'sms / whatsapp' => 'sms.log'] as $kind => $f) {
            $file = static::$app->dir . '/wp2_probe/' . $f;
            foreach (array_filter(explode("\n", (string) @file_get_contents($file))) as $line) {
                $v = json_decode($line, true);
                $out[] = $kind === 'telegram' ? ['kind' => $kind, 'text' => (string) $v, 'to' => ''] : ['kind' => $kind, 'text' => (string) ($v[1] ?? ''), 'to' => (string) ($v[0] ?? '')];
            }
            @unlink($file);
        }
        return $out;
    }

    private static function recharge(int $customerId, int $plan, string $gateway, string $channel): string
    {
        return (string) var_export(static::$app->exec(sprintf('$result = Package::rechargeUser(%d, "radius", %d, %s, %s);', $customerId, $plan, var_export($gateway, true), var_export($channel, true))), true);
    }

    public function testNoNotificationCarriesAVoucherCredential(): void
    {
        self::notifications();
        $paths = [];
        $codes = [];
        // voucher-only #buy, then #recharge (the voucher-only recharge row now exists)
        $vo = self::tag();
        $codes[] = $vo;
        $paths['voucher-only #buy'] = ['result' => self::recharge(0, self::$hotspot, 'Voucher', $vo), 'notes' => self::notifications()];
        $paths['voucher-only #recharge'] = ['result' => self::recharge(0, self::$hotspot, 'Voucher', $vo), 'notes' => self::notifications()];
        // voucher-only device failure (the RADIUS key rules refuse an interior control character): System Error + #buy
        $bad = self::tag() . "\x01z";
        $codes[] = $bad;
        $paths['voucher-only device failure'] = ['result' => self::recharge(0, self::$hotspot, 'Voucher', $bad), 'notes' => self::notifications()];
        // the end-to-end voucher-only redemption (public source)
        $svc = self::tag();
        $codes[] = $svc;
        $this->voucher($svc);
        $paths['voucher-only redemption service'] = ['result' => (string) static::$app->exec(self::p6Code($svc)), 'notes' => self::notifications()];
        // a customer's Voucher recharge: the channel is the (spent) code
        $cv = $this->person(self::tag());
        $cvCode = 'W9N07' . strtoupper(bin2hex(random_bytes(5)));
        $paths['customer voucher recharge'] = ['result' => self::recharge($cv['id'], self::$hotspot, 'Voucher', $cvCode), 'notes' => self::notifications()];
        // positive controls: an ordinary customer recharge (#buy, #recharge) and an ordinary customer's device failure
        $cc = $this->person(self::tag());
        $paths['control customer #buy'] = ['result' => self::recharge($cc['id'], self::$hotspot, 'Recharge', 'admin'), 'notes' => self::notifications()];
        $paths['control customer #recharge'] = ['result' => self::recharge($cc['id'], self::$hotspot, 'Recharge', 'admin'), 'notes' => self::notifications()];
        $cf = $this->person(self::tag());
        $paths['control customer device failure'] = ['result' => self::recharge($cf['id'], self::$failPlan, 'Recharge', 'admin'), 'notes' => self::notifications()];

        $log = (string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log');
        $tables = ['tbl_message_logs' => json_encode(static::$db->rows('SELECT * FROM tbl_message_logs'), JSON_INVALID_UTF8_SUBSTITUTE),
            'tbl_logs' => json_encode(static::$db->rows('SELECT * FROM tbl_logs'), JSON_INVALID_UTF8_SUBSTITUTE),
            'tbl_reseller_audit_events' => json_encode(static::$db->rows('SELECT * FROM tbl_reseller_audit_events'), JSON_INVALID_UTF8_SUBSTITUTE)];
        $leaks = [];
        foreach ($paths as $name => $p) {
            foreach ($p['notes'] as $n) {
                foreach ($codes as $code) {
                    foreach (['code' => $code, 'sha256 prefix' => substr(hash('sha256', $code), 0, 16)] as $what => $needle) {
                        if (str_contains($n['text'], $needle) || str_contains($n['to'], $needle)) {
                            $leaks[] = "{$what} in {$n['kind']} ({$name})";
                        }
                    }
                }
            }
        }
        foreach ($codes as $code) {
            $enc = substr(json_encode($code, JSON_INVALID_UTF8_SUBSTITUTE), 1, -1);
            foreach (['php_error.log' => $log] + $tables as $where => $hay) {
                foreach (['code' => $where === 'php_error.log' ? $code : $enc, 'sha256 prefix' => substr(hash('sha256', $code), 0, 16)] as $what => $needle) {
                    if (str_contains($hay, $needle)) {
                        $leaks[] = "{$what} in {$where}";
                    }
                }
            }
        }
        // a customer's Voucher recharge: the code is spent (never redeemable again, not a login credential) - recorded, not
        // judged: it stays in the customer's own invoice (receipt), the operator message channel and the transaction method
        $spent = [];
        foreach ($paths['customer voucher recharge']['notes'] as $n) {
            if (str_contains($n['text'], $cvCode)) {
                $spent[] = $n['kind'];
            }
        }
        foreach ($tables as $where => $hay) {
            if (str_contains($hay, $cvCode)) {
                $spent[] = $where;
            }
        }
        $kinds = array_map(static fn(array $p): array => array_map(static fn(array $n): string => $n['kind'], $p['notes']), $paths);
        $text = static fn(string $path, string $kind = 'telegram'): string => implode("\n---\n", array_map(static fn(array $n): string => $n['text'], array_filter($paths[$path]['notes'], static fn(array $n): bool => $n['kind'] === $kind)));
        // finance / record controls: the compatibility fields keep the code (no schema or ledger change, no history rewrite)
        $finance = [];
        foreach (['voucher-only' => $vo, 'customer voucher' => $cvCode] as $k => $code) {
            $finance[$k] = ['transactions_with_method' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE method = ?', ['Voucher - ' . $code]),
                'recharges_with_method' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE method = ?', ['Voucher - ' . $code])];
        }
        $finance['voucher-only username rows'] = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE BINARY username = ?', [$vo]);
        $controls = [
            'customer #buy names the customer and channel' => str_contains($text('control customer #buy'), "#u{$cc['username']} ") && str_contains($text('control customer #buy'), "\nChannel: admin\n") && str_contains($text('control customer #buy'), ' #buy #'),
            'customer #recharge names the customer and channel' => str_contains($text('control customer #recharge'), "#u{$cc['username']} ") && str_contains($text('control customer #recharge'), "\nChannel: admin\n") && str_contains($text('control customer #recharge'), ' #recharge #'),
            'customer device failure names the customer' => str_contains($text('control customer device failure'), "System Error. When activate Package. You need to sync manually\n") && str_contains($text('control customer device failure'), "\nCustomer: u{$cf['username']}\n"),
            'customer invoice still sent by WhatsApp' => str_contains($text('control customer #buy', 'sms / whatsapp'), $cc['username']),
            'customer voucher recharge still names the customer' => str_contains($text('customer voucher recharge'), "#u{$cv['username']} "),
            'voucher-only operators are still notified' => str_contains($text('voucher-only #buy'), ' #buy #') && str_contains($text('voucher-only #recharge'), ' #recharge #')
                && str_contains($text('voucher-only device failure'), 'System Error. When activate Package. You need to sync manually'),
        ];
        $results = array_map(static fn(array $p): string => $p['result'], $paths);
        $obs = ['results' => $results, 'notification_kinds' => $kinds, 'leaks' => array_values(array_unique($leaks)), 'controls' => $controls, 'finance_records' => $finance,
            'spent_customer_voucher_code_seen_in' => array_values(array_unique($spent)),
            'voucher_only_telegram_sample' => $text('voucher-only #buy') === '' ? null : preg_replace('/W9U0\w+|w9u0\w+/', '<name>', $leaks === [] ? $text('voucher-only #buy') : '<withheld: carries the code>')];
        self::record('U0 N-07 notification credential exposure', $leaks === [] ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);

        foreach (['voucher-only #buy', 'voucher-only #recharge', 'voucher-only device failure', 'customer voucher recharge', 'control customer #buy', 'control customer #recharge', 'control customer device failure'] as $k) {
            $this->assertMatchesRegularExpression("/^'INV-/", $results[$k], "finance control: {$k} still returns its invoice");
        }
        $this->assertSame('GRANTED', $results['voucher-only redemption service']);
        $this->assertSame(['voucher-only' => ['transactions_with_method' => 2, 'recharges_with_method' => 1], 'customer voucher' => ['transactions_with_method' => 1, 'recharges_with_method' => 1],
            'voucher-only username rows' => 2], $finance, 'finance control: the recharge / transaction rows are unchanged (compatibility fields keep the code)');
        $this->assertSame(array_fill_keys(array_keys($controls), true), $controls, 'positive controls: ordinary notifications unchanged');
        $this->assertSame([], $obs['leaks'], 'no voucher code and no short code hash in any notification, message log, log or audit row');
    }
}
