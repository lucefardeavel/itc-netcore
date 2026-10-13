<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * WP8 A3 rename integrity through the reseller re-provision (R1; plan rev. 3 §R2 F-2 … F-5, owner OD-A3R-9 / 11):
 *  - several Radius plans sharing one key: the rename runs once per run and the result equals a fresh ADMIN_SYNC
 *    (status DONE; the baseline ended REPROVISION_UNCONFIRMED because the second rename deleted the first plan's rows);
 *  - an equivalent (case-only) rename keeps the policy;
 *  - an ambiguous rename is refused BEFORE any write: the customer row and RADIUS stay unchanged, the outcome is
 *    REFUSED_AMBIGUOUS_IDENTITY (never FAILED_REVERTED: nothing was reverted).
 */
final class RenameIntegrityTest extends ActivationTestCase
{
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

    /** @return list<string> the policy rows of one EXACT key ("table|attribute|op|value"), sorted */
    private function exact(string $key): array
    {
        $out = [];
        foreach (['radcheck', 'radreply'] as $t) {
            foreach (static::$db->rows("SELECT HEX(username) AS h, attribute, op, value FROM {$t}") as $r) {
                if (strtolower($r['h']) === bin2hex($key)) {
                    $out[] = "{$t}|{$r['attribute']}|{$r['op']}|{$r['value']}";
                }
            }
        }
        foreach (static::$db->rows('SELECT HEX(username) AS h, groupname, priority FROM radusergroup') as $r) {
            if (strtolower($r['h']) === bin2hex($key)) {
                $out[] = "radusergroup|{$r['groupname']}|{$r['priority']}";
            }
        }
        sort($out);
        return $out;
    }

    private function radiusChecksum(): string
    {
        $rows = [];
        foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
            foreach (static::$db->rows("SELECT * FROM {$t} ORDER BY id") as $r) {
                $rows[] = $t . json_encode($r);
            }
        }
        return hash('sha256', implode("\n", $rows));
    }

    private function customerRow(int $c): array
    {
        return static::$db->rows('SELECT username, password, pppoe_username, pppoe_password, pppoe_ip, status FROM tbl_customers WHERE id = ?', [$c])[0];
    }

    private function lastOutcome(int $c): ?string
    {
        $rows = static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'reprovision.identity_edit' AND target_customer_id = ? ORDER BY id DESC LIMIT 1", [$c]);
        return $rows === [] ? null : (json_decode((string) $rows[0]['after_json'], true)['outcome'] ?? null);
    }

    /**
     * a reseller-owned customer with a Hotspot recharge (keyed on the username) and a PPPoE recharge (keyed on its own
     * PPPoE username). NOTE: two Radius plans on the SAME key cannot both verify even in an ADMIN_SYNC (pre-existing,
     * not a rename effect), so the keys differ here.
     */
    private function twoPlans(array $extra = []): int
    {
        [, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, $extra + ['pppoe_username' => 'rip' . bin2hex(random_bytes(4))]);
        $this->recharge($c, $this->radiusPlan('20', 'Hotspot'), '2031-01-01', '10:00:00');
        $this->recharge($c, $this->radiusPlan('20', 'PPPOE'), '2031-01-01', '10:00:00', 'on', 'PPPOE');
        $this->assertSame('DONE', R::run($this->ctx('ad'), $c, R::ADMIN_SYNC)['status']);
        return $c;
    }

    public function testUsernameRenameAcrossSeveralPlansRunsOnceAndEqualsAFreshSync(): void
    {
        $c = $this->twoPlans();
        $old = $this->username($c);
        $pppoe = (string) static::$db->value('SELECT pppoe_username FROM tbl_customers WHERE id = ?', [$c]);
        $pppoeBefore = $this->exact($pppoe);
        $new = 'ri' . bin2hex(random_bytes(4));
        // the username pair applies to BOTH plans; repeated, it deleted the rows the Hotspot plan had just written
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '', 'pppoe_username' => $pppoe]);
        $this->assertSame('DONE', $r['status'], 'the baseline ended REPROVISION_UNCONFIRMED here');
        $this->assertSame([], $this->exact($old), 'nothing under the old exact key');
        $after = $this->exact($new);
        $this->assertNotSame([], $after, 'the Hotspot policy survived under the new key');
        $this->assertSame('DONE', R::run($this->ctx('ad'), $c, R::ADMIN_SYNC)['status']);
        $this->assertSame($after, $this->exact($new), 'a fresh sync changes nothing: the rename lost nothing');
        $this->assertSame($pppoeBefore, $this->exact($pppoe), 'the PPPoE key (not renamed) is unchanged');
        $this->customersChanged();
    }

    public function testEquivalentRenameKeepsThePolicy(): void
    {
        $lower = 'rie' . bin2hex(random_bytes(4));
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, ['username' => $lower]);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $before = $this->exact($lower);
        $this->assertNotSame([], $before);
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => strtoupper($lower), 'password' => '']);
        $this->assertSame('DONE', $r['status']);
        $this->assertSame([], $this->exact($lower));
        $this->assertSame($before, $this->exact(strtoupper($lower)), 'the same policy under the new bytes');
        $this->customersChanged();
    }

    /** @return array<string,array{0:string,1:bool}> where the equivalent foreign key sits */
    public static function ambiguous(): array
    {
        return ['equivalent to the old name' => ['old', true], 'equivalent to the new name' => ['new', false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ambiguous')]
    public function testAmbiguousRenameIsRefusedBeforeAnyWrite(string $where, bool $unused): void
    {
        $old = 'ria' . bin2hex(random_bytes(4));
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, ['username' => $old]);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $new = 'rib' . bin2hex(random_bytes(4));
        // legacy data: an orphan RADIUS key of another subscriber that the collation treats as equal
        static::$db->insertRow('radcheck', ['username' => strtoupper($where === 'old' ? $old : $new), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'foreign']);
        $customer = $this->customerRow($c);
        $radius = $this->radiusChecksum();
        $this->expectExceptionReason('RENAME_AMBIGUOUS_IDENTITY', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']));
        $this->assertSame($customer, $this->customerRow($c), 'refused before the identity write');
        $this->assertSame($radius, $this->radiusChecksum(), 'RADIUS untouched, the foreign key included');
        $this->assertSame('REFUSED_AMBIGUOUS_IDENTITY', $this->lastOutcome($c), 'not FAILED_REVERTED: nothing was reverted');
        static::$db->exec("DELETE FROM radcheck WHERE value = 'foreign'");
    }
}
