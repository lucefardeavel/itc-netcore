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
 * change; delete_stale_sessions = no; the permanent policy is byte-identical to the gate reference copy.
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
        $post = self::postAuth();
        $reference = implode("\n", self::code(self::read('dev/wp8/freeradius/option-g/post-auth.unlang')));
        $this->assertStringStartsWith($reference . "\n", $post, 'byte-identical (code) to the policy the 2026-10-07 real-NAS gate passed, first in post-auth');
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
        $this->assertMatchesRegularExpression("/FROM radacct WHERE username = '%\\{SQL-User-Name\\}' AND acctstoptime IS NULL/", $post, 'the stock-escaped key, open rows only');
        $this->assertStringNotContainsString("'%{User-Name}'", $post, 'never the raw User-Name inside SQL');
        $this->assertDoesNotMatchRegularExpression('/\b(DELETE\s+FROM|UPDATE\s+radacct|INSERT\s+INTO)\b/i', $post, 'no accounting write');
        foreach (['reject', 'fail', 'ok', 'handled', 'invalid', 'userlock', 'notfound', 'noop', 'updated'] as $rc) {
            $this->assertMatchesRegularExpression('/^\s*' . $rc . ' = 1$/m', $post, "module result {$rc} is mapped explicitly");
        }
        $this->assertStringContainsString("if (ok) {\n\t\t\t\tif (&control:Tmp-String-7 == \"SAFE\") {\n\t\t\t\t}", $post, 'accept only on ok AND SAFE');
        $this->assertSame(4, preg_match_all('/^\s*reject$/m', $post), 'four reject branches (SQL key, unparsable rows, not SAFE, module not ok)');
        $this->assertSame(5, substr_count($post, 'attr_filter.access_reject'), 'each reject branch filters the reply (+ Post-Auth-Type REJECT)');
        $this->assertSame(4, substr_count($post, '&Reply-Message := "Session verification failed"'));
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
