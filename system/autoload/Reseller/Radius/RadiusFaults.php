<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Failure-injection points inside the RADIUS writers (WP4 failure tests). A no-op unless a test assigns
 * $hook; runtime code never assigns it (enforced by a source guard).
 *  - radius.after_write: inside the transaction, before COMMIT (an exception there rolls back: FAILED);
 *  - radius.commit_lost: right after COMMIT (an exception there models a lost acknowledgement: UNKNOWN).
 */
final class RadiusFaults
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
