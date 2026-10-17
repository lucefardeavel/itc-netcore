<?php
// CLI ONLY: refused before anything else
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * WP10 minimum operational telemetry (Architecture Lock AD-21, REL-05, N-09 / R-021). CLI ONLY, READ-ONLY.
 *
 *   php system/reseller/health.php [--json] [--finance]      (for cron / the monitoring agent, e.g. every 5 minutes)
 *
 * Exit codes follow the monitoring-plugin convention: 0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN (the check itself failed).
 * Plugins are not loaded. Every metric is a count or an age; no record content, no secret is printed.
 *
 *  db                 database reachable, REPEATABLE-READ, UTC session, clock skew (WARN > 5 s)        CRITICAL > 30 s
 *  schema             reseller schema ready (incl. CHECK expressions / enforcement)                     CRITICAL
 *  activations_stale  non-terminal activations not updated for 5 / 30 minutes                          WARN / CRIT
 *  reconciliation     activations in RECONCILIATION_REQUIRED (funds held)                                WARN
 *  reservations       oldest ACTIVE wallet reservation (age; N-09: never expire on their own)           WARN > 1 h, CRIT > 24 h
 *  admission_pending  PPPoE claims / Hotspot slots PENDING for more than 10 minutes (locked-out subscribers)  WARN
 *  vouchers_review    vouchers left CLAIMED (operator review)                                           WARN
 *  balance_held       legacy balance operations HELD                                                    WARN
 *  accounting         newest radacct update (accounting freshness; WARN when > 15 minutes while sessions are open)
 *  nas_clock          open sessions whose accounting stamp is > 120 s in the future (a NAS clock that is ahead)       WARN
 *  coa_failures       Disconnect / CoA results that were not SUCCESS in the last hour (activation annotations)  WARN
 *  quota_enforcer     quota subscribers online and the quota enforcer silent > 3 min (CRIT); over-quota open session (WARN)
 *  finance (--finance) whole-database finance integrity (DriftReport::financeIntegrity; expensive, run hourly)         CRITICAL
 */

define('NUXR_DEFER_PLUGINS', true);
require dirname(__DIR__, 2) . '/init.php';

$args = array_slice($argv, 1);
$checks = [];
$set = static function (string $name, int $level, string $detail, ?float $value = null) use (&$checks): void {
    $checks[] = ['check' => $name, 'level' => $level, 'status' => ['OK', 'WARNING', 'CRITICAL', 'UNKNOWN'][$level], 'value' => $value, 'detail' => $detail];
};
$run = static function (string $name, callable $fn) use ($set): void {
    try {
        $fn();
    } catch (Throwable $e) {
        $set($name, 3, 'check failed (ref ' . \Reseller\Security\ErrorReporter::report($e, 'health') . ')');
    }
};
$pdo = ORM::get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static fn(string $sql, array $p = []): mixed => (static function () use ($pdo, $sql, $p) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
})();
$radius = static function () use ($pdo): PDO {
    try {
        $r = ORM::get_db('radius');
        $r->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $r;
    } catch (Throwable) {
        return $pdo;
    }
};

$run('db', static function () use ($set, $q): void {
    $maria = stripos((string) $q('SELECT VERSION()'), 'mariadb') !== false;
    $iso = (string) $q('SELECT @@SESSION.' . ($maria ? 'tx_isolation' : 'transaction_isolation'));
    $off = (int) $q('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
    $skew = abs((int) $q('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())') - time());
    $ok = $iso === 'REPEATABLE-READ' && $off === 0 && $skew <= 30;
    $set('db', $ok ? ($skew > 5 ? 1 : 0) : 2, "isolation={$iso} session_utc_offset={$off}s clock_skew={$skew}s");
});
$run('schema', static function () use ($set): void {
    \Reseller\Domain\SchemaReadiness::reset();
    $st = \Reseller\Domain\SchemaReadiness::status();
    $set('schema', $st['ready'] ? 0 : 2, $st['ready'] ? 'ready' : count($st['problems']) . ' problem(s)');
});
$run('activations_stale', static function () use ($set, $pdo): void {
    // read through the activation store (the only approved reader of the activation table besides reconciliation)
    $five = \Reseller\Activation\ActivationStore::staleCount($pdo, gmdate('Y-m-d H:i:s', time() - 300));
    $thirty = \Reseller\Activation\ActivationStore::staleCount($pdo, gmdate('Y-m-d H:i:s', time() - 1800));
    $set('activations_stale', $thirty > 0 ? 2 : ($five > 0 ? 1 : 0), "{$five} stale > 5 min, {$thirty} stale > 30 min (reconcile.php stale)", (float) $five);
});
$run('reconciliation', static function () use ($set, $pdo): void {
    $n = \Reseller\Activation\ActivationStore::statusCounts($pdo, '1 = 1', [])[\Reseller\Activation\ActivationState::RECONCILIATION_REQUIRED] ?? 0;
    $set('reconciliation', $n > 0 ? 1 : 0, "{$n} activation(s) need reconciliation (funds held)", (float) $n);
});
$run('reservations', static function () use ($set, $q): void {
    $age = $q("SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), UTC_TIMESTAMP(6)) FROM tbl_manager_wallet_reservations WHERE status = 'ACTIVE'");
    $age = $age === null ? 0 : (int) $age;
    $set('reservations', $age > 86400 ? 2 : ($age > 3600 ? 1 : 0), "oldest ACTIVE reservation {$age}s", (float) $age);
});
$run('admission_pending', static function () use ($set, $radius): void {
    $r = $radius();
    $n = (int) $r->query("SELECT COUNT(*) FROM nuxr_admission WHERE state = 'PENDING' AND created_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE")->fetchColumn();
    $h = (int) $r->query("SELECT COUNT(*) FROM nuxr_hs_admission WHERE state = 'PENDING' AND last_acct_at IS NULL AND created_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE")->fetchColumn();
    $set('admission_pending', $n + $h > 0 ? 1 : 0, "{$n} PPPoE claim(s) PENDING > 10 min (reconcile.php admission-pending), {$h} Hotspot slot(s) PENDING > 10 min (nuxr_hs_admission_report.sql)", (float) ($n + $h));
});
$run('vouchers_review', static function () use ($set, $q): void {
    $n = (int) $q("SELECT COUNT(*) FROM tbl_voucher WHERE status = 'CLAIMED'");
    $set('vouchers_review', $n > 0 ? 1 : 0, "{$n} voucher(s) CLAIMED (reconcile.php voucher-review)", (float) $n);
});
$run('balance_held', static function () use ($set): void {
    $n = count(\Reseller\Domain\LegacyCustomerBalanceService::held());
    $set('balance_held', $n > 0 ? 1 : 0, "{$n} legacy balance operation(s) HELD", (float) $n);
});
$run('accounting', static function () use ($set, $radius): void {
    $r = $radius();
    $open = (int) $r->query('SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL')->fetchColumn();
    $age = $r->query('SELECT TIMESTAMPDIFF(SECOND, MAX(COALESCE(acctupdatetime, acctstarttime)), UTC_TIMESTAMP()) FROM radacct WHERE acctstoptime IS NULL')->fetchColumn();
    $age = $age === null ? 0 : (int) $age;
    $set('accounting', $open > 0 && $age > 900 ? 1 : 0, "{$open} open session(s), newest accounting update {$age}s ago", (float) $age);
});
$run('nas_clock', static function () use ($set, $radius): void {
    // N2: quota attribution no longer uses the NAS clock (a period owns the rows that arrived after it started), but accounting
    // times (display, freshness, the time-rule fallback) do. A NAS whose stamps lie more than 120 s in the future is ahead.
    $n = (int) $radius()->query('SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) > UTC_TIMESTAMP() + INTERVAL 120 SECOND')->fetchColumn();
    $set('nas_clock', $n > 0 ? 1 : 0, "{$n} open session(s) with an accounting stamp more than 120 s in the future (a NAS clock that is ahead: check NTP on the NAS)", (float) $n);
});
$run('coa_failures', static function () use ($set, $q): void {
    $n = (int) $q("SELECT COUNT(*) FROM tbl_reseller_activation_events WHERE event_type = 'ANNOTATION' AND created_at > UTC_TIMESTAMP(6) - INTERVAL 1 HOUR
        AND metadata LIKE '%\"disconnect\"%' AND (metadata LIKE '%UNKNOWN%' OR metadata LIKE '%FAILED%' OR metadata LIKE '%ERROR%')");
    $set('coa_failures', $n > 0 ? 1 : 0, "{$n} Disconnect / CoA outcome(s) not confirmed in the last hour (drift SESSION_POLICY_STALE)", (float) $n);
});
$run('quota_enforcer', static function () use ($set, $radius, $q): void {
    // V1 data / time quota: the accounting enforcer (system/reseller/quota.php, cron every minute) must be alive while
    // quota subscribers are online; an over-quota open session is a WARNING (ended within one pass normally)
    $r = $radius();
    $online = (int) $r->query("SELECT COUNT(DISTINCT a.username) FROM radacct a JOIN radcheck q ON q.username = a.username AND q.attribute IN ('Max-Data', 'Max-All-Session') WHERE a.acctstoptime IS NULL")->fetchColumn();
    $beat = $q("SELECT value FROM tbl_appconfig WHERE setting = 'nuxr_quota_enforcer_heartbeat'");
    $age = $beat === false || $beat === null ? null : (int) $q('SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())', [(string) $beat]);
    $over = count((new \Reseller\Radius\QuotaEnforcer($r))->overQuota());
    $dead = $online > 0 && ($age === null || $age > 180);
    $set('quota_enforcer', $dead ? 2 : ($over > 0 ? 1 : 0), "{$online} quota subscriber(s) online, enforcer last pass " . ($age === null ? 'never' : "{$age}s ago") . ", {$over} over quota with an open session (quota.php report)", (float) $over);
});
if (in_array('--finance', $args, true)) {
    $run('finance', static function () use ($set): void {
        $issues = \Reseller\Activation\DriftReport::financeIntegrity();
        $set('finance', $issues === [] ? 0 : 2, $issues === [] ? 'consistent' : count($issues) . ' integrity issue(s): reconcile.php finance-check', (float) count($issues));
    });
}

$worst = max(array_column($checks, 'level'));
if (in_array('--json', $args, true)) {
    echo json_encode(['status' => ['OK', 'WARNING', 'CRITICAL', 'UNKNOWN'][$worst], 'checks' => $checks], JSON_UNESCAPED_SLASHES) . "\n";
} else {
    foreach ($checks as $c) {
        printf("%-8s %-18s %s\n", $c['status'], $c['check'], $c['detail']);
    }
}
exit($worst);
