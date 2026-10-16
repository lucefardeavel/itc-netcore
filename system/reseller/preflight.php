<?php
/**
 * WP9 / WP10 release preflight of ONE ISP instance. CLI ONLY, READ-ONLY (it never writes).
 *
 *   php system/reseller/preflight.php [--plugins-approved=<inventory file>] [--json]
 *
 * Every check prints PASS / WARN / FAIL with its evidence. Exit 0 when no check FAILs, 4 otherwise. Plugins are NOT
 * loaded (a preflight must not run unreviewed code). Checks:
 *  keys            $app_secret (NEW-7) valid; $api_secret valid (WARN when the API is not configured)
 *  stage           live stage, no display_errors, HTTPS application URL (WARN)
 *  engine          MariaDB 10.11 / MySQL 8.0 (the tested policy; WARN otherwise)
 *  isolation       REPEATABLE-READ globally and for this session (W8-18 deployment contract)
 *  time            SQL session time zone UTC (+00:00) (P-A4-1) and database / PHP clock skew < 5 s (NTP, P-A4-2)
 *  schema          SchemaReadiness (migrations, tables, CHECK expressions and enforcement - WP1-CHECK)
 *  radius_checks   nuxr_admission / nuxr_hs_admission CHECK constraints: exact expressions and enforcement, also the
 *                  GLOBAL MariaDB check_constraint_checks used by the FreeRADIUS sessions (A2-R hardening L4)
 *  identity        OBS-1 survey on this database: no canonical identity with more than one holder (FAIL), stored names
 *                  that are not canonical (WARN)
 *  profile         release profile: legacy MikroTik-API / rlm_rest devices disabled (WARN when enabled)
 *  plugins         installed plugin files; with --plugins-approved: no unknown / modified / missing file (B2)
 *  grants          the runtime account is least-privilege: no global privileges, no UPDATE / DELETE on the ledger and
 *                  audit tables (WP10 LPDB; FAIL for an administrative account such as root)
 *  mac             MAC-auth impact (MAC-H1A): RADIUS usernames that look like lower-case MAC addresses (WARN)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('NUXR_DEFER_PLUGINS', true);
require dirname(__DIR__, 2) . '/init.php';

$args = array_slice($argv, 1);
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
$pdo = ORM::get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$one = static fn(string $sql, array $p = []): mixed => (static function () use ($pdo, $sql, $p) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
})();
$isMaria = stripos((string) $one('SELECT VERSION()'), 'mariadb') !== false;
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

$fail = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL'));
if (in_array('--json', $args, true)) {
    echo json_encode(['preflight' => $fail === 0 ? 'PASS' : 'FAIL', 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    foreach ($results as $r) {
        printf("%-5s %-26s %s\n", $r['status'], $r['check'], $r['detail']);
    }
    echo 'preflight=' . ($fail === 0 ? 'PASS' : 'FAIL') . " ({$fail} failing check(s))\n";
}
exit($fail === 0 ? 0 : 4);
