<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab UX findings (ITC NetCore lab 2026-10-25):
 *  - UX-14: the customer portal in Arabic still printed "Hotspot Plan", "Radius Plan", "Prepaid" / "Postpaid" and the raw
 *    plan type (hard-coded English in the active-plan widget), and the language menu listed every file in system/lan as
 *    "Arabic", "English", "Indonesia", "Spanish", "Turkish". The widget is translated; the menu offers Arabic and English
 *    only, each by its native name (the other language files are kept).
 *  - UX-9: the login form labelled the username field "Usernames" (Arabic: the plural); it is the singular "Username".
 */
final class W10PortalArabicTest extends Wp2HttpTestCase
{
    public function testArabicPortalShowsNoHardCodedEnglishPlanText(): void
    {
        // a second service of the customer on the RADIUS router: the "Radius Plan" heading of the widget
        static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => $this->cid('cNULL2'), 'username' => 'cNULL2', 'plan_id' => static::$seed->planId, 'namebp' => 'wp2_plan',
            'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => gmdate('Y-m-d', time() + 86400 * 10),
            'time' => '23:59:59', 'status' => 'on', 'method' => 'Admin - lab', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => $this->sid('ad'),
        ]);
        $c = $this->customer('cNULL2');
        $switch = $c->postWithToken('accounts/language-update-post', [], ['lang' => 'arabic'], 'home');
        $this->assertSame(302, $switch->status, 'language switch: ' . $switch->excerpt());
        $home = $c->get('home');
        $this->assertSame(200, $home->status, $home->excerpt());
        $body = $home->body;
        $this->assertStringContainsString('wp2_plan', $body, 'positive control: the active-plan widget is rendered');
        $this->assertStringContainsString('خطة Radius', $body, 'the RADIUS service heading is translated');
        $this->assertStringContainsString('دفع مسبق', $body, 'the payment type is translated');
        foreach (['Prepaid', 'Postpaid', 'Radius Plan', 'Hotspot Plan', 'PPPOE Plan', 'VPN Plan'] as $english) {
            $this->assertStringNotContainsString($english, $body, "Arabic portal: no hard-coded \"{$english}\"");
        }
        $this->assertDoesNotMatchRegularExpression('#<b>\s*دفع مسبق\s*</b>\s*Personal\b#u', $body, 'the plan type is translated, not printed raw');
    }

    public function testAStoredDefaultPlanTitleIsTranslatedAndACustomTitleIsKept(): void
    {
        // UI polish 2026-10-29: the localisation form pre-fills "Radius Plan" (and the other defaults) into its inputs, so any
        // save of that form stores the English default and the Arabic portal printed it raw. A stored value equal to the
        // default is the untranslated default; an operator's own title is shown as configured.
        static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => $this->cid('cNULL2'), 'username' => 'cNULL2', 'plan_id' => static::$seed->planId, 'namebp' => 'wp2_plan',
            'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => gmdate('Y-m-d', time() + 86400 * 10),
            'time' => '23:59:59', 'status' => 'on', 'method' => 'Admin - lab', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => $this->sid('ad'),
        ]);
        $c = $this->customer('cNULL2');
        $this->assertSame(302, $c->postWithToken('accounts/language-update-post', [], ['lang' => 'arabic'], 'home')->status);
        try {
            static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = 'radius_plan'");
            static::$db->insertRow('tbl_appconfig', ['setting' => 'radius_plan', 'value' => 'Radius Plan']);
            $home = $c->get('home');
            $this->assertSame(200, $home->status, $home->excerpt());
            $this->assertStringContainsString('خطة Radius', $home->body, 'the stored English default is translated');
            $this->assertStringNotContainsString('Radius Plan', $home->body);

            static::$db->exec("UPDATE tbl_appconfig SET value = 'Fibre W10' WHERE setting = 'radius_plan'");
            $home = $c->get('home');
            $this->assertSame(200, $home->status, $home->excerpt());
            $this->assertStringContainsString('Fibre W10', $home->body, "the operator's own title is kept");
        } finally {
            static::$db->exec("DELETE FROM tbl_appconfig WHERE setting = 'radius_plan'");
        }
    }

    public function testCustomerLanguageMenuOffersArabicAndEnglishByNativeName(): void
    {
        $menu = $this->customer('cNULL2')->get('autoload_user/language', ['select' => 'arabic']);
        $this->assertSame(200, $menu->status, $menu->excerpt());
        $this->assertSame(2, substr_count($menu->body, '<li>'), 'exactly two languages: ' . $menu->excerpt());
        $this->assertStringContainsString('العربية', $menu->body);
        $this->assertStringContainsString('English', $menu->body);
        $this->assertStringContainsString('lang=arabic', $menu->body);
        $this->assertStringContainsString('lang=english', $menu->body);
        foreach (['Indonesia', 'Spanish', 'Turkish', '>Arabic<', 'lang=spanish', 'lang=indonesia', 'lang=turkish'] as $gone) {
            $this->assertStringNotContainsString($gone, $menu->body, "not offered: {$gone}");
        }
        // the language files are kept on disk (presentation change only)
        foreach (['indonesia', 'spanish', 'turkish'] as $file) {
            $this->assertFileExists(static::$app->dir . '/system/lan/' . $file . '.json');
        }
    }

    public function testLoginPageSaysUsernameNotUsernames(): void
    {
        foreach (['login', 'forgot'] as $route) {
            $page = $this->client()->get($route);
            $this->assertSame(200, $page->status, "{$route}: " . $page->excerpt());
            $this->assertStringNotContainsString('Usernames', $page->body, "{$route}: the singular label");
        }
        $this->assertStringContainsString('Username', $this->client()->get('login')->body, 'positive control: the label is shown');
    }
}
