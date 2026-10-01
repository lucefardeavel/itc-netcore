<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Security\DataScope;

/** Strict validation of the non-money inputs of the financial commands (typed values only). */
final class FinanceInput
{
    private const REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/D';
    private const EXTERNAL = '/^[\x21-\x7E](?:[\x20-\x7E]{0,98}[\x21-\x7E])?$/D';

    /** Mandatory reason / resolution note: trimmed, 3–255 characters, valid UTF-8, no control characters. */
    public static function reason(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new DomainError('INVALID_REASON');
        }
        $t = trim($value);
        $len = mb_strlen($t, 'UTF-8');
        if ($len < 3 || $len > 255 || preg_match('/[\x00-\x1F\x7F]/', $t)) {
            throw new DomainError('INVALID_REASON');
        }
        return $t;
    }

    /** Stable idempotency reference (tbl_manager_transfers.reference). */
    public static function reference(mixed $value): string
    {
        if (!is_string($value) || !preg_match(self::REFERENCE, $value)) {
            throw new DomainError('INVALID_REFERENCE');
        }
        return $value;
    }

    /** Optional external reference (e.g. bank transfer id): printable ASCII, 1–100 characters. */
    public static function externalReference(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !preg_match(self::EXTERNAL, $value)) {
            throw new DomainError('INVALID_INPUT');
        }
        return $value;
    }

    /** A positive id; anything else is indistinguishable from a missing object. */
    public static function id(mixed $value): int
    {
        if (!DataScope::validId($value)) {
            throw new DomainError('NOT_FOUND');
        }
        return (int) $value;
    }
}
