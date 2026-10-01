<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * Strict reader of the legacy default retail price tbl_plans.price (WP3B, WP1 binding input "legacy
 * VARCHAR price parsing/validation"). The column is VARCHAR(40) written by the legacy plan forms
 * (Validator::UnsignedNumber, /^\+?[0-9]+$/), so values such as "+1000", "0100" or more than 16 digits
 * can exist. They are never normalised: only the exact WP3A Money input grammar is accepted
 * ("0", "12", "12.5", "12.50"; at most 16 integer digits and 2 decimals). Anything else, including
 * leading/trailing spaces, signs, separators, exponents and non-ASCII digits, is LEGACY_RETAIL_INVALID.
 *
 * tbl_plans.price stays the legacy customer/retail price (D009/D010): it is read here and never written.
 */
final class LegacyRetailPrice
{
    public static function parse(mixed $raw): Money
    {
        if (!is_string($raw)) {
            throw new DomainError('LEGACY_RETAIL_INVALID');
        }
        try {
            $m = Money::parseNonNegative($raw);
        } catch (DomainError) {
            throw new DomainError('LEGACY_RETAIL_INVALID');
        }
        if (!$m->isStorable()) {
            throw new DomainError('LEGACY_RETAIL_INVALID');
        }
        return $m;
    }

    /** Non-throwing variant for diagnostics. */
    public static function tryParse(mixed $raw): ?Money
    {
        try {
            return self::parse($raw);
        } catch (DomainError) {
            return null;
        }
    }
}
