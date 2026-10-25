<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab UX findings (ITC NetCore lab 2026-10-25):
 *  - UX-1: every customer saw the stock PHPNuxBill demo announcement ("Pengumuman!! Besok libur ..." on the login page
 *    and the customer dashboard). The shipped announcement pages are empty, and an empty (or whitespace-only) page draws
 *    no panel; a page with content still does (positive control).
 *  - UX-2: a reseller-managed customer (manager_id set) was offered Deactivate, Recharge and Buy Package / Buy Balance,
 *    which the OD-11 gate always refuses, and the refusal showed the internal code "(pending WP5B)". Those entry points
 *    are not offered to that customer (an unassigned customer keeps them), and the refusal - unchanged on the server -
 *    reads as a customer-facing sentence.
 */
final class W10PortalContentTest extends Wp2HttpTestCase
{
    private const DEMO = ['Pengumuman', 'This Announcement is for', 'Besok libur'];
    private const PANEL = 'nuxr-announcement';
    private const REFUSAL_EN = 'This service is managed by your reseller. Please contact your agent to renew or change it.';
    private const REFUSAL_AR = 'تُدار هذه الخدمة من قِبل الموزّع الخاص بك.';

    private function pageFile(string $name): string
    {
        return static::$app->dir . '/pages/' . $name . '.html';
    }

    private function writePage(string $name, string $html): void
    {
        file_put_contents($this->pageFile($name), $html);
        clearstatcache();
    }

    private function loginPage(): string
    {
        $r = $this->client()->get('login');
        $this->assertSame(200, $r->status, $r->excerpt());
        return $r->body;
    }

    private function customerHome(string $username): string
    {
        $r = $this->customer($username)->get('home');
        $this->assertSame(200, $r->status, $r->excerpt());
        return $r->body;
    }

    /** UX-1: a fresh install (pages/ copied from pages_template/) shows no demo text and no empty announcement panel. */
    public function testFreshInstallShowsNoDemoAnnouncement(): void
    {
        $login = $this->loginPage();
        $home = $this->customerHome('cNULL');
        foreach (self::DEMO as $demo) {
            $this->assertStringNotContainsString($demo, $login, "login page: {$demo}");
            $this->assertStringNotContainsString($demo, $home, "customer home: {$demo}");
        }
        $this->assertStringNotContainsString(self::PANEL, $login, 'login page: an empty announcement draws no panel');
        $this->assertStringNotContainsString(self::PANEL, $home, 'customer home: an empty announcement draws no panel');
        $this->assertStringContainsString('col-sm-4 col-sm-offset-4', $login, 'login page: the form is centred without the panel');
        $this->assertStringContainsString('csrf_token', $login, 'login page: the login form is still rendered');
    }

    /** UX-1: the panel is drawn only for a page with content; whitespace only counts as empty. */
    public function testAnnouncementPanelIsDrawnOnlyForContent(): void
    {
        $login = (string) file_get_contents($this->pageFile('Announcement'));
        $customer = (string) file_get_contents($this->pageFile('Announcement_Customer'));
        try {
            $this->writePage('Announcement', '<p class="w10ann">Maintenance window tonight</p>');
            $body = $this->loginPage();
            $this->assertStringContainsString('<p class="w10ann">Maintenance window tonight</p>', $body);
            $this->assertStringContainsString(self::PANEL, $body, 'login page: a page with content is shown in its panel');
            $this->assertStringNotContainsString('col-sm-4 col-sm-offset-4', $body, 'login page: the form sits beside the panel');

            $this->writePage('Announcement', " \n\t \n");
            $this->assertStringNotContainsString(self::PANEL, $this->loginPage(), 'login page: whitespace only draws no panel');

            $this->writePage('Announcement_Customer', '<p class="w10cann">Customer notice</p>');
            $body = $this->customerHome('cNULL');
            $this->assertStringContainsString('<p class="w10cann">Customer notice</p>', $body);
            $this->assertStringContainsString(self::PANEL, $body, 'customer home: a page with content is shown in its box');

            $this->writePage('Announcement_Customer', "\n   \n");
            $this->assertStringNotContainsString(self::PANEL, $this->customerHome('cNULL'), 'customer home: whitespace only draws no box');
        } finally {
            $this->writePage('Announcement', $login);
            $this->writePage('Announcement_Customer', $customer);
        }
    }

    /** UX-2: the refused entry points are not offered to a reseller-managed customer; an unassigned one keeps them. */
    public function testResellerManagedPortalOffersNoRefusedEntryPoints(): void
    {
        $owned = $this->customerHome('cA1');
        foreach (['deactivate=', 'recharge=', 'order/package', 'order/balance'] as $link) {
            $this->assertStringNotContainsString($link, $owned, "reseller-managed customer home: no {$link} link");
        }
        $this->assertStringContainsString('sync=', $owned, 'the bounded re-provision (sync) is still offered');
        // positive control: an unassigned customer with the same active plan still has them
        $legacy = $this->customerHome('cNULL');
        foreach (['deactivate=', 'recharge=', 'order/package'] as $link) {
            $this->assertStringContainsString($link, $legacy, "unassigned customer home: {$link} link");
        }
    }

    /** UX-2: the refusal itself is unchanged; its text is customer-facing and carries no internal code. */
    public function testRefusalMessageIsCustomerFacing(): void
    {
        $db = static::$db;
        $rid = (int) static::$seed->recharges['cA1'];
        $db->exec("UPDATE tbl_user_recharges SET status = 'on', expiration = '" . gmdate('Y-m-d', time() + 86400 * 10) . "' WHERE id = " . $rid);
        $c = $this->customer('cA1');
        $before = $this->snapshot();
        static::$app->clearProbes();
        $r = $c->postWithToken('home', [], ['deactivate' => (string) $rid], 'home');
        $this->assertContains($r->status, [200, 302], $r->excerpt());
        $this->assertFalse($r->leaksInternals());
        $this->assertUnchanged($before, 'refused portal deactivate');
        $this->assertSame([], static::$app->probe('device'), 'no device call');
        $this->assertSame('on', (string) $db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$rid]), 'service not ended');
        $page = $c->get('home');
        $this->assertSame(200, $page->status, $page->excerpt());
        $this->assertTrue(str_contains($page->body, self::REFUSAL_EN) || str_contains($page->body, self::REFUSAL_AR), 'the refusal is shown: ' . $page->excerpt());
        $this->assertStringNotContainsString('WP5B', $page->body);
        $this->assertStringNotContainsString('temporarily unavailable for reseller-managed customers', $page->body);
    }
}
