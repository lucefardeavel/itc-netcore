<?php
namespace Reseller\Tests\Unit;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Migration\MigrationSet;
use Reseller\Migration\VariantRegistry;

/** Static checks of the shipped migration definitions (no database). */
final class MigrationDefinitionsTest extends TestCase
{
    private static function set(): MigrationSet
    {
        return MigrationSet::fromDirectory(dirname(__DIR__, 2) . '/system/reseller/migrations');
    }

    public function testOrderedGapFreeIds(): void
    {
        $this->assertSame([
            'R0001_manager_tree',
            'R0002_pricing_foundation',
            'R0003_activation_foundation',
            'R0004_wallet_finance_foundation',
            'R0005_customer_ownership',
        ], self::set()->ids());
    }

    public function testOnlyLegacyChangeIsCustomerOwnershipAndItRunsLast(): void
    {
        $set = self::set();
        foreach ($set->migrations as $m) {
            $parts = array_keys($m->contract['table_parts']);
            if ($m->id === 'R0005_customer_ownership') {
                $this->assertSame(['tbl_customers'], $parts);
                $this->assertSame(['manager_id', 'assigned_sales_id'], array_keys($m->contract['table_parts']['tbl_customers']['columns']));
                $this->assertCount(1, $m->up, 'customer ownership must be one atomic ALTER');
            } else {
                $this->assertSame([], $parts, "{$m->id} must not alter legacy tables");
            }
            foreach ($m->up as $step) {
                // each step is exactly one DDL statement: CREATE TABLE or ALTER TABLE, never DML
                $this->assertMatchesRegularExpression('/^\s*(CREATE TABLE|ALTER TABLE)\s/i', $step['sql'], "{$m->id}: WP1 migrations contain no DML (no backfill, no seed, no root import)");
                $this->assertCount(1, \Reseller\Tests\Support\FixtureBuilder::splitSql($step['sql']), "{$m->id}: exactly one statement per step");
                foreach (VariantRegistry::CORE_TABLES as $legacy) {
                    if ($m->id !== 'R0005_customer_ownership') {
                        $this->assertStringNotContainsString("ALTER TABLE `{$legacy}`", $step['sql']);
                    }
                }
                $this->assertStringNotContainsString('tbl_plans` MODIFY', $step['sql']);
                // legacy tbl_users.root / tbl_customers.created_by are never migration input (no DML exists at all);
                // the only statement touching tbl_customers must not mention created_by
                $this->assertStringNotContainsString('`root`', $step['sql'], 'tbl_users.root is never migration input');
                if (str_contains($step['sql'], '`tbl_customers`') && str_starts_with(ltrim($step['sql']), 'ALTER')) {
                    $this->assertStringNotContainsString('created_by', $step['sql'], 'tbl_customers.created_by is never migration input');
                }
            }
        }
        $this->assertSame('R0005_customer_ownership', $set->migrations[count($set->migrations) - 1]->id);
    }

    public function testEveryForeignKeyIsRestrictNeverCascade(): void
    {
        $count = 0;
        foreach (self::set()->migrations as $m) {
            $fks = [];
            foreach ($m->contract['tables'] as $t) {
                $fks = array_merge($fks, $t['foreign_keys']);
            }
            foreach ($m->contract['table_parts'] as $p) {
                $fks = array_merge($fks, $p['foreign_keys']);
            }
            foreach ($fks as $fk) {
                $count++;
                $this->assertStringEndsWith('ON DELETE RESTRICT ON UPDATE RESTRICT', $fk);
            }
            foreach ($m->up as $step) {
                $this->assertStringNotContainsStringIgnoringCase('CASCADE', $step['sql']);
                $this->assertStringNotContainsStringIgnoringCase('SET NULL', $step['sql']);
            }
        }
        $this->assertGreaterThan(30, $count);
    }

    public function testUserReferencesAreUnsignedAndCustomerPlanReferencesSigned(): void
    {
        foreach (self::set()->migrations as $m) {
            $tables = $m->contract['tables'];
            foreach ($m->contract['table_parts'] as $t => $p) {
                $tables[$t . '#part'] = $p;
            }
            foreach ($tables as $t) {
                foreach ($t['foreign_keys'] as $fk) {
                    preg_match('/^\S+ \(([^)]+)\) REFERENCES (\S+) \(([^)]+)\)/', $fk, $mm);
                    $col = $mm[1];
                    $def = $t['columns'][$col];
                    if ($mm[2] === 'tbl_users' || $mm[2] === 'tbl_manager_wallets') {
                        $this->assertStringStartsWith('int unsigned', $def, "{$col} must be INT UNSIGNED (tbl_users.id)");
                    } elseif (in_array($mm[2], ['tbl_customers', 'tbl_plans'], true)) {
                        $this->assertMatchesRegularExpression('/^int (not )?null/', $def, "{$col} must be signed INT (legacy PK)");
                    } else {
                        $this->assertStringStartsWith('bigint unsigned', $def);
                    }
                }
            }
        }
    }

    public function testAllNewMoneyIsDecimal18_2(): void
    {
        $money = ['base_cost', 'wholesale_cost', 'retail_price', 'provider_settlement_amount', 'balance', 'debt_limit', 'amount', 'balance_before', 'balance_after'];
        $seen = 0;
        foreach (self::set()->migrations as $m) {
            foreach ($m->contract['tables'] as $t) {
                foreach ($t['columns'] as $col => $def) {
                    if (in_array($col, $money, true)) {
                        $seen++;
                        $this->assertStringStartsWith('decimal(18,2)', $def, $col);
                    }
                    $this->assertStringNotContainsString('float', $def);
                    $this->assertStringNotContainsString('double', $def);
                }
            }
        }
        $this->assertSame(13, $seen, 'every new money column is DECIMAL(18,2)');
    }

    public function testNoVoucherCommerceSchema(): void
    {
        foreach (self::set()->migrations as $m) {
            foreach (array_keys($m->contract['tables']) as $t) {
                $this->assertStringNotContainsStringIgnoringCase('voucher', $t);
            }
            foreach ($m->up as $step) {
                $sql = preg_replace("/'VOUCHER'/", '', $step['sql']); // the activation channel enum names the legacy entrypoint
                $this->assertStringNotContainsStringIgnoringCase('voucher', $sql, "{$m->id} must not add voucher-commerce schema");
            }
        }
    }

    public function testTimestampsAreDatetime6WithoutServerDefaults(): void
    {
        foreach (self::set()->migrations as $m) {
            foreach ($m->contract['tables'] as $t) {
                foreach ($t['columns'] as $col => $def) {
                    if (str_ends_with($col, '_at')) {
                        $this->assertMatchesRegularExpression('/^datetime\(6\) (not )?null$/', $def, "{$col}: UTC DATETIME(6), value supplied by the writer");
                    }
                    $this->assertStringNotContainsString('current_timestamp', $def);
                }
            }
        }
    }

    public function testEveryDownStepHasExistenceGuardAndReverseOrder(): void
    {
        foreach (self::set()->migrations as $m) {
            $this->assertNotEmpty($m->down);
            $created = $m->createdTables();
            $dropped = [];
            foreach ($m->down as $d) {
                $this->assertNotEmpty($d['if_exists']);
                if (isset($d['if_exists']['table'])) {
                    $dropped[] = $d['if_exists']['table'];
                }
            }
            $this->assertSame(array_reverse($created), $dropped, "{$m->id}: down steps drop tables in reverse creation order");
        }
    }
}
