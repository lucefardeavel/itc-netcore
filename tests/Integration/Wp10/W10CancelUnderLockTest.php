<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ReconciliationService;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * Lab closure (independent review 2026-10-26) on the operator cancel of a stale pre-provision activation (OD-7):
 *  - FIN-3: the web path checked "stale" on an unlocked read; the cancel transaction re-checked the status only, so an
 *    activation touched in between (fresh again) could still be cancelled. With the operator threshold the cancel now
 *    re-checks staleness on the locked row and refuses a fresh one (ACTIVATION_IN_PROGRESS), changing nothing;
 *  - the cancel audit was written AFTER the commit: when it failed, the reservation was already released and the
 *    activation CANCELLED with no audit record. It is now written in the same transaction: no audit, no cancel.
 */
final class W10CancelUnderLockTest extends ActivationTestCase
{
    /** a FUNDS_RESERVED activation with its ACTIVE reservation (crash after the reservation committed, before execute) */
    private function stuck(): array
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.before_execute') {
                throw new \RuntimeException('crash');
            }
        };
        try {
            $this->activate($leaf, $c, $plan, 'NEW');
            $this->fail('no crash');
        } catch (\RuntimeException) {
        } finally {
            ActivationFaults::$hook = null;
        }
        $a = static::$db->rows("SELECT id, reference, status FROM tbl_reseller_activations WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$c])[0];
        $this->assertSame('FUNDS_RESERVED', $a['status']);
        return $a;
    }

    private function reservationStatus(int $activationId): ?string
    {
        $v = static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$activationId]);
        return $v === null || $v === false ? null : (string) $v;
    }

    public function testAFreshActivationIsNotCancelledUnderTheOperatorThreshold(): void
    {
        $a = $this->stuck();
        // fresh: updated just now (as if the worker touched it after the operator's unlocked staleness check)
        $actor = ReconciliationService::staffActor($this->ctx('ad'));
        $this->expectExceptionReason('ACTIVATION_IN_PROGRESS', fn() => ReconciliationService::cancel($actor, $a['reference'], ReconciliationService::STALE_SECONDS));
        $this->assertSame('FUNDS_RESERVED', $this->act($a['reference'])['status'], 'nothing changed');
        $this->assertSame('ACTIVE', $this->reservationStatus((int) $a['id']), 'the reservation is still held');
        // stale: the same call cancels and releases
        static::$db->exec("UPDATE tbl_reseller_activations SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR WHERE id = {$a['id']}");
        $this->assertSame('CANCELLED', ReconciliationService::cancel($actor, $a['reference'], ReconciliationService::STALE_SECONDS)['status']);
        $this->assertSame('RELEASED', $this->reservationStatus((int) $a['id']));
    }

    public function testACancelIsNeverCommittedWithoutItsAuditRecord(): void
    {
        $a = $this->stuck();
        $actor = ReconciliationService::staffActor($this->ctx('ad'));
        static::$db->exec('RENAME TABLE tbl_reseller_audit_events TO tbl_reseller_audit_events_w10off');
        $error = null;
        try {
            ReconciliationService::cancel($actor, $a['reference']);
        } catch (\PDOException $e) {
            $error = $e;                           // the audit insert failed (its table is missing)
        } finally {
            static::$db->exec('RENAME TABLE tbl_reseller_audit_events_w10off TO tbl_reseller_audit_events');
        }
        $this->assertNotNull($error, 'the cancel went through without its audit record');
        $this->assertSame('FUNDS_RESERVED', $this->act($a['reference'])['status'], 'not cancelled');
        $this->assertSame('ACTIVE', $this->reservationStatus((int) $a['id']), 'not released');
        $this->assertSame('CANCELLED', ReconciliationService::cancel($actor, $a['reference'])['status'], 'with the audit table back, the cancel works');
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'activation.cancel' AND JSON_UNQUOTE(JSON_EXTRACT(target_snapshot, '$.reference')) = ?", [$a['reference']]), 'audited once');
    }
}
