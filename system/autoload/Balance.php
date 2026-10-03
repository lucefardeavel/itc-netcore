<?php
/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/
/**
 *  This script is for managing user balance
 **/

class Balance
{

    public static function plus($id_customer, $amount)
    {
        // WP5B §14 (owner-approved): one atomic relative update, never an absolute write-back of a stale
        // read, so a concurrent locked debit is never lost
        $st = ORM::get_db()->prepare('UPDATE tbl_customers SET balance = balance + ? WHERE id = ?');
        $st->execute([$amount, $id_customer]);
    }

    public static function transfer($id_customer, $phoneTarget, $amount)
    {
        global $config;
        // WP2 / OD-11 safety gate: no legacy balance movement involving a reseller-owned customer;
        // checked before the sender is debited.
        if (\Reseller\Domain\ServiceGate::isBlockedCustomerId($id_customer) || \Reseller\Domain\ServiceGate::isBlockedUsername((string) $phoneTarget)) {
            \Reseller\Domain\ServiceGate::refused('balanceTransfer', $id_customer, (string) $phoneTarget);
            return false;
        }
        if (Balance::min($id_customer, $amount)) {
            return Balance::plusByPhone($phoneTarget, $amount);
        } else {
            return false;
        }
    }

    public static function min($id_customer, $amount)
    {
        // WP5B §14: atomic relative update (the legacy no-floor semantics are unchanged)
        $st = ORM::get_db()->prepare('UPDATE tbl_customers SET balance = balance - ? WHERE id = ?');
        $st->execute([$amount, $id_customer]);
        return true;
    }

    public static function plusByPhone($phone_customer, $amount)
    {
        // WP5B §14: tbl_customers.username is not unique: exactly one row (the lowest id, as the legacy
        // find_one) is resolved, then updated by id, atomically and relatively
        $id = self::idByUsername($phone_customer);
        if ($id === null) {
            return false;
        }
        $st = ORM::get_db()->prepare('UPDATE tbl_customers SET balance = balance + ? WHERE id = ?');
        $st->execute([$amount, $id]);
        return true;
    }

    public static function minByPhone($phone_customer, $amount)
    {
        // WP5B §14: one row by id; the floor is checked by the same atomic statement
        $id = self::idByUsername($phone_customer);
        if ($id === null) {
            return false;
        }
        $db = ORM::get_db();
        $st = $db->prepare('UPDATE tbl_customers SET balance = balance - ? WHERE id = ? AND balance >= ?');
        $st->execute([$amount, $id, $amount]);
        if ($st->rowCount() > 0) {
            return true;
        }
        // a zero amount changes no row: the legacy result is whether the balance covers it
        $chk = $db->prepare('SELECT balance >= ? FROM tbl_customers WHERE id = ?');
        $chk->execute([$amount, $id]);
        return (float) $amount == 0 && (string) $chk->fetchColumn() === '1';
    }

    private static function idByUsername($username)
    {
        $st = ORM::get_db()->prepare('SELECT id FROM tbl_customers WHERE username = ? ORDER BY id LIMIT 1');
        $st->execute([$username]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
