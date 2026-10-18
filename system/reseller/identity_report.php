<?php
// CLI ONLY: refused before anything else
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * OBS-1 collision report (owner decision D7, 2026-10-18). CLI only, READ ONLY.
 *
 *   php system/reseller/identity_report.php [--json]
 *
 * Runs the canonical-identity survey (system/reseller/identity_survey.sql, the shipped copy of dev/wp9/identity_survey.sql) on the
 * database the application is configured for and prints: the session check, the column collations, every canonical key held by more
 * than one identity (customers across both name fields, owned / unassigned, live vouchers, voucher-only recharges), the stored names whose
 * canonical form differs from the stored bytes, names with interior control characters and RADIUS policy keys without exactly one owner.
 *
 * Run it ONLY on an owner-approved, isolated copy of the data (it prints customer names and ids). It never writes: the session is
 * read-only and every statement must be a SELECT. It does not decide which identity keeps a key - that is the owner's decision
 * (owner-review/WP10/OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md lists the options). Exit: 0 report printed, 3 database unavailable, 5 usage.
 */

define('NUXR_DEFER_PLUGINS', true);
require dirname(__DIR__, 2) . '/init.php';

$json = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--json') {
        $json = true;
    } else {
        fwrite(STDERR, "usage: php system/reseller/identity_report.php [--json]\n");
        exit(5);
    }
}
try {
    $pdo = ORM::get_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
} catch (Throwable $e) {
    fwrite(STDERR, 'database unavailable (ref ' . \Reseller\Security\ErrorReporter::report($e, 'identity-report') . ")\n");
    exit(3);
}
$titles = [
    0 => 'session check (must be OK)', 1 => 'identity column collations', 2 => 'canonical keys held by more than one identity (OBS-1 collisions)',
    3 => 'stored names whose canonical form differs from the stored bytes', 4 => 'names with an interior control character', 5 => 'RADIUS policy keys without exactly one owner',
];
$sql = (string) file_get_contents(__DIR__ . '/identity_survey.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
$report = [];
foreach (preg_split('/;\s*\n/', (string) $sql) as $i => $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') {
        continue;
    }
    $n = count($report);
    if (!preg_match('/^SELECT\b/i', $stmt)) {
        $report[$n] = ['title' => $titles[$n] ?? "statement {$n}", 'error' => 'REFUSED: not a SELECT'];
        continue;
    }
    try {
        $report[$n] = ['title' => $titles[$n] ?? "statement {$n}", 'rows' => $pdo->query($stmt)->fetchAll(PDO::FETCH_ASSOC)];
    } catch (Throwable $e) {
        $report[$n] = ['title' => $titles[$n] ?? "statement {$n}", 'error' => 'ref ' . \Reseller\Security\ErrorReporter::report($e, 'identity-report')];
    }
}
$summary = [
    'collisions' => count($report[2]['rows'] ?? []), 'non_canonical_names' => count($report[3]['rows'] ?? []), 'interior_control_names' => count($report[4]['rows'] ?? []),
    'radius_keys_without_one_owner' => count($report[5]['rows'] ?? []), 'statements_failed' => count(array_filter($report, static fn(array $r): bool => isset($r['error']))),
];
if ($json) {
    echo json_encode(['summary' => $summary, 'sections' => $report], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}
echo "OBS-1 identity collision report (read only)\n";
foreach ($summary as $k => $v) {
    echo str_pad($k, 34) . $v . "\n";
}
foreach ($report as $n => $sec) {
    echo "\n== [{$n}] {$sec['title']}\n";
    if (isset($sec['error'])) {
        echo '   ' . $sec['error'] . "\n";
        continue;
    }
    if ($sec['rows'] === []) {
        echo "   (none)\n";
        continue;
    }
    foreach ($sec['rows'] as $r) {
        echo '   ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
exit(0);
