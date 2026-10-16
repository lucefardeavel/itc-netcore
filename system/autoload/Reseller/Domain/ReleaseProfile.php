<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Radius\ProvisionFailure;
use Reseller\Radius\ProvisionResult;
use Reseller\Security\ErrorReporter;

/**
 * WP9 release profile (owner authorization §5: an unsafe, conditional legacy driver that the launch configuration
 * does not need may be disabled explicitly, with tests proving it cannot be reached).
 *
 * The SaaS RADIUS release serves subscribers through FreeRADIUS only (Architecture Lock axis 8: RADIUS + CoA). The
 * legacy MikroTik-API drivers (MikrotikHotspot / MikrotikPppoe / MikrotikVpn: OBS-S7-1 ignored !trap, OBS-S7-2 VPN NAT
 * by address, OBS-W8-4 plaintext API without timeout, V-1 VPN equivalence untested) and the rlm_rest device
 * (RadiusRest: not proven with a real FreeRADIUS rlm_rest deployment, W8-15) are therefore DISABLED unless the
 * operator sets `$legacy_devices_enabled = true;` in config.php - a documented, unsupported configuration whose open
 * findings must be closed before it is used in production. Disabled drivers refuse to be constructed (every call path
 * creates the driver object first), so no legacy device operation can run.
 */
final class ReleaseProfile
{
    /** devices of the release profile: FreeRADIUS (SQL) and the network-less Dummy device */
    public const SUPPORTED_DEVICES = ['Radius', 'Dummy'];

    public static function legacyDevicesEnabled(): bool
    {
        return ($GLOBALS['legacy_devices_enabled'] ?? false) === true;
    }

    public static function deviceEnabled(string $device): bool
    {
        return in_array($device, self::SUPPORTED_DEVICES, true) || self::legacyDevicesEnabled();
    }

    /** @throws ProvisionFailure DEVICE_DISABLED_BY_RELEASE_PROFILE */
    public static function assertDeviceEnabled(string $device): void
    {
        if (self::deviceEnabled($device)) {
            return;
        }
        ErrorReporter::log('device.release_profile_refused', 'device=' . preg_replace('/[^A-Za-z0-9_]/', '', $device));
        throw new ProvisionFailure(ProvisionResult::failed('DEVICE_DISABLED_BY_RELEASE_PROFILE',
            'This device type is disabled in the release profile (FreeRADIUS only).', false));
    }
}
