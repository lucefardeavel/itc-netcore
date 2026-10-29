<?php
// CLI ONLY: refused before anything else
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * WP9 / WP10 release preflight of ONE ISP instance. CLI ONLY, READ-ONLY (it never writes).
 *
 *   php system/reseller/preflight.php [--setup] [--plugins-approved=<inventory file>] [--json]
 *
 * Without --setup this is the release / production-readiness preflight. --setup is for the initial installation,
 * before the NAS are onboarded: the only difference is that no NAS at all reports WARN (NOT_CONFIGURED) instead of FAIL.
 *
 * Every check prints PASS / WARN / FAIL with its evidence. Exit 0 when no check FAILs, 4 otherwise. Plugins are NOT
 * loaded (a preflight must not run unreviewed code). Checks:
 *  db.connect      only when the runtime account of config.php cannot connect: FAIL (MySQL error number, never the driver
 *                  message or the account), exit 4, no other check runs (fresh-install proof G-4)
 *  keys            $app_secret (NEW-7) valid; $api_secret valid (WARN when the API is not configured)
 *  stage           live stage, no display_errors, HTTPS application URL (WARN)
 *  engine          MariaDB 10.11 / MySQL 8.0 (the tested policy; WARN otherwise)
 *  isolation       REPEATABLE-READ globally and for this session (W8-18 deployment contract)
 *  time            SQL session time zone UTC (+00:00) (P-A4-1) and database / PHP clock skew < 5 s (NTP, P-A4-2);
 *                  time.nas_clock: open sessions whose NAS accounting stamp lies in the future - WARN above 5 s, FAIL above
 *                  the 10 s session-end cut-off allowance (RAD-3a operational control; it sees a NAS clock AHEAD only;
 *                  FAIL "could not run" when the RADIUS accounting table is unavailable - V1 requires RADIUS)
 *  schema          SchemaReadiness (migrations, tables, CHECK expressions and enforcement - WP1-CHECK)
 *  radius_checks   nuxr_admission / nuxr_hs_admission CHECK constraints: exact expressions and enforcement, also the
 *                  GLOBAL MariaDB check_constraint_checks used by the FreeRADIUS sessions (A2-R hardening L4)
 *  quota_plans     V1: enabled Limited Radius plans whose quota is invalid (zero / unusable) are listed (WARN): a sale fails closed
 *  identity        OBS-1 survey on this database: no canonical identity with more than one holder (FAIL), stored names
 *                  that are not canonical (WARN)
 *  profile         release profile: legacy MikroTik-API / rlm_rest devices disabled (WARN when enabled)
 *  plugins         installed plugin files; with --plugins-approved: no unknown / modified / missing file (B2)
 *  grants          the runtime account is least-privilege: no global privileges, no UPDATE / DELETE on the ledger and
 *                  audit tables (WP10 LPDB; FAIL for an administrative account such as root)
 *  mac             MAC-auth impact (MAC-H1A): RADIUS usernames that look like lower-case MAC addresses (WARN)
 *  nas             every NAS of type mikrotik_snmp, the live-session verification contract of V1 (FAIL otherwise, TD-2);
 *                  no NAS at all is NOT_CONFIGURED: FAIL in the release preflight, WARN with --setup (PF-0)
 */

define('NUXR_DEFER_PLUGINS', true);

$args = array_slice($argv, 1);
$setup = in_array('--setup', $args, true);
$opt = static function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return null;
};
$results = [];
$add = static function (string $check, string $status, string $detail) use (&$results): void {
    $results[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
};
$finish = static function () use (&$results, $args, $setup): void {
    $fail = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL'));
    if (in_array('--json', $args, true)) {
        echo json_encode(['preflight' => $fail === 0 ? 'PASS' : 'FAIL', 'mode' => $setup ? 'setup' : 'release', 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        foreach ($results as $r) {
            printf("%-5s %-26s %s\n", $r['status'], $r['check'], $r['detail']);
        }
        echo 'preflight=' . ($fail === 0 ? 'PASS' : 'FAIL') . " ({$fail} failing check(s), " . ($setup ? 'setup' : 'release') . " mode)\n";
    }
    exit($fail === 0 ? 0 : 4);
};
// Fresh-install proof G-4: init.php reads the settings through the runtime account of config.php. An account that cannot
// connect is a FAIL line with the exit code of every failing check (4), not a PHP fatal error: no driver message (it names
// the account and the host), no trace; the MySQL error number only.
try {
    require dirname(__DIR__, 2) . '/init.php';
    $pdo = ORM::get_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $one = static fn(string $sql, array $p = []): mixed => (static function () use ($pdo, $sql, $p) {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    })();
    $isMaria = stripos((string) $one('SELECT VERSION()'), 'mariadb') !== false;
} catch (PDOException $e) {
    $code = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) ? (int) $m[1] : (int) ($e->errorInfo[1] ?? 0);
    $why = match (true) {
        in_array($code, [1044, 1045, 1698], true) => 'access denied for the runtime account (check $db_user / $db_pass in config.php)',
        $code === 1049 => 'unknown database (check $db_name in config.php)',
        in_array($code, [2002, 2003, 2005, 2006, 2013], true) => 'database server not reachable (check $db_host in config.php and the server)',
        default => 'connection failed',
    };
    @error_log('[nuxr] event=preflight.db_connect code=' . $code);
    $add('db.connect', 'FAIL', "the application database account of config.php cannot connect: {$why}" . ($code > 0 ? " (MySQL error {$code})" : '') . '; no other check ran');
    $finish();
}
$try = static function (string $check, callable $fn) use ($add): void {
    try {
        $fn();
    } catch (Throwable $e) {
        $add($check, 'FAIL', 'check could not run (ref ' . \Reseller\Security\ErrorReporter::report($e, 'preflight') . ')');
    }
};

$try('keys', static function () use ($add): void {
    $add('keys.app_secret', \Reseller\Security\AppKey::available() ? 'PASS' : 'FAIL', \Reseller\Security\AppKey::available()
        ? 'dedicated application key configured' : '$app_secret missing, shorter than 32 characters or equal to another secret: staff login, cookies, links and OTP fail closed');
    $add('keys.api_secret', \Reseller\Security\ApiToken::secret() !== null ? 'PASS' : 'WARN', \Reseller\Security\ApiToken::secret() !== null
        ? 'dedicated API secret configured' : 'no valid $api_secret: the API fails closed (acceptable when the API is not used)');
});
$try('stage', static function () use ($add): void {
    global $_app_stage;
    $add('stage', ($_app_stage ?? '') === 'Live' && !ini_get('display_errors') ? 'PASS' : 'FAIL', 'stage=' . ($_app_stage ?? '?') . ' display_errors=' . (ini_get('display_errors') ? 'on' : 'off'));
    $add('stage.https', str_starts_with((string) APP_URL, 'https://') ? 'PASS' : 'WARN', 'APP_URL scheme ' . (string) parse_url((string) APP_URL, PHP_URL_SCHEME) . ' (HTTPS required for production cookies / PWA)');
});
$try('engine', static function () use ($add, $one, $isMaria): void {
    $v = (string) $one('SELECT VERSION()');
    $ok = $isMaria ? str_starts_with($v, '10.11.') : str_starts_with($v, '8.0.');
    $add('engine', $ok ? 'PASS' : 'WARN', ($isMaria ? 'MariaDB ' : 'MySQL ') . $v . ($ok ? '' : ' (outside the tested policy MariaDB 10.11 / MySQL 8.0)'));
});
$try('isolation', static function () use ($add, $one, $isMaria): void {
    $var = $isMaria ? 'tx_isolation' : 'transaction_isolation';
    $g = (string) $one("SELECT @@GLOBAL.{$var}");
    $s = (string) $one("SELECT @@SESSION.{$var}");
    $add('isolation', $g === 'REPEATABLE-READ' && $s === 'REPEATABLE-READ' ? 'PASS' : 'FAIL', "global={$g} session={$s} (contract: REPEATABLE-READ)");
});
$try('time', static function () use ($add, $one): void {
    $tz = (string) $one('SELECT @@SESSION.time_zone');
    $off = (int) $one('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
    $add('time.sql_session_zone', $off === 0 ? 'PASS' : 'FAIL', "session time_zone={$tz} offset={$off}s (contract P-A4-1: UTC for every writer and reader)");
    $skew = abs((int) $one('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())') - time());
    $add('time.clock_skew', $skew <= 5 ? 'PASS' : ($skew <= 30 ? 'WARN' : 'FAIL'), "database vs application clock skew {$skew}s (NTP on every host, P-A4-2)");
});
$try('time.nas_clock', static function () use ($add): void {
    // RAD-3a (owner decision 2026-10-28): V1 keeps the session-end cut-off, which compares the NAS's own Accounting stamp
    // and allows the NAS 10 s (B-L08). The readiness gate therefore checks the NAS clocks it can see: an open session whose
    // latest stamp is in the future was stamped by a NAS clock that is ahead. Same population and expression as the
    // health check nas_clock. A clock that is BEHIND cannot be seen here: NTP on the NAS and TA-02 cover it.
    try {
        $rp = ORM::get_db('radius');
    } catch (Throwable) {
        $rp = ORM::get_db();
    }
    $rp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rows = $rp->query('SELECT nasipaddress, MAX(TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), COALESCE(acctupdatetime, acctstarttime))) FROM radacct
        WHERE acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) > UTC_TIMESTAMP() + INTERVAL 5 SECOND
        GROUP BY nasipaddress ORDER BY 2 DESC')->fetchAll(PDO::FETCH_NUM);
    $max = $rows === [] ? 0 : (int) $rows[0][1];
    $list = implode(', ', array_map(static fn(array $r): string => $r[0] . ' +' . (int) $r[1] . 's', array_slice($rows, 0, 5)));
    $add('time.nas_clock', $max > 10 ? 'FAIL' : ($max > 5 ? 'WARN' : 'PASS'), $rows === []
        ? 'no open session stamped more than 5 s in the future (a NAS clock that is behind is not visible here: NTP on every NAS, TA-02)'
        : "NAS clock ahead: {$list} (limit 10 s, the session-end cut-off allowance; enable / repair NTP on the NAS - docs/OPERATOR_RECOVERY_RUNBOOK.md 4c)");
});
$try('schema', static function () use ($add): void {
    \Reseller\Domain\SchemaReadiness::reset();
    $st = \Reseller\Domain\SchemaReadiness::status();
    $add('schema', $st['ready'] ? 'PASS' : 'FAIL', $st['ready'] ? 'reseller schema ready (incl. CHECK expressions and enforcement)' : implode('; ', $st['problems']));
});
$try('radius_checks', static function () use ($add, $isMaria): void {
    try {
        $rp = ORM::get_db('radius');
    } catch (Throwable) {
        $rp = ORM::get_db();
    }
    $rp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $root = dirname(__DIR__, 2) . '/install/freeradius/';
    $expected = \Reseller\Domain\CheckConstraints::expectedFromSql((string) file_get_contents($root . 'nuxr_admission.sql'))
        + \Reseller\Domain\CheckConstraints::expectedFromSql((string) file_get_contents($root . 'nuxr_hs_admission.sql'));
    // ALTER-guard strings repeat the CREATE TABLE expressions; the CREATE TABLE ones are authoritative
    $tables = $rp->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('nuxr_admission', 'nuxr_hs_admission')")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff(['nuxr_admission', 'nuxr_hs_admission'], $tables);
    $problems = \Reseller\Domain\CheckConstraints::problems($rp, $isMaria ? 'mariadb' : 'mysql', $expected);
    foreach ($missing as $t) {
        $problems[] = "table {$t} missing (apply install/freeradius/{$t}.sql)";
    }
    if ($isMaria && (string) $rp->query('SELECT @@GLOBAL.check_constraint_checks')->fetchColumn() !== '1') {
        $problems[] = 'GLOBAL check_constraint_checks is not 1 (FreeRADIUS sessions would not enforce the CHECK constraints)';
    }
    $add('radius_checks', $problems === [] ? 'PASS' : 'FAIL', $problems === [] ? 'admission CHECK constraints exact and enforced' : implode('; ', $problems));
});
$try('quota_plans', static function () use ($add, $pdo): void {
    // V1 (owner QUOTA_V1_GB_SUPPORT_APPROVED): a Limited Radius plan with an invalid quota (zero / negative / non-numeric /
    // unknown unit) is refused when saved and never provisioned. Plans saved before V1 may hold such values: their sales
    // fail closed (the voucher / activation is held for the operator), so list them before launch. Read-only.
    $n = 0;
    $bad = 0;
    foreach ($pdo->query("SELECT typebp, limit_type, time_limit, time_unit, data_limit, data_unit FROM tbl_plans WHERE typebp = 'Limited' AND device = 'Radius' AND enabled = 1")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $n++;
        if (\Reseller\Radius\QuotaPolicy::problem($p) !== null) {
            $bad++;
        }
    }
    $add('quota.plans', $bad === 0 ? 'PASS' : 'WARN', "{$n} enabled Limited Radius plan(s), {$bad} with an invalid quota" . ($bad === 0 ? '' : ' (zero / unusable limit or unit: edit the plan in Services, or disable it; selling it fails closed)'));
});
$try('identity', static function () use ($add, $pdo): void {
    $trim = "'^[ \\\\t\\\\n\\\\r\\\\x0B\\\\x00]+|[ \\\\t\\\\n\\\\r\\\\x0B\\\\x00]+$'";
    $k = static fn(string $c): string => "CONVERT(REGEXP_REPLACE({$c}, {$trim}, '') USING utf8mb4) COLLATE utf8mb4_general_ci";
    $sql = "SELECT COUNT(*) FROM (SELECT k FROM (
        SELECT {$k('username')} AS k, CONCAT('c', id) AS src FROM tbl_customers
        UNION ALL SELECT {$k('pppoe_username')}, CONCAT('c', id) FROM tbl_customers WHERE REGEXP_REPLACE(pppoe_username, {$trim}, '') <> ''
        UNION ALL SELECT {$k('code')}, CONCAT('v', id) FROM tbl_voucher WHERE status = 'CLAIMED' OR (status = '1' AND user = '0')
        UNION ALL SELECT {$k('username')}, 'vr' FROM tbl_user_recharges WHERE customer_id = 0) x GROUP BY k HAVING COUNT(DISTINCT src) > 1) y";
    $collisions = (int) $pdo->query($sql)->fetchColumn();
    $add('identity.collisions', $collisions === 0 ? 'PASS' : 'FAIL', "{$collisions} canonical identit" . ($collisions === 1 ? 'y' : 'ies') . ' held by more than one customer / voucher (OBS-1: resolve each before launch; run dev/wp9/identity_survey.sql for the list)');
    $edge = (int) $pdo->query("SELECT COUNT(*) FROM tbl_customers WHERE username REGEXP '^[ \\\\t\\\\n\\\\r\\\\x0B\\\\x00]|[ \\\\t\\\\n\\\\r\\\\x0B\\\\x00]$' OR pppoe_username REGEXP '^[ \\\\t\\\\n\\\\r\\\\x0B\\\\x00]|[ \\\\t\\\\n\\\\r\\\\x0B\\\\x00]$'")->fetchColumn();
    $add('identity.non_canonical', $edge === 0 ? 'PASS' : 'WARN', "{$edge} customer name(s) with edge whitespace / control characters (write-time checks refuse ambiguous use)");
});
$try('shared_key', static function () use ($add, $pdo): void {
    // W8-14: an existing customer with an active Hotspot AND PPPoE service on one RADIUS key (re-provision unconfirmable)
    $n = (int) $pdo->query("SELECT COUNT(*) FROM tbl_customers c WHERE (TRIM(c.pppoe_username) = '' OR LOWER(TRIM(c.pppoe_username)) = LOWER(TRIM(c.username)))
        AND EXISTS (SELECT 1 FROM tbl_user_recharges h WHERE h.customer_id = c.id AND h.type = 'Hotspot' AND h.status = 'on')
        AND EXISTS (SELECT 1 FROM tbl_user_recharges p WHERE p.customer_id = c.id AND p.type = 'PPPOE' AND p.status = 'on')")->fetchColumn();
    $add('shared_key', $n === 0 ? 'PASS' : 'WARN', "{$n} customer(s) with an active Hotspot and PPPoE service on one RADIUS key (set a distinct PPPoE username)");
});
$try('profile', static function () use ($add): void {
    $legacy = \Reseller\Domain\ReleaseProfile::legacyDevicesEnabled();
    $add('profile', $legacy ? 'WARN' : 'PASS', $legacy ? 'legacy MikroTik-API / rlm_rest devices ENABLED: unsupported configuration with open findings' : 'FreeRADIUS-only release profile');
});
$try('plugins', static function () use ($add, $opt): void {
    global $PLUGIN_PATH;
    $files = glob(rtrim((string) $PLUGIN_PATH, '/') . '/*.php') ?: [];
    $approved = $opt('plugins-approved');
    if ($approved === null) {
        $add('plugins', $files === [] ? 'PASS' : 'WARN', count($files) . ' plugin file(s) installed; plugins are fully trusted code - pass --plugins-approved=<inventory> to verify them');
        return;
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/plugins.php') . ' --dir=' . escapeshellarg((string) $PLUGIN_PATH) . ' --expect=' . escapeshellarg($approved);
    exec($cmd, $out, $rc);
    $add('plugins', $rc === 0 ? 'PASS' : 'FAIL', implode(' | ', $out));
});
$try('grants', static function () use ($add, $pdo): void {
    $lines = array_map(static fn(array $r): string => (string) reset($r), $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_ASSOC));
    $bad = [];
    foreach ($lines as $l) {
        if (preg_match('/^GRANT (.+?) ON (\*\.\*|`[^`]+`\.\*|\*) TO/i', $l, $m) && !preg_match('/^USAGE$/i', trim($m[1]))) {
            $bad[] = 'global / database-wide privileges: ' . $m[1] . ' ON ' . $m[2];
        }
        if (preg_match('/^GRANT (.+?) ON `[^`]+`\.`(tbl_manager_ledger|tbl_reseller_audit_events|tbl_reseller_activation_events)`/i', $l, $m)
            && preg_match('/\b(UPDATE|DELETE|ALL)\b/i', $m[1])) {
            $bad[] = $m[2] . ': ' . $m[1];
        }
    }
    $add('grants', $bad === [] ? 'PASS' : 'FAIL', $bad === [] ? 'least-privilege runtime account (append-only tables: SELECT, INSERT)' : implode('; ', array_slice($bad, 0, 5)) . ' - apply system/reseller/grants.php');
});
$try('mac', static function () use ($add): void {
    try {
        $rp = ORM::get_db('radius');
    } catch (Throwable) {
        $rp = ORM::get_db();
    }
    $n = (int) $rp->query("SELECT COUNT(DISTINCT username) FROM radcheck WHERE (username COLLATE utf8mb4_bin) REGEXP '^[0-9a-f]{2}([:-][0-9a-f]{2}){5}$' AND (username COLLATE utf8mb4_bin) REGEXP '[a-f]'")->fetchColumn();
    $add('mac', $n === 0 ? 'PASS' : 'WARN', "{$n} RADIUS username(s) that look like lower-case MAC addresses (MAC-H1A: MAC auth is refused for them)");
});
$try('nas', static function () use ($add, $setup): void {
    // TD-2 (owner decision 2026-10-28): V1 runs the WP8 Option G contract on every NAS - PHPNuxBill NAS type mikrotik_snmp,
    // read-only SNMP and a guard credential entry (install/freeradius/README.md "NAS contract"). With another type (the
    // default "other") the stock layer trusts every open radacct row: a subscriber whose Stop was lost is locked out, and
    // stays locked out after the NAS's own Accounting-On, because the reservation of the lost session can then never be
    // verified. The credential file itself lives on the FreeRADIUS host: check it there with nuxr_guard_selfcheck.
    try {
        $rp = ORM::get_db('radius');
    } catch (Throwable) {
        $rp = ORM::get_db();
    }
    $rows = $rp->query('SELECT nasname, type FROM nas ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    $bad = array_values(array_filter($rows, static fn(array $r): bool => (string) $r[1] !== 'mikrotik_snmp'));
    $list = implode(', ', array_map(static fn(array $r): string => $r[0] . ' (' . ($r[1] === '' || $r[1] === null ? 'no type' : $r[1]) . ')', array_slice($bad, 0, 5)));
    if ($rows === []) {
        // PF-0 (owner decision 2026-10-28): a deployment that requires NAS verification never passes without a NAS
        $add('nas.verification', $setup ? 'WARN' : 'FAIL', 'NOT_CONFIGURED: no NAS configured' . ($setup
            ? ' (initial setup; onboard every NAS as type mikrotik_snmp before the release preflight)'
            : ' - the release preflight requires every NAS of type mikrotik_snmp with live-session verification; use --setup only during the initial installation'));
        return;
    }
    $add('nas.verification', $bad === [] ? 'PASS' : 'FAIL',
        ($bad === [] ? count($rows) . ' NAS, all of type mikrotik_snmp (live-session verification on; confirm the guard with nuxr_guard_selfcheck on the FreeRADIUS host)'
            : count($bad) . ' of ' . count($rows) . " NAS not of type mikrotik_snmp: {$list} - unsupported for V1 (a lost Accounting-Stop locks the subscriber out; set RADIUS > NAS > Type to mikrotik_snmp and add its guard credential entry)"));
});

$finish();
