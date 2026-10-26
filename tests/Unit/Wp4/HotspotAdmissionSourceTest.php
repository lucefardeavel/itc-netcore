<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP8 H-2B (owner implementation authorization 2026-10-11): source guards for the HOTSPOT ADMISSION SLOTS in
 * install/freeradius - the global shared_users limit across every NAS of an ISP, claim v2, Class "nuxh1" correlation,
 * STRICT MODE (a PENDING slot is never released by time, SNMP or another request), the ACTIVE-only SNMP verifier and the
 * Hotspot-only bypass of the stock Simultaneous-Use check. The behaviour itself is proven by
 * Integration/Wp4/HotspotAdmissionContractTest (harness FreeRADIUS, both engines) and the WP8 lab gates (real CHR).
 */
final class HotspotAdmissionSourceTest extends TestCase
{
    private const HS = 'if ((&Service-Type == Login-User) && (&NAS-Port-Type == Wireless-802.11)) {';
    private const KEY = "UNHEX('%{Tmp-String-3}')";
    private const START = 'if (!&control:Simultaneous-Use || (&control:Simultaneous-Use !~ /^[1-9][0-9]?$/)) {';

    private static function read(string $rel): string
    {
        $f = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($f);
        return (string) file_get_contents($f);
    }

    private static function code(string $src): string
    {
        return implode("\n", array_filter(array_map('rtrim', explode("\n", $src)), static fn(string $l): bool => trim($l) !== '' && !preg_match('/^\s*#/', $l))) . "\n";
    }

    private static function section(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . ' \{\n(.*?)^\}/ms', self::code(self::read('install/freeradius/sites-available/phpnuxbill')), $m));
        return $m[1];
    }

    /** the H-2B part of the Hotspot block: from its first statement to Post-Auth-Type REJECT */
    private static function slots(): string
    {
        $post = self::section('post-auth');
        $a = strpos($post, self::START);
        $b = strpos($post, 'Post-Auth-Type REJECT {');
        self::assertNotFalse($a);
        self::assertNotFalse($b);
        return substr($post, $a, $b - $a);
    }

    public function testTheSlotsRunOnlyForHotspotAfterH1AAndNeverForPppoe(): void
    {
        $post = self::section('post-auth');
        $hs = strpos($post, self::HS);
        $exact = strpos($post, '&Tmp-String-0 := "nuxr-hotspot=exact"');
        $start = strpos($post, self::START);
        $this->assertNotFalse($hs);
        $this->assertTrue($hs < $exact && $exact < $start, 'Hotspot discriminator -> H1-A exact spelling -> slots');
        $this->assertSame(1, substr_count($post, self::START), 'one slot block');
        $this->assertStringNotContainsString('nuxr_hs_admission', substr($post, 0, $hs), 'the PPPoE branch never touches the Hotspot slots');
        $this->assertStringNotContainsString('nuxr_hotspot_slot_verifier', substr($post, 0, $hs));
        $this->assertStringNotContainsString('nuxr_admission ', self::slots(), 'the Hotspot slots never touch the PPPoE reservation');
        $this->assertStringNotContainsString('nuxr_pppoe_session_guard', self::slots());
        $this->assertStringNotContainsString('checkrad', self::code(self::read('install/freeradius/sites-available/phpnuxbill')), 'checkrad is never called by the policy');
        // the key is the H1-A stored spelling (exact bytes) and its well-formedness is checked again
        $this->assertStringContainsString('(&Tmp-String-3 !~ /^[0-9a-f]{2,512}$/)', self::slots());
        $this->assertSame(0, preg_match('/UNHEX\(\'%\{Tmp-String-2\}\'\)/', self::slots()), 'never the PPPoE key');
    }

    public function testOrderIdentityAgeSlotsClaimVerifierLateClaimClass(): void
    {
        $s = self::slots();
        $pos = static fn(string $needle): int => (int) strpos($s, $needle);
        $order = [
            'limit' => 'nuxr-hs-admission=reject:limit"',
            'identity' => 'nuxr-hs-admission=reject:identity"',
            'late' => 'nuxr-hs-admission=reject:late"',
            'count' => 'hs-slots=%{sql:SELECT COUNT(*) FROM nuxr_hs_admission',
            'insert' => 'INSERT IGNORE INTO nuxr_hs_admission ',
            'slots' => 'nuxr-hs-admission=reject:slots"',
            'token' => 'nuxr-hs-admission=reject:token"',
            'attach' => "SELECT CONCAT('a=', COALESCE((SELECT token FROM nuxr_hs_admission",
            'precheck' => "SELECT CONCAT(IF(COUNT(a.slot) < p.l AND COALESCE(SUM(a.slot > p.l), 0) = 0, 'free', 'full'), '/', LOWER(HEX(p.k)), '/', p.l)",
            'claim' => "hs-claim=%{sql:UPDATE nuxr_hs_admission SET prev_token = token",
            'verifier' => 'nuxr_hotspot_slot_verifier {',
            'full' => 'nuxr-hs-admission=reject:full"',
            'lateclaim' => 'nuxr-hs-admission=reject:late-claim"',
            'class' => '&Class := "0x6e75786831%{control:Tmp-String-5}"',
        ];
        $prev = -1;
        foreach ($order as $k => $needle) {
            $this->assertNotFalse(strpos($s, $needle), "{$k} present");
            $this->assertGreaterThan($prev, $pos($needle), "{$k} in order");
            $prev = $pos($needle);
        }
        $this->assertSame(1, substr_count($s, '&Class := '), 'one Class, set last');
        $tail = substr($s, $pos($order['class']));
        $this->assertDoesNotMatchRegularExpression('/%\{sql:/', $tail, 'no SQL after the Class: nothing can fail after the claim succeeded');
        $this->assertSame(2, substr_count($s, '&Tmp-String-6 := "%{expr:(%{time_since:us} - %l * 1000000 - %M) / 1000}"'), 'age before anything is written and after the claim (one clock, AV-5)');
        $this->assertSame(2, substr_count($s, 'if ((&Tmp-String-6 !~ /^[0-9]{1,9}$/) || (<integer>&Tmp-String-6 > 5000)) {'), '5000 ms budget, malformed age rejects');
    }

    public function testClaimV2IsAPrecheckAndOneConditionalUpdateWithoutASubquery(): void
    {
        $s = self::slots();
        $claim = "UPDATE nuxr_hs_admission SET prev_token = token, prev_state = state, state = 'PENDING', token = '%{control:Tmp-String-5}'";
        $this->assertSame(2, substr_count($s, $claim), 'the claim + the one retry after a verified release');
        $this->assertSame(2, substr_count($s, "WHERE username = " . self::KEY . " AND slot <= %{control:Simultaneous-Use} AND state = 'ENDED' ORDER BY slot LIMIT 1}"), 'lowest ENDED slot <= limit');
        $this->assertSame(2, substr_count($s, "%{sql:SELECT CONCAT(IF(COUNT(a.slot) < p.l AND COALESCE(SUM(a.slot > p.l), 0) = 0, 'free', 'full'), '/', LOWER(HEX(p.k)), '/', p.l) FROM (SELECT " . self::KEY . " AS k, %{control:Simultaneous-Use} AS l) AS p LEFT JOIN nuxr_hs_admission AS a ON a.username = p.k AND a.state <> 'ENDED' GROUP BY p.k, p.l}"),
            'non-locking pre-check before each claim: fewer than limit in use AND no live slot above the limit (a decrease: review M1), key and limit from ONE parameter row');
        $this->assertSame(2, substr_count($s, 'if (&Tmp-String-4 == "free/%{Tmp-String-3}/%{control:Simultaneous-Use}") {'),
            'the echoed key and limit must be the expected ones: an expansion that rlm_sql emptied (pool exhausted) can never read as free');
        // no subquery inside the claim UPDATE (claim v1 deadlocked on MySQL 8.0)
        foreach (explode($claim, $s) as $i => $part) {
            if ($i === 0) {
                continue;
            }
            $stmt = substr($part, 0, (int) strpos($part, '}"'));
            $this->assertStringNotContainsString('SELECT', $stmt, 'claim v2: no subquery in the UPDATE');
        }
        $this->assertStringContainsString('if (&Tmp-String-9 != "hs-claim=1") {', $s, 'a claim succeeds only on exactly one affected row');
        $this->assertSame(2, substr_count($s, 'if (&Tmp-String-8 != "hs-slots=%{control:Simultaneous-Use}") {'), 'count first, insert only when missing, then the count must equal the limit (never the INSERT IGNORE result)');
        $this->assertSame(2, substr_count($s, "hs-slots=%{sql:SELECT COUNT(*) FROM nuxr_hs_admission WHERE username = " . self::KEY . " AND slot <= %{control:Simultaneous-Use}}"));
        $this->assertStringContainsString('WHERE t.n <= %{control:Simultaneous-Use} ORDER BY t.n}', $s, 'slots created in slot order');
        $this->assertStringContainsString('if (&control:Tmp-String-5 !~ /^[0-9a-f]{32}$/) {', $s, '128-bit token');
        $this->assertStringContainsString('%{randstr:hhhhhhhhhhhhhhhh}', $s);
    }

    public function testStrictModeOnlyTwoReleasesAndNeverOfAPendingSlotByTimeOrSnmp(): void
    {
        $s = self::slots();
        $this->assertSame(3, substr_count($s, "SET state = 'ENDED'"), 'exactly three releases in post-auth');
        $this->assertSame(1, substr_count($s, "end_reason = 'claim_failed' WHERE username = " . self::KEY . " AND token = '%{control:Tmp-String-5}' AND state = 'PENDING' AND attach_count = 0}"),
            'an unexpected claim result: the creator ends its own (possibly committed) claim - no Accept with that token exists (review M3)');
        $this->assertSame(1, substr_count($s, "end_reason = 'late_claim' WHERE username = " . self::KEY . " AND token = '%{control:Tmp-String-5}' AND state = 'PENDING' AND attach_count = 0}"),
            'late claim: its creator, its own token, still PENDING, not re-used by a retransmission');
        $this->assertStringContainsString('if (&control:Tmp-String-9 == "created") {', $s);
        $this->assertSame(1, substr_count($s, "end_reason = 'verified_gone' WHERE username = " . self::KEY . " AND token = '%{control:Tmp-String-8}' AND state = 'ACTIVE' AND confirmed_at <= UTC_TIMESTAMP(6) - INTERVAL 10 SECOND}"),
            'verified release: ACTIVE only, exactly the proven token');
        // time never RELEASES a slot. The only time condition allowed is the fresh-session hold (lab SEC-E2, expert review
        // 2026-10-26), which can only PREVENT a release: exactly twice (candidate list + release), nothing else
        $hold = 'confirmed_at <= UTC_TIMESTAMP(6) - INTERVAL 10 SECOND';
        $this->assertSame(2, substr_count($s, $hold), 'the fresh-session hold on the verifier candidates and the verified release');
        $this->assertDoesNotMatchRegularExpression('/INTERVAL|TIMESTAMPDIFF|created_at\s*<|updated_at\s*<|last_acct_at\s*</i', str_replace($hold, '', $s), 'no other time-based condition anywhere in the slot policy');
        // the verifier only ever sees ACTIVE slots
        $this->assertStringContainsString("FROM nuxr_hs_admission WHERE username = " . self::KEY . " AND state = 'ACTIVE' AND confirmed_at <= UTC_TIMESTAMP(6) - INTERVAL 10 SECOND ORDER BY o, slot LIMIT 12) AS x}", $s);
        $this->assertSame(1, substr_count($s, 'nuxr_hotspot_slot_verifier {'), 'the verifier runs at most once');
        foreach (['reject', 'fail', 'ok', 'handled', 'invalid', 'userlock', 'notfound', 'noop', 'updated'] as $rc) {
            $this->assertMatchesRegularExpression('/nuxr_hotspot_slot_verifier \{[^}]*\b' . $rc . ' = 1\n/s', $s, "module result {$rc} mapped explicitly");
        }
        $this->assertStringContainsString('if (ok && (&control:Tmp-String-7 == "GONE") && (&control:Tmp-String-8 =~ /^[0-9a-f]{32}$/)) {', $s, 'release only on ok AND GONE AND a token');
        $this->assertStringContainsString("&Exec-Program !* ANY\n", $s, 'no reply Exec-Program can run');
        // audit first, then the release; both checked
        $audit = strpos($s, "hs-audit=%{sql:INSERT INTO nuxr_hs_admission_audit");
        $release = strpos($s, "end_reason = 'verified_gone'");
        $this->assertNotFalse($audit);
        $this->assertLessThan($release, $audit, 'never an unaudited automatic release');
        $this->assertStringContainsString("token = '%{control:Tmp-String-8}' AND state = 'ACTIVE'}\"", substr($s, $audit, $release - $audit), 'the audit row only for a still-ACTIVE slot with that token');
        $this->assertStringContainsString('if (&Tmp-String-8 != "hs-audit=1") {', $s);
        $this->assertStringContainsString('if (&Tmp-String-8 != "hs-release=1") {', $s);
        // a retransmission attaches to a PENDING claim of the SAME attempt only; it never creates or releases one
        $this->assertStringContainsString("SET attach_count = attach_count + 1, updated_at = UTC_TIMESTAMP(6) WHERE username = " . self::KEY . " AND token = '%{control:Tmp-String-5}' AND state = 'PENDING' AND nas = '%{NAS-IP-Address}' AND acct_session_id = '%{Acct-Session-Id}' AND calling_station_id = '%{Calling-Station-Id}' AND request_authenticator = '%{Tmp-String-7}'}", $s);
    }

    public function testEveryRefusalIsFilteredAndNoRadacctWriteOrDelete(): void
    {
        $s = self::slots();
        $reasons = ['limit', 'identity', 'late', 'slots', 'token', 'verified-audit', 'verified-release', 'attach-read', 'full', 'late-claim'];
        foreach ($reasons as $why) {
            $this->assertMatchesRegularExpression('/&Tmp-String-0 := "nuxr-hs-admission=reject:' . preg_quote($why, '/') . '"\n\s*\}\n\s*attr_filter\.access_reject\n\s*update reply \{\n\s*&Reply-Message := "Session verification failed"\n\s*\}\n\s*reject\n/', $s, "reason {$why}");
        }
        $this->assertSame(count($reasons), preg_match_all('/^\s*reject$/m', $s));
        $this->assertSame(count($reasons), substr_count($s, 'attr_filter.access_reject'));
        $this->assertDoesNotMatchRegularExpression('/\b(DELETE\s+FROM|UPDATE\s+radacct|INSERT\s+INTO\s+radacct|REPLACE\s+INTO|TRUNCATE|DROP\s|ALTER\s)/i', $s);
        $this->assertStringNotContainsString("'%{User-Name}'", $s, 'never the raw User-Name inside post-auth SQL');
        $this->assertMatchesRegularExpression('/\(&Acct-Session-Id !~ \/\^\[0-9A-Za-z\]\{1,32\}\$\/\)/', $s, 'attempt identity validated before any SQL use');
        $this->assertStringContainsString('(&Calling-Station-Id !~ /^[0-9A-Fa-f]{2}([:-][0-9A-Fa-f]{2}){5}$/)', $s, 'a MAC the verifier can compare (review L6)');
    }

    public function testAccountingIsTokenBoundAndNeverRevivesOrDeletes(): void
    {
        $acct = self::section('accounting');
        $this->assertLessThan(strpos($acct, '0x6e75786831'), strpos($acct, "\tsql\n"), 'the stock accounting runs first');
        $this->assertStringContainsString('if (&Tmp-String-3 =~ /^0x6e75786831([0-9a-f]{32})$/) {', $acct, '"nuxh1" + 128-bit token, distinct from the PPPoE "nuxr1"');
        $where = "WHERE token = '%{Tmp-String-4}' AND nas = '%{NAS-IP-Address}' AND acct_session_id = '%{Acct-Session-Id}' AND calling_station_id = '%{Calling-Station-Id}' AND state IN ('PENDING', 'ACTIVE')}";
        $this->assertSame(2, substr_count($acct, $where), 'Stop and Start/Interim: token AND NAS AND session AND MAC, never from ENDED');
        $this->assertSame(2, substr_count($acct, 'UPDATE nuxr_hs_admission'));
        $this->assertStringContainsString("UPDATE nuxr_hs_admission SET state = 'ENDED', ended_at = UTC_TIMESTAMP(6), last_acct_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), end_reason = 'acct_stop' " . $where, $acct);
        $this->assertStringContainsString("UPDATE nuxr_hs_admission SET state = 'ACTIVE', confirmed_at = COALESCE(confirmed_at, UTC_TIMESTAMP(6)), last_acct_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) " . $where, $acct);
        $this->assertStringContainsString('if (&Tmp-String-5 != "nuxr-hs-acct=1") {', $acct, 'an unmatched Start / Interim-Update is an orphan');
        $this->assertSame(1, substr_count($acct, "'orphan_accounting'"));
        $this->assertDoesNotMatchRegularExpression('/DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE|DROP\s|UPDATE\s+radacct/i', $acct);
        $this->assertStringNotContainsString('Accounting-On', $acct, 'Accounting-On changes no slot');
    }

    public function testTheStockSimultaneousUseCheckIsSkippedForHotspotOnly(): void
    {
        $session = self::section('session');
        $this->assertSame("\t" . self::HS . "\n\t\tok\n\t}\n\telse {\n\t\tsql\n\t}\n", $session, 'Hotspot: the slots decide; every other request: the stock check, unchanged');
    }

    public function testTheVerifierModuleAndProgramAreFailClosedAndReadOnly(): void
    {
        $m = self::code(self::read('install/freeradius/mods-available/nuxr_hotspot_slot_verifier'));
        $this->assertStringContainsString("exec nuxr_hotspot_slot_verifier {\n\twait = yes\n\tprogram = \"\${confdir}/nuxr/nuxr_hotspot_slot_verifier %{request:Tmp-String-3} %{request:Tmp-String-1}\"\n\tshell_escape = no\n\toutput_pairs = control\n\ttimeout = 3\n}\n", $m);
        $this->assertStringNotContainsString('input_pairs', $m, 'empty environment: no request attribute or password reaches the program');
        $src = self::read('install/freeradius/bin/nuxr_hotspot_slot_verifier');
        $this->assertStringStartsWith("#!/bin/sh\n", $src);
        $this->assertStringContainsString("set -uf\numask 077\n", $src);
        $this->assertStringContainsString('CREDS="${0%/*}/nas_snmp"', $src, 'the protected credential file of the PPPoE guard, next to the program');
        $this->assertSame(1, substr_count($src, 'comm=$('), 'community read once from the credential file');
        $this->assertSame(2, substr_count($src, '$comm'), 'community: validated, then written to the private snmp.conf - nothing else');
        $this->assertStringContainsString("printf 'defCommunity %s\\ndefVersion 2c\\nhexOutputLength 0\\nmibs :\\n' \"\$comm\" > \"\$d/snmp.conf\"", $src);
        $this->assertDoesNotMatchRegularExpression('/\s-c\s/', $src, 'never a community on a command line');
        $this->assertDoesNotMatchRegularExpression('/\b(mysql|mariadb|eval|curl|wget)\b/', $src, 'no database access, no eval, no network tool but net-snmp');
        $this->assertStringNotContainsString('checkrad', $src);
        $health = strpos($src, 'snmpget -r0 -t1 -On "$ip" "$OID_UPTIME" "$OID_OBJID" "$OID_ROSVER"');
        $walk = strpos($src, 'snmpbulkwalk -r0 -t1 -On -Ox -Cr50');
        $this->assertNotFalse($health);
        $this->assertNotFalse($walk);
        $this->assertLessThan(strpos($src, 'u=$(walk_col "$ip" 3 "$d")'), $health, 'health probe before the Hotspot table');
        $this->assertStringContainsString('grep -qxF "$OID_OBJID = OID: .1.3.6.1.4.1.14988.1"', $src, 'MikroTik RouterOS only');
        $this->assertStringContainsString('"^$OID_ROSVER = STRING: \"7\.[0-9][0-9.]*\""', $src, 'the validated RouterOS major version only');
        $this->assertStringContainsString('OID_HS=.1.3.6.1.4.1.14988.1.1.5.1.1', $src, 'MIKROTIK-MIB Hotspot active table (Gate A)');
        $this->assertStringContainsString('DEADLINE_MS=1500', $src, 'hard wall-clock deadline 1.5 s, below the 3 s module timeout');
        $this->assertStringContainsString('if [ $(( ($(date +%s%N) - T0) / 1000000 )) -ge "$DEADLINE_MS" ]; then', $src);
        foreach (['GONE) exit 0', 'LIVE) exit 1', '*) exit 2'] as $x) {
            $this->assertStringContainsString($x, $src, 'exact tri-state');
        }
        $this->assertStringContainsString('if (!rows && empty != 1) exit 1', $src, 'no output at all is never "empty"');
        $this->assertStringContainsString('if ((usr[$1] "") == (user "")) print $2', $src, 'user bytes AND MAC, joined by session index, compared as strings');
    }

    public function testSchemaIsAdditiveAndTheOnlySqlFilesAreReadOnlyExceptTheMigration(): void
    {
        $up = self::read('install/freeradius/nuxr_hs_admission.sql');
        $this->assertSame(2, substr_count($up, 'CREATE TABLE IF NOT EXISTS'));
        $this->assertDoesNotMatchRegularExpression('/\b(DROP|ALTER|DELETE|TRUNCATE|UPDATE|INSERT|RENAME)\b/', self::code(str_replace('--', '#', $up)));
        $this->assertStringContainsString('PRIMARY KEY (username, slot)', $up);
        $this->assertStringContainsString('UNIQUE KEY uq_nuxr_hs_admission_token (token)', $up);
        // an empty (failed) rlm_sql expansion can never be stored: token always 32 hex, a claimed slot always complete
        $this->assertStringContainsString("CONSTRAINT ck_nuxr_hs_admission_token CHECK (token REGEXP '^[0-9a-f]{32}$' AND username <> '')", $up);
        $this->assertStringContainsString("CONSTRAINT ck_nuxr_hs_admission_claim CHECK (state = 'ENDED' OR (nas REGEXP '^[0-9]{1,3}([.][0-9]{1,3}){3}$' AND acct_session_id <> ''", $up);
        $this->assertStringContainsString("AND calling_station_id <> '' AND request_authenticator REGEXP '^[0-9a-f]{32}$' AND session_user <> ''))", $up);
        $this->assertStringContainsString("'check_constraints'", self::read('install/freeradius/nuxr_hs_admission_check.sql'), 'the readiness check verifies them');
        foreach (['install/freeradius/nuxr_hs_admission_check.sql', 'install/freeradius/nuxr_hs_admission_report.sql'] as $rel) {
            $code = self::code(str_replace('--', '#', self::read($rel)));
            $this->assertDoesNotMatchRegularExpression('/\b(DROP|ALTER|DELETE|TRUNCATE|UPDATE|INSERT|RENAME|REPLACE|CREATE)\b/i', $code, "{$rel}: read-only");
        }
        $this->assertSame([], glob(dirname(__DIR__, 3) . '/install/freeradius/*hs*release*') ?: [], 'NO executable manual release of a Hotspot slot (owner decision 2026-10-11)');
        $this->assertStringNotContainsString('nuxr_hs_admission', self::read('install/radius.sql'), 'install/radius.sql is not changed (owner)');
        foreach (['dev/test/freeradius/start.sh', 'dev/wp8/freeradius/start.sh'] as $rel) {
            $this->assertStringContainsString('install -m 0755 /nuxr/install/bin/nuxr_hotspot_slot_verifier "$R/nuxr/nuxr_hotspot_slot_verifier"', self::read($rel), "{$rel} installs the verifier as the README says");
        }
    }
}
