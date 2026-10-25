<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab UX finding UX-8 (ITC NetCore lab 2026-10-25): the staff footer (and the phone "More" sheet) linked the product
 * version to the About page (community) for every role, but community is system.admin (SuperAdmin / Admin): Sales,
 * Report and Agent followed a link into a 403. The link is shown only where the route is allowed; for the other roles
 * the version is plain text. The route itself stays refused for them (server-side, unchanged).
 */
final class W10FooterAboutLinkTest extends Wp2HttpTestCase
{
    public function testTheVersionLinksToAboutOnlyForRolesThatMayOpenIt(): void
    {
        foreach (['sa', 'ad'] as $who) {
            $page = $this->staff($who)->get('dashboard');
            $this->assertSame(200, $page->status, "{$who}: " . $page->excerpt());
            $this->assertStringContainsString('_route=community', $page->body, "{$who}: positive control, the About link is offered");
        }
        foreach (['A1', 'rp'] as $who) {
            $c = $this->staff($who);
            $page = $c->get('dashboard');
            $this->assertSame(200, $page->status, "{$who}: " . $page->excerpt());
            $this->assertStringNotContainsString('_route=community', $page->body, "{$who}: no link into a refused page");
            $this->assertMatchesRegularExpression('#itc-footer-version"><span id="version"><bdi>#', $page->body, "{$who}: the version is still shown, as text");
            $this->assertDenied($c->get('community'), "{$who}: the About page itself stays refused");
        }
    }
}
