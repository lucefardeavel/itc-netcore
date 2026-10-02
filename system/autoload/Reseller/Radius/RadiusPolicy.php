<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * The exact RADIUS state one subscriber must have (immutable). Check items go to radcheck (FreeRADIUS
 * control list: password, simultaneous use, expiration, counters); reply items go to radreply (sent to the
 * NAS: rate, address, terminate time); the group links the plan's radgroupreply rows.
 *
 * MANAGED_* list every attribute this application owns per user. Applying a policy removes managed
 * attributes that the policy does not contain (including the legacy rows the old device wrote into the
 * wrong table) and never touches anything else. radacct is never part of a policy.
 */
final class RadiusPolicy
{
    public const MANAGED_CHECK = [
        'Cleartext-Password', 'Simultaneous-Use', 'Expiration', 'Max-All-Session', 'Max-Data', 'Nuxr-Period-Start',
        // legacy rows written into radcheck by the pre-WP4 device (reply attributes in the control list)
        'Mikrotik-Rate-Limit', 'Port-Limit', 'Mikrotik-Wireless-Comment', 'WISPr-Session-Terminate-Time', 'access-period', 'Max-Volume',
    ];
    public const MANAGED_REPLY = [
        'Mikrotik-Rate-Limit', 'Framed-Pool', 'Framed-IP-Address', 'Framed-IP-Netmask', 'WISPr-Session-Terminate-Time',
        'Port-Limit', 'Mikrotik-Wireless-Comment',
    ];

    /**
     * @param list<array{0:string,1:string,2:string}> $check [attribute, op, value]
     * @param list<array{0:string,1:string,2:string}> $reply [attribute, op, value]
     */
    public function __construct(
        public readonly string $username,
        public readonly array $check,
        public readonly array $reply,
        public readonly ?string $group,
    ) {
    }

    /** @return array<string,string> attribute => value */
    public function checkMap(): array
    {
        return self::map($this->check);
    }

    /** @return array<string,string> attribute => value */
    public function replyMap(): array
    {
        return self::map($this->reply);
    }

    /** @param list<array{0:string,1:string,2:string}> $items */
    private static function map(array $items): array
    {
        $out = [];
        foreach ($items as [$attribute, , $value]) {
            $out[$attribute] = $value;
        }
        ksort($out);
        return $out;
    }
}
