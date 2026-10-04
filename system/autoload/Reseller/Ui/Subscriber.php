<?php
namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\CustomerSearch;
use Reseller\Radius\AccountingService;

/**
 * WP6A subscriber display helpers (read only). Callers pass customers they already loaded through DataScope; the
 * helpers only add display data for those ids. Session data comes from the authoritative FreeRADIUS accounting
 * table (OD-D: existing database data, never a live NAS call); when it is unavailable the state says so.
 */
final class Subscriber
{
    /** An open session not updated for longer than this is shown as "status unknown", never "online". */
    public const STALE_SECONDS = 1800;

    /**
     * Latest service summary per customer (one query for a whole page): the active recharge with the latest expiry,
     * else the latest recharge.
     *
     * @param list<int> $customerIds
     * @return array<int,array{plan:string,type:string,expiration:string,time:string,on:bool,days:?int,count:int}>
     */
    public static function plans(array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (\ORM::for_table('tbl_user_recharges')->select_many('customer_id', 'namebp', 'type', 'expiration', 'time', 'status')->where_in('customer_id', $ids)->order_by_desc('id')->find_array() as $r) {
            $cid = (int) $r['customer_id'];
            $on = $r['status'] === 'on';
            $cur = $out[$cid] ?? null;
            $better = $cur === null || ($on && !$cur['on']) || ($on === $cur['on'] && $on && (string) $r['expiration'] > $cur['expiration']);
            $count = ($cur['count'] ?? 0) + ($on ? 1 : 0);
            if ($better) {
                $out[$cid] = ['plan' => (string) $r['namebp'], 'type' => (string) $r['type'], 'expiration' => (string) $r['expiration'], 'time' => (string) $r['time'],
                    'on' => $on, 'days' => Format::daysUntil((string) $r['expiration']), 'count' => $count];
            } else {
                $out[$cid]['count'] = $count;
            }
        }
        return $out;
    }

    /** Usernames of open RADIUS sessions whose framed IP starts with the term (search; [] when not applicable / unavailable). */
    public static function sessionUsernames(string $term): array
    {
        global $config;
        if (!CustomerSearch::looksLikeIp($term) || empty($config['radius_enable'])) {
            return [];
        }
        try {
            return AccountingService::connection()->openSessionUsernamesByIp($term);
        } catch (\Throwable $e) {
            \Reseller\Security\ErrorReporter::report($e, 'subscriber-search-sessions');
            return [];
        }
    }

    /**
     * Online / offline from the accounting history of the subscriber's RADIUS usernames (username and PPPoE
     * username). States: online, stale (open but not updated recently), offline, never (no session recorded),
     * unavailable (RADIUS accounting not configured / not readable). The NAS address only when $nas is true.
     *
     * @param array<string,mixed> $customer
     * @return array<string,mixed>
     */
    public static function session(array $customer, bool $nas): array
    {
        global $config;
        if (empty($config['radius_enable'])) {
            return ['state' => 'unavailable'];
        }
        $names = array_values(array_unique(array_filter([(string) ($customer['username'] ?? ''), (string) ($customer['pppoe_username'] ?? '')])));
        try {
            $acc = AccountingService::connection();
            $open = [];
            foreach ($names as $n) {
                $open = array_merge($open, $acc->openSessions($n));
            }
            usort($open, static fn(array $a, array $b): int => (int) $b['radacctid'] <=> (int) $a['radacctid']);
            $row = $open[0] ?? $acc->lastSession($names);
        } catch (\Throwable $e) {
            \Reseller\Security\ErrorReporter::report($e, 'subscriber-session');
            return ['state' => 'unavailable'];
        }
        if ($row === null) {
            return ['state' => 'never'];
        }
        $seen = (string) ($row['acctupdatetime'] ?? '') !== '' ? (string) $row['acctupdatetime'] : (string) $row['acctstarttime'];
        $state = 'offline';
        if ($open !== []) {
            $age = time() - (int) strtotime($seen);
            $state = $age > self::STALE_SECONDS ? 'stale' : 'online';
        }
        $out = [
            'state' => $state, 'open_sessions' => count($open), 'username' => (string) $row['username'],
            'started' => (string) $row['acctstarttime'], 'last_update' => $seen, 'stopped' => $row['acctstoptime'] !== null ? (string) $row['acctstoptime'] : null,
            'framed_ip' => (string) $row['framedipaddress'], 'mac' => (string) ($row['callingstationid'] ?? ''),
            'session_seconds' => (int) $row['acctsessiontime'], 'download' => (int) $row['acctoutputoctets'], 'upload' => (int) $row['acctinputoctets'],
        ];
        if ($nas) {
            $out['nas'] = (string) $row['nasipaddress'];
        }
        return $out;
    }
}
