<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab finding (ITC NetCore lab 2026-10-24, E2E defect D-2 / RBAC F-5): the customer portal history pages fell back
 * to a lookup by USERNAME (voucher/list-activated when the customer had no rows of its own; order/history always,
 * through an undefined variable). After a rename, a new customer who takes the old username saw the previous
 * holder's transactions and payment orders.
 * Owner direction D-20: a legacy row without an id (user_id = 0, written before the column existed) may also belong
 * to an earlier holder of the reused name; its ownership cannot be proven, so the portal fails closed for it. The
 * financial rows themselves are preserved unchanged (never rewritten or erased) for staff and authorized recovery.
 */
final class W10PortalHistoryOwnerTest extends Wp2HttpTestCase
{
    private function trx(int $userId, string $username, string $marker): int
    {
        return (int) static::$db->insertRow('tbl_transactions', ['invoice' => $marker, 'username' => $username, 'user_id' => $userId, 'plan_name' => $marker,
            'price' => '1', 'recharged_on' => '2026-10-01', 'recharged_time' => '00:00:00', 'expiration' => '2026-11-01', 'time' => '00:00:00',
            'method' => 'lab', 'routers' => 'wp2_router', 'type' => 'Hotspot']);
    }

    private function order(int $userId, string $username, string $marker): int
    {
        return (int) static::$db->insertRow('tbl_payment_gateway', ['username' => $username, 'user_id' => $userId, 'gateway' => 'lab', 'plan_id' => static::$seed->planId,
            'plan_name' => $marker, 'routers_id' => 0, 'routers' => 'wp2_router', 'price' => '1', 'created_date' => '2026-10-01 00:00:00']);
    }

    private function rowsOf(string $table, array $ids): string
    {
        return sha1((string) json_encode(static::$db->rows('SELECT * FROM ' . $table . ' WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id')));
    }

    public function testHistoryListsOnlyRowsProvablyOwnedByTheCustomerAndPreservesTheRest(): void
    {
        $me = $this->cid('cNULL2');
        $previous = $this->cid('cB');   // the earlier holder of the reused name (any other customer id)
        $tag = strtoupper(bin2hex(random_bytes(3)));
        // the earlier holder left rows under the reused username: one recorded with its id, one legacy row without an id
        $trx = [$this->trx($previous, 'cNULL2', 'TF' . $tag), $this->trx(0, 'cNULL2', 'TL' . $tag)];
        $ord = [$this->order($previous, 'cNULL2', 'OF' . $tag), $this->order(0, 'cNULL2', 'OL' . $tag)];
        $trxHash = $this->rowsOf('tbl_transactions', $trx);
        $ordHash = $this->rowsOf('tbl_payment_gateway', $ord);
        $c = $this->customer('cNULL2');

        foreach (['no own rows yet' => false, 'with own rows' => true] as $phase => $own) {
            if ($own) {
                $this->trx($me, 'cNULL2', 'TM' . $tag);
                $this->order($me, 'cNULL2', 'OM' . $tag);
            }
            $act = $c->get('voucher/list-activated')->body;
            $hist = $c->get('order/history')->body;
            foreach (['TF' => $act, 'TL' => $act, 'OF' => $hist, 'OL' => $hist] as $m => $body) {
                $this->assertFalse(str_contains($body, $m . $tag), "{$phase}: {$m} row of the earlier holder listed to the new holder");
            }
            if ($own) {
                $this->assertTrue(str_contains($act, 'TM' . $tag), 'own transaction listed');
                $this->assertTrue(str_contains($hist, 'OM' . $tag), 'own order listed');
            }
        }
        // the financial history is preserved byte for byte (no rewrite of ownership, no deletion)
        $this->assertSame($trxHash, $this->rowsOf('tbl_transactions', $trx), 'transactions preserved');
        $this->assertSame($ordHash, $this->rowsOf('tbl_payment_gateway', $ord), 'payment orders preserved');
    }
}
