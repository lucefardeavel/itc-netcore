<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Failure-injection points inside the financial writers (WP3A failure-atomicity tests). A no-op unless
 * a test assigns $hook; runtime code never assigns it (enforced by a source guard).
 */
final class FinanceFaults
{
    /** @var (\Closure(string):void)|null */
    public static ?\Closure $hook = null;

    public static function hit(string $point): void
    {
        if (self::$hook !== null) {
            (self::$hook)($point);
        }
    }
}
