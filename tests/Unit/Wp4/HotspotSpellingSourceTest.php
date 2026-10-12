<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP8 H-1 H1-A (owner authorization 2026-10-10): source guards for the HOTSPOT CANONICAL-SPELLING check in
 * install/freeradius. RouterOS keys a Hotspot session and its Port-Limit by the spelling it receives and ignores a
 * User-Name in the Access-Accept (H1-B gate), so the policy admits a Hotspot login only under the exact stored bytes.
 * Pinned here: Hotspot-only discriminator, after the PPPoE branch; the stock SQL-User-Name is created first; the
 * comparison is byte-wise (hex of the request User-Name vs hex of the stored row), never the collation; an ambiguous
 * identity (more than one byte-distinct stored spelling) is refused; every unexpected result rejects; the refusal
 * carries no Reply-Message (same as a wrong password) and is filtered; nothing is written; request / password /
 * authorize are untouched.
 */
final class HotspotSpellingSourceTest extends TestCase
{
    private const DISCRIMINATOR = 'if ((&Service-Type == Login-User) && (&NAS-Port-Type == Wireless-802.11)) {';

    private static function code(): string
    {
        $f = dirname(__DIR__, 3) . '/install/freeradius/sites-available/phpnuxbill';
        self::assertFileExists($f);
        return implode("\n", array_filter(array_map('rtrim', explode("\n", (string) file_get_contents($f))),
            static fn(string $l): bool => trim($l) !== '' && !preg_match('/^\s*#/', $l))) . "\n";
    }

    private static function section(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . ' \{\n(.*?)^\}/ms', self::code(), $m));
        return $m[1];
    }

    /** the H1-A canonical-spelling check: from the Hotspot discriminator to the WP8 H-2B admission slots that follow it
     *  in the same Hotspot block (their own source guards: HotspotAdmissionSourceTest) */
    private static function block(): string
    {
        $post = self::section('post-auth');
        $a = strpos($post, self::DISCRIMINATOR);
        $b = strpos($post, 'if (!&control:Simultaneous-Use || (&control:Simultaneous-Use !~ /^[1-9][0-9]?$/)) {');
        self::assertNotFalse($a, 'the Hotspot block exists');
        self::assertNotFalse($b);
        self::assertLessThan($b, $a);
        return substr($post, $a, $b - $a);
    }

    public function testHotspotOnlyAfterThePppoeBranch(): void
    {
        $post = self::section('post-auth');
        $this->assertSame(1, substr_count($post, self::DISCRIMINATOR), 'exactly one Hotspot block');
        $this->assertStringStartsWith('if ((&Service-Type == Framed-User) && (&Framed-Protocol == PPP) && (&NAS-Port-Type == Ethernet)', trim($post), 'the PPPoE branch still opens post-auth');
        $this->assertGreaterThan(strpos($post, '&Class := "0x6e75787231%{control:Tmp-String-5}"'), strpos($post, self::DISCRIMINATOR), 'after the whole PPPoE branch');
        $block = self::block();
        $this->assertStringNotContainsString('nuxr_admission', $block, 'no admission reservation for Hotspot');
        $this->assertStringNotContainsString('nuxr_pppoe_session_guard', $block);
        $this->assertStringNotContainsString('Framed-User', $block);
    }

    public function testCanonicalBytesAreComparedNeverTheCollation(): void
    {
        $block = self::block();
        $prime = strpos($block, "&Tmp-String-5 := \"%{sql:SELECT '1'}\"");
        $query = strpos($block, "&Tmp-String-1 := \"%{sql:SELECT CONCAT('c=', COUNT(DISTINCT CAST(username AS BINARY)), ';', COALESCE(LOWER(HEX(MIN(CAST(username AS BINARY)))), '-')) FROM radcheck WHERE username = '%{SQL-User-Name}'}\"");
        $this->assertNotFalse($prime, 'the stock SQL-User-Name is created first (FreeRADIUS 3.2.8, H1-B gate)');
        $this->assertNotFalse($query, 'stored spellings counted byte-wise; the stored bytes in hex');
        $this->assertLessThan($query, $prime);
        $this->assertStringContainsString('if (!&SQL-User-Name || (&Tmp-String-5 != "1")) {', $block, 'no key -> reject');
        $this->assertStringContainsString('&Tmp-String-2 := "%{hex:&User-Name}"', $block, 'the raw request User-Name bytes');
        $this->assertStringContainsString('if (&Tmp-String-1 =~ /^c=1;([0-9a-f]{2,512})$/) {', $block, 'exactly ONE stored spelling, well-formed');
        $this->assertStringContainsString('if ((&Tmp-String-2 !~ /^[0-9a-f]{2,512}$/) || (&Tmp-String-2 != &Tmp-String-3)) {', $block, 'byte equality of the two hex strings');
        $this->assertDoesNotMatchRegularExpression('/\bLOWER\(\s*username|UPPER\(|COLLATE\s+utf8mb4_general_ci|=~ \/.*\/i/i', $block, 'no case-insensitive comparison of the names');
        $this->assertStringNotContainsString("'%{User-Name}'", $block, 'never the raw User-Name inside SQL');
    }

    public function testEveryOtherOutcomeRejectsLikeAWrongPassword(): void
    {
        $block = self::block();
        $this->assertSame(3, preg_match_all('/^\s*reject$/m', $block), 'no key, ambiguous / missing identity, spelling');
        $this->assertSame(3, substr_count($block, 'attr_filter.access_reject'), 'every refusal filters the reply');
        foreach (['no-key', 'identity', 'spelling'] as $why) {
            $this->assertMatchesRegularExpression('/&Tmp-String-0 := "nuxr-hotspot=reject:' . $why . '"\n\s*\}\n\s*attr_filter\.access_reject\n\s*reject\n/', $block, "reason {$why}: filtered, then reject");
        }
        $this->assertStringNotContainsString('Reply-Message', $block, 'no Reply-Message: the refusal does not tell that the account exists');
        $this->assertStringContainsString("else {\n\t\t\tupdate request {\n\t\t\t\t&Tmp-String-0 := \"nuxr-hotspot=reject:identity\"", $block, 'any other query result (error, empty, 0 or 2+ spellings) rejects');
        $post = self::section('post-auth');
        $this->assertStringContainsString("Post-Auth-Type REJECT {\n\t\tattr_filter.access_reject\n\t}", $post, 'the wrong-password path is unchanged');
    }

    public function testNothingIsWrittenAndRequestAndAuthenticationAreUntouched(): void
    {
        $block = self::block();
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE\s+\w+\s+SET|DELETE|REPLACE|TRUNCATE|ALTER|DROP)\b/i', str_replace('update request {', '', $block), 'read-only SQL');
        $this->assertDoesNotMatchRegularExpression('/update (reply|control) \{/', $block, 'the reply / control lists are not changed (only the reject filter)');
        $this->assertDoesNotMatchRegularExpression('/&(User-Name|User-Password|CHAP-Password|Cleartext-Password|SQL-User-Name) :?=/', $block, 'request identity and credentials untouched');
        $authorize = self::section('authorize');
        $this->assertStringContainsString("if (!&Message-Authenticator) {\n\t\tdo_not_respond\n\t}", $authorize, 'Message-Authenticator enforcement unchanged');
        $this->assertStringNotContainsString('Wireless-802.11', $authorize, 'authorize is not Hotspot-specific');
    }
}
