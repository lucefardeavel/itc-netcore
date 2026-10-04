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

    /**
     * Activation customer picker: reseller-owned customers matching the term within customer.read scope that the
     * actor may activate (ActivationService::payerOf: Agent own customers, Sales assigned, Admin any), at most 20.
     *
     * @return list<array<string,mixed>>
     */
    public static function activatable(\Reseller\Security\ActorContext $a, string $term): array
    {
        [$pred, $params] = CustomerSearch::predicate($term, 'tbl_customers', false, self::sessionUsernames($term));
        $rows = \Reseller\Security\DataScope::scopeCustomers(\ORM::for_table('tbl_customers'), $a, \Reseller\Security\Capability::CUSTOMER_READ)
            ->where_raw($pred, $params)->where_not_null('tbl_customers.manager_id')
            ->order_by_asc('tbl_customers.username')->limit(40)->find_array();
        $out = [];
        foreach ($rows as $r) {
            try {
                \Reseller\Activation\ActivationService::payerOf($a, $r);
            } catch (\Reseller\Domain\DomainError) {
                continue;
            }
            $out[] = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'fullname' => (string) $r['fullname'], 'status' => (string) $r['status'],
                'phonenumber' => (string) $r['phonenumber'], 'pppoe_ip' => (string) $r['pppoe_ip']];
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    /**
     * Display data of the actor's activation catalog (no price: the effective prices come only from the server quote).
     *
     * @param list<array{id:int,name_plan:string,type:string}> $catalog
     * @return list<array<string,mixed>>
     */
    public static function planCards(array $catalog): array
    {
        $ids = array_map(static fn(array $p): int => (int) $p['id'], $catalog);
        if ($ids === []) {
            return [];
        }
        $extra = [];
        foreach (\ORM::for_table('tbl_plans')->select_many('tbl_plans.id', 'tbl_plans.validity', 'tbl_plans.validity_unit')->select('tbl_bandwidth.name_bw', 'name_bw')
            ->left_outer_join('tbl_bandwidth', ['tbl_bandwidth.id', '=', 'tbl_plans.id_bw'])->where_in('tbl_plans.id', $ids)->find_array() as $r) {
            $extra[(int) $r['id']] = $r;
        }
        return array_map(static fn(array $p): array => $p + ['validity' => (string) ($extra[$p['id']]['validity'] ?? ''), 'validity_unit' => (string) ($extra[$p['id']]['validity_unit'] ?? ''),
            'speed' => (string) ($extra[$p['id']]['name_bw'] ?? '')], $catalog);
    }
}
