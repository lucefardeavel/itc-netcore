<?php
namespace Reseller\Tests\Unit\Ux;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\RouteMap;
use Reseller\Ui\HotspotLogin;

/**
 * V1.0.1 hotspot sign-in: the router's login page sends the device to the card page, which signs it in on that router.
 * The router address is only ever a private / CGNAT address or a listed host, the CHAP answer is the router's own
 * md5(chap-id . password . chap-challenge), and a held account is used once.
 */
final class HotspotLoginTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/HotspotLogin.php';
    }

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testOnlyPrivateAddressesOrListedHostsAreRouterAddresses(): void
    {
        $listed = HotspotLogin::hostList(' login.itc, Hotspot.Local ');
        $this->assertSame(['login.itc', 'hotspot.local'], $listed);
        foreach (['http://10.5.50.1/login', 'http://192.168.88.1/login', 'https://172.20.1.1/login', 'http://100.64.3.1:8080/login',
            'http://login.itc/login', 'http://HOTSPOT.local/login'] as $ok) {
            $this->assertTrue(HotspotLogin::allowedLoginUrl($ok, $listed), $ok);
        }
        foreach (['https://example.com/login', 'http://8.8.8.8/login', 'http://172.32.0.1/login', 'javascript:alert(1)',
            '//10.0.0.1/login', 'http://user:pw@10.0.0.1/login', 'http://login.itc.example.com/login', 'ftp://10.0.0.1/',
            "http://10.0.0.1/login\nX", 'http://10.0.0.1/' . str_repeat('a', 600), ''] as $bad) {
            $this->assertFalse(HotspotLogin::allowedLoginUrl($bad, $listed), $bad);
        }
    }

    public function testChapAnswerMatchesTheRouterAndPapPostsThePassword(): void
    {
        $ctx = ['login' => 'http://10.5.50.1/login', 'dst' => 'http://example.com/', 'chap_id' => 'e6', 'chap_challenge' => '00ff10'];
        $form = HotspotLogin::formFor($ctx, 'ABC123', '4321');
        $this->assertSame('get', $form['method']);
        $this->assertSame('http://10.5.50.1/login', $form['action']);
        $this->assertSame(md5("\xe6" . '4321' . "\x00\xff\x10"), $form['fields']['password']);
        $this->assertSame(['username' => 'ABC123', 'dst' => 'http://example.com/', 'popup' => 'true'],
            array_diff_key($form['fields'], ['password' => 1]));

        $pap = HotspotLogin::formFor(['chap_id' => '', 'chap_challenge' => '', 'dst' => ''] + $ctx, 'ABC123', '4321');
        $this->assertSame('post', $pap['method']);
        $this->assertSame(['username' => 'ABC123', 'password' => '4321', 'popup' => 'true'], $pap['fields']);
    }

    public function testCaptureRefusesForeignAddressesAndTheHeldAccountIsUsedOnce(): void
    {
        HotspotLogin::capture(['nux-hs' => 'https://example.com/collect'], '');
        $this->assertFalse(HotspotLogin::active());

        HotspotLogin::capture(['nux-hs' => 'http://10.5.50.1/login', 'nux-dst' => 'javascript:alert(1)', 'nux-chapid' => 'zz',
            'nux-chapch' => '0a0b', 'nux-err' => '1'], '');
        $this->assertTrue(HotspotLogin::active());
        $this->assertTrue(HotspotLogin::takeRouterError());
        $this->assertFalse(HotspotLogin::takeRouterError(), 'told once');
        $this->assertNull(HotspotLogin::takeForm(), 'nothing held yet');

        HotspotLogin::hold('ABC123', 'secret');
        $form = HotspotLogin::takeForm();
        $this->assertSame('post', $form['method'], 'an invalid chap-id is dropped, so no CHAP');
        $this->assertArrayNotHasKey('dst', $form['fields'], 'a non-http destination is dropped');
        $this->assertNull(HotspotLogin::takeForm(), 'used once');

        HotspotLogin::capture(['nux-hs' => 'http://203.0.113.9/login'], '');
        $this->assertFalse(HotspotLogin::active(), 'a later foreign address clears the context');
    }

    public function testTheFlowIsWiredIntoTheSignInAndTheSettings(): void
    {
        $login = self::read('system/controllers/login.php');
        $this->assertStringContainsString("\\Reseller\\Ui\\HotspotLogin::capture(\$_GET, (string) (\$config['hs_login_hosts'] ?? ''));", $login);
        $this->assertSame(4, substr_count($login, '\\Reseller\\Ui\\HotspotLogin::continueAfterActivation($voucher);'), 'every card activation success');
        $this->assertStringContainsString("\$ui->display('customer/hotspot.tpl');", $login);
        $this->assertSame('public', RouteMap::ROUTES['login']['hotspot']);
        $this->assertSame(['GET' => \Reseller\Security\Capability::SYSTEM_ADMIN], RouteMap::ROUTES['settings']['hotspot-page']);

        $page = self::read('system/hotspot/login.html');
        foreach (['__ITC_NETCORE_URL__', '$(link-login-only-esc)', '$(chap-id)', '$(chap-challenge)', '$(if error)1$(endif)'] as $part) {
            $this->assertStringContainsString($part, $page);
        }
        $this->assertStringContainsString("'hs_login_hosts'", self::read('system/controllers/settings.php'));
        $this->assertStringContainsString('name="hs_login_hosts"', self::read('ui/ui/admin/settings/app.tpl'));
    }
}
