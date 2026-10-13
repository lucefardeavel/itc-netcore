<?php
namespace Reseller\Tests\Integration\Wp5b;

// Verification probe only (WP8 A3 final pre-acceptance, item 3); kept outside the repository, mounted read-only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * Two Radius plans (Hotspot + PPPoE) on the SAME RADIUS key (no PPPoE username, both keyed on the username):
 *  P1 ADMIN_SYNC outcome; P2 IDENTITY_EDIT (username rename) outcome and the resulting RADIUS rows.
 * Records what is observed (STDERR JSON) and asserts it, so the exit code is evidence.
 */
final class SameKeyAdminSyncProbeTest extends ActivationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $root = '/repo';
        $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
        $GLOBALS['_app_stage'] = 'Live';
        foreach (['Package', 'Text'] as $c) {
            require_once $root . "/system/autoload/{$c}.php";
        }
        require_once $root . '/system/devices/Radius.php';
    }

    private function outcome(callable $f): array
    {
        try {
            return ['status' => $f()['status'], 'reason' => null];
        } catch (DomainError $e) {
            return ['status' => 'DomainError', 'reason' => $e->reason];
        }
    }

    private function lastAudit(int $c, string $type): ?array
    {
        $rows = static::$db->rows('SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = ? AND target_customer_id = ? ORDER BY id DESC LIMIT 1', [$type, $c]);
        return $rows === [] ? null : json_decode((string) $rows[0]['after_json'], true);
    }

    private function exactCount(string $key): int
    {
        $n = 0;
        foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
            foreach (static::$db->rows("SELECT HEX(username) AS h FROM {$t}") as $r) {
                $n += strtolower($r['h']) === bin2hex($key) ? 1 : 0;
            }
        }
        return $n;
    }

    public function testTwoRadiusPlansOnTheSameKey(): void
    {
        [, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, ['pppoe_username' => '']);
        $this->recharge($c, $this->radiusPlan('20', 'Hotspot'), '2031-01-01', '10:00:00');
        $this->recharge($c, $this->radiusPlan('20', 'PPPOE'), '2031-01-01', '10:00:00', 'on', 'PPPOE');
        $old = $this->username($c);

        $sync = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::ADMIN_SYNC));
        $syncAudit = $this->lastAudit($c, 'reprovision.admin_sync');
        $new = 'skp' . bin2hex(random_bytes(4));
        $rename = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']));
        $renameAudit = $this->lastAudit($c, 'reprovision.identity_edit');
        $obs = [
            'admin_sync' => $sync + ['audit_outcome' => $syncAudit['outcome'] ?? null, 'audit_verified' => $syncAudit['verified'] ?? null, 'audit_device' => $syncAudit['device'] ?? null],
            'identity_edit' => $rename + ['audit_outcome' => $renameAudit['outcome'] ?? null, 'audit_verified' => $renameAudit['verified'] ?? null,
                'rows_old_exact' => $this->exactCount($old), 'rows_new_exact' => $this->exactCount($new),
                'old_session_event' => $this->lastAudit($c, 'rename.old_session')['old_session'] ?? null],
        ];
        fwrite(STDERR, 'PROBE ' . json_encode($obs) . "\n");
        $this->assertSame(['DomainError', R::UNCONFIRMED], [$sync['status'], $sync['reason']], 'ADMIN_SYNC with two Radius plans on one key');
        $this->assertFalse($syncAudit['verified'] ?? null, 'the verification is what fails');
        $this->customersChanged();
    }
}
