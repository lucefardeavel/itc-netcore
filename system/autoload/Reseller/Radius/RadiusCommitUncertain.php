<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/** The COMMIT of a RADIUS write was sent but its outcome is not known (UNKNOWN, never FAILED). */
final class RadiusCommitUncertain extends \RuntimeException
{
}
