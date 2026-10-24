<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab finding (ITC NetCore lab 2026-10-24, E2E defect D-2 / RBAC F-5): the customer portal history pages fell back
 * to a lookup by USERNAME (voucher/list-activated when the customer had no rows of its own; order/history always,
 * through an undefined variable). After a rename, a new customer who takes the old username saw the previous
 * holder's transactions and payment orders. Rows recorded with another customer's id must never be listed; legacy
 * rows written before user_id existed (user_id = 0) keep the username match.
 */
final class W10PortalHistoryOwnerTest extends Wp2HttpTestCase
{
    private function trx(int $userId, string $username, string $marker): void
    {
        static::$db->insertRow('tbl_transactions', ['invoice' => $marker, 'username' => $username, 'user_id' => $userId, 'plan_name' => $marker,
            'price' => '1', 'recharged_on' => '2026-10-01', 'recharged_time' => '00:00:00', 'expiration' => '2026-11-01', 'time' => '00:00:00',
            'method' => 'lab', 'routers' => 'wp2_router', 'type' => 'Hotspot']);
    }

    private function order(int $userId, string $username, string $marker): void
    {
        static::$db->insertRow('tbl_payment_gateway', ['username' => $username, 'user_id' => $userId, 'gateway' => 'lab', 'plan_id' => static::$seed->planId,
            'plan_name' => $marker, 'routers_id' => 0, 'routers' => 'wp2_router', 'price' => '1', 'created_date' => '2026-10-01 00:00:00']);
    }

    public function testHistoryNeverListsAnotherCustomersRowsUnderAReusedUsername(): void
    {
        $me = $this->cid('cNULL2');
        $previous = $this->cid('cB');   // the earlier holder of the name (any other customer id)
        $tag = strtoupper(bin2hex(random_bytes(3)));
        // the earlier holder's rows carry the reused username but its own id; a legacy row has no id
        $this->trx($previous, 'cNULL2', 'TF' . $tag);
        $this->trx(0, 'cNULL2', 'TL' . $tag);
        $this->order($previous, 'cNULL2', 'OF' . $tag);
        $this->order(0, 'cNULL2', 'OL' . $tag);
        $c = $this->customer('cNULL2');

        foreach (['first' => false, 'with own rows' => true] as $phase => $own) {
            if ($own) {
                $this->trx($me, 'cNULL2', 'TM' . $tag);
                $this->order($me, 'cNULL2', 'OM' . $tag);
            }
            $act = $c->get('voucher/list-activated')->body;
            $ord = $c->get('order/history')->body;
            $this->assertFalse(str_contains($act, 'TF' . $tag), "{$phase}: activation history lists the previous holder's transaction");
            $this->assertFalse(str_contains($ord, 'OF' . $tag), "{$phase}: order history lists the previous holder's order");
            // positive controls: legacy rows (no id) still match the username; own rows are listed
            $this->assertTrue(str_contains($act, 'TL' . $tag), "{$phase}: legacy transaction listed");
            $this->assertTrue(str_contains($ord, 'OL' . $tag), "{$phase}: legacy order listed");
            if ($own) {
                $this->assertTrue(str_contains($act, 'TM' . $tag), 'own transaction listed');
                $this->assertTrue(str_contains($ord, 'OM' . $tag), 'own order listed');
            }
        }
    }
}
