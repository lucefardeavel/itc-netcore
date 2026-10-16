<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP9-C final red team, XSS-1 (stored script injection across privilege levels; Smarty has no auto-escaping,
 * GAP_ANALYSIS §14, WP9 PLAN O8-C deferred): every free-text field that a LOWER-privilege principal can write is
 * filled with a marker payload, through the application's own routes:
 *  - an Agent creates a customer (full name, address, email, city, district) and a Sales sub-account (full name,
 *    phone, email, city, sub-district, ward) whose username itself carries the payload;
 *  - a customer edits its own profile (full name, address, email);
 * then SuperAdmin, Admin and the Agent open every page that lists or shows those records. The marker element must
 * never reach a page unescaped (it may appear escaped as text). Positive control: the stored values really contain
 * the payload, and the pages really show the escaped form.
 */
final class W9StoredXssCrawlTest extends Wp2HttpTestCase
{
    private const TAG = 'x-nuxr-xss';

    private static function payload(string $field): string
    {
        // short enough for the 45-character name columns
        return substr($field, 0, 6) . '"><' . self::TAG . '>';
    }

    /** @return list<string> routes whose HTML contains the raw marker element */
    private function raw(\Reseller\Tests\Support\HttpClient $c, array $routes): array
    {
        $hits = [];
        foreach ($routes as $r) {
            $res = $c->get($r);
            if (str_contains($res->body, '<' . self::TAG . '>')) {
                $hits[] = $r . ' (HTTP ' . $res->status . ')';
            }
        }
        return $hits;
    }

    public function testLowerPrivilegeTextNeverRendersAsMarkup(): void
    {
        $a = $this->staff('A');
        // Agent creates a customer and a Sales sub-account through the normal forms
        $cu = 'w9xss' . bin2hex(random_bytes(3));
        $r = $a->postWithToken('customers/add-post', ['username' => $cu, 'fullname' => self::payload('fullname'), 'password' => 'Pw-' . bin2hex(random_bytes(4)),
            'pppoe_password' => '', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'email' => 'x@example.invalid', 'address' => self::payload('address'),
            'phonenumber' => '0700123', 'city' => self::payload('city'), 'district' => self::payload('district'), 'coordinates' => '']);
        $this->assertContains($r->status, [200, 302], $r->excerpt());
        $cid = (int) static::$db->value('SELECT id FROM tbl_customers WHERE username = ?', [$cu]);
        $this->assertGreaterThan(0, $cid, 'the Agent created the customer');
        $this->assertStringContainsString('<' . self::TAG . '>', (string) static::$db->value('SELECT fullname FROM tbl_customers WHERE id = ?', [$cid]), 'positive control: stored raw');
        $su = 'w9' . bin2hex(random_bytes(2)) . '<' . self::TAG . '>';   // a staff username is free text (3-45 characters)
        $a->postWithToken('settings/users-post', ['username' => $su, 'fullname' => self::payload('staff'), 'password' => 'Pw-' . bin2hex(random_bytes(4)), 'user_type' => 'Sales',
            'phone' => '0700', 'email' => 'y@example.invalid', 'city' => self::payload('scity'), 'subdistrict' => self::payload('ssub'), 'ward' => self::payload('sward')]);
        $sid = (int) static::$db->value('SELECT id FROM tbl_users WHERE username = ?', [$su]);
        $this->assertGreaterThan(0, $sid, 'the Agent created the Sales account');
        // a customer edits its own profile
        $cust = $this->customer('cA1');
        $cust->postWithToken('accounts/edit-profile-post', ['fullname' => self::payload('selfname'), 'address' => self::payload('selfaddr'), 'email' => 'z@example.invalid',
            'phonenumber' => '0700'], [], 'accounts/profile');
        $self = $this->cid('cA1');

        $routes = ['dashboard', 'customers/list', 'customers/view/' . $cid, 'customers/view/' . $self, 'customers/edit/' . $cid, 'customers/list&search=' . rawurlencode($cu),
            'settings/users', 'settings/users-view/' . $sid, 'settings/users-edit/' . $sid, 'reseller/tree', 'reseller/detail/' . $sid, 'activation/list',
            'activation/create&customer=' . $cid, 'operations/overview', 'search_user&query=' . rawurlencode($cu), 'logs/list', 'plan/list', 'message/send',
            'customers/view/' . $cid . '/activation', 'finance/overview'];
        // plus every GET page of the route map without a required id (SuperAdmin sees all of them); actions whose name
        // suggests a side effect on GET are skipped
        foreach (\Reseller\Security\RouteMap::ROUTES as $controller => $actions) {
            foreach (array_keys($actions) as $action) {
                if ($action === '*' || preg_match('/post|delete|remove|logout|sync|deactivate|reset|kill|disconnect|enable|disable|activate|recharge|refill|send|export|csv|print|backup|restore|update|install|upload|clear|generate|test|download|pdf|cancel|reverse|rollback|reconcile|resume|confirm|approve|reject|buy|pay|order|callback|cron|ajax|select2|autoload/', (string) $action)
                    || in_array($controller, ['logout', 'autoload', 'callback', 'api', 'voucher', 'register', 'login', 'forgot'], true)) {
                    continue;
                }
                $routes[] = $action === '' ? $controller : $controller . '/' . $action;
            }
        }
        $routes = array_values(array_unique($routes));
        $this->assertGreaterThan(60, count($routes), 'the crawl covers the route map');
        $found = [];
        foreach (['sa', 'ad', 'A'] as $viewer) {
            foreach ($this->raw($this->staff($viewer), $routes) as $hit) {
                $found[] = "{$viewer}: {$hit}";
            }
        }
        // positive control: the escaped form is shown
        $this->assertStringContainsString('&lt;' . self::TAG . '&gt;', $this->staff('sa')->get('customers/view/' . $cid)->body, 'the payload is displayed as text');
        $this->assertSame([], $found, 'lower-privilege text rendered as markup');
    }
}
