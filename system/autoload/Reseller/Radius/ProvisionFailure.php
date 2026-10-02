<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Raised by the legacy device adapter (system/devices/Radius.php) when a provisioning operation is not
 * SUCCESS, so the legacy callers keep their existing failure path (Package::rechargeUser reports it and
 * notifies the operator "sync manually"). The message is sanitized; the result carries the status.
 */
final class ProvisionFailure extends \RuntimeException
{
    public function __construct(public readonly ProvisionResult $result)
    {
        parent::__construct('RADIUS provisioning ' . $result->status . ': ' . $result->code);
    }
}
