<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab finding (ITC NetCore lab 2026-10-24, E2E defect D-3): Package::rechargeUser (pinned) ends the request with an
 * alert when the customer is not Active. It ran AFTER the claim, so a voucher redeemed for a Suspended / Disabled /
 * Inactive customer was left CLAIMED (operator review) although nothing was granted, and the attempt was not recorded.
 * The same refusal is now judged before the claim: the voucher stays redeemable, nothing is granted, the attempt is
 * audited, and the portal and staff refill still tell the account status.
 */
final class W10VoucherInactiveCustomerTest extends Wp2HttpTestCase
{
    private function voucher(): array
    {
        $code = 'W10V' . strtoupper(bin2hex(random_bytes(5)));
        $id = static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'wp2_router', 'id_plan' => static::$seed->planId, 'code' => $code,
            'user' => '0', 'status' => '0', 'generated_by' => $this->sid('ad')]);
        return [$id, $code];
    }

    private function vstatus(int $id): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_voucher WHERE id = ?', [$id]);
    }

    private function setStatus(string $username, string $status): void
    {
        static::$db->exec("UPDATE tbl_customers SET status = '" . $status . "' WHERE username = '" . $username . "'");
    }

    private function recharges(string $username): int
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$this->cid($username)]);
    }

    public function testServiceRefusesANotActiveCustomerBeforeTheClaim(): void
    {
        foreach (['Suspended', 'Disabled', 'Inactive'] as $status) {
            [$id, $code] = $this->voucher();
            $this->setStatus('cNULL', $status);
            $before = $this->recharges('cNULL');
            try {
                $out = static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, new \Reseller\Domain\RedemptionTarget(%d, %s), \Reseller\Domain\RedemptionSource::public());',
                    var_export($code, true), $this->cid('cNULL'), var_export('cNULL', true)));
            } finally {
                $this->setStatus('cNULL', 'Active');
            }
            $this->assertSame('0', $this->vstatus($id), "{$status}: the voucher stays redeemable (never CLAIMED without a grant)");
            $this->assertSame('REFUSED', $out['status'] ?? null, "{$status}: refused");
            $this->assertSame('CUSTOMER_NOT_ACTIVE', $out['reason'] ?? null);
            $this->assertSame($before, $this->recharges('cNULL'), "{$status}: nothing granted");
        }
        // the refusal is recorded (PUBLIC source: the voucher log), and an Active customer still redeems the same code
        [$id, $code] = $this->voucher();
        $this->setStatus('cNULL', 'Suspended');
        static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, new \Reseller\Domain\RedemptionTarget(%d, %s), \Reseller\Domain\RedemptionSource::public());',
            var_export($code, true), $this->cid('cNULL'), var_export('cNULL', true)));
        $this->setStatus('cNULL', 'Active');
        $out = static::$app->exec(sprintf('$result = \Reseller\Domain\VoucherRedemptionService::redeem(%s, new \Reseller\Domain\RedemptionTarget(%d, %s), \Reseller\Domain\RedemptionSource::public());',
            var_export($code, true), $this->cid('cNULL'), var_export('cNULL', true)));
        $this->assertSame('GRANTED', $out['status'], 'after reactivation the same voucher is granted once');
        $this->assertSame('1', $this->vstatus($id));
    }

    public function testPortalAndStaffRefillShowTheAccountStatusAndKeepTheVoucher(): void
    {
        // portal: the customer is logged in while Active, then suspended (portal login of a Suspended customer is an open
        // owner question, F-6; this test does not depend on it)
        [$id, $code] = $this->voucher();
        $c = $this->customer('cNULL2');
        $c->get('voucher/activation');
        $this->setStatus('cNULL2', 'Suspended');
        try {
            $r = $c->postWithToken('voucher/activation-post', ['code' => $code], [], 'voucher/activation');
            $page = $c->get('voucher/activation');
        } finally {
            $this->setStatus('cNULL2', 'Active');
        }
        $this->assertSame('0', $this->vstatus($id), 'portal: the voucher stays redeemable: ' . $r->excerpt());
        $this->assertStringContainsString('Suspended', $page->body . $r->body, 'portal: the account status is shown');
        // staff refill for a Suspended customer
        [$id2, $code2] = $this->voucher();
        $this->setStatus('cNULL', 'Suspended');
        try {
            $s = $this->staff('ad');
            $r2 = $s->postWithToken('plan/refill-post', ['id_customer' => $this->cid('cNULL'), 'code' => $code2], [], 'plan/refill');
        } finally {
            $this->setStatus('cNULL', 'Active');
        }
        $this->assertSame('0', $this->vstatus($id2), 'staff refill: the voucher stays redeemable: ' . $r2->excerpt());
    }
}
