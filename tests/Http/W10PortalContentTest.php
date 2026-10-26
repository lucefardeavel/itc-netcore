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
 *    are not offered to that customer (an unassigned customer keeps them); the refusal on the server is unchanged.
 */
final class W10PortalContentTest extends Wp2HttpTestCase
{
    private const DEMO = ['Pengumuman', 'This Announcement is for', 'Besok libur'];
    private const PANEL = 'nuxr-announcement';

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

    /** Sets an app setting for this test; returns the previous value (null when the row did not exist). */
    private function setConfig(string $key, string $value): ?string
    {
        $prev = static::$db->rows('SELECT value FROM tbl_appconfig WHERE setting = ?', [$key]);
        static::$db->pdo->prepare('DELETE FROM tbl_appconfig WHERE setting = ?')->execute([$key]);
        static::$db->insertRow('tbl_appconfig', ['setting' => $key, 'value' => $value]);
        return $prev === [] ? null : (string) $prev[0]['value'];
    }

    private function restoreConfig(string $key, ?string $prev): void
    {
        static::$db->pdo->prepare('DELETE FROM tbl_appconfig WHERE setting = ?')->execute([$key]);
        if ($prev !== null) {
            static::$db->insertRow('tbl_appconfig', ['setting' => $key, 'value' => $prev]);
        }
    }

    /**
     * UX-2 follow-up: voucher activation (voucher.php) and balance transfer / recharge-a-friend (home.php, order routes)
     * are refused for a reseller-managed customer, so its portal offers no voucher Recharge form, no Voucher menu link,
     * no balance-transfer and no recharge-a-friend form; an unassigned customer still has all of them (positive control).
     */
    public function testResellerManagedPortalOffersNoVoucherOrBalanceTransferEntryPoints(): void
    {
        $saved = [];
        foreach (['enable_balance' => 'yes', 'allow_balance_transfer' => 'yes', 'disable_voucher' => 'no'] as $k => $v) {
            $saved[$k] = $this->setConfig($k, $v);
        }
        try {
            $markers = [
                'voucher/activation-post' => 'the voucher Recharge form',
                'voucher/activation' => 'the Voucher menu link / Order Voucher button',
                'name="nuxr_nonce"' => 'the balance-transfer form',
                'value="balance"' => 'the balance-transfer send button',
                'value="plan"' => 'the recharge-a-friend form',
            ];
            $owned = $this->customerHome('cA1');
            foreach ($markers as $needle => $what) {
                $this->assertStringNotContainsString($needle, $owned, "reseller-managed customer home: no {$what}");
            }
            $this->assertStringContainsString('voucher/list-activated', $owned, 'the activation history stays in the menu');
            $legacy = $this->customerHome('cNULL');
            foreach ($markers as $needle => $what) {
                $this->assertStringContainsString($needle, $legacy, "unassigned customer home: {$what}");
            }
            // the Voucher menu entry is in the shared header: absent on another portal page of the owned customer too
            $mail = $this->customer('cA1')->get('mail');
            $this->assertSame(200, $mail->status, $mail->excerpt());
            $this->assertStringNotContainsString('voucher/activation', $mail->body, 'reseller-managed customer inbox: no Voucher menu link');
            $mailLegacy = $this->customer('cNULL')->get('mail');
            $this->assertSame(200, $mailLegacy->status, $mailLegacy->excerpt());
            $this->assertStringContainsString('voucher/activation', $mailLegacy->body, 'unassigned customer inbox: the Voucher menu link');
        } finally {
            foreach ($saved as $k => $prev) {
                $this->restoreConfig($k, $prev);
            }
        }
    }

    /** UX-2 follow-up: the "Expired" label of an expired plan is plain text for a reseller-managed customer (no order link). */
    public function testExpiredOwnedPlanLabelDoesNotLinkToOrder(): void
    {
        $db = static::$db;
        $past = gmdate('Y-m-d', time() - 86400 * 3);
        $ids = [(int) static::$seed->recharges['cA1'], (int) static::$seed->recharges['cNULL']];
        $before = $db->rows('SELECT id, status, expiration FROM tbl_user_recharges WHERE id IN (' . implode(',', $ids) . ')');
        try {
            $db->exec("UPDATE tbl_user_recharges SET status = 'off', expiration = '{$past}' WHERE id IN (" . implode(',', $ids) . ')');
            $owned = $this->customerHome('cA1');
            $this->assertMatchesRegularExpression('#<span class="label label-danger pull-right">#', $owned, 'the Expired label is shown');
            $this->assertStringNotContainsString('order/package', $owned, 'reseller-managed customer: the Expired label is not a link');
            $this->assertDoesNotMatchRegularExpression('#<a class="label label-danger pull-right"#', $owned);
            // positive control: an unassigned customer's Expired label still leads to Buy Package
            $legacy = $this->customerHome('cNULL');
            $this->assertMatchesRegularExpression('#<a class="label label-danger pull-right"\s+href="[^"]*order/package#', $legacy, 'unassigned customer: the Expired label links to order/package');
        } finally {
            $st = $db->pdo->prepare('UPDATE tbl_user_recharges SET status = ?, expiration = ? WHERE id = ?');
            foreach ($before as $row) {
                $st->execute([$row['status'], $row['expiration'], $row['id']]);
            }
        }
    }

    /**
     * UX-2: hiding the entry points changes nothing on the server: a direct deactivate is still refused, with no state
     * change and no device call. (Rewording the refusal text lives in the hash-pinned ServiceGate.php: owner decision.)
     */
    public function testDirectDeactivateIsStillRefused(): void
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
        $this->assertStringContainsString('temporarily unavailable for reseller-managed customers', $page->body, 'the shipped refusal is shown');
    }
}
