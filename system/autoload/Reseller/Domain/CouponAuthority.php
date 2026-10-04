<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Server-authoritative coupons for the online payment flow of unassigned customers (WP5B fix round 1, blocker 5).
 * The gateway page only previews a coupon (no use is consumed and no amount is remembered as authority); the session
 * keeps the coupon code. At order/buy the coupon is re-fetched and re-validated (active, date window, legacy
 * applicability: not for Balance plans, minimum order; usage limit), the discount is derived again with the legacy
 * formula, and one use is consumed by a conditional UPDATE in the SAME transaction that writes the payment row: two
 * requests can never both consume the last allowed use. A posted discount is never read.
 */
final class CouponAuthority
{
    /**
     * The discount of a valid coupon for a plan price, or a DomainError COUPON_INVALID (the reason in the detail).
     *
     * @param array<string,mixed> $coupon tbl_coupons row
     */
    public static function discount(?array $coupon, mixed $price, string $planRouters, ?string $today = null): float
    {
        $today ??= date('Y-m-d');
        $why = match (true) {
            $coupon === null => 'not found',
            $planRouters === 'balance' => 'not available for Balance',
            $coupon['status'] !== 'active' => 'not active',
            $today < (string) $coupon['start_date'] || $today > (string) $coupon['end_date'] => 'not valid today',
            (int) $coupon['max_usage'] > 0 && (int) $coupon['usage_count'] >= (int) $coupon['max_usage'] => 'usage limit reached',
            $price < $coupon['min_order_amount'] => 'minimum order not met',
            default => null,
        };
        if ($why !== null) {
            throw new DomainError('COUPON_INVALID', 'The coupon is not valid (' . $why . ').');
        }
        // the legacy formula (order.php gateway page)
        $discount = 0;
        if ($coupon['type'] === 'percent') {
            $discount = ($coupon['value'] / 100) * $price;
            if ($discount > $coupon['max_discount_amount']) {
                $discount = $coupon['max_discount_amount'];
            }
        } elseif ($coupon['type'] === 'fixed') {
            $discount = $coupon['value'];
        }
        if ($discount >= $price) {
            throw new DomainError('COUPON_INVALID', 'The coupon is not valid (discount exceeds the price).');
        }
        return $discount + 0;
    }

    /** Preview (gateway page): validates and derives the discount; consumes nothing. */
    public static function preview(string $code, mixed $price, string $planRouters): array
    {
        $coupon = Tx::row(self::pdo(), 'SELECT * FROM tbl_coupons WHERE code = ?', [$code]);
        return ['coupon' => $coupon, 'discount' => self::discount($coupon, $price, $planRouters)];
    }

    /**
     * At order/buy: runs $writePayment (which writes the payment row) in ONE transaction together with the coupon's
     * re-validation and the conditional consumption of one use. $writePayment receives the authoritative discount (0
     * without a coupon). An invalid / exhausted coupon writes nothing (COUPON_INVALID).
     *
     * @template T
     * @param callable(float):T $writePayment
     * @return T
     */
    public static function buy(?string $code, mixed $price, string $planRouters, callable $writePayment): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $discount = 0;
            if ($code !== null && $code !== '') {
                $coupon = Tx::row($pdo, 'SELECT * FROM tbl_coupons WHERE code = ? FOR UPDATE', [$code]);
                $discount = self::discount($coupon, $price, $planRouters);
                $n = Tx::exec($pdo, "UPDATE tbl_coupons SET usage_count = usage_count + 1 WHERE id = ? AND status = 'active' AND (max_usage <= 0 OR usage_count < max_usage)",
                    [(int) $coupon['id']]);
                if ($n !== 1) {
                    throw new DomainError('COUPON_INVALID', 'The coupon is not valid (usage limit reached).');
                }
            }
            $result = $writePayment($discount);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function pdo(): \PDO
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
