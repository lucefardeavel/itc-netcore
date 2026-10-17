<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 *
 * This is Core, don't modification except you want to contribute
 * better create new plugin
 **/

use Reseller\Radius\AccountingService;
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
        // WP5B §4.3: device default deny for reseller-managed customers (only LegacyReprovisionService may call)
        \Reseller\Domain\LegacyReprovisionService::assertDeviceAllowed($customer);
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
        // WP5B §4.3: device default deny for reseller-managed customers (only LegacyReprovisionService may call)
        \Reseller\Domain\LegacyReprovisionService::assertDeviceAllowed($customer);
        $this->add_customer($customer, $plan);
    }

    function remove_customer($customer, $plan)
    {
        $pppoe = ($plan['type'] ?? '') == 'PPPOE';
        if (($expired = self::expiredPlan($customer, $plan)) !== null) {
            // the plan's "expired plan": the subscriber keeps a restricted profile without an end date (legacy)
            $this->provision($customer, $expired, null);
        } else {
            $this->customerDeactivate(RadiusPolicyBuilder::key(self::row($customer), $pppoe), true);
        }
    }

    /**
     * Renames the subscriber's RADIUS key (exact bytes: RadiusRepository::renameUser, WP8 A3 rename integrity).
     *
     * WP8 A3 (R-WP8-CARRY-02): the live sessions of the OLD key are then ended, targeted per captured accounting row:
     *  - before the rename: for an equivalent rename (case / accent only) a snapshot of the old key's open rows, because
     *    accounting rows of the old and the new name cannot be told apart by name;
     *  - after the rename: the targets (non-equivalent: every open row of the old name; equivalent: the snapshot rows
     *    still open). Another customer holding the old name means nothing is targeted;
     *  - inside LegacyReprovisionService::run() the capture is handed over and the service sends the Disconnects after
     *    the tree lock is released; on the legacy path they are sent here. A NAS problem never fails the rename.
     *
     * @return array<string,mixed>|null the capture (null when nothing was renamed)
     */
    public function change_username($plan, $from, $to)
    {
        // WP5B §4.3: device default deny for reseller-managed customers (only LegacyReprovisionService may call)
        \Reseller\Domain\LegacyReprovisionService::assertDeviceAllowed(null, $from, $to);
        $snapshot = self::oldIdentityBefore((string) $from, (string) $to);
        // owner D-3: an existing exact target is replaced only inside the customer's own rename scope
        $replace = \Reseller\Domain\LegacyReprovisionService::targetReplaceable((string) $to);
        self::check((new RadiusProvisioner(self::repository()))->rename((string) $from, (string) $to, $replace));
        \Reseller\Domain\LegacyReprovisionService::renameApplied((string) $to);
        if ((string) $from === (string) $to) {
            return null;
        }
        $capture = self::oldIdentityAfter($snapshot);
        if (!\Reseller\Domain\LegacyReprovisionService::deferOldSessions($capture)) {
            \Reseller\Domain\LegacyReprovisionService::disconnectCaptured($capture);
        }
        return $capture;
    }

    /**
     * WP8 A3, before the rename (never throws): whether the names are equivalent for radacct.username, the open-row
     * snapshot of an equivalent rename, and whether another customer holds the old name.
     *
     * @return array<string,mixed>
     */
    private static function oldIdentityBefore(string $from, string $to): array
    {
        $s = ['from' => $from, 'to' => $to, 'equivalent' => null, 'snapshot' => [], 'holder' => null];
        if ($from === $to) {
            return $s;
        }
        try {
            $pdo = ORM::get_db('radius');
            $collation = (string) $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'radacct' AND COLUMN_NAME = 'username'")->fetchColumn();
            if (!preg_match('/^[a-z0-9]+_[a-z0-9_]+$/', $collation)) {
                throw new RuntimeException('radacct.username collation unknown');
            }
            $st = $pdo->prepare("SELECT CONVERT(? USING utf8mb4) COLLATE {$collation} = CONVERT(? USING utf8mb4) COLLATE {$collation}");
            $st->execute([$from, $to]);
            $equivalent = (string) $st->fetchColumn() === '1';
            if ($equivalent) {
                $s['snapshot'] = array_map(static fn(array $r): int => (int) $r['radacctid'], (new AccountingService($pdo))->openSessions($from));
            }
            // a customer that holds the old name (username or PPPoE username) but not the new one is somebody else
            $holder = false;
            foreach (ORM::for_table('tbl_customers')->where_raw('(username = ? OR pppoe_username = ?)', [$from, $from])->find_array() as $c) {
                $holder = $holder || ((string) $c['username'] !== $to && (string) $c['pppoe_username'] !== $to);
            }
            $s['holder'] = $holder;
            $s['equivalent'] = $equivalent;
        } catch (Throwable $e) {
            ErrorReporter::report($e, 'rename-old-identity');
        }
        return $s;
    }

    /**
     * WP8 A3, after the rename (never throws): the captured old-identity accounting rows.
     *
     * @param array<string,mixed> $s
     * @return array{from:string,to:string,equivalent:?bool,state:string,targets:list<array<string,mixed>>}
     */
    private static function oldIdentityAfter(array $s): array
    {
        $capture = ['from' => $s['from'], 'to' => $s['to'], 'equivalent' => $s['equivalent'], 'state' => 'CAPTURE_FAILED', 'targets' => []];
        if ($s['equivalent'] === null) {
            return $capture;
        }
        if ($s['holder'] === true) {
            $capture['state'] = 'AMBIGUOUS';
            return $capture;
        }
        try {
            $rows = (new AccountingService(ORM::get_db('radius')))->openSessions($s['from']);
            if ($s['equivalent']) {
                $rows = array_values(array_filter($rows, static fn(array $r): bool => in_array((int) $r['radacctid'], $s['snapshot'], true)));
            }
            $capture['targets'] = $rows;
            $capture['state'] = 'TARGETED';
        } catch (Throwable $e) {
            ErrorReporter::report($e, 'rename-old-identity');
        }
        return $capture;
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
        self::singleOwner((string) $username);
        self::check((new RadiusProvisioner(self::repository()))->suspend((string) $username));
        return $radiusDisconnect ? $this->disconnectCustomer($username) : [];
    }

    /** Removes the subscriber's policy rows; accounting history is kept. */
    public function customerDelete($username)
    {
        self::singleOwner((string) $username);
        self::check((new RadiusProvisioner(self::repository()))->remove((string) $username));
    }

    /**
     * Legacy entry point: applies the plan with an explicit end time ('' = no end time).
     */
    public function customerAddPlan($customer, $plan, $expired = '')
    {
        // WP9-B / N-01 (OD-W9-9 B1'): this public grant method had no guard; it now applies the same device default
        // deny as add_customer (reseller-owned customers are served only through the activation path)
        \Reseller\Domain\LegacyReprovisionService::assertDeviceAllowed($customer);
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

    /**
     * WP9-B / N-01: the expired plan that remove_customer() applies. The caller can only NARROW the choice (an empty
     * plan_expired means "deactivate"); the plan id itself always comes from the stored plan, never from the
     * caller-supplied array. For a reseller-owned customer a resale-enabled (sellable) plan is never applied as the
     * free fallback profile: the customer is deactivated instead.
     *
     * @return array<string,mixed>|null the stored expired plan, or null to deactivate
     */
    private static function expiredPlan($customer, $plan): ?array
    {
        if (empty($plan['plan_expired']) || empty($plan['id'])) {
            return null;
        }
        $stored = ORM::for_table('tbl_plans')->find_one((int) $plan['id']);
        $expiredId = $stored ? (int) $stored['plan_expired'] : 0;
        if ($expiredId <= 0) {
            return null;
        }
        $p = ORM::for_table('tbl_plans')->find_one($expiredId);
        if (!$p) {
            return null;
        }
        $row = self::row($customer);
        // an unmigrated schema has no reseller-owned customer (same rule as the WP8 device guard)
        if (\Reseller\Domain\SchemaReadiness::isReady()
            && (\Reseller\Domain\ServiceGate::isBlockedCustomerId($row['id'] ?? null) || \Reseller\Domain\ServiceGate::isBlockedUsername((string) ($row['username'] ?? '')))) {
            try {
                // read-only (only PricingService writes pricing rows; no ORM object of the pricing table exists here)
                $st = ORM::get_db()->prepare('SELECT 1 FROM tbl_reseller_plan_base_costs WHERE plan_id = ? AND is_resale_enabled = 1 LIMIT 1');
                // a failed read counts as sellable (fail closed: no free fallback)
                $sellable = !$st->execute([$expiredId]) || $st->fetchColumn() !== false;
            } catch (\Throwable $e) {
                $sellable = true; // fail closed: unknown -> no free fallback
            }
            if ($sellable) {
                ErrorReporter::log('radius.expired_plan_refused', 'reason=resale_enabled_plan_for_reseller_owned_customer');
                return null;
            }
        }
        return $p->as_array();
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
        self::singleOwner($policy->username);
        // WP9 U0 final hardening: concurrent policy writes for different keys can deadlock (InnoDB 1213: the transaction
        // was rolled back, nothing applied). The legacy grant has no retry state machine (WP5A retries through
        // RETRY_PENDING), so the same policy write is run again here, bounded; any other failure is unchanged
        $provisioner = new RadiusProvisioner(self::repository());
        for ($attempt = 1; ; $attempt++) {
            $result = $provisioner->provision($policy);
            if ($attempt < 3 && $result->status === ProvisionResult::FAILED && (int) ($result->metadata['db_error'] ?? 0) === 1213) {
                ErrorReporter::log('radius.deadlock_retry', 'attempt=' . $attempt);
                usleep(random_int(5000, 50000) * $attempt);
                continue;
            }
            break;
        }
        self::check($result);
        // a new policy takes effect at the next session: end the current ones (one Disconnect each, never retried).
        // V1 / owner RENEWAL_OPTION_B_APPROVED (legacy recharge and voucher renewal included): the end is CONFIRMED
        // (Accounting-Stop after the ACK, or NAK 503); an unconfirmed end is logged and never undoes the provisioning
        $this->endSessions($policy->username);
    }

    /** Disconnect + confirmation of every open session of $key (SessionTermination; never throws). */
    private function endSessions(string $key): void
    {
        global $_app_stage;
        if ($_app_stage == 'demo' || $_app_stage == 'Demo') {
            return;
        }
        try {
            foreach (\Reseller\Radius\SessionTermination::end(\Reseller\Radius\AccountingService::connection()->openSessions($key)) as $e) {
                if (!$e['confirmed']) {
                    ErrorReporter::log('radius.disconnect', 'result=' . $e['result'] . ' state=' . $e['state'] . ' radacctid=' . $e['radacctid']);
                }
            }
        } catch (Throwable $e) {
            ErrorReporter::report($e, 'radius-disconnect');
        }
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

    /**
     * WP9 U0 final hardening (D-U0-R1): a subscriber key with more than one logical owner (grandfathered duplicate) is
     * never provisioned, suspended or removed - nothing written, no disconnect; a confirmed, non-retryable failure.
     */
    private static function singleOwner(string $key): void
    {
        try {
            \Reseller\Domain\CustomerIdentity::assertSingleOwner($key);
        } catch (\Reseller\Domain\DomainError $e) {
            throw new ProvisionFailure(ProvisionResult::failed($e->reason === 'IDENTITY_AMBIGUOUS' ? 'RADIUS_IDENTITY_AMBIGUOUS' : 'RADIUS_IDENTITY_CHECK_UNAVAILABLE', $e->getMessage(), false));
        }
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
