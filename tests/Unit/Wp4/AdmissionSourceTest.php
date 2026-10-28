<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP8 A2-R (owner authorization 2026-10-09): source guards for the PPPoE ADMISSION RESERVATION in install/freeradius. They
 * pin the properties the A2-R feasibility gates and the implementation gates proved, so a later edit cannot silently
 * weaken them: claim last before the Access-Accept; plain INSERT / conditional UPDATE only (no INSERT IGNORE, no
 * DELETE, no ON DUPLICATE KEY, no radacct write); every SQL result checked against an exact sentinel; Strict Mode (a
 * PENDING claim of another attempt is never overridden); the attempt identity includes the Request Authenticator; the
 * late-claim guard before and after the claim; only the creator releases, only with attach_count = 0; accounting
 * transitions conditioned on token + NAS + Acct-Session-Id + Calling-Station-Id + state; Accounting-On untouched; the
 * additive schema leaves install/radius.sql unchanged.
 */
final class AdmissionSourceTest extends TestCase
{
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

    /** the PPPoE branch of post-auth: everything before the Hotspot block (WP8 H-1 H1-A + WP8 H-2B slots, which a PPPoE
     *  request never enters - disjoint discriminator; the Hotspot slots have their own HotspotAdmissionSourceTest) */
    private static function pppoe(): string
    {
        $post = self::section('post-auth');
        $hotspot = strpos($post, 'if ((&Service-Type == Login-User) && (&NAS-Port-Type == Wireless-802.11)) {');
        self::assertNotFalse($hotspot);
        return substr($post, 0, $hotspot);
    }

    public function testClaimIsTheLastStepBeforeTheAcceptAndOnlyAfterTheVerification(): void
    {
        $post = self::section('post-auth');
        $guard = strpos($post, 'nuxr_pppoe_session_guard {');
        $late = strpos($post, 'nuxr-admission=reject:late"');
        $insert = strpos($post, 'INSERT INTO nuxr_admission');
        $lateClaim = strpos($post, 'nuxr-admission=reject:late-claim"');
        $class = strpos($post, '&Class := "0x6e75787231%{control:Tmp-String-5}"');
        $this->assertNotFalse($guard);
        $this->assertTrue($guard < $late && $late < $insert && $insert < $lateClaim && $lateClaim < $class, 'A1 verification -> late guard -> claim -> late-claim guard -> Class');
        $this->assertSame(1, substr_count(self::pppoe(), '&Class := '), 'one Class in the PPPoE branch, set last');
        // the PPPoE branch ends after the Class; the only code after it is the Hotspot canonical-spelling block (WP8 H-1),
        // which a PPPoE request never enters (disjoint discriminator)
        $hotspot = strpos($post, 'if ((&Service-Type == Login-User) && (&NAS-Port-Type == Wireless-802.11)) {');
        $this->assertNotFalse($hotspot);
        $this->assertGreaterThan($class, $hotspot, 'the Hotspot block follows the PPPoE branch');
        $tail = substr($post, $class, $hotspot - $class);
        $this->assertDoesNotMatchRegularExpression('/%\{sql:/', $tail, 'no SQL after the Class: nothing can fail after the claim succeeded');
        $this->assertStringStartsWith('if ((&Service-Type == Framed-User) && (&Framed-Protocol == PPP) && (&NAS-Port-Type == Ethernet)', trim($post), 'PPPoE only');
    }

    public function testOnlyAtomicStatementsAndNoDestructiveSql(): void
    {
        $site = self::code(self::read('install/freeradius/sites-available/phpnuxbill'));
        // WP8 H-2B: the Hotspot slots create missing slot rows with ONE INSERT IGNORE (verified by a SELECT - see
        // HotspotAdmissionSourceTest); the A2-R PPPoE reservation uses only plain INSERT / conditional UPDATE
        $this->assertDoesNotMatchRegularExpression('/INSERT\s+IGNORE|ON\s+DUPLICATE|REPLACE\s+INTO|DELETE\s+FROM|TRUNCATE|DROP\s/i', self::pppoe() . self::section('accounting'));
        $this->assertDoesNotMatchRegularExpression('/ON\s+DUPLICATE|REPLACE\s+INTO|DELETE\s+FROM|TRUNCATE|DROP\s/i', $site);
        $this->assertSame(1, preg_match_all('/INSERT\s+IGNORE/i', $site), 'the only INSERT IGNORE: the Hotspot slot creation (WP8 H-2B)');
        $this->assertDoesNotMatchRegularExpression('/(UPDATE|INSERT\s+INTO)\s+radacct/i', self::section('post-auth') . self::section('accounting'), 'the policy never writes radacct');
        $post = self::pppoe();
        // creation: plain INSERT on the primary key; take-over: conditioned on state AND the previous token
        $this->assertStringContainsString("INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, attach_count, created_at, updated_at) VALUES (UNHEX('%{Tmp-String-2}'), 'PENDING', '%{control:Tmp-String-5}'", $post);
        // lab SEC-E2 fresh-session hold: an ACTIVE row is taken over only when its session was confirmed >= 10 s ago
        $this->assertStringContainsString("WHERE username = UNHEX('%{Tmp-String-2}') AND state = '%{control:Tmp-String-0}' AND token = '%{control:Tmp-String-1}' AND (state <> 'ACTIVE' OR confirmed_at <= UTC_TIMESTAMP(6) - INTERVAL 10 SECOND)}", $post);
        $this->assertSame(0, substr_count($post, 'AND token = \'%{control:Tmp-String-1}\'}'), 'no take-over statement without the hold');
        $this->assertStringContainsString("elsif ((&control:Tmp-String-0 == \"ENDED\") || (&control:Tmp-String-0 == \"ACTIVE\")) {", $post, 'only ENDED or (verified) ACTIVE can be taken over');
        // attach: the SAME attempt only, never creates or releases
        $this->assertSame(2, substr_count($post, "SET attach_count = attach_count + 1, updated_at = UTC_TIMESTAMP(6) WHERE username = UNHEX('%{Tmp-String-2}') AND state = 'PENDING' AND token = '%{control:Tmp-String-1}' AND nas = '%{NAS-IP-Address}' AND acct_session_id = '%{Acct-Session-Id}' AND calling_station_id = '%{Calling-Station-Id}' AND nas_port_id = '%{NAS-Port-Id}' AND request_authenticator = '%{Tmp-String-7}' AND session_user = '%{SQL-User-Name}'}"));
        // release: creator only, PENDING only, nobody attached, exactly this token
        $this->assertSame(1, substr_count($post, "end_reason = 'late_claim' WHERE username = UNHEX('%{Tmp-String-2}') AND token = '%{control:Tmp-String-5}' AND state = 'PENDING' AND attach_count = 0}"));
        $this->assertStringContainsString('if (&control:Tmp-String-9 == "created") {', $post);
        $this->assertSame(1, substr_count($post, "state = 'ENDED'"), 'the only post-auth release is the late claim of its creator');
        $this->assertLessThan(strpos($post, 'nuxr_pppoe_session_guard {'), strpos($post, 'reject:pending-other'), 'Strict check BEFORE any SNMP verification: a PENDING claim is never decided by SNMP');
    }

    public function testEverySqlResultIsCheckedAgainstASentinel(): void
    {
        $post = self::pppoe();
        $this->assertStringContainsString("SELECT CONCAT('k=', COALESCE((SELECT HEX(username) FROM radcheck WHERE username = '%{SQL-User-Name}' ORDER BY CAST(username AS BINARY) LIMIT 1), '-'))", $post, 'canonical key = exact radcheck.username bytes');
        $this->assertStringContainsString('if (&Tmp-String-1 =~ /^k=([0-9A-F]{2,512})$/) {', $post);
        $this->assertSame(2, substr_count($post, "SELECT CONCAT('r=', COALESCE((SELECT CONCAT(state, '|', token, '|', IF("), 'reservation read + one re-read');
        $this->assertStringContainsString('elsif (&Tmp-String-3 =~ /^r=(PENDING|ACTIVE|ENDED)\|([0-9a-f]{32})\|(same|other)\|(spell|respell)\|([0-9.]{7,15})$/) {', $post);
        // WP9 A2-R hardening L2: the open-row-other-spelling count carries the echo of its input
        $this->assertStringContainsString('if (&Tmp-String-4 !~ /^m=0;/) {', $post);
        $this->assertStringContainsString("SELECT CONCAT('m=', (SELECT COUNT(*) FROM radacct r WHERE r.username = p.u AND r.acctstoptime IS NULL AND (r.username COLLATE utf8mb4_bin) <> (p.u COLLATE utf8mb4_bin)), ';u=', LOWER(HEX(p.u))) FROM (SELECT CONVERT('%{SQL-User-Name}' USING utf8mb4) COLLATE utf8mb4_general_ci AS u) AS p", $post);
        $this->assertSame(2, substr_count($post, 'if (&Tmp-String-0 != "nuxr-admission-claim=1") {'), 'a claim succeeds only on exactly one affected row');
        $this->assertSame(2, substr_count($post, 'if ((&Tmp-String-6 !~ /^[0-9]{1,9}$/) || (<integer>&Tmp-String-6 > 5000)) {'), 'late guard before and after the claim, malformed age rejects');
        $this->assertSame(2, substr_count($post, '&Tmp-String-6 := "%{expr:(%{time_since:us} - %l * 1000000 - %M) / 1000}"'), 'request age on ONE clock: gettimeofday() now (rlm_date time_since) minus the gettimeofday() receive time (WP8 AV-5)');
        $this->assertSame(2, substr_count($post, '&Tmp-String-6 :='), 'no other age computation');
        $this->assertDoesNotMatchRegularExpression('/%c(?![a-z])/', self::code(self::read('install/freeradius/sites-available/phpnuxbill')), 'never the coarse time() clock %c (WP8 AV-5: it lags gettimeofday() after a second boundary)');
        $this->assertStringNotContainsString('%C', self::code(self::read('install/freeradius/sites-available/phpnuxbill')), 'no split microseconds / seconds reading of the current time');
        $this->assertStringContainsString('(&Tmp-String-7 !~ /^[0-9a-f]{32}$/)', $post, 'the Request Authenticator is part of the identity');
        $this->assertStringContainsString("request_authenticator = '%{Tmp-String-7}', 'same', 'other')", $post);
        $this->assertStringContainsString('if ((&control:Tmp-String-0 == "PENDING") && ((&control:Tmp-String-2 != "same") || (&control:Tmp-String-3 != "spell"))) {', $post, 'Strict Mode');
        $this->assertStringContainsString('if (&control:Tmp-String-5 !~ /^[0-9a-f]{32}$/) {', $post, '128-bit token');
    }

    public function testAccountingTransitionsAreConditionalAndTokenBound(): void
    {
        $acct = self::section('accounting');
        $this->assertLessThan(strpos($acct, '&Class'), strpos($acct, "\tsql\n"), 'the stock accounting runs first');
        $this->assertStringContainsString('if (&Tmp-String-0 =~ /^0x6e75787231([0-9a-f]{32})$/) {', $acct);
        $where = "WHERE token = '%{Tmp-String-1}' AND nas = '%{NAS-IP-Address}' AND acct_session_id = '%{Acct-Session-Id}' AND calling_station_id = '%{Calling-Station-Id}' AND state IN ('PENDING', 'ACTIVE')}";
        $this->assertSame(2, substr_count($acct, $where));
        $this->assertStringContainsString("SET state = 'ENDED'", $acct);
        $this->assertStringContainsString("SET state = 'ACTIVE'", $acct);
        $this->assertStringNotContainsString('Accounting-On', $acct, 'Accounting-On changes no reservation (D-A2R-6)');
        $this->assertSame(2, substr_count($acct, 'UPDATE nuxr_admission'));
    }

    public function testSchemaUpgradeIsAdditiveAndRadiusSqlUnchanged(): void
    {
        $up = self::read('install/freeradius/nuxr_admission.sql');
        $this->assertSame(2, substr_count($up, 'CREATE TABLE IF NOT EXISTS'));
        // WP9 A2-R hardening L1 (protected-file exception, re-pinned 2026-10-16): the only ALTER is the guarded, once-only
        // addition of the two CHECK constraints to a pre-WP9 table; still nothing dropped, deleted or rewritten
        $code = self::code(str_replace(["\n", '--'], ["\n", "\n#"], $up));
        $this->assertDoesNotMatchRegularExpression('/\b(DROP|DELETE|UPDATE|INSERT|TRUNCATE|RENAME)\b/i', $code, 'idempotent and additive');
        $this->assertSame(2, preg_match_all('/\bALTER\b/i', $code), 'only the two guarded ADD CONSTRAINT statements');
        $this->assertSame(2, substr_count($up, "'ALTER TABLE nuxr_admission ADD CONSTRAINT ck_nuxr_admission_"));
        $this->assertSame(2, substr_count($up, "AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_nuxr_admission_"), 'each guarded by information_schema');
        $this->assertStringContainsString("CONSTRAINT ck_nuxr_admission_token CHECK (token REGEXP '^[0-9a-f]{32}$' AND username <> '' AND session_user <> '')", $up);
        $this->assertStringContainsString("CONSTRAINT ck_nuxr_admission_claim CHECK (nas REGEXP '^[0-9]{1,3}([.][0-9]{1,3}){3}$' AND acct_session_id <> ''", $up);
        $this->assertStringContainsString("username varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL", $up);
        $this->assertStringContainsString('PRIMARY KEY (username)', $up);
        $this->assertStringContainsString('UNIQUE KEY uq_nuxr_admission_token (token)', $up);
        $this->assertStringNotContainsString('nuxr_admission', self::read('install/radius.sql'), 'install/radius.sql is not changed (owner)');
        $chk = self::read('install/freeradius/nuxr_admission_check.sql');
        $this->assertStringContainsString("'nuxr_admission=READY'", $chk);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)\b/', preg_replace('/^--.*$/m', '', $chk), 'the check is read-only');
        $this->assertStringContainsString("'check_constraints'", $chk);
        $this->assertStringContainsString("AND CONSTRAINT_NAME IN ('ck_nuxr_admission_token', 'ck_nuxr_admission_claim')) = 2, NULL, 'check_constraints')", $chk, 'WP9 A2-R hardening L4');
        $rel = self::read('install/freeradius/nuxr_admission_release.sql');
        $this->assertStringContainsString("AND state = 'PENDING';", $rel, 'only PENDING claims are released');
        $this->assertStringContainsString('INSERT INTO nuxr_admission_audit', $rel, 'every decision is audited');
        $this->assertDoesNotMatchRegularExpression('/DELETE\s+FROM/i', $rel);
    }
}
