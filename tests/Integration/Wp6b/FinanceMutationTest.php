<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6B slice 2 over real HTTP: provider funding / withdrawal (Admin) and parent -> direct-child transfer (Agent /
 * Admin) through review (no write) -> execute (the accepted TransferService). The browser is never authority: the
 * amount is parsed by the accepted Money grammar after a lossless digit mapping, the reference is server-generated,
 * the reviewed parameters are bound by the confirm token, a double submit replays once, refusals write nothing.
 */
final class FinanceMutationTest extends Wp2HttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ids = static::$seed->staff;
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\TransferService::fund(\$ad, {$ids['A']}, '300', 'opening provider funding', 'WP6B-M-A-0001');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    /** @return array<string,string> the hidden fields of the review form (+ csrf) */
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

    private function rowsIn(string $table): int
    {
        return (int) static::$db->value("SELECT COUNT(*) FROM {$table}");
    }

    private function review(HttpClient $c, string $route, array $data): string
    {
        $r = $c->postWithToken($route, $data);
        $this->assertSame(200, $r->status, $r->excerpt());
        return $r->body;
    }

    public function testProviderFundingReviewExecuteAndReplay(): void
    {
        $b = $this->sid('B');
        $ad = $this->staff('ad');
        $this->assertSame(200, $ad->get('finance/funding/' . $b, ['op' => 'fund'])->status);
        $before = $this->snapshot();
        // Arabic-Indic digits and the Arabic decimal separator are mapped losslessly before the strict grammar
        $html = $this->review($ad, 'finance/funding-review', ['manager_id' => (string) $b, 'op' => 'fund', 'amount' => '١٥٠٫٥٠', 'reason' => 'opening credit', 'external_reference' => 'RCPT-77']);
        $this->assertStringContainsString('Review provider funding', $html);
        $this->assertStringContainsString('150.50', $html);
        $this->assertUnchanged($before, 'the review writes nothing');
        $f = self::hidden($html);
        $this->assertMatchesRegularExpression('/^WEB-FND-\d{8}-[0-9A-F]{16}$/', $f['reference'], 'server-generated reference');
        $this->assertSame(['op' => 'fund', 'target' => (string) $b, 'amount' => '150.50', 'reason' => 'opening credit', 'external' => 'RCPT-77'],
            array_intersect_key($f, array_flip(['op', 'target', 'amount', 'reason', 'external'])));
        $transfers = $this->rowsIn('tbl_manager_transfers');
        $r = $ad->postWithToken('finance/funding-post', $f + ['balance' => '999999', 'available' => '999999']);
        $this->assertSame(302, $r->status);
        $this->assertMatchesRegularExpression('#finance/transfer/(\d+)#', (string) $r->location());
        preg_match('#finance/transfer/(\d+)#', (string) $r->location(), $m);
        $this->assertSame($transfers + 1, $this->rowsIn('tbl_manager_transfers'));
        $row = static::$db->rows('SELECT reference, operation_type, amount, to_manager_id, external_reference FROM tbl_manager_transfers WHERE id = ?', [(int) $m[1]])[0];
        $this->assertSame([$f['reference'], 'PROVIDER_FUNDING', '150.50', (string) $b, 'RCPT-77'], [$row['reference'], $row['operation_type'], (string) $row['amount'], (string) $row['to_manager_id'], $row['external_reference']]);
        $this->assertSame('150.50', (string) static::$db->value('SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [$b]), 'browser balance fields ignored');
        // double submit: the same reviewed operation is recorded once
        $again = $ad->postWithToken('finance/funding-post', $f);
        $this->assertSame((string) $r->location(), (string) $again->location());
        $this->assertSame($transfers + 1, $this->rowsIn('tbl_manager_transfers'));
        $this->assertStringContainsString('Already recorded', $ad->get('finance/transfer/' . $m[1])->body);
    }

    public function testATamperedReviewIsRefusedBeforeAnyServiceCall(): void
    {
        $b = $this->sid('B');
        $ad = $this->staff('ad');
        $f = self::hidden($this->review($ad, 'finance/funding-review', ['manager_id' => (string) $b, 'op' => 'fund', 'amount' => '10', 'reason' => 'tamper probe']));
        $sa = $this->staff('sa');
        $sa->get('dashboard');
        $before = $this->snapshot();
        foreach (['amount' => '10000', 'target' => (string) $this->sid('C'), 'reference' => 'WP6B-OTHER-0001', 'op' => 'withdraw', 'token' => str_repeat('0', 64)] as $k => $v) {
            $r = $ad->postWithToken('finance/funding-post', array_replace($f, [$k => $v]));
            $this->assertSame(200, $r->status, "tampered {$k}");
            $this->assertStringContainsString('This confirmation is no longer valid', $r->body, "tampered {$k}");
        }
        $this->assertUnchanged($before, 'nothing written for a tampered review');
        // another session cannot replay this review either
        $r = $sa->postWithToken('finance/funding-post', array_diff_key($f, ['csrf_token' => true]));
        $this->assertStringContainsString('This confirmation is no longer valid', $r->body);
        $this->assertUnchanged($before, 'nothing written');
    }

    public function testWithdrawalNeedsTheTypedUsername(): void
    {
        $a = $this->sid('A');
        $ad = $this->staff('ad');
        $html = $this->review($ad, 'finance/funding-review', ['manager_id' => (string) $a, 'op' => 'withdraw', 'amount' => '5', 'reason' => 'withdraw probe']);
        $this->assertStringContainsString('Review provider withdrawal', $html);
        $this->assertStringContainsString('data-nuxr-match="A"', $html);
        $f = self::hidden($html);
        $before = $this->snapshot();
        $r = $ad->postWithToken('finance/funding-post', $f + ['confirm' => 'B']);
        $this->assertStringContainsString('The typed confirmation does not match', $r->body);
        $this->assertUnchanged($before, 'nothing written without the typed username');
        $r = $ad->postWithToken('finance/funding-post', $f + ['confirm' => 'A']);
        $this->assertSame(302, $r->status);
        $this->assertSame('PROVIDER_WITHDRAWAL', static::$db->value('SELECT operation_type FROM tbl_manager_transfers WHERE reference = ?', [$f['reference']]));
    }

    public function testAgentSendsOnlyFromItsOwnWalletToADirectChild(): void
    {
        $a = $this->sid('A');
        $b = $this->sid('B');
        $agent = $this->staff('A');
        $form = $agent->get('finance/send')->body;
        $this->assertStringContainsString('name="to_id" value="' . $b . '"', $form, 'its direct child is offered');
        $this->assertStringNotContainsString('value="' . $this->sid('C') . '"', $form);
        $before = $this->snapshot();
        // not a direct child: refused at review, nothing written
        foreach ([$this->sid('C'), $this->sid('F'), $a] as $to) {
            $r = $agent->postWithToken('finance/send-review', ['to_id' => (string) $to, 'amount' => '1', 'reason' => 'probe']);
            $this->assertStringNotContainsString('name="token"', $r->body, "to {$to}: no review");
        }
        // more than the available credit: the service refuses at execute, nothing written, the form keeps the values
        $f = self::hidden($this->review($agent, 'finance/send-review', ['to_id' => (string) $b, 'amount' => '100000', 'reason' => 'too much']));
        $this->assertSame((string) $a, $f['from'], 'the source is always the Agent itself');
        $r = $agent->postWithToken('finance/send-post', $f);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Insufficient available credit', $r->body);
        $this->assertStringContainsString('value="100000.00"', $r->body, 'the reviewed amount is kept');
        // a review cannot be re-pointed at another source
        $r = $agent->postWithToken('finance/send-post', array_replace($f, ['from' => (string) $b]));
        $this->assertStringContainsString('This confirmation is no longer valid', $r->body);
        $this->assertUnchanged($before, 'refusals write nothing');
        // a valid transfer
        $f = self::hidden($this->review($agent, 'finance/send-review', ['to_id' => (string) $b, 'amount' => '25', 'reason' => 'weekly top-up']));
        $r = $agent->postWithToken('finance/send-post', $f);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame(['PARENT_CHILD_TRANSFER', (string) $a, (string) $b, '25.00'],
            array_map('strval', array_values(static::$db->rows('SELECT operation_type, from_manager_id, to_manager_id, amount FROM tbl_manager_transfers WHERE reference = ?', [$f['reference']])[0])));
    }

    public function testRolesWithoutTheCapabilityAreDenied(): void
    {
        $b = $this->sid('B');
        $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
        foreach (['A1', 'rp'] as $u) {
            $c = $this->staff($u);
            $this->assertDenied($c->get('finance/funding/' . $b, ['op' => 'fund']), "{$u} funding");
            $this->assertDenied($c->get('finance/send'), "{$u} send");
            $this->assertDenied($c->postWithToken('finance/funding-post', ['op' => 'fund', 'target' => (string) $b, 'amount' => '1', 'reason' => 'x', 'reference' => 'WP6B-X-0001']), "{$u} funding-post");
        }
        // an Agent never funds; a frozen Agent never transfers
        $this->assertDenied($this->staff('A')->get('finance/funding/' . $b, ['op' => 'fund']), 'Agent funding');
        $this->assertDenied($this->staff('F')->get('finance/send'), 'frozen Agent send');
        $this->assertUnchanged($before, 'denials write nothing', ['tbl_logs', 'tbl_reseller_audit_events', 'tbl_users']);
    }
}
