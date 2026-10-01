<?php
namespace Reseller\Tests\Unit\Wp2;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Audit\AuditLog;
use Reseller\Security\ActorContext;
use Reseller\Security\ApiGuard;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;
use Reseller\Security\ErrorReporter;
use Reseller\Security\PathGuard;

/** DataScope SQL shape, PathGuard (NEW-1 / NEW-4), API sanitizer, audit redactor, error sanitizer. */
final class GuardsTest extends TestCase
{
    private static function staff(string $role, array $over = []): ActorContext
    {
        return ActorContext::make($over + ['type' => ActorContext::STAFF, 'channel' => 'WEB', 'userId' => 42, 'role' => $role, 'status' => 'Active',
            'enrolled' => true, 'managerId' => $role === 'Sales' ? 7 : 42, 'schemaReady' => true]);
    }

    public function testCustomerPredicatesAreParenthesizedAndBound(): void
    {
        $this->assertNull(DataScope::customerPredicate(self::staff('SuperAdmin'), C::CUSTOMER_READ));
        $this->assertNull(DataScope::customerPredicate(self::staff('Admin'), C::CUSTOMER_READ));
        [$sql, $p] = DataScope::customerPredicate(self::staff('Agent'), C::CUSTOMER_READ);
        $this->assertSame('(tbl_customers.manager_id IN (SELECT descendant_id FROM tbl_manager_tree WHERE ancestor_id = ?))', $sql);
        $this->assertSame([42], $p);
        [$sql, $p] = DataScope::customerPredicate(self::staff('Sales'), C::CUSTOMER_READ);
        $this->assertSame('(tbl_customers.assigned_sales_id = ? AND tbl_customers.manager_id = ?)', $sql, 'C21: assigned AND owned by the Sales manager');
        $this->assertSame([42, 7], $p);
        foreach (['Report', 'Agent'] as $role) {
            $a = $role === 'Agent' ? self::staff('Agent', ['enrolled' => false]) : self::staff('Report');
            $this->assertSame(['(1 = 0)', []], DataScope::customerPredicate($a, C::CUSTOMER_READ));
        }
        [$sql] = DataScope::byCustomerColumnPredicate(self::staff('Agent'), C::INVOICE_READ, 'tbl_transactions.user_id');
        $this->assertStringStartsWith('(tbl_transactions.user_id IN (SELECT sc.id FROM tbl_customers sc WHERE (sc.manager_id IN', $sql);
        $this->assertStringNotContainsString('username', $sql, 'derived objects are scoped by id, never by username');
        [$sql, $p] = DataScope::voucherPredicate(self::staff('Sales'), C::VOUCHER_READ);
        $this->assertSame(['(tbl_voucher.generated_by = ?)', [42]], [$sql, $p]);
        [$sql] = DataScope::staffPredicate(self::staff('Agent'), C::STAFF_DISABLE);
        $this->assertStringContainsString('depth > 0', $sql, 'D scope = strict descendants');
        [$sql] = DataScope::staffPredicate(self::staff('Agent'), C::STAFF_CREATE);
        $this->assertStringContainsString('depth = 1', $sql);
        $this->expectException(\InvalidArgumentException::class);
        DataScope::customerPredicate(self::staff('Agent'), C::CUSTOMER_READ, 'x; DROP TABLE y');
    }

    public function testIdValidation(): void
    {
        foreach ([1, '1', '9007199254'] as $ok) {
            $this->assertTrue(DataScope::validId($ok));
        }
        foreach ([0, -1, '0', '01', '1 OR 1=1', '1e3', '', null, [], '1.5'] as $bad) {
            $this->assertFalse(DataScope::validId($bad), var_export($bad, true));
        }
    }

    public function testPathGuardRejectsTraversal(): void
    {
        $root = sys_get_temp_dir() . '/wp2_pg_' . bin2hex(random_bytes(3));
        mkdir($root . '/system/lan', 0777, true);
        mkdir($root . '/pages/vouchers', 0777, true);
        file_put_contents($root . '/system/lan/english.json', '{}');
        file_put_contents($root . '/system/lan/country.json', '{}');
        file_put_contents($root . '/pages/Voucher.html', '');
        file_put_contents($root . '/pages/Plugin_Page.html', '');
        file_put_contents($root . '/secret.json', '{}');
        try {
            $this->assertSame('english', PathGuard::language('english', $root));
            foreach (['../../secret', '../secret', 'english/../english', 'country', 'English', "english\0", 'x', 'spanish', '/etc/passwd', 'english.json', ['english'], null] as $bad) {
                $this->assertNull(PathGuard::language($bad, $root), 'language ' . json_encode($bad));
            }
            $pages = $root . '/pages';
            $this->assertSame('Voucher', PathGuard::pageName('Voucher', $pages));
            $this->assertSame('Announcement', PathGuard::pageName('Announcement', $pages), 'allowlisted even before the file exists');
            $this->assertSame('Plugin_Page', PathGuard::pageName('Plugin_Page', $pages), 'existing page file');
            $this->assertNull(PathGuard::pageName('Plugin_Page', $pages, false));
            foreach (['../secret', '..%2fsecret', 'Voucher.html', 'a/b', 'a\\b', '/etc/passwd', "Voucher\0", str_repeat('A', 65), 'Unknown', '', '.htaccess'] as $bad) {
                $this->assertNull(PathGuard::pageName($bad, $pages), 'page ' . json_encode($bad));
            }
            $this->assertSame('Logo Header', PathGuard::voucherTemplateName('Logo Header'));
            foreach (['../x', 'a/b', 'a\\b', 'x.php', "x\0", str_repeat('b', 65), '', '..'] as $bad) {
                $this->assertNull(PathGuard::voucherTemplateName($bad), 'template ' . json_encode($bad));
            }
            $this->assertTrue(PathGuard::inside($pages . '/vouchers/new.html', $pages . '/vouchers'));
            $this->assertFalse(PathGuard::inside($pages . '/vouchers/../../secret.json', $pages . '/vouchers'));
            $this->assertFalse(PathGuard::inside($pages . "/x\0.html", $pages));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testApiSanitizerRemovesCredentialsAndConfig(): void
    {
        $in = [
            'd' => [['id' => 1, 'username' => 'u', 'password' => 'p', 'pppoe_password' => 'x', 'login_token' => 'lt', 'balance' => 5]],
            '_c' => ['db' => 'x'], '_admin' => ['id' => 1, 'username' => 'a', 'password' => 'h', 'login_token' => 't', 'user_type' => 'Admin'],
            'csrf_token' => 'c', 'api_key' => 'k', 'token' => 'a2.1.2.abc', 'nested' => ['secret' => 's', 'ok' => 'v'],
        ];
        $out = ApiGuard::sanitize($in);
        $this->assertSame([['id' => 1, 'username' => 'u', 'balance' => 5]], $out['d']);
        $this->assertArrayNotHasKey('_c', $out);
        $this->assertSame(['id' => 1, 'username' => 'a', 'user_type' => 'Admin'], $out['_admin']);
        $this->assertArrayNotHasKey('csrf_token', $out);
        $this->assertArrayNotHasKey('api_key', $out);
        $this->assertSame('a2.1.2.abc', $out['token'], 'the issued API token itself is the login response');
        $this->assertSame(['ok' => 'v'], $out['nested']);
    }

    public function testAuditRedactorAndErrorSanitizer(): void
    {
        $GLOBALS['db_pass'] = 'Sup3rSecretDbPass';
        $GLOBALS['api_secret'] = str_repeat('k', 40);
        $r = AuditLog::redact(['username' => 'x', 'password' => 'p', 'new_password_hash' => 'h', 'api_token' => 't', 'otp' => '1',
            'note' => 'leaked Sup3rSecretDbPass here', 'deep' => ['secret_key' => 'z', 'keep' => 1]]);
        $this->assertSame(['username' => 'x', 'note' => 'leaked [redacted] here', 'deep' => ['keep' => 1]], $r);
        $s = ErrorReporter::sanitize("SQLSTATE[28000] Access denied password=Sup3rSecretDbPass token: abc " . str_repeat('k', 40));
        $this->assertStringNotContainsString('Sup3rSecretDbPass', $s);
        $this->assertStringNotContainsString(str_repeat('k', 40), $s);
        $this->assertStringNotContainsString('abc', $s);
        $this->assertStringContainsString('An internal error occurred', ErrorReporter::publicMessage('ref1'));
        unset($GLOBALS['db_pass'], $GLOBALS['api_secret']);
    }
}
