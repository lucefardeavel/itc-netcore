<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * Lab finding (ITC NetCore lab 2026-10-24, E2E defect 1): the customer edit page printed the customer password, the
 * PPPoE username / password / IP, state and zip without escaping. W9StoredXssCrawlTest seeds names, addresses and
 * cities only, so these fields were never covered:
 *  - a customer changes its own password in the portal (accounts/change-password-post);
 *  - an Agent creates a customer with PPPoE credentials, state and zip (customers/add-post);
 * then SuperAdmin, Admin and the Agent open the customer view and edit pages. The marker element must never reach a
 * page unescaped. Positive control: every field the application accepted really stores the payload, and the password
 * field (the reported path) is among them.
 */
final class W10CustomerCredentialFieldsXssTest extends Wp2HttpTestCase
{
    private const TAG = 'x-nuxr-xss';

    private static function payload(string $field): string
    {
        return substr($field, 0, 6) . '"><' . self::TAG . '>';
    }

    public function testCredentialAndAddressFieldsNeverRenderAsMarkup(): void
    {
        // an Agent creates a customer with PPPoE credentials, state and zip
        $a = $this->staff('A');
        $cu = 'w10xss' . bin2hex(random_bytes(3));
        $fields = ['pppoe_username' => self::payload('pppoeu'), 'pppoe_password' => self::payload('pppoep'),
            'pppoe_ip' => self::payload('pppoei'), 'state' => self::payload('state')];
        // zip is varchar(10): too short for the marker (an overlong zip is a separate validation finding, B-L06)
        // R-HF-CARRY-01 F-2: the static IP is validated on save (a non-IPv4 value refuses the whole add), so the IP payload
        // is stored the way only legacy data can hold it (direct write below); every other field goes through add-post
        $r = $a->postWithToken('customers/add-post', ['username' => $cu, 'fullname' => 'W10 xss', 'password' => 'Pw-' . bin2hex(random_bytes(4)),
            'service_type' => 'PPPoE', 'account_type' => 'Personal', 'email' => 'x@example.invalid', 'address' => 'a',
            'phonenumber' => '0700123', 'city' => 'c', 'district' => 'd', 'coordinates' => ''] + array_diff_key($fields, ['pppoe_ip' => 1]));
        $this->assertContains($r->status, [200, 302], $r->excerpt());
        $cid = (int) static::$db->value('SELECT id FROM tbl_customers WHERE username = ?', [$cu]);
        $this->assertGreaterThan(0, $cid, 'the Agent created the customer');
        static::$db->pdo->prepare('UPDATE tbl_customers SET pppoe_ip = ? WHERE id = ?')->execute([$fields['pppoe_ip'], $cid]);

        // the customer cA1 sets its own password to the payload in the portal
        $self = $this->cid('cA1');
        $np = self::payload('passwd');
        $this->customer('cA1')->postWithToken('accounts/change-password-post',
            ['password' => Wp2Seed::customerPassword('cA1'), 'npass' => $np, 'cnpass' => $np], [], 'accounts/change-password');

        // positive control: which payloads the application stored (validation may refuse some fields, e.g. the IP)
        $row = static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$cid])[0];
        $stored = array_keys(array_filter($fields, fn($v, $k) => (string) ($row[$k] ?? '') === $v, ARRAY_FILTER_USE_BOTH));
        $this->assertSame($np, (string) static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$self]),
            'positive control: the portal stored the password payload (the reported path)');
        $this->assertContains('pppoe_password', $stored, 'positive control: the Agent stored a PPPoE password payload');

        $routes = ['customers/view/' . $cid, 'customers/edit/' . $cid, 'customers/view/' . $self, 'customers/edit/' . $self];
        $found = [];
        foreach (['sa', 'ad', 'A'] as $viewer) {
            $c = $this->staff($viewer);
            foreach ($routes as $route) {
                $res = $c->get($route);
                if (str_contains($res->body, '<' . self::TAG . '>')) {
                    $found[] = "{$viewer}: {$route} (HTTP {$res->status})";
                }
            }
        }
        $this->assertSame([], $found, 'customer credential / address text rendered as markup (stored fields: ' . implode(', ', $stored) . ', password)');
        // the escaped form is what the edit page shows (the value round-trips unchanged through the form)
        $this->assertTrue(str_contains($this->staff('sa')->get('customers/edit/' . $self)->body, '&lt;' . self::TAG . '&gt;'),
            'the password payload is present in escaped form');
    }
}
