<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 *
 * This is Core, don't modification except you want to contribute
 * better create new plugin
 **/

use Reseller\Radius\CoAClient;
use Reseller\Radius\ProvisionFailure;
use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;
use Reseller\Security\ErrorReporter;

/**
 * FreeRADIUS SQL device (WP4). The legacy public methods are kept for every existing caller; the work is
 * done by Reseller\Radius (policy builder, repository, provisioner, CoA client):
 *  - the RADIUS Expiration is the SAVED billing expiry of the subscriber's active recharge (owner decision Q5),
 *    never "now + validity";
 *  - accounting history (radacct) is never deleted (C17, D018): the pre-WP4 code purged it on every
 *    activation, renewal, sync, password / username change and expiry;
 *  - sessions are ended through the native CoA client (no shell, SEC-11);
 *  - a provisioning operation that is not SUCCESS raises ProvisionFailure, so callers keep their existing
 *    failure path (Package::rechargeUser reports and notifies "sync manually").
 */
class Radius
{

    // show Description
    function description()
    {
        return [
            'title' => 'Radius',
            'description' => 'Radius system with Mysql/Mariadb as database',
            'author' => 'ibnux',
            'url' => [
                'Github' => 'https://github.com/hotspotbilling/phpnuxbill/',
                'Telegram' => 'https://t.me/phpnuxbill',
                'Donate' => 'https://paypal.me/ibnux'
            ]
        ];
    }

    /** Activation / renewal / re-provisioning: the policy of the active recharge of this plan. */
    function add_customer($customer, $plan)
    {
        $p = ORM::for_table('tbl_plans')->where('id', $plan['id'])->findOne();
        if (!$p) {
            throw new ProvisionFailure(ProvisionResult::failed('PLAN_NOT_FOUND', 'The plan does not exist.', false));
        }
        $period = self::billingPeriod($customer, (int) $p['id']);
        if ($period === null) {
            throw new ProvisionFailure(ProvisionResult::failed('NO_ACTIVE_RECHARGE', 'The customer has no active recharge for this plan.', false));
        }
        $this->provision($customer, $p->as_array(), $period[1], $period[0]);
    }

    function sync_customer($customer, $plan)
    {
        $this->add_customer($customer, $plan);
    }

    function remove_customer($customer, $plan)
    {
        $pppoe = ($plan['type'] ?? '') == 'PPPOE';
        if (!empty($plan['plan_expired'])) {
            // the plan's "expired plan": the subscriber keeps a restricted profile without an end date (legacy)
            $p = ORM::for_table("tbl_plans")->find_one($plan['plan_expired']);
            if ($p) {
                $this->provision($customer, $p->as_array(), null);
            }
        } else {
            $this->customerDeactivate(RadiusPolicyBuilder::key(self::row($customer), $pppoe), true);
        }
    }

    public function change_username($plan, $from, $to)
    {
        self::check((new RadiusProvisioner(self::repository()))->rename((string) $from, (string) $to));
    }

    function add_plan($plan)
    {
        $bw = ORM::for_table("tbl_bandwidth")->find_one($plan['id_bw']);
        self::check((new RadiusProvisioner(self::repository()))->applyPlan((int) $plan['id'], $bw ? $bw->as_array() : null));
    }

    function update_plan($old_name, $plan)
    {
        $this->add_plan($plan);
    }

    function remove_plan($plan)
    {
        self::check((new RadiusProvisioner(self::repository()))->removePlan((int) $plan['id']));
    }

    function online_customer($customer, $router_name)
    {
    }

    function connect_customer($customer, $ip, $mac_address, $router_name)
    {
    }

    function disconnect_customer($customer, $router_name)
    {
        $results = $this->disconnectCustomer($customer['username']);
        if (!empty($customer['pppoe_username']) && $customer['pppoe_username'] != $customer['username']) {
            $results = array_merge((array) $results, (array) $this->disconnectCustomer($customer['pppoe_username']));
        }
        return $results;
    }

    public function getTableNas()
    {
        return ORM::for_table('nas', 'radius');
    }

    /** Read access to the FreeRADIUS accounting history; never use it to delete or rewrite rows. */
    public function getTableAcct()
    {
        return ORM::for_table('radacct', 'radius');
    }

    public function getTableCustomer()
    {
        return ORM::for_table('radcheck', 'radius');
    }

    public function getTableCustomerAttr()
    {
        return ORM::for_table('radreply', 'radius');
    }

    public function getTablePackage()
    {
        return ORM::for_table('radgroupreply', 'radius');
    }

    public function getTableUserPackage()
    {
        return ORM::for_table('radusergroup', 'radius');
    }

    public function nasAdd($name, $ip, $ports, $secret, $routers = "", $description = "", $type = 'other', $server = null, $community = null)
    {
        return self::repository()->nasAdd(['nasname' => $ip, 'shortname' => $name, 'type' => $type, 'ports' => $ports, 'secret' => $secret,
            'description' => $description, 'server' => $server, 'community' => $community, 'routers' => $routers]);
    }

    /** A null or empty secret keeps the stored secret (the edit form never shows it). */
    public function nasUpdate($id, $name, $ip, $ports, $secret, $routers = "", $description = "", $type = 'other', $server = null, $community = null)
    {
        return self::repository()->nasUpdate((int) $id, ['nasname' => $ip, 'shortname' => $name, 'type' => $type, 'ports' => $ports,
            'secret' => ($secret === null || $secret === '') ? null : $secret, 'description' => $description, 'server' => $server,
            'community' => $community, 'routers' => $routers]);
    }

    /** Suspends a subscriber (random password, nothing deleted) and ends its sessions. */
    public function customerDeactivate($username, $radiusDisconnect = true)
    {
        self::check((new RadiusProvisioner(self::repository()))->suspend((string) $username));
        return $radiusDisconnect ? $this->disconnectCustomer($username) : [];
    }

    /** Removes the subscriber's policy rows; accounting history is kept. */
    public function customerDelete($username)
    {
        self::check((new RadiusProvisioner(self::repository()))->remove((string) $username));
    }

    /**
     * Legacy entry point: applies the plan with an explicit end time ('' = no end time).
     */
    public function customerAddPlan($customer, $plan, $expired = '')
    {
        $expiry = $expired !== '' ? new DateTimeImmutable((string) $expired) : null;
        $this->provision($customer, self::row($plan), $expiry);
        return true;
    }

    /**
     * Ends every open session of the subscriber through the native CoA client.
     *
     * @return list<string> "<nas>: <status> <code>" per open session
     */
    public function disconnectCustomer($username)
    {
        global $_app_stage;
        if ($_app_stage == 'demo' || $_app_stage == 'Demo') {
            return [];
        }
        $out = [];
        try {
            foreach (CoAClient::connection()->disconnect((string) $username) as $r) {
                if (!$r->isSuccess()) {
                    ErrorReporter::log('radius.disconnect', 'status=' . $r->status . ' code=' . $r->code);
                }
                $out[] = ($r->nasId ?? '-') . ': ' . $r->status . ' ' . $r->code;
            }
        } catch (Throwable $e) {
            ErrorReporter::report($e, 'radius-disconnect');
        }
        return $out;
    }

    /** @param array<string,mixed> $plan */
    private function provision($customer, array $plan, ?DateTimeImmutable $expiry, ?DateTimeImmutable $periodStart = null): void
    {
        $bw = ORM::for_table('tbl_bandwidth')->find_one($plan['id_bw']);
        try {
            $policy = RadiusPolicyBuilder::build(self::row($customer), $plan, $bw ? $bw->as_array() : null, $expiry, $periodStart);
        } catch (InvalidArgumentException $e) {
            throw new ProvisionFailure(ProvisionResult::failed($e->getMessage(), 'The customer data cannot be provisioned.', false));
        }
        self::check((new RadiusProvisioner(self::repository()))->provision($policy));
        // a new policy takes effect at the next session: end the current one (best effort, never retried)
        $this->disconnectCustomer($policy->username);
    }

    /**
     * [start, expiry] of the active recharge of this plan (customer id, or the username for a voucher-only
     * customer), in the application timezone: the saved billing period.
     *
     * @return array{0:?DateTimeImmutable,1:DateTimeImmutable}|null
     */
    private static function billingPeriod($customer, int $planId): ?array
    {
        $q = ORM::for_table('tbl_user_recharges')->where('plan_id', $planId)->where('status', 'on');
        if (!empty($customer['id'])) {
            $q->where('customer_id', $customer['id']);
        } else {
            $q->where('username', $customer['username']);
        }
        $t = $q->order_by_desc('id')->find_one();
        if (!$t || empty($t['expiration'])) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $t['expiration'] . ' ' . (empty($t['time']) ? '23:59:59' : $t['time']));
        if ($at === false) {
            return null;
        }
        $from = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $t['recharged_on'] . ' ' . (empty($t['recharged_time']) ? '00:00:00' : $t['recharged_time']));
        return [$from === false ? null : $from, $at];
    }

    /** A row as an array (callers pass ORM objects or arrays). */
    private static function row($row): array
    {
        return is_object($row) && method_exists($row, 'as_array') ? $row->as_array() : (array) $row;
    }

    private static function repository(): RadiusRepository
    {
        return RadiusRepository::connection();
    }

    private static function check(ProvisionResult $result): void
    {
        if (!$result->isSuccess()) {
            throw new ProvisionFailure($result);
        }
    }
}
