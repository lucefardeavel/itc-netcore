<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6B slice 3 (owner decision OD-2) over real HTTP: the administrative accounting corrections — debt line, explicit
 * credit / debit adjustment, reversal of one existing reversible transfer — Admin / SuperAdmin only, review (no write)
 * -> typed confirmation where risky -> the accepted WalletService / TransferService. Never "edit balance"; an
 * adjustment never opens a wallet; refusals write nothing.
 */
final class FinanceCorrectionTest extends Wp2HttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ids = static::$seed->staff;
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\TransferService::fund(\$ad, {$ids['A']}, '300', 'opening provider funding', 'WP6B-C-A-0001');
\\Reseller\\Finance\\TransferService::fund(\$ad, {$ids['A']}, '25', 'mistaken funding', 'WP6B-C-A-0002');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    /** @return array<string,string> hidden fields of the review form */
    private static function hidden(string $html): array
    {
        $start = strpos($html, 'name="token"');
        $form = substr($html, (int) strrpos(substr($html, 0, (int) $start), '<form'));
        preg_match_all('/<input type="hidden" name="([a-z_]+)" value="([^"]*)"/', $form, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $k, $v]) {
            $out[$k] = html_entity_decode($v, ENT_QUOTES);
        }
        return $out;
    }

    private function review(HttpClient $c, string $route, array $data): string
    {
        $r = $c->postWithToken($route, $data);
        $this->assertSame(200, $r->status, $r->excerpt());
        return $r->body;
    }

    private function wallet(string $u): ?array
    {
        $rows = static::$db->rows('SELECT balance, debt_limit FROM tbl_manager_wallets WHERE manager_id = ?', [$this->sid($u)]);
        return $rows[0] ?? null;
    }

    public function testDebtLineReviewTypedDecreaseAndExposureFloor(): void
    {
        $b = (string) $this->sid('B');
        $ad = $this->staff('ad');
        $ad->get('dashboard');
        $before = $this->snapshot();
        $html = $this->review($ad, 'finance/debt-limit-review', ['manager_id' => $b, 'limit' => '50', 'reason' => 'credit line for B']);
        foreach (['Review debt line change', 'Administrative accounting operation', 'opens the wallet'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertMatchesRegularExpression('#>[^<>]*?\b0 → [^<>]*?\b50<#u', $html, 'debt line now -> proposed');
        $this->assertStringNotContainsString('Edit balance', $html);
        $this->assertUnchanged($before, 'the review writes nothing');
        $this->assertSame(302, $ad->postWithToken('finance/debt-limit-post', self::hidden($html))->status);
        $this->assertSame('50.00', (string) $this->wallet('B')['debt_limit'], 'a positive debt line opens the wallet');
        // the same value: no change, no confirm button
        $same = $this->review($ad, 'finance/debt-limit-review', ['manager_id' => $b, 'limit' => '50', 'reason' => 'same again']);
        $this->assertStringContainsString('No change', $same);
        $this->assertStringNotContainsString('fa-check" aria-hidden="true"></i>Confirm', $same, 'no confirmation for no change');
        // use the debt line (balance -40), then a decrease below the exposure is refused by the service
        $w = self::hidden($this->review($ad, 'finance/funding-review', ['manager_id' => $b, 'op' => 'withdraw', 'amount' => '40', 'reason' => 'use the line']));
        $this->assertSame(302, $ad->postWithToken('finance/funding-post', $w + ['confirm' => 'B'])->status);
        $this->assertSame('-40.00', (string) $this->wallet('B')['balance']);
        $dec = $this->review($ad, 'finance/debt-limit-review', ['manager_id' => $b, 'limit' => '10', 'reason' => 'lower the line']);
        $this->assertMatchesRegularExpression('#→ [^<>]*?-30<#u', $dec, 'projected available credit');
        $this->assertStringContainsString('data-nuxr-match="B"', $dec, 'a decrease needs the typed username');
        $f = self::hidden($dec);
        $before = $this->snapshot();
        $this->assertStringContainsString('The typed confirmation does not match', $ad->postWithToken('finance/debt-limit-post', $f)->body);
        $r = $ad->postWithToken('finance/debt-limit-post', $f + ['confirm' => 'B']);
        $this->assertStringContainsString('The debt limit cannot be reduced', $r->body);
        $this->assertUnchanged($before, 'refused decreases write nothing');
        $this->assertSame('50.00', (string) $this->wallet('B')['debt_limit']);
    }

    public function testAdjustmentsAreExplicitAndNeverOpenAWallet(): void
    {
        $ad = $this->staff('ad');
        $ad->get('dashboard');
        $this->assertNull($this->wallet('C'));
        $html = $this->review($ad, 'finance/adjust-review', ['manager_id' => (string) $this->sid('C'), 'op' => 'credit', 'amount' => '5', 'reason' => 'probe']);
        $this->assertStringContainsString('Review credit adjustment', $html);
        $this->assertStringContainsString('Adjustments never open a wallet', $html);
        $this->assertStringNotContainsString('fa-check" aria-hidden="true"></i>Confirm', $html, 'no confirm for a missing wallet');
        $this->assertNull($this->wallet('C'));
        // a debit adjustment of an existing wallet: typed username, ADJUSTMENT_DEBIT through the accepted service
        $a = (string) $this->sid('A');
        $f = self::hidden($this->review($ad, 'finance/adjust-review', ['manager_id' => $a, 'op' => 'debit', 'amount' => '12.5', 'reason' => 'correct a duplicate']));
        $before = $this->snapshot();
        $this->assertStringContainsString('The typed confirmation does not match', $ad->postWithToken('finance/adjust-post', $f + ['confirm' => 'a'])->body);
        $this->assertUnchanged($before, 'nothing written');
        $this->assertSame(302, $ad->postWithToken('finance/adjust-post', $f + ['confirm' => 'A'])->status);
        $this->assertSame(['ADJUSTMENT_DEBIT', '12.50'], array_map('strval', array_values(static::$db->rows('SELECT operation_type, amount FROM tbl_manager_transfers WHERE reference = ?', [$f['reference']])[0])));
        $this->assertStringStartsWith('WEB-ADD-', $f['reference']);
    }

    public function testOnlyAnExistingReversibleTransferCanBeReversed(): void
    {
        $ad = $this->staff('ad');
        $ad->get('dashboard');
        $orig = (int) static::$db->value("SELECT id FROM tbl_manager_transfers WHERE reference = 'WP6B-C-A-0002'");
        $form = $ad->get('finance/reverse/' . $orig)->body;
        $this->assertStringContainsString('Provider withdrawal', $form, 'the compensating operation of a funding');
        $html = $this->review($ad, 'finance/reverse-review', ['transfer_id' => (string) $orig, 'reason' => 'funded the wrong reseller']);
        $this->assertStringContainsString('data-nuxr-match="WP6B-C-A-0002"', $html, 'typed original reference');
        $f = self::hidden($html);
        $before = $this->snapshot();
        $this->assertStringContainsString('The typed confirmation does not match', $ad->postWithToken('finance/reverse-post', $f + ['confirm' => 'nope'])->body);
        $this->assertUnchanged($before, 'nothing written');
        $r = $ad->postWithToken('finance/reverse-post', $f + ['confirm' => 'WP6B-C-A-0002']);
        preg_match('#is-danger" role="alert">.*?<div>(.*?)</div>#s', $r->body, $err);
        $this->assertSame(302, $r->status, 'reverse refused: ' . ($err[1] ?? $r->excerpt()));
        $this->assertSame('REVERSED', static::$db->value('SELECT status FROM tbl_manager_transfers WHERE id = ?', [$orig]));
        $rev = static::$db->rows('SELECT id, operation_type, reverses_transfer_id FROM tbl_manager_transfers WHERE reference = ?', [$f['reference']])[0];
        $this->assertSame(['PROVIDER_WITHDRAWAL', (string) $orig], [(string) $rev['operation_type'], (string) $rev['reverses_transfer_id']]);
        // already reversed / a reversal itself: informational, no form
        $again = $ad->get('finance/reverse/' . $orig)->body;
        $this->assertStringContainsString('already reversed', $again);
        $this->assertStringNotContainsString('finance/reverse-review', $again);
        $this->assertStringContainsString('cannot be reversed', $ad->get('finance/reverse/' . $rev['id'])->body);
        $this->assertStringNotContainsString('Reverse this transfer', $ad->get('finance/transfer/' . $orig)->body);
    }

    public function testCorrectionsAreAdminOnly(): void
    {
        $a = (string) $this->sid('A');
        $agent = $this->staff('A');
        $sales = $this->staff('A1');
        $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
        foreach ([$agent, $sales] as $c) {
            foreach (['finance/debt-limit/' . $a, 'finance/adjust/' . $a, 'finance/reverse/1'] as $route) {
                $this->assertDenied($c->get($route, ['op' => 'credit']), $route);
            }
            foreach (['finance/debt-limit-post', 'finance/adjust-post', 'finance/reverse-post'] as $route) {
                $this->assertDenied($c->postWithToken($route, ['op' => 'credit', 'target' => $a, 'amount' => '1', 'reason' => 'forged', 'reference' => 'WP6B-FORGED-01']), $route);
            }
        }
        $this->assertStringNotContainsString('Accounting corrections', $agent->get('finance/wallet/' . $a)->body);
        $this->assertUnchanged($before, 'nothing written', ['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
    }
}
