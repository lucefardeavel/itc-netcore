<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\TestDatabase;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * OD-1 audit content: every audited operation writes an append-only row with actor/target identity
 * snapshots, request id, channel and a UTC timestamp; no row ever contains a password, credential
 * hash, token, db_pass or api_secret; snapshots survive a later rename/delete of the identity.
 */
final class AuditTest extends Wp2HttpTestCase
{
    private const STAFF_PW = 'Audit-Staff-Secret-91x';
    private const STAFF_PW2 = 'Audit-Staff-Secret-92y';
    private const CUST_PW = 'Audit-Cust-Secret-93z';

    /** Runs a representative set of audited operations, then checks every row of the audit table. */
    public function testAuditRowsCarryContextAndNeverSecrets(): void
    {
        $startedUtc = time();
        $sa = $this->staff('sa');
        $a = $this->staff('A');
        // staff lifecycle (create with a password, password reset, disable/reactivate, delete)
        $sa->postWithToken('settings/users-post', ['username' => 'aud_report', 'fullname' => 'Audit Report', 'password' => self::STAFF_PW, 'user_type' => 'Report']);
        $rid = (int) static::$db->value("SELECT id FROM tbl_users WHERE username = 'aud_report'");
        $this->assertGreaterThan(0, $rid);
        $sa->postWithToken('settings/users-edit-post', ['id' => $rid, 'username' => 'aud_report', 'fullname' => 'Audit Report', 'password' => self::STAFF_PW2, 'cpassword' => self::STAFF_PW2]);
        $this->assertSame(sha1(self::STAFF_PW2), static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$rid]), 'password reset applied');
        $a->postWithToken('settings/users-post', ['username' => 'aud_sales', 'fullname' => 'Audit Sales', 'password' => self::STAFF_PW, 'user_type' => 'Sales']);
        $sid = (int) static::$db->value("SELECT id FROM tbl_users WHERE username = 'aud_sales'");
        $this->assertGreaterThan(0, $sid);
        $a->postWithToken('reseller/status-post', ['user_id' => $sid, 'status' => 'Inactive']);
        $a->postWithToken('reseller/status-post', ['user_id' => $sid, 'status' => 'Active']);
        // customer create with credentials, Sales assignment, transfer
        $a->postWithToken('customers/add-post', ['username' => 'aud_cust', 'fullname' => 'Audit Customer', 'password' => self::CUST_PW, 'pppoe_password' => self::CUST_PW,
            'service_type' => 'Hotspot', 'account_type' => 'Personal', 'email' => '', 'address' => '', 'phonenumber' => '', 'assigned_sales_id' => $sid]);
        $cid = (int) static::$db->value("SELECT id FROM tbl_customers WHERE username = 'aud_cust'");
        $this->assertGreaterThan(0, $cid);
        $a->postWithToken('reseller/customer-clear-sales', ['customer_id' => $cid]);
        $sa->postWithToken('reseller/customer-transfer', ['customer_id' => $cid, 'manager_id' => $this->sid('C')]);
        // an API-channel event: API login, then a refused service operation through the API
        $token = $this->apiToken('sa');
        $this->client()->api('POST', 'plan/recharge-post', ['id_customer' => $cid, 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'cash'], $token);
        // delete the Report account (snapshot must survive)
        $sa->postWithToken('settings/users-delete/' . $rid);
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_users WHERE id = ?', [$rid]), 'unreferenced Report deleted');

        foreach (['staff.create', 'staff.update', 'staff.disable', 'staff.reactivate', 'staff.delete', 'customer.create', 'customer.clear_sales', 'customer.transfer_owner', 'service.refused'] as $type) {
            $this->assertNotEmpty($this->auditRows($type), "{$type} audited");
        }

        $secrets = [
            self::STAFF_PW, self::STAFF_PW2, self::CUST_PW, sha1(self::STAFF_PW), sha1(self::STAFF_PW2),
            TestDatabase::rootPassword(), (string) static::$app->apiSecret(), $token, explode('.', $token)[3],
        ];
        foreach (static::$db->rows('SELECT password FROM tbl_users') as $u) {
            $secrets[] = (string) $u['password'];
        }
        foreach (static::$db->rows("SELECT login_token FROM tbl_users WHERE login_token IS NOT NULL AND login_token <> ''") as $u) {
            $secrets[] = (string) $u['login_token'];
        }
        $secrets = array_values(array_unique(array_filter($secrets, static fn($s) => strlen($s) >= 8)));

        $rows = static::$db->rows('SELECT * FROM tbl_reseller_audit_events ORDER BY id');
        $this->assertGreaterThanOrEqual(9, count($rows));
        $channels = [];
        foreach ($rows as $row) {
            $flat = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($secrets as $s) {
                $this->assertStringNotContainsString($s, $flat, "audit row {$row['id']} ({$row['event_type']}) contains a secret");
            }
            $this->assertDoesNotMatchRegularExpression('/"[^"]*(pass|secret|token|otp|signature|salt)[^"]*"\s*:/i', (string) $row['actor_snapshot'] . $row['target_snapshot'] . $row['before_json'] . $row['after_json'], "row {$row['id']} has a secret-like key");
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $row['request_id']);
            $this->assertContains($row['channel'], ['WEB', 'API', 'CLI']);
            $this->assertIsArray(json_decode((string) $row['actor_snapshot'], true), 'actor snapshot is JSON');
            $actor = json_decode((string) $row['actor_snapshot'], true);
            $this->assertArrayHasKey('username', $actor);
            if ($row['target_snapshot'] !== null) {
                $this->assertIsArray(json_decode((string) $row['target_snapshot'], true));
            }
            // UTC timestamp (DATETIME(6) written from gmdate): within the test's own window
            $ts = strtotime((string) $row['created_at'] . ' UTC');
            $this->assertGreaterThanOrEqual($startedUtc - 5, $ts, 'created_at is UTC');
            $this->assertLessThanOrEqual(time() + 5, $ts, 'created_at is UTC');
            $this->assertMatchesRegularExpression('/\.\d{6}$/', (string) $row['created_at'], 'microsecond precision');
            $channels[$row['channel']] = true;
        }
        $this->assertArrayHasKey('WEB', $channels);
        $this->assertArrayHasKey('API', $channels, 'API-channel events are recorded as API');

        // identity snapshots survive the delete of the referenced account
        $created = array_values(array_filter($this->auditRows('staff.create'), static fn($r) => (int) $r['target_user_id'] === $rid));
        $this->assertCount(1, $created);
        $snap = json_decode((string) ($created[0]['target_snapshot'] ?? $created[0]['after_json']), true);
        $this->assertSame('aud_report', $snap['username'] ?? null);
        $deleted = array_values(array_filter($this->auditRows('staff.delete'), static fn($r) => (int) $r['target_user_id'] === $rid));
        $this->assertCount(1, $deleted);
        $this->assertSame('aud_report', json_decode((string) $deleted[0]['target_snapshot'], true)['username'] ?? null);
        // and survive a rename of the actor
        static::$db->exec("UPDATE tbl_users SET username = 'A-renamed' WHERE id = " . $this->sid('A'));
        $byA = static::$db->rows('SELECT actor_snapshot FROM tbl_reseller_audit_events WHERE actor_user_id = ? ORDER BY id', [$this->sid('A')]);
        $this->assertNotEmpty($byA);
        $this->assertSame('A', json_decode((string) $byA[0]['actor_snapshot'], true)['username']);
        static::$db->exec("UPDATE tbl_users SET username = 'A' WHERE id = " . $this->sid('A'));
    }

    /** Append-only: no runtime code path updates, deletes or truncates the audit table. */
    public function testNoRuntimeCodeMutatesTheAuditTable(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['system', 'ui', 'install'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (preg_match('/\.(php|tpl)$/', $f->getFilename())) {
                    $files[] = $f->getPathname();
                }
            }
        }
        foreach (['index.php', 'init.php', 'radius.php', 'update.php'] as $f) {
            $files[] = $root . '/' . $f;
        }
        $this->assertGreaterThan(100, count($files));
        $hits = [];
        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            if (preg_match('/\b(UPDATE|DELETE\s+FROM|TRUNCATE(\s+TABLE)?|REPLACE\s+INTO|ALTER\s+TABLE|DROP\s+TABLE)\s+`?tbl_reseller_audit_events/i', $src)) {
                $hits[] = substr($f, strlen($root) + 1);
            }
            if (preg_match("/for_table\\(\\s*['\"]tbl_reseller_audit_events/", $src)) {
                $hits[] = substr($f, strlen($root) + 1) . ' (ORM access)';
            }
        }
        // the only allowed statement is the R0006 rollback (DROP while empty, strict runner, CLI only)
        $this->assertSame(['system/reseller/migrations/R0006_reseller_audit.php'], $hits);
        // the logs retention screen cannot reach the audit table
        $logs = (string) file_get_contents($root . '/system/controllers/logs.php');
        $this->assertStringNotContainsString('tbl_reseller_audit_events', $logs);
    }
}
