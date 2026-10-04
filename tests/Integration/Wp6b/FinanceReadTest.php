<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6B slice 1 over real HTTP: the finance read surface (wallets, ledger, entry, transfers, transfer, CSV) follows the
 * accepted scopes exactly (wallet.read / ledger.read: G Admin, T Agent; Sales / Report none), never reveals identities
 * above the viewer, exports exactly the screen's rows, audits exports without their contents and writes nothing else.
 *
 * Tree (Wp2Seed): A (top) -> B; C (top, unrelated); D (inactive) -> F (frozen); Sales A1 under A; Report rp.
 */
final class FinanceReadTest extends Wp2HttpTestCase
{
    private const SENTINEL = 'wp6b-sentinel-reason-7f3';

    /** @var array<string,int> */
    private static array $t = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ids = static::$seed->staff;
        $sentinel = self::SENTINEL;
        $out = static::$app->exec(<<<PHP
\$ctx = static fn(string \$u) => \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', \$u)->find_one()->as_array(), 'CLI');
\$fa = \\Reseller\\Finance\\TransferService::fund(\$ctx('ad'), {$ids['A']}, '500', 'opening provider funding', 'WP6B-F-A-0001');
\$fc = \\Reseller\\Finance\\TransferService::fund(\$ctx('ad'), {$ids['C']}, '50', 'unrelated tree funding', 'WP6B-F-C-0001');
\$ab = \\Reseller\\Finance\\TransferService::transferToChild(\$ctx('A'), {$ids['A']}, {$ids['B']}, '120', 'top-up {$sentinel}', 'WP6B-T-AB-0001');
\\Reseller\\Finance\\WalletService::setDebtLimit(\$ctx('ad'), {$ids['B']}, '30', 'credit line for B');
\$result = ['fa' => \$fa->toArray()['transfer_id'], 'fc' => \$fc->toArray()['transfer_id'], 'ab' => \$ab->toArray()['transfer_id']];
PHP);
        if (!is_array($out)) {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
        self::$t = $out;
    }

    private function main(string $html): string
    {
        $start = strpos($html, 'id="nuxr-main"');
        return $start !== false ? substr($html, $start, max(0, (int) strpos($html, '<footer', $start) - $start)) : $html;
    }

    public function testScopesFollowTheAcceptedCapabilities(): void
    {
        $id = static::$seed->staff;
        $ad = $this->staff('ad');
        $admin = $ad->get('finance/wallets')->body;
        foreach (['A', 'B', 'C', 'D', 'F', 'E'] as $u) {
            $this->assertStringContainsString('finance/wallet/' . $id[$u] . '"', $admin, "Admin sees wallet {$u}");
        }
        $a = $this->staff('A');
        $agent = $a->get('finance/wallets')->body;
        $this->assertStringContainsString('finance/wallet/' . $id['A'] . '"', $agent);
        $this->assertStringContainsString('finance/wallet/' . $id['B'] . '"', $agent);
        foreach (['C', 'D', 'F', 'E'] as $u) {
            $this->assertStringNotContainsString('finance/wallet/' . $id[$u] . '"', $agent, "Agent A never sees wallet {$u}");
        }
        // IDOR: another tree's wallet / ledger / transfer is indistinguishable from a nonexistent one
        foreach (['finance/wallet/' . $id['C'], 'finance/ledger/' . $id['C'], 'finance/transfer/' . self::$t['fc'], 'finance/wallet/999999', 'finance/transfer/999999'] as $route) {
            $r = $a->get($route);
            $this->assertSame(302, $r->status, "A {$route}: refused");
            $this->assertStringNotContainsString('unrelated tree funding', $r->body);
        }
        // B (child of A): its own wallet only; the parent's wallet is above its scope
        $b = $this->staff('B');
        $this->assertSame(302, $b->get('finance/wallet/' . $id['A'])->status);
        $this->assertSame(200, $b->get('finance/wallet/' . $id['B'])->status);
        // Sales and Report hold no finance capability
        foreach (['A1', 'rp'] as $u) {
            foreach (['finance', 'finance/wallets', 'finance/ledger/' . $id['A'], 'finance/transfers'] as $route) {
                $this->assertDenied($this->staff($u)->get($route), "{$u} GET {$route}");
            }
        }
        // a frozen Agent keeps its reads
        $this->assertSame(200, $this->staff('F')->get('finance')->status);
    }

    public function testIdentitiesAboveTheViewerAreRoleLabelsAndInScopeReasonsStay(): void
    {
        $id = static::$seed->staff;
        $b = $this->main($this->staff('B')->get('finance/ledger/' . $id['B'])->body);
        $this->assertStringContainsString('Transfer received', $b);
        $this->assertStringContainsString('Upstream reseller', $b, 'the parent is a role label for the child');
        $this->assertStringNotContainsString('Staff A', $b, 'no parent name');
        $this->assertStringContainsString(self::SENTINEL, $b, 'the in-scope ledger reason is shown (OD-4)');
        $a = $this->main($this->staff('A')->get('finance/ledger/' . $id['A'])->body);
        $this->assertStringContainsString('Provider funding', $a);
        $this->assertStringContainsString('Provider (administrator)', $a, 'the funding operator is a role label for an Agent');
        $this->assertDoesNotMatchRegularExpression('#<bdi dir="ltr">ad</bdi>#', $a, 'no administrator username');
        $this->assertStringContainsString('<bdi dir="ltr">B</bdi>', $a, 'a child inside the scope is named');
        $admin = $this->main($this->staff('ad')->get('finance/ledger/' . $id['A'])->body);
        $this->assertStringContainsString('<bdi dir="ltr">ad</bdi>', $admin, 'Admin sees the operator');
        // the transfer page shows only the legs inside the viewer's scope
        $legs = $this->main($this->staff('B')->get('finance/transfer/' . self::$t['ab'])->body);
        $this->assertStringContainsString('Transfer received', $legs);
        $this->assertStringNotContainsString('Transfer sent', $legs, "the parent's leg is outside B's scope");
    }

    public function testExportsEqualTheScreenAndAreAuditedWithoutContents(): void
    {
        $id = static::$seed->staff;
        $a = $this->staff('A');
        $range = ['from' => gmdate('Y-m-d', time() - 86400), 'to' => gmdate('Y-m-d', time() + 86400)];
        $before = count($this->auditRows('export.finance'));
        $csv = $a->get('finance/transfers', $range + ['format' => 'csv']);
        $this->assertSame(200, $csv->status, $csv->excerpt());
        $this->assertStringContainsString('text/csv', (string) $csv->header('content-type'));
        $screen = $a->get('finance/transfers', $range)->body;
        preg_match_all('#(WP6B-[A-Z]-[A-Z]+-\d{4})#', $screen, $s);
        preg_match_all('#(WP6B-[A-Z]-[A-Z]+-\d{4})#', $csv->body, $c);
        $this->assertSame(array_values(array_unique($s[1])), array_values(array_unique($c[1])), 'CSV rows = screen rows');
        $this->assertSame(['WP6B-T-AB-0001', 'WP6B-F-A-0001'], array_values(array_unique($c[1])), "A's scope only, newest first");
        $this->assertStringNotContainsString('WP6B-F-C-0001', $csv->body);
        $audit = $this->auditRows('export.finance');
        $this->assertCount($before + 1, $audit);
        $last = end($audit);
        $after = json_decode((string) $last['after_json'], true);
        $this->assertSame(['export' => 'transfers', 'filters' => $range, 'rows' => 2], $after, 'metadata only');
        $this->assertStringNotContainsString(self::SENTINEL, (string) $last['after_json'], 'no exported contents in the audit');
        // a time-series export needs a range of at most 366 days; nothing is written then
        $snap = $this->snapshot(['tbl_logs']);
        $r = $a->get('finance/ledger/' . $id['A'], ['format' => 'csv']);
        $this->assertSame(302, $r->status, 'refused without a range');
        $r = $a->get('finance/ledger/' . $id['A'], ['format' => 'csv', 'from' => '2025-01-01', 'to' => '2026-12-31']);
        $this->assertSame(302, $r->status, 'refused above 366 days');
        $this->assertUnchanged($snap, 'refused exports write nothing', ['tbl_logs']);
        // wallet export: scope parity for the Agent
        $w = $a->get('finance/wallets', ['format' => 'csv'])->body;
        $this->assertStringContainsString('Staff B', $w);
        $this->assertStringNotContainsString('Staff C', $w);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $w, 'UTF-8 BOM');
    }

    public function testReadPagesWriteNothing(): void
    {
        $id = static::$seed->staff;
        $ad = $this->staff('ad'); // signing in updates last_login; the reads after it must write nothing
        $ad->get('dashboard');
        $before = $this->snapshot();
        foreach (['finance', 'finance/wallets', 'finance/wallet/' . $id['B'], 'finance/ledger/' . $id['B'], 'finance/transfers', 'finance/transfer/' . self::$t['ab']] as $route) {
            $r = $ad->get($route);
            $this->assertSame(200, $r->status, "{$route}: " . $r->excerpt());
            $this->assertFalse($r->leaksInternals(), $route);
        }
        $this->assertStringContainsString('Ledger entries are permanent', $ad->get('finance/ledger/' . $id['B'])->body);
        $this->assertUnchanged($before, 'finance reads write nothing');
    }
}
