<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Domain\DomainError;
use Reseller\Domain\SchemaReadiness;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceFaults;
use Reseller\Finance\LedgerService;
use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\FinanceTestCase;

/**
 * WP3A fix round 1 (owner): a registry row saying R0003/R0004 is APPLIED is not proof that the finance
 * runtime tables exist. Each WP3A dependency is part of SchemaReadiness, so a missing finance table
 * makes the reseller schema NOT READY: PermissionService refuses every finance capability for every
 * role (no global-admin bypass) and every finance read/mutation is refused with SCHEMA_NOT_READY
 * before any finance SQL — never by a later "table not found" error. Restoring the table restores
 * readiness and real operations over the restored table and its foreign keys.
 */
final class FinanceSchemaReadinessTest extends FinanceTestCase
{
    private const FINANCE_TABLES = [
        'tbl_reseller_activations', 'tbl_manager_wallets', 'tbl_manager_transfers', 'tbl_manager_ledger', 'tbl_manager_wallet_reservations',
    ];
    private const FINANCE_CAPS = [C::WALLET_READ, C::LEDGER_READ, C::WALLET_FUND_WITHDRAW, C::WALLET_TRANSFER, C::WALLET_DEBT_LIMIT, C::WALLET_ADJUST, C::WALLET_REVERSE];
    private const OFFLINE = '_wp3a_off';

    /** @return array<string,array{0:string}> */
    public static function financeTables(): array
    {
        $out = [];
        foreach (self::FINANCE_TABLES as $t) {
            $out[$t] = [$t];
        }
        return $out;
    }

    private static function resetReadiness(): void
    {
        SchemaReadiness::reset();
        ActorContext::reset();
    }

    protected function tearDown(): void
    {
        // never leave a table offline for the next test, whatever happened
        foreach (self::FINANCE_TABLES as $t) {
            if ((int) static::$db->value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t . self::OFFLINE]) === 1) {
                static::$db->exec("RENAME TABLE `{$t}" . self::OFFLINE . "` TO `{$t}`");
            }
        }
        self::resetReadiness();
        parent::tearDown();
    }

    public function testRequiredTableListIsExact(): void
    {
        $required = (new \ReflectionClassConstant(SchemaReadiness::class, 'REQUIRED_TABLES'))->getValue();
        $this->assertSame([
            'tbl_manager_tree', 'tbl_reseller_audit_events', 'tbl_customers', 'tbl_voucher_logins',
            'tbl_reseller_activations', 'tbl_manager_wallets', 'tbl_manager_transfers', 'tbl_manager_ledger', 'tbl_manager_wallet_reservations',
            // WP3B: the pricing authorities are runtime-authoritative (Wp3b/PricingSchemaReadinessTest)
            'tbl_reseller_plan_base_costs', 'tbl_manager_plan_prices',
            // WP5A: the activation timeline is runtime-authoritative (Wp5a schema-readiness tests)
            'tbl_reseller_activation_events',
        ], $required);
        $this->assertSame(['R0001_manager_tree', 'R0002_pricing_foundation', 'R0003_activation_foundation',
            'R0004_wallet_finance_foundation', 'R0005_customer_ownership', 'R0006_reseller_audit',
            // WP5B (owner decision OD-14 option B): the activation reversal states
            'R0007_activation_reversal_states'], SchemaReadiness::EXPECTED);
        self::resetReadiness();
        $this->assertSame(['ready' => true, 'problems' => []], SchemaReadiness::status());
    }

    #[DataProvider('financeTables')]
    public function testMissingFinanceTableFailsClosedAndRecovers(string $table): void
    {
        // 1. valid migrated database with real financial state built through the services
        self::resetReadiness();
        $this->assertTrue(SchemaReadiness::isReady(), 'valid start');
        $p = $this->funded('100', $this->sid('A'));
        $c = $this->agent($p);
        $t = TransferService::transferToChild($this->ctx($p), $p, $c, '10', 'stock', self::ref());
        $toCommit = ReservationService::reserve($this->activation($p), $p, Money::parsePositive('5'));
        $toRelease = ReservationService::reserve($this->activation($p), $p, Money::parsePositive('4'));
        $spare = $this->activation($p);
        $entry = (int) $this->ledger($p)[0]['id'];

        // 2. make exactly this dependency unavailable (deterministic, as the accepted WP2 SchemaDriftTest does)
        static::$db->exec("RENAME TABLE `{$table}` TO `{$table}" . self::OFFLINE . '`');
        try {
            // 3./4. reset the cache: NOT READY, for exactly this reason
            self::resetReadiness();
            $this->assertSame(['ready' => false, 'problems' => ["table {$table} missing"]], SchemaReadiness::status());

            // 5. every finance capability fails closed for SuperAdmin, Admin and Agents (no global-admin bypass)
            $actors = ['SuperAdmin' => $this->ctx('sa'), 'Admin' => $this->ctx('ad'), 'Agent A' => $this->ctx('A'), 'Agent P' => $this->ctx($p)];
            foreach ($actors as $label => $a) {
                $this->assertTrue($a->isStaff());
                $this->assertFalse($a->schemaReady, "{$label} context sees the schema as not ready");
                $flags = PermissionService::flags($a);
                foreach (self::FINANCE_CAPS as $cap) {
                    $this->assertSame(PermissionService::NONE, PermissionService::scopeOf($a, $cap), "{$label}: {$cap} with {$table} missing");
                    $this->assertFalse($flags[$cap], "{$label}: {$cap} flag");
                }
                $this->assertFalse(PermissionService::can($a, C::RESELLER_TREE_READ), 'the whole reseller schema is not ready (WP2 rule)');
            }

            // 6. reads and mutations are refused at the readiness boundary, before any finance SQL
            $reached = [];
            FinanceFaults::$hook = static function (string $point) use (&$reached): void {
                $reached[] = $point;
            };
            $before = $this->checksums();
            [$sa, $ad, $agentA, $agentP] = array_values($actors);
            $calls = [
                'wallet view (SuperAdmin)' => fn() => WalletService::view($sa, $p),
                'wallet list (Agent)' => fn() => WalletService::listScoped($agentA),
                'ledger entries (Agent)' => fn() => LedgerService::entries($agentP, $p),
                'ledger entry (Admin)' => fn() => LedgerService::entry($ad, $entry),
                'provider funding (Admin)' => fn() => TransferService::fund($ad, $p, '1', 'blocked funding', self::ref()),
                'provider withdrawal (Admin)' => fn() => TransferService::withdraw($ad, $p, '1', 'blocked withdrawal', self::ref()),
                'credit adjustment (SuperAdmin)' => fn() => TransferService::adjustCredit($sa, $p, '1', 'blocked adjustment', self::ref()),
                'debit adjustment (Admin)' => fn() => TransferService::adjustDebit($ad, $p, '1', 'blocked adjustment', self::ref()),
                'debt limit (SuperAdmin)' => fn() => WalletService::setDebtLimit($sa, $p, '10', 'blocked debt limit'),
                'transfer (Agent)' => fn() => TransferService::transferToChild($agentP, $p, $c, '1', 'blocked transfer', self::ref()),
                'transfer (Admin)' => fn() => TransferService::transferToChild($ad, $p, $c, '1', 'blocked transfer', self::ref()),
                'reversal (SuperAdmin)' => fn() => TransferService::reverse($sa, $t->transferId, 'blocked reversal', self::ref()),
                'reserve' => fn() => ReservationService::reserve($spare, $p, Money::parsePositive('1')),
                'commit' => fn() => ReservationService::commit($toCommit->reservationId, FinanceActor::staff($ad)),
                'release' => fn() => ReservationService::release($toRelease->reservationId, FinanceActor::system('activation-service'), 'blocked release'),
            ];
            foreach ($calls as $label => $call) {
                try {
                    $call();
                    $this->fail("{$label} was not refused with {$table} missing");
                } catch (DomainError $e) {
                    // a PDOException ("table not found") is not caught here and fails the test
                    $this->assertSame('SCHEMA_NOT_READY', $e->reason, $label);
                }
            }
            FinanceFaults::$hook = null;
            $this->assertSame([], $reached, 'no financial write step was reached');
            $this->assertSame([], DbProbe::changed($before, $this->checksums()), 'no financial (or any other) state changed');
            $this->assertNull(static::$db->value('SELECT IS_USED_LOCK(?)', ['nuxr_tree_' . substr(sha1(static::$db->name), 0, 20)]));
            $this->assertFalse(static::$svc->inTransaction());
        } finally {
            FinanceFaults::$hook = null;
            // 7. restore the dependency
            static::$db->exec("RENAME TABLE `{$table}" . self::OFFLINE . "` TO `{$table}`");
            // 8. reset readiness
            self::resetReadiness();
        }

        // 9. readiness and the capability matrix return; real operations work over the restored table
        $this->assertSame(['ready' => true, 'problems' => []], SchemaReadiness::status());
        foreach (['sa', 'ad'] as $u) {
            foreach (self::FINANCE_CAPS as $cap) {
                $this->assertSame('G', PermissionService::scopeOf($this->ctx($u), $cap), "{$u} {$cap} restored");
            }
        }
        $agent = $this->ctx($p);
        $this->assertSame(['T', 'T', 'C'], [PermissionService::scopeOf($agent, C::WALLET_READ), PermissionService::scopeOf($agent, C::LEDGER_READ), PermissionService::scopeOf($agent, C::WALLET_TRANSFER)]);
        TransferService::fund($this->ctx('ad'), $p, '1', 'after restore', self::ref());
        TransferService::transferToChild($this->ctx($p), $p, $c, '1', 'after restore', self::ref());
        TransferService::reverse($this->ctx('sa'), $t->transferId, 'after restore', self::ref());
        ReservationService::commit($toCommit->reservationId, FinanceActor::staff($this->ctx('ad')));
        ReservationService::release($toRelease->reservationId, FinanceActor::system('activation-service'), 'confirmed unused');
        $this->assertSame('ACTIVE', ReservationService::reserve($spare, $p, Money::parsePositive('1'))->status);
        // p: 100 - 10 + 1 - 1 + 10 (reversal) - 5 (commit) = 95; c: 10 + 1 - 10 = 1
        $this->assertSame(['95.00', '1.00'], [$this->balance($p), $this->balance($c)]);
        $this->assertSame('94.00', WalletService::view($this->ctx('ad'), $p)->available()->format());
    }
}
