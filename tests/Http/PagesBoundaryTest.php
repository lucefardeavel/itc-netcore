<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * OD-12 code boundary (owner review fix 2): pages/*.html and pages/vouchers/*.html are included as
 * Smarty templates (e.g. ui/ui/customer/pages.tpl), so writing or resetting them is a code-execution
 * surface. pages/<Page> (read) stays system.admin; pages/<Page>-post and pages/<Page>-reset require
 * system.code (SuperAdmin only), enforced server-side by RouteGuard. Hiding the controls is cosmetic.
 */
final class PagesBoundaryTest extends Wp2HttpTestCase
{
    private function page(string $name): string
    {
        return static::$app->dir . '/pages/' . $name . '.html';
    }

    private function pagesProbe(): array
    {
        return static::$app->probe('pages');
    }

    /** Owner test 1: Admin may open/read an allowed page; the write/reset controls are not offered. */
    public function testAdminMayReadAPageWithoutWriteControls(): void
    {
        $ad = $this->staff('ad');
        $r = $ad->get('pages/Announcement');
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertStringContainsString('id="summernote"', $r->body);
        $this->assertStringContainsString('Read only: editing page templates requires a SuperAdmin.', $r->body);
        $this->assertStringNotContainsString('pages/Announcement-reset', $r->body);
        $this->assertDoesNotMatchRegularExpression('#<button type="submit" class="btn btn-primary btn-block">#', $r->body);
        // positive control: the SuperAdmin view offers both
        $sa = $this->staff('sa')->get('pages/Announcement');
        $this->assertSame(200, $sa->status);
        $this->assertStringContainsString('pages/Announcement-reset', $sa->body);
        $this->assertMatchesRegularExpression('#<button type="submit" class="btn btn-primary btn-block">#', $sa->body);
        $this->assertStringNotContainsString('Read only: editing page templates', $sa->body);
    }

    /** Owner tests 2, 3, 4: Admin forged POSTs to page-post / page-reset are denied and change nothing. */
    public function testAdminForgedWriteAndResetAreDenied(): void
    {
        $ad = $this->staff('ad');
        $ad->token();
        static::$app->clearProbes();
        $tree = static::$app->treeHash('pages');
        $announcement = hash_file('sha256', $this->page('Announcement'));
        $before = $this->snapshot();
        $attempts = [
            ['pages/Announcement-post', ['html' => '<p>{$smarty.now} ADMIN_WRITE</p>']],
            ['pages/Voucher-post', ['html' => '<p>{$smarty.now} ADMIN_TEMPLATE</p>', 'template_save' => 'yes', 'template_name' => 'Admin Injected']],
            ['pages/Announcement-reset', []],
            ['pages/Voucher-reset', []],
            ['pages/Payment_Info-post', ['html' => 'x']],
        ];
        foreach ($attempts as [$route, $post]) {
            $r = $ad->postWithToken($route, $post);
            $this->assertSame(403, $r->status, "Admin {$route}: " . $r->excerpt());
            $this->assertFalse($r->leaksInternals());
        }
        $this->assertSame($announcement, hash_file('sha256', $this->page('Announcement')), 'page file unchanged');
        $this->assertSame($tree, static::$app->treeHash('pages'), 'no page or voucher template written, reset or created');
        $this->assertFileDoesNotExist(static::$app->dir . '/pages/vouchers/Admin Injected.html');
        $this->assertNotContains('save_pages', $this->pagesProbe(), 'the Pages controller never ran');
        $this->assertSame(5, substr_count(static::$app->serverLog(), 'capability system.code'), 'each denial logged against system.code');
        $this->assertUnchanged($before, 'denied page writes');
        // GET on the POST-only routes: 405 for the SuperAdmin, never a write
        foreach (['pages/Announcement-post', 'pages/Announcement-reset'] as $route) {
            $this->assertSame(405, $this->staff('sa')->get($route)->status, $route);
        }
        $this->assertSame($tree, static::$app->treeHash('pages'));
    }

    /** Owner test 5: a denied reset performs no remote fetch and no write. */
    public function testDeniedResetPerformsNoRemoteFetchOrWrite(): void
    {
        $template = static::$app->dir . '/pages_template/Registration_Info.html';
        $this->assertFileExists($template);
        rename($template, $template . '.hidden');
        $marker = '<p>WP2 marker: a running reset would have fetched remotely and overwritten this file</p>';
        file_put_contents($this->page('Registration_Info'), $marker);
        try {
            $ad = $this->staff('ad');
            $ad->token();
            static::$app->clearProbes();
            $r = $ad->postWithToken('pages/Registration_Info-reset');
            $this->assertSame(403, $r->status, $r->excerpt());
            // without a local template the reset branch would call Http::getData() and write its result
            $this->assertSame($marker, file_get_contents($this->page('Registration_Info')), 'no write');
            $this->assertStringContainsString('event=access.denied', static::$app->serverLog());
            $this->assertStringContainsString('capability system.code', static::$app->serverLog(), 'refused by RouteGuard before the controller');
        } finally {
            rename($template . '.hidden', $template);
            copy($template, $this->page('Registration_Info'));
        }
    }

    /** Owner test 6: the SuperAdmin with a valid CSRF token writes an allowed page and a voucher template. */
    public function testSuperAdminWritesAnAllowedPage(): void
    {
        $sa = $this->staff('sa');
        static::$app->clearProbes();
        $original = file_get_contents($this->page('Announcement'));
        try {
            $html = '<p>WP2 SuperAdmin page content</p>';
            $r = $sa->postWithToken('pages/Announcement-post', ['html' => $html]);
            $this->assertSame(302, $r->status, $r->excerpt());
            $this->assertSame($html, file_get_contents($this->page('Announcement')));
            $this->assertContains('save_pages', $this->pagesProbe());
            $r = $sa->postWithToken('pages/Voucher-post', ['html' => '<p>[[voucher_code]]</p>', 'template_save' => 'yes', 'template_name' => 'Boundary Template']);
            $this->assertSame(302, $r->status);
            $this->assertSame('<p>[[voucher_code]]</p>', file_get_contents(static::$app->dir . '/pages/vouchers/Boundary Template.html'));
            // CSRF still applies to the SuperAdmin
            $r = $sa->post('pages/Announcement-post', ['html' => '<p>no token</p>']);
            $this->assertSame(403, $r->status, 'a SuperAdmin request without the CSRF token is refused');
            $this->assertSame($html, file_get_contents($this->page('Announcement')), 'a request without the CSRF token writes nothing');
        } finally {
            file_put_contents($this->page('Announcement'), $original);
        }
    }

    /** Owner test 7: the SuperAdmin reset works only on an allowed canonical page. */
    public function testSuperAdminResetOnlyOnCanonicalPages(): void
    {
        $sa = $this->staff('sa');
        file_put_contents($this->page('Announcement'), '<p>locally modified</p>');
        $r = $sa->postWithToken('pages/Announcement-reset');
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame(file_get_contents(static::$app->dir . '/pages_template/Announcement.html'), file_get_contents($this->page('Announcement')), 'restored from the shipped template');

        // an existing but non-canonical page cannot be reset (no remote fetch for arbitrary names)
        file_put_contents($this->page('Custom_Page'), '<p>custom</p>');
        $tree = static::$app->treeHash('pages');
        $r = $sa->postWithToken('pages/Custom_Page-reset');
        $this->assertSame(404, $r->status, $r->excerpt());
        $this->assertSame('<p>custom</p>', file_get_contents($this->page('Custom_Page')));
        foreach (['..%2f..%2fconfig-reset', '..-reset', 'Voucher.html-reset', 'NoSuchPage-reset', str_repeat('A', 80) . '-reset'] as $name) {
            $r = $sa->postWithToken('pages/' . $name);
            $this->assertContains($r->status, [404, 405], "pages/{$name}");
        }
        $this->assertSame($tree, static::$app->treeHash('pages'), 'no file written by non-canonical or traversal resets');
        @unlink($this->page('Custom_Page'));
    }

    /** Agent, Sales and Report hold neither system.admin nor system.code. */
    public function testOtherRolesHaveNoPagesAccess(): void
    {
        $tree = static::$app->treeHash('pages');
        foreach (['A', 'A1', 'rp'] as $u) {
            $c = $this->staff($u);
            $this->assertSame(403, $c->get('pages/Announcement')->status, "{$u} read");
            $this->assertSame(403, $c->postWithToken('pages/Announcement-post', ['html' => 'x'])->status, "{$u} write");
            $this->assertSame(403, $c->postWithToken('pages/Announcement-reset')->status, "{$u} reset");
        }
        $this->assertSame($tree, static::$app->treeHash('pages'));
    }

    /** Owner test 9: the API never exposes the Pages read, write or reset surface. */
    public function testApiNeverExposesPages(): void
    {
        $tree = static::$app->treeHash('pages');
        foreach (['sa', 'ad'] as $u) {
            $token = $this->apiToken($u);
            foreach ([['GET', 'pages/Announcement', []], ['POST', 'pages/Announcement-post', ['html' => '<p>api</p>']], ['POST', 'pages/Announcement-reset', []],
                ['POST', 'pages/Voucher-post', ['html' => '<p>api</p>', 'template_save' => 'yes', 'template_name' => 'Api Tpl']]] as [$method, $route, $data]) {
                $r = $this->client()->api($method, $route, $data, $token);
                $this->assertFalse($r->json()['success'] ?? true, "API {$u} {$method} {$route}: " . $r->excerpt());
                $this->assertStringNotContainsString('summernote', $r->body);
            }
        }
        $this->assertSame($tree, static::$app->treeHash('pages'), 'no file written through the API');
    }
}
