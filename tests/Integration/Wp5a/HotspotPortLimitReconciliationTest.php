<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ReconciliationService;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * WP8 A1 (OD-W8-16 Option G): RadiusPolicyBuilder writes the Hotspot Port-Limit (= Simultaneous-Use) as a REPLY
 * attribute. Effect on activations and reconciliation, which compares the canonical RADIUS projection with the
 * target captured at provisioning time:
 *  - a new Hotspot activation provisions and verifies Port-Limit like every managed attribute; PPPoE gets none;
 *  - an activation captured BEFORE A1 (target without Port-Limit) still reconciles to TARGET while RADIUS is
 *    unchanged (no re-provisioning since): no behaviour change for existing activations;
 *  - only if the subscriber was re-provisioned by the A1 builder in the meantime does the pre-A1 target differ, and
 *    then exactly by `reply:Port-Limit`: EXTERNAL_STATE_AMBIGUOUS, funds held, nothing written - the accepted safe
 *    outcome (operator decision), never a release or a fabricated success.
 */
final class HotspotPortLimitReconciliationTest extends ActivationTestCase
{
    private function system(): ActivationActor
    {
        return ActivationActor::system('wp8-a1-test');
    }

    private function id(string $reference): int
    {
        return (int) $this->act($reference)['id'];
    }

    /** @return list<array<string,mixed>> */
    private function reply(int $customer): array
    {
        return static::$db->rows('SELECT attribute, op, value FROM radreply WHERE username = ? ORDER BY attribute', [$this->username($customer)]);
    }

    /** The subscriber write committed but its acknowledgement was lost: never verified (RECONCILIATION_REQUIRED). */
    private function unknownProvisioning(string $type = 'Hotspot'): array
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', $type);
        $c = $this->customer($leaf);
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.commit_lost' && ++$calls === 2) {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $out = $this->activate($leaf, $c, $plan, 'NEW');
        RadiusFaults::$hook = null;
        $this->assertSame(['RECONCILIATION_REQUIRED', 'RADIUS_COMMIT_UNKNOWN'], [$out['status'], $out['error_code']]);
        return [$out['reference'], $top, $leaf, $c];
    }

    /** Rewrites the captured target as the pre-A1 builder captured it: the same projection without Port-Limit. */
    private function captureAsBeforeA1(string $reference): void
    {
        $rows = static::$db->rows("SELECT id, metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND to_state = 'PROVISIONING'", [$this->id($reference)]);
        $changed = 0;
        foreach ($rows as $r) {
            $meta = json_decode((string) $r['metadata'], true);
            if (!is_array($meta['radius']['target']['subscriber']['reply'] ?? null)) {
                continue;
            }
            $before = count($meta['radius']['target']['subscriber']['reply']);
            $meta['radius']['target']['subscriber']['reply'] = array_values(array_filter($meta['radius']['target']['subscriber']['reply'],
                static fn(array $row): bool => $row[0] !== 'Port-Limit'));
            $changed += $before - count($meta['radius']['target']['subscriber']['reply']);
            static::$db->pdo->prepare('UPDATE tbl_reseller_activation_events SET metadata = ? WHERE id = ?')->execute([json_encode($meta), $r['id']]);
        }
        $this->assertSame(1, $changed, 'the A1 target carried exactly one Port-Limit row');
    }

    public function testNewHotspotActivationProvisionsAndVerifiesThePortLimitPppoeGetsNone(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', 'Hotspot');
        $c = $this->customer($leaf);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $this->assertContains(['attribute' => 'Port-Limit', 'op' => ':=', 'value' => '1'], $this->reply($c), 'Hotspot: Port-Limit = Simultaneous-Use in radreply');
        $this->assertSame(0, $this->rowCount('radcheck', "username = ? AND attribute = 'Port-Limit'", [$this->username($c)]), 'never in the control list');

        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', 'PPPOE');
        $p = $this->customer($leaf);
        $this->assertSame('COMPLETED', $this->activate($leaf, $p, $plan, 'NEW')['status']);
        $this->assertNotContains('Port-Limit', array_column($this->reply($p), 'attribute'), 'PPPoE never relies on Port-Limit');
    }

    public function testPreA1CapturedActivationStillReconcilesToTargetWhileRadiusIsUnchanged(): void
    {
        [$ref, , , $c] = $this->unknownProvisioning();
        $this->captureAsBeforeA1($ref);
        static::$db->pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Port-Limit'")->execute([$this->username($c)]);  // RADIUS as the pre-A1 write left it
        $row = ReconciliationService::reconcile($this->system(), $ref);
        $this->assertSame('COMPLETED', $row['status'], 'pre-A1 target == pre-A1 RADIUS state: settled once, as before A1');
        $this->assertContains('RECONCILIATION:RECONCILIATION_REQUIRED->VERIFIED:SUCCESS', $this->timeline($ref));
    }

    public function testPreA1CapturedActivationAfterAnA1ReprovisionIsAmbiguousAndHoldsTheFunds(): void
    {
        [$ref, $top, $leaf, $c] = $this->unknownProvisioning();
        $this->captureAsBeforeA1($ref);          // RADIUS keeps the A1 write (Port-Limit present): re-provisioned by A1
        $radius = $this->reply($c);
        $row = ReconciliationService::reconcile($this->system(), $ref);
        $this->assertSame(['RECONCILIATION_REQUIRED', ReconciliationService::AMBIGUOUS], [$row['status'], $row['error_code']]);
        $meta = json_decode((string) static::$db->value('SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND error_code = ? ORDER BY sequence_no DESC LIMIT 1',
            [$this->id($ref), ReconciliationService::AMBIGUOUS]), true);
        $this->assertSame(['reply:Port-Limit'], $meta['differs_from_target'], 'exactly the A1 attribute, named, never valued');
        $this->assertSame('ACTIVE', (string) static::$db->value('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$this->id($ref)]), 'funds held');
        $this->assertSame(0, $this->rowCount('tbl_manager_ledger', 'activation_id = ?', [$this->id($ref)]), 'no ledger movement');
        $this->assertSame(['1000.00', '1000.00'], [$this->balance($leaf), $this->balance($top)]);
        $this->assertSame($radius, $this->reply($c), 'reconciliation wrote nothing');
    }
}
