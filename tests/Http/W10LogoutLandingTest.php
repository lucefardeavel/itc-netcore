<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpResponse;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab UX finding UX-7 (ITC NetCore lab 2026-10-25): logout always continued to the CUSTOMER login, also for staff.
 * The target is decided before the session is cleared: staff -> the staff login (admin), customer -> the customer
 * login. The D-9 per-device revocation is unchanged (W10StaffDeviceRevocationTest).
 */
final class W10LogoutLandingTest extends Wp2HttpTestCase
{
    /** The route the logout redirect continues to (UX 2026-11-09: a 302 with a notice, no countdown page). */
    private static function landing(HttpResponse $r): string
    {
        $url = (string) $r->location();
        if ($r->status !== 302 || $url === '') {
            return 'no redirect: ' . $r->excerpt();
        }
        if (preg_match('#[?&]_route=([^&]*)#', $url, $q)) {
            return urldecode($q[1]);
        }
        return trim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    public function testStaffLogoutLandsOnTheStaffLogin(): void
    {
        foreach (['sa', 'A1', 'rp'] as $who) {
            $c = $this->staff($who);
            $r = $c->get('logout');
            $this->assertSame(302, $r->status, "{$who}: " . $r->excerpt());
            $this->assertSame('admin', self::landing($r), "{$who}: staff logout continues to the staff login");
            $this->assertSame(302, $c->get('dashboard')->status, "{$who}: logged out");
        }
    }

    public function testCustomerLogoutLandsOnTheCustomerLogin(): void
    {
        $c = $this->customer('cNULL');
        $this->assertSame(200, $c->get('home')->status, 'positive control: the customer is logged in');
        $r = $c->get('logout');
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame('login', self::landing($r), 'customer logout continues to the customer login');
        $this->assertNotSame(200, $c->get('home')->status, 'logged out');
    }
}
