<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP9 final security audit, SESS-1 / HDR-1:
 *  - the PHP session cookie carries the authenticated staff / customer session ($_SESSION['aid'] / ['uid']); it was
 *    sent with PHP's defaults (readable by scripts, no SameSite), so a script injection could read and replay it. It is
 *    now HttpOnly and SameSite=Lax (and Secure under HTTPS), like the remember cookies; strict session mode refuses an
 *    uninitialized session id chosen by the client (session fixation);
 *  - every page is sent with X-Frame-Options SAMEORIGIN / CSP frame-ancestors 'self' (staff pages could be framed by
 *    any site: clickjacking) and X-Content-Type-Options nosniff.
 */
final class W9SessionCookieHardeningTest extends Wp2HttpTestCase
{
    /** @return list<string> the Set-Cookie lines of the session cookie */
    private static function sessionCookies(\Reseller\Tests\Support\HttpResponse $r): array
    {
        return array_values(array_filter($r->headers['set-cookie'] ?? [], static fn(string $c): bool => str_starts_with($c, session_name() . '=') || str_starts_with($c, 'PHPSESSID=')));
    }

    public function testSessionCookieAndFramingHeaders(): void
    {
        foreach (['admin' => 'admin', 'customer login' => 'login'] as $label => $route) {
            $r = $this->client()->get($route);
            $this->assertSame(200, $r->status, $label);
            $cookies = self::sessionCookies($r);
            $this->assertNotEmpty($cookies, "{$label}: a session cookie is issued");
            foreach ($cookies as $c) {
                $this->assertMatchesRegularExpression('/;\s*HttpOnly/i', $c, "{$label}: HttpOnly");
                $this->assertMatchesRegularExpression('/;\s*SameSite=Lax/i', $c, "{$label}: SameSite=Lax");
            }
            $this->assertSame('SAMEORIGIN', $r->header('X-Frame-Options'), "{$label}: X-Frame-Options");
            $this->assertStringContainsString("frame-ancestors 'self'", (string) $r->header('Content-Security-Policy'), "{$label}: frame-ancestors");
            $this->assertSame('nosniff', $r->header('X-Content-Type-Options'), "{$label}: nosniff");
        }
        // the authenticated staff session keeps working (positive control)
        $this->assertSame(200, $this->staff('ad')->get('dashboard')->status);
    }

    public function testClientChosenSessionIdIsNotAdopted(): void
    {
        $fixed = 'w9fixation' . bin2hex(random_bytes(8));
        $r = $this->client()->raw('GET', '?_route=login', null, ['Cookie: PHPSESSID=' . $fixed]);
        $this->assertSame(200, $r->status);
        foreach (self::sessionCookies($r) as $c) {
            $this->assertStringNotContainsString($fixed, $c, 'a fresh id is issued instead of the client-chosen one');
        }
        $this->assertNotEmpty(self::sessionCookies($r), 'strict mode replaces the unknown id');
    }
}
