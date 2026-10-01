<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * WP2 fixture set (disposable databases only):
 *
 *   staff   sa (SuperAdmin), ad (Admin), rp (Report)
 *           A (Agent, top), B (Agent, child of A), C (Agent, top, unrelated tree)
 *           A1, A2 (Sales under A), C1 (Sales under C)
 *           D (Agent, top, Inactive), F (Agent, child of D → frozen)
 *           E (Agent, legacy, NOT enrolled, root=0), E1 (Sales, legacy, root=E, NOT enrolled)
 *   customers cA1 (A/A1), cA2 (A/A2), cA0 (A, no Sales), cB (B), cC1 (C/C1), cF (F), cNULL, cNULL2 (legacy)
 *   service  Probe-device plan, active recharges, transactions (incl. user_id 0), vouchers by A, B, C, ad
 *
 * Every staff password is "pw-<username>"; every customer password is "cpw-<username>".
 */
final class Wp2Seed
{
    /** @var array<string,int> */
    public array $staff = [];
    /** @var array<string,int> */
    public array $customers = [];
    public int $planId = 0;
    public int $routerId = 0;
    /** @var array<string,int> */
    public array $recharges = [];
    /** @var array<string,int> */
    public array $transactions = [];
    /** @var array<string,int> voucher code => id */
    public array $vouchers = [];

    public const STAFF = [
        // username => [role, parent username|null, status, enrolled]
        'sa' => ['SuperAdmin', null, 'Active', false],
        'ad' => ['Admin', null, 'Active', false],
        'rp' => ['Report', null, 'Active', false],
        'A' => ['Agent', null, 'Active', true],
        'B' => ['Agent', 'A', 'Active', true],
        'C' => ['Agent', null, 'Active', true],
        'A1' => ['Sales', 'A', 'Active', true],
        'A2' => ['Sales', 'A', 'Active', true],
        'C1' => ['Sales', 'C', 'Active', true],
        'D' => ['Agent', null, 'Inactive', true],
        'F' => ['Agent', 'D', 'Active', true],
        'E' => ['Agent', null, 'Active', false],
        'E1' => ['Sales', 'E', 'Active', false],
    ];

    public const CUSTOMERS = [
        // username => [manager, sales]
        'cA1' => ['A', 'A1'], 'cA2' => ['A', 'A2'], 'cA0' => ['A', null], 'cB' => ['B', null],
        'cC1' => ['C', 'C1'], 'cF' => ['F', null], 'cNULL' => [null, null], 'cNULL2' => [null, null],
    ];

    public static function password(string $username): string
    {
        return 'pw-' . $username;
    }

    public static function customerPassword(string $username): string
    {
        return 'cpw-' . $username;
    }

    public static function seed(TestDatabase $db): self
    {
        $s = new self();
        $pdo = $db->pdo;
        $now = gmdate('Y-m-d H:i:s');
        // the installer-seeded default credential is replaced (a dedicated test covers it)
        $pdo->exec("UPDATE tbl_users SET password = '" . sha1('wp2-default-admin-changed') . "' WHERE username = 'admin'");
        foreach (self::STAFF as $u => [$role, $parent, $status, $enrolled]) {
            $s->staff[$u] = $db->insertRow('tbl_users', [
                'username' => $u, 'fullname' => 'Staff ' . $u, 'password' => sha1(self::password($u)),
                'user_type' => $role, 'status' => $status, 'root' => $parent !== null ? $s->staff[$parent] : 0,
                'creationdate' => $now, 'last_login' => $now,
            ]);
        }
        $tree = $pdo->prepare('INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))');
        foreach (self::STAFF as $u => [$role, $parent, $status, $enrolled]) {
            if (!$enrolled) {
                continue;
            }
            $id = $s->staff[$u];
            $tree->execute([$id, $id, 0]);
            if ($parent !== null) {
                foreach ($db->rows('SELECT ancestor_id, depth FROM tbl_manager_tree WHERE descendant_id = ?', [$s->staff[$parent]]) as $a) {
                    $tree->execute([(int) $a['ancestor_id'], $id, (int) $a['depth'] + 1]);
                }
            }
        }
        foreach (self::CUSTOMERS as $u => [$mgr, $sales]) {
            $s->customers[$u] = $db->insertRow('tbl_customers', [
                'username' => $u, 'password' => self::customerPassword($u), 'fullname' => 'Customer ' . $u,
                'address' => '', 'phonenumber' => '0700' . strlen($u), 'email' => strtolower($u) . '@example.invalid',
                'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active',
                'balance' => 5000, 'created_by' => $s->staff['ad'], 'auto_renewal' => 1,
                'manager_id' => $mgr !== null ? $s->staff[$mgr] : null,
                'assigned_sales_id' => $sales !== null ? $s->staff[$sales] : null,
            ]);
        }
        $s->routerId = $db->insertRow('tbl_routers', ['name' => 'wp2_router', 'ip_address' => '127.0.0.1:9', 'username' => 'u', 'password' => 'p', 'description' => '', 'enabled' => 1]);
        $bw = $db->insertRow('tbl_bandwidth', ['name_bw' => 'wp2_bw', 'rate_down' => 10, 'rate_down_unit' => 'Mbps', 'rate_up' => 5, 'rate_up_unit' => 'Mbps']);
        $s->planId = $db->insertRow('tbl_plans', [
            'name_plan' => 'wp2_plan', 'id_bw' => $bw, 'price' => '1000', 'type' => 'Hotspot', 'typebp' => 'Unlimited',
            'limit_type' => 'Time_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB',
            'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1, 'routers' => 'wp2_router', 'is_radius' => 0,
            'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Probe',
        ]);
        $exp = gmdate('Y-m-d', time() + 86400 * 10);
        foreach (['cA1', 'cB', 'cC1', 'cNULL'] as $u) {
            $s->recharges[$u] = $db->insertRow('tbl_user_recharges', [
                'customer_id' => $s->customers[$u], 'username' => $u, 'plan_id' => $s->planId, 'namebp' => 'wp2_plan',
                'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => $exp, 'time' => '23:59:59',
                'status' => 'on', 'method' => 'Admin - seed', 'routers' => 'wp2_router', 'type' => 'Hotspot', 'admin_id' => $s->staff['ad'],
            ]);
            $s->transactions[$u] = $db->insertRow('tbl_transactions', [
                'invoice' => 'INV-WP2' . $s->customers[$u], 'username' => $u, 'user_id' => $s->customers[$u], 'plan_name' => 'wp2_plan',
                'price' => '1000', 'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => $exp,
                'time' => '23:59:59', 'method' => 'Admin - seed', 'routers' => 'wp2_router', 'type' => 'Hotspot', 'admin_id' => $s->staff['ad'],
            ]);
        }
        $s->transactions['voucher0'] = $db->insertRow('tbl_transactions', [
            'invoice' => 'INV-WP2V0', 'username' => 'VOUCHERONLY', 'user_id' => 0, 'plan_name' => 'wp2_plan', 'price' => '1000',
            'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => $exp, 'time' => '23:59:59',
            'method' => 'Voucher - X', 'routers' => 'wp2_router', 'type' => 'Hotspot', 'admin_id' => 0,
        ]);
        foreach (['A', 'B', 'C', 'ad', 'A1'] as $gen) {
            $code = 'WP2V' . strtoupper($gen) . bin2hex(random_bytes(3));
            $s->vouchers[$gen] = $db->insertRow('tbl_voucher', [
                'type' => 'Hotspot', 'routers' => 'wp2_router', 'id_plan' => $s->planId, 'code' => $code,
                'user' => '0', 'status' => '0', 'generated_by' => $s->staff[$gen],
            ]);
        }
        return $s;
    }

    public function voucherCode(string $generator): string
    {
        return (string) $this->db()->value('SELECT code FROM tbl_voucher WHERE id = ?', [$this->vouchers[$generator]]);
    }

    private ?TestDatabase $dbRef = null;

    public function attach(TestDatabase $db): self
    {
        $this->dbRef = $db;
        return $this;
    }

    private function db(): TestDatabase
    {
        return $this->dbRef ?? throw new \LogicException('seed not attached');
    }
}
