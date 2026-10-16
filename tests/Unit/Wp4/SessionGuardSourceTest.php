<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP8 A1 (OD-W8-16 Option G, owner decisions D1-D4 of 2026-10-09): source guards for the PERMANENT fail-closed PPPoE
 * session guard in install/freeradius. They pin the properties the real-NAS gate of 2026-10-07 proved, so that a later
 * edit cannot silently weaken them: PPPoE discriminator; stock SQL-User-Name key; only ok + SAFE accepts; every reject
 * filtered to Reply-Message; no shell, no environment, no secret in argv; bounded deadline; no destructive accounting
 * change; delete_stale_sessions = no; the permanent policy keeps every line of the gate reference copy, in order (WP8
 * A2-R extends it with the admission reservation and widens only the open-row query; see AdmissionSourceTest).
 */
final class SessionGuardSourceTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        $f = self::root() . '/' . $rel;
        self::assertFileExists($f);
        return (string) file_get_contents($f);
    }

    /** non-comment, non-blank lines */
    private static function code(string $src): array
    {
        return array_values(array_filter(array_map('rtrim', explode("\n", $src)), static fn(string $l): bool => trim($l) !== '' && !preg_match('/^\s*#/', $l)));
    }

    private static function postAuth(): string
    {
        $code = implode("\n", self::code(self::read('install/freeradius/sites-available/phpnuxbill'))) . "\n";
        self::assertSame(1, preg_match('/^post-auth \{\n(.*?)^\}/ms', $code, $m));
        return $m[1];
    }

    public function testThePolicyIsTheGateTestedPolicyAtTheStartOfPostAuth(): void
    {
        // WP8 A2-R (owner authorization 2026-10-09, D-A2R-4): the A1 policy is EXTENDED, not rewritten. Every code line of
        // the 2026-10-07 real-NAS gate reference stays in post-auth, in the same order, except the open-row query, which
        // now also selects the NAS of an ACTIVE admission reservation (so a session proven by accounting is verified on
        // its NAS even when its radacct row is closed). The admission steps sit between those lines.
        $post = self::postAuth();
        $reference = self::code(self::read('dev/wp8/freeradius/option-g/post-auth.unlang'));
        $lines = explode("\n", rtrim($post, "\n"));
        $this->assertSame($reference[0], $lines[0], 'the PPPoE discriminator opens post-auth');
        $at = 0;
        foreach ($reference as $i => $ref) {
            if (str_contains($ref, '&Tmp-String-8 := "%{sql:SELECT CONCAT(\'n=\'')) {
                $this->assertStringContainsString("FROM radacct WHERE username = '%{SQL-User-Name}' AND acctstoptime IS NULL", $ref);
                continue;          // the one deliberately extended line (asserted in testDiscriminatorKeyAndDecisionAreFailClosed)
            }
            $found = array_search($ref, array_slice($lines, $at, null, true), true);
            $this->assertNotFalse($found, "A1 reference line {$i} kept in order: {$ref}");
            $at = $found + 1;
        }
        $this->assertSame(1, substr_count($post, 'nuxr_pppoe_session_guard {'), 'the guard runs exactly once');
        $this->assertLessThan(strpos($post, 'Post-Auth-Type REJECT {'), strpos($post, 'nuxr_pppoe_session_guard {'), 'never inside Post-Auth-Type REJECT');
        $this->assertStringNotContainsString('nuxr_pppoe_session_guard', self::sessionSection(), 'never from session { }');
    }

    private static function sessionSection(): string
    {
        $code = implode("\n", self::code(self::read('install/freeradius/sites-available/phpnuxbill'))) . "\n";
        self::assertSame(1, preg_match('/^session \{\n(.*?)^\}/ms', $code, $m));
        return $m[1];
    }

    public function testDiscriminatorKeyAndDecisionAreFailClosed(): void
    {
        $post = self::postAuth();
        $this->assertStringContainsString('if ((&Service-Type == Framed-User) && (&Framed-Protocol == PPP) && (&NAS-Port-Type == Ethernet) && &control:Simultaneous-Use) {', $post,
            'PPPoE only (RouterOS 7.24.5 discriminator); Hotspot (Login-User) never matches');
        $this->assertStringContainsString("&Tmp-String-5 := \"%{sql:SELECT '1'}\"", $post, 'creates the stock SQL-User-Name before the open-row query');
        // WP9 A2-R hardening L2 (protected-file exception, re-pinned 2026-10-16): the open-row statement reads the stock-escaped
        // key and the canonical key from ONE parameter row p and echoes both back (an emptied expansion cannot become n=0)
        $this->assertStringContainsString("FROM (SELECT CONVERT('%{SQL-User-Name}' USING utf8mb4) COLLATE utf8mb4_general_ci AS u, UNHEX('%{Tmp-String-2}') AS k) AS p}\"", $post,
            'the stock-escaped key from one parameter row');
        $this->assertStringContainsString("(SELECT COUNT(*) FROM radacct r WHERE r.username = p.u AND r.acctstoptime IS NULL) + (SELECT COUNT(*) FROM nuxr_admission a WHERE a.username = p.k AND a.state = 'ACTIVE')", $post,
            'open rows only - plus the NAS of an ACTIVE admission reservation (WP8 A2-R)');
        $this->assertStringContainsString("';u=', LOWER(HEX(p.u)), ';k=', HEX(p.k))", $post, 'the echo of the inputs');
        $this->assertStringContainsString('if ((&Tmp-String-8 !~ /^n=[0-9]+;[0-9.,]*;u=[0-9a-f]{2,512};k=%{Tmp-String-2}$/) || (&Tmp-String-4 !~ /^m=[0-9]+;u=[0-9a-f]{2,512}$/)) {', $post, 'echo mismatch (or an emptied name) rejects');
        $this->assertStringContainsString('if (&Tmp-String-8 =~ /^(n=[0-9]+;[0-9.,]*);u=/) {', $post, 'normalized back to the A1 form');
        $this->assertLessThan(strpos($post, 'if (&Tmp-String-8 == "n=0;") {'), strpos($post, 'nuxr-admission=reject:echo'), 'the echo is validated BEFORE n=0 can skip the NAS verification');
        $this->assertStringNotContainsString("'%{User-Name}'", $post, 'never the raw User-Name inside SQL');
        $this->assertDoesNotMatchRegularExpression('/\\b(DELETE\\s+FROM|UPDATE\\s+radacct|INSERT\\s+INTO\\s+radacct|REPLACE\\s+INTO|TRUNCATE)\\b/i', $post, 'no accounting write, no delete');
        $hotspot = strpos($post, 'if ((&Service-Type == Login-User) && (&NAS-Port-Type == Wireless-802.11)) {');
        $this->assertSame(1, preg_match_all('/\\bINSERT\\s+INTO\\b/i', substr($post, 0, $hotspot)), 'the only PPPoE INSERT is the admission claim (WP8 A2-R)');
        // WP8 H-2B (Hotspot only): slot creation (INSERT IGNORE INTO) + the audit row of a verified release
        $this->assertSame(2, preg_match_all('/\\bINSERT\\s+(IGNORE\\s+)?INTO\\b/i', substr($post, $hotspot)), 'Hotspot: slot creation + verified-release audit only');
        foreach (['reject', 'fail', 'ok', 'handled', 'invalid', 'userlock', 'notfound', 'noop', 'updated'] as $rc) {
            $this->assertMatchesRegularExpression('/^\\s*' . $rc . ' = 1$/m', $post, "module result {$rc} is mapped explicitly");
        }
        // WP9 A2-R hardening L3: the SAFE verdict of THIS request is recorded; an ACTIVE take-over requires it
        $this->assertStringContainsString("if (ok) {\n\t\t\t\tif (&control:Tmp-String-7 == \"SAFE\") {\n\t\t\t\t\tupdate control {\n\t\t\t\t\t\t&Tmp-String-8 := \"verified\"\n\t\t\t\t\t}\n\t\t\t\t}", $post, 'accept only on ok AND SAFE');
        $this->assertStringContainsString('if ((&control:Tmp-String-0 == "ACTIVE") && (&control:Tmp-String-8 != "verified")) {', $post);
        $this->assertLessThan(strpos($post, "nuxr-admission-claim=%{sql:UPDATE nuxr_admission SET prev_token"), strpos($post, 'reject:active-unverified'), 'checked before the take-over claim');
        $this->assertLessThan(strpos($post, '&Tmp-String-7 := "%{Packet-Authentication-Vector}"'), strpos($post, "&Tmp-String-8 !* ANY"), 'the flag is cleared first');
        // A1: SQL key, unparsable rows, not SAFE, module not ok (4); A2-R: identity, key, reservation read, pending of
        // another attempt, ACTIVE under another spelling, open rows under another spelling, late before the claim,
        // token, claim lost, late claim (10); WP9 A2-R hardening: echo, ACTIVE take-over without this request's SAFE (2)
        // 16 PPPoE reject branches (A1 + A2-R + WP9) + 3 Hotspot canonical-spelling branches (WP8 H-1 H1-A, which carry NO
        // Reply-Message: their refusal looks like a wrong password - HotspotSpellingSourceTest) + 10 Hotspot admission
        // slot branches (WP8 H-2B: limit, identity, late, slots, token, verified-audit, verified-release, attach-read,
        // full, late-claim - with the same Reply-Message as the PPPoE refusals; HotspotAdmissionSourceTest)
        $this->assertSame(29, preg_match_all('/^\\s*reject$/m', $post), 'every reject branch');
        $this->assertSame(30, substr_count($post, 'attr_filter.access_reject'), 'each reject branch filters the reply (+ Post-Auth-Type REJECT)');
        $this->assertSame(26, substr_count($post, '&Reply-Message := "Session verification failed"'), 'the PPPoE + Hotspot admission branches');
        $this->assertSame(16, substr_count(substr($post, 0, $hotspot), '&Reply-Message := "Session verification failed"'), 'the PPPoE branches');
        $this->assertStringContainsString("&Exec-Program !* ANY\n", $post, 'no reply Exec-Program can run');
        $this->assertStringContainsString('sql', self::sessionSection(), 'Simultaneous-Use (session { sql }) stays the first layer');
    }

    public function testExecModuleHasNoShellNoEnvironmentAndABoundedTimeout(): void
    {
        $m = implode("\n", self::code(self::read('install/freeradius/mods-available/nuxr_pppoe_session_guard')));
        $this->assertStringContainsString('program = "${confdir}/nuxr/nuxr_pppoe_session_guard %{hex:&User-Name} %{control:Simultaneous-Use} %{request:Tmp-String-9}"', $m);
        foreach (['wait = yes', 'shell_escape = no', 'output_pairs = control', 'timeout = 3'] as $line) {
            $this->assertStringContainsString("\t{$line}\n", $m . "\n");
        }
        $this->assertStringNotContainsString('input_pairs', $m, 'empty environment: no request attribute or password reaches the program');
    }

    public function testGuardProgramKeepsTheGateTestedLogic(): void
    {
        $this->assertSame('0755', substr(sprintf('%o', fileperms(self::root() . '/install/freeradius/bin/nuxr_pppoe_session_guard')), -4));
        $guard = self::code(self::read('install/freeradius/bin/nuxr_pppoe_session_guard'));
        $reference = self::code(self::read('dev/wp8/freeradius/option-g/nuxr_pppoe_session_guard'));
        $diff = array_values(array_diff($guard, $reference));
        $this->assertSame(['CREDS="${0%/*}/nas_snmp"         # next to this program (installed under ${confdir}/nuxr/), mode 0600, FreeRADIUS user'], $diff,
            'identical to the gate reference except the credential file location');
        $this->assertCount(count($reference), $guard);
        $src = implode("\n", $guard);
        $this->assertStringStartsWith("#!/bin/sh\n", self::read('install/freeradius/bin/nuxr_pppoe_session_guard'), 'POSIX sh, no bash features');
        foreach (['set -uf', 'umask 077', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL=C', 'DEADLINE_TICKS=36', 'trap cleanup EXIT'] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        $this->assertStringNotContainsString('eval', $src);
        foreach ($guard as $line) {
            if (preg_match('/\bsnmp(get|bulkwalk)\b/', $line)) {
                $this->assertStringContainsString('-r0 -t1', $line, 'one try, 1 s');
                $this->assertDoesNotMatchRegularExpression('/\s-c\s|\$comm/', $line, 'the community is never an argument');
            }
        }
        $this->assertSame(1, substr_count($src, 'comm=$('), 'community read once from the credential file');
        $this->assertSame(2, substr_count($src, '$comm'), 'community: validated, then written to the private snmp.conf - nothing else');
    }

    public function testStaleSessionsAreNeverZappedAndStartScriptsUseThePermanentFiles(): void
    {
        foreach (['install/freeradius/mods-available/sql.example', 'dev/test/freeradius/sql'] as $rel) {
            $code = implode("\n", self::code(self::read($rel)));
            $this->assertStringContainsString('delete_stale_sessions = no', $code, $rel);
            $this->assertStringNotContainsString('delete_stale_sessions = yes', $code, $rel);
        }
        foreach (['dev/test/freeradius/start.sh', 'dev/wp8/freeradius/start.sh'] as $rel) {
            $code = implode("\n", self::code(self::read($rel)));
            $this->assertStringContainsString('install -m 0755 /nuxr/install/bin/nuxr_pppoe_session_guard "$R/nuxr/nuxr_pppoe_session_guard"', $code, $rel);
            $this->assertStringContainsString('cp /nuxr/install/mods-available/nuxr_pppoe_session_guard "$R/mods-enabled/nuxr_pppoe_session_guard"', $code, $rel);
            $this->assertStringNotContainsString('option-g/', $code, "{$rel}: the gate reference copy is never installed");
            $this->assertStringNotContainsString('apt-get', $code, "{$rel}: no package installation at run time");
        }
    }
}
