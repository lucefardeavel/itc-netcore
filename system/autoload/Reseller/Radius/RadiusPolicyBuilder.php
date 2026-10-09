<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Builds the exact RADIUS policy for one subscriber from customer + plan + bandwidth + the authoritative
 * billing expiry (06_RADIUS_CONTRACT "RadiusPolicyBuilder"). Pure: no database, no network, no clock.
 *
 * Owner decisions (WP4): the expiry is the saved billing expiry (Q5), never "now + validity"; a PPPoE
 * customer is keyed by pppoe_username when set (Q7); FreeRADIUS counters are provided by the project
 * configuration (install/freeradius, Q2 option a).
 *
 * Attribute placement:
 *  - check (radcheck): Cleartext-Password, Simultaneous-Use, Expiration, Max-All-Session (time quota,
 *    sqlcounter "nuxr_timecounter"), Max-Data (data quota, sqlcounter "nuxr_datacounter") and, with a
 *    quota, Nuxr-Period-Start (epoch of the billing period start): the counters sum only the sessions of
 *    the current period, because accounting history is no longer deleted at renewal (C17);
 *  - reply (radreply): Mikrotik-Rate-Limit; PPPoE static Framed-IP-Address OR Framed-Pool (never 0.0.0.0,
 *    never both); Hotspot WISPr-Session-Terminate-Time and Port-Limit = Simultaneous-Use (max(1, shared_users));
 *  - group: plan_<id> (radusergroup -> radgroupreply rows of the plan).
 * Port-Limit placement: the legacy device stored Port-Limit (and Mikrotik-Wireless-Comment) in radcheck, the
 * control list, where they never reached the NAS; that placement is still removed and never written. The Hotspot
 * Port-Limit is a REPLY attribute (WP8 OD-W8-16 Option G, verified on RouterOS 7.24.5: the Hotspot enforces it,
 * also when an accounting Stop was lost). PPPoE gets no Port-Limit - RouterOS ignores it for PPPoE, which is
 * protected by Simultaneous-Use plus the fail-closed post-auth session guard (install/freeradius). VPN unchanged.
 */
final class RadiusPolicyBuilder
{
    /**
     * @param array<string,mixed>      $customer tbl_customers row (username, password, pppoe_*)
     * @param array<string,mixed>      $plan     tbl_plans row
     * @param array<string,mixed>|null $bandwidth tbl_bandwidth row of the plan
     */
    public static function build(array $customer, array $plan, ?array $bandwidth, ?\DateTimeImmutable $expiry, ?\DateTimeImmutable $periodStart = null): RadiusPolicy
    {
        $type = (string) ($plan['type'] ?? '');
        $pppoe = $type === 'PPPOE';
        $username = self::key($customer, $pppoe);
        $password = $pppoe && (string) ($customer['pppoe_password'] ?? '') !== '' ? (string) $customer['pppoe_password'] : (string) ($customer['password'] ?? '');
        if ($password === '') {
            throw new \InvalidArgumentException('POLICY_NO_PASSWORD');
        }
        $check = [['Cleartext-Password', ':=', $password]];
        $shared = max(1, (int) ($plan['shared_users'] ?? 1));
        $check[] = ['Simultaneous-Use', ':=', (string) ($pppoe ? 1 : $shared)];
        if ($expiry !== null) {
            $check[] = ['Expiration', ':=', $expiry->format('d M Y H:i:s')];
        }
        if ($type === 'Hotspot' && ($plan['typebp'] ?? '') === 'Limited') {
            $limit = (string) ($plan['limit_type'] ?? '');
            if ($limit === 'Time_Limit' || $limit === 'Both_Limit') {
                $check[] = ['Max-All-Session', ':=', (string) self::seconds($plan)];
            }
            if ($limit === 'Data_Limit' || $limit === 'Both_Limit') {
                $check[] = ['Max-Data', ':=', self::bytes($plan)];
            }
            if (in_array($limit, ['Time_Limit', 'Data_Limit', 'Both_Limit'], true)) {
                $check[] = ['Nuxr-Period-Start', ':=', (string) ($periodStart?->getTimestamp() ?? 0)];
            }
        }

        $reply = [];
        if ($bandwidth !== null && ($rate = self::rateLimit($bandwidth)) !== null) {
            $reply[] = ['Mikrotik-Rate-Limit', ':=', $rate];
        }
        if ($pppoe) {
            $ip = trim((string) ($customer['pppoe_ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $ip !== '0.0.0.0') {
                $reply[] = ['Framed-IP-Address', ':=', $ip];
            } elseif (trim((string) ($plan['pool'] ?? '')) !== '') {
                $reply[] = ['Framed-Pool', ':=', trim((string) $plan['pool'])];
            }
        }
        if ($type === 'Hotspot' && $expiry !== null) {
            $reply[] = ['WISPr-Session-Terminate-Time', ':=', $expiry->format('Y-m-d\TH:i:sP')];
        }
        if ($type === 'Hotspot') {
            $reply[] = ['Port-Limit', ':=', (string) $shared];
        }
        $planId = (int) ($plan['id'] ?? 0);
        return new RadiusPolicy($username, $check, $reply, $planId > 0 ? 'plan_' . $planId : null);
    }

    /** RADIUS key of a customer for a plan type (Q7: PPPoE uses pppoe_username when set). */
    public static function key(array $customer, bool $pppoe): string
    {
        $key = $pppoe && trim((string) ($customer['pppoe_username'] ?? '')) !== '' ? trim((string) $customer['pppoe_username']) : trim((string) ($customer['username'] ?? ''));
        if ($key === '' || strlen($key) > 64 || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new \InvalidArgumentException('POLICY_INVALID_USERNAME');
        }
        return $key;
    }

    /**
     * Plan-level group reply rows (radgroupreply, group plan_<id>): the plan's rate. Ascend-Xmit-Rate is the
     * upload rate (the legacy device wrote the download rate twice).
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function groupReply(array $bandwidth): array
    {
        $rate = self::rateLimit($bandwidth);
        if ($rate === null) {
            return [];
        }
        return [
            ['Ascend-Data-Rate', ':=', (string) self::bps((string) $bandwidth['rate_down'], (string) $bandwidth['rate_down_unit'])],
            ['Ascend-Xmit-Rate', ':=', (string) self::bps((string) $bandwidth['rate_up'], (string) $bandwidth['rate_up_unit'])],
            ['Mikrotik-Rate-Limit', ':=', $rate],
        ];
    }

    /**
     * MikroTik "rx/tx [burst ...]" (upload/download as seen from the subscriber). An optional burst tail is
     * appended only when it consists of MikroTik rate tokens; anything else is dropped.
     */
    public static function rateLimit(array $bandwidth): ?string
    {
        $up = self::rate((string) ($bandwidth['rate_up'] ?? ''), (string) ($bandwidth['rate_up_unit'] ?? ''));
        $down = self::rate((string) ($bandwidth['rate_down'] ?? ''), (string) ($bandwidth['rate_down_unit'] ?? ''));
        if ($up === null || $down === null) {
            return null;
        }
        $rate = $up . '/' . $down;
        $burst = trim((string) ($bandwidth['burst'] ?? ''));
        if ($burst !== '' && preg_match('#^[0-9]+[kKmMgG]?(/[0-9]+[kKmMgG]?)?( [0-9]+[kKmMgGs]?(/[0-9]+[kKmMgGs]?)?){0,4}$#', $burst)) {
            $rate .= ' ' . $burst;
        }
        return $rate;
    }

    private static function rate(string $value, string $unit): ?string
    {
        if (!preg_match('/^[0-9]{1,9}$/', $value) || (int) $value === 0) {
            return null;
        }
        return $value . match ($unit) {
            'Kbps' => 'K',
            'Gbps' => 'G',
            default => 'M',
        };
    }

    private static function bps(string $value, string $unit): int
    {
        return (int) $value * match ($unit) {
            'Kbps' => 1000,
            'Gbps' => 1000000000,
            default => 1000000,
        };
    }

    private static function seconds(array $plan): int
    {
        $limit = max(0, (int) ($plan['time_limit'] ?? 0));
        return ($plan['time_unit'] ?? '') === 'Hrs' ? $limit * 3600 : $limit * 60;
    }

    /** Decimal bytes as a string (64-bit safe; the legacy device used the same decimal units). */
    private static function bytes(array $plan): string
    {
        $limit = max(0, (int) ($plan['data_limit'] ?? 0));
        return (string) ($limit * (($plan['data_unit'] ?? '') === 'GB' ? 1000000000 : 1000000));
    }
}
