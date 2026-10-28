<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * Lab finding D-9 (ITC NetCore lab 2026-10-24; owner direction D-17): with the default multi-session setting a staff
 * remember cookie (`aid`) stayed valid for its whole 7-day lifetime after logout, because the only server-side token
 * (tbl_users.login_token, one slot) is ignored when single_session is not 'yes'. Logout must revoke THIS device only:
 * a captured cookie of the logged-out device is refused (alone or with its old session id), every other device keeps
 * working and can still be restored from its own cookie, and a new login works.
 */
final class W10StaffDeviceRevocationTest extends Wp2HttpTestCase
{
    private function replay(array $cookies): int
    {
        $c = $this->client();
        $c->cookies = $cookies;
        return $c->get('dashboard')->status;
    }

    public function testLogoutRevokesThisDeviceOnlyAndACapturedCookieCannotBeReplayed(): void
    {
        $d1 = $this->staff('C');
        $d2 = $this->staff('C');
        $c1 = $d1->cookies;
        $c2 = $d2->cookies;
        $this->assertArrayHasKey('aid', $c1, 'remember cookie issued at login (device 1)');
        $this->assertArrayHasKey('aid', $c2, 'remember cookie issued at login (device 2)');
        $this->assertNotSame($c1['aid'], $c2['aid'], 'one cookie per device');
        $this->assertSame(200, $this->replay(['aid' => $c1['aid']]), 'positive control: a live device cookie restores a session');

        $d1->get('logout');
        $this->assertSame(302, $d1->get('dashboard')->status, 'device 1 is logged out');
        $this->assertSame(302, $this->replay(['aid' => $c1['aid']]), 'captured cookie of the logged-out device is refused');
        $this->assertSame(302, $this->replay($c1), 'captured cookie + old session id of the logged-out device is refused');

        $this->assertSame(200, $d2->get('dashboard')->status, 'device 2 session stays active');
        $this->assertSame(200, $this->replay(['aid' => $c2['aid']]), 'device 2 can still be restored from its own cookie');

        $d2->get('logout');
        $this->assertSame(302, $this->replay(['aid' => $c2['aid']]), 'device 2 cookie refused after its own logout');

        $d3 = $this->client();
        $d3->loginStaff('C', Wp2Seed::password('C'));
        $this->assertSame(200, $d3->get('dashboard')->status, 'a new login works');
        $this->assertSame(200, $this->replay(['aid' => $d3->cookies['aid']]), 'and its cookie restores');
    }

    public function testARevokedDeviceCannotBeRestoredEvenWithAValidMac(): void
    {
        // the cookie MAC (user, time, password) stays valid after logout: only the server-side device record decides
        $d = $this->staff('A2');
        $cookie = $d->cookies['aid'];
        $d->get('logout');
        foreach ([1, 2, 3] as $attempt) {
            $this->assertSame(302, $this->replay(['aid' => $cookie]), "replay {$attempt} refused");
        }
    }
}
