<?php
// CLI ONLY: refused before anything else (the usage below is long; the guard stays first)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Activation reconciliation tool (WP5A, owner decision A4). CLI ONLY; acts as the SYSTEM actor.
 *
 * Nothing here is automatic: `stale` only reports; every other command acts on one activation reference.
 *
 * Usage:
 *   php system/reseller/reconcile.php stale [--seconds=300]      list non-terminal activations not updated for N seconds
 *   php system/reseller/reconcile.php show <ACT-...>             state and event timeline
 *   php system/reseller/reconcile.php reconcile <ACT-...> [--stale-seconds=300]   (WP9: at least 300)
 *                       delivery already verified -> retry the settlement only (RADIUS is not consulted);
 *                       never verified -> RADIUS == target: settle once; == captured prior state: release,
 *                       FAILED (NOT_APPLIED); anything else: EXTERNAL_STATE_AMBIGUOUS, nothing changed (exit 4)
 *   php system/reseller/reconcile.php resume <ACT-...>           continue a STALE FUNDS_RESERVED / RETRY_PENDING / VERIFIED (WP9)
 *   php system/reseller/reconcile.php rollback <ACT-...> --operator=<name> --reason=<text>
 *                       restore the captured prior RADIUS state; release only once verified (audited: operator, reason)
 *   php system/reseller/reconcile.php cancel <ACT-...> --operator=<name> --reason=<text>
 *                       before provisioning, STALE only (>= 300 s): release, CANCELLED (audited: operator, reason)
 *
 * WP5B operator commands (read-only unless stated):
 *   php system/reseller/reconcile.php drift [--no-radius]        detective report: periods not backed by an activation,
 *                       RADIUS drift, paid legacy payments of reseller-owned customers, CLAIMED vouchers,
 *                       REPROVISION_UNCONFIRMED customers, HELD balance operations (exit 4 when anything is found)
 *   php system/reseller/reconcile.php expired-autorenew         AUTO_RENEW_UNAVAILABLE records (reseller-owned, not renewed)
 *   php system/reseller/reconcile.php balance-held              HELD legacy balance operations
 *   php system/reseller/reconcile.php balance-resolve <id> <completed|refund> --operator=<name> --reason=<text>
 *                       operator decision on one HELD operation (exactly once, audited in the same transaction; refund credits back)
 *
 * WP9-OPS recovery commands (runbook: docs/OPERATOR_RECOVERY_RUNBOOK.md):
 *   php system/reseller/reconcile.php finance-check             read-only finance + activation integrity (exit 4 = issues)
 *   php system/reseller/reconcile.php voucher-review            vouchers left CLAIMED (review) with their grant evidence
 *   php system/reseller/reconcile.php voucher-resolve <id> <confirm|deny|release> --operator=<name> --reason=<text>
 *   php system/reseller/reconcile.php session-end <ACT-...>      re-send the Disconnect of a plan change (drift SESSION_POLICY_STALE)
 *   php system/reseller/reconcile.php admission-pending         unresolved PPPoE admission claims (PENDING)
 *   php system/reseller/reconcile.php admission-release <key hex> <token> --operator=<name> --reason=<text> --evidence=<NAS proof>
 *
 * Exit codes: 0 OK, 2 refused (state / not found), 3 failed, 4 external state ambiguous (funds held), 5 usage.
 */

require dirname(__DIR__, 2) . '/init.php';

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationService;
use Reseller\Activation\ActivationStore;
use Reseller\Activation\ReconciliationService;
use Reseller\Domain\DomainError;

$args = array_slice($argv, 1);
$command = $args[0] ?? '';
$reference = $args[1] ?? '';
$option = static function (string $name, int $default) use ($args): int {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=") && ctype_digit(substr($a, strlen($name) + 3))) {
            return (int) substr($a, strlen($name) + 3);
        }
    }
    return $default;
};
$actor = ActivationActor::system('wp5a-reconcile');
// WP9-OPS: --name=value string options of the recovery commands
$text = static function (string $name) use ($args): string {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return '';
};
$print = static function (array $row): void {
    echo json_encode(['reference' => $row['reference'], 'status' => $row['status'], 'action' => $row['action'],
        'error_code' => $row['error_code']], JSON_UNESCAPED_SLASHES) . "\n";
};

try {
    switch ($command) {
        case 'stale':
            foreach (ReconciliationService::stale($option('seconds', ReconciliationService::STALE_SECONDS)) as $r) {
                echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
            }
            exit(0);
        case 'show':
            $row = ActivationStore::byReference(\ORM::get_db(), $reference) ?? throw new DomainError('NOT_FOUND');
            $print($row);
            foreach (ActivationStore::events(\ORM::get_db(), (int) $row['id']) as $e) {
                echo '  #' . $e['sequence_no'] . ' ' . $e['event_type'] . ' ' . ($e['from_state'] ?? '-') . ' -> ' . $e['to_state']
                    . ($e['provisioning_result'] !== null ? ' [' . $e['provisioning_result'] . ']' : '') . ($e['error_code'] !== null ? ' ' . $e['error_code'] : '') . "\n";
            }
            exit(0);
        case 'reconcile':
            // WP9-B / N-06: the operator tool never reconciles a live in-flight activation (the 300 s guard is a floor)
            $staleSeconds = $option('stale-seconds', ReconciliationService::STALE_SECONDS);
            if ($staleSeconds < ReconciliationService::STALE_SECONDS) {
                fwrite(STDERR, 'refused: --stale-seconds must be at least ' . ReconciliationService::STALE_SECONDS . " (live activations are never reconciled)\n");
                exit(5);
            }
            $row = ReconciliationService::reconcile($actor, $reference, $staleSeconds);
            $print($row);
            if ($row['error_code'] === ReconciliationService::AMBIGUOUS) {
                fwrite(STDERR, ReconciliationService::AMBIGUOUS . ': ' . ReconciliationService::AMBIGUOUS_MESSAGE . "\n");
                exit(4);
            }
            exit(0);
        case 'resume':
            $row = ActivationStore::byReference(\ORM::get_db(), $reference) ?? throw new DomainError('NOT_FOUND');
            // WP9-B / N-06: resume only a STALE activation (same rule as the web operator resume, OD-7)
            if (!in_array($reference, array_column(ReconciliationService::stale(), 'reference'), true)) {
                fwrite(STDERR, 'refused: the activation is not stale (updated less than ' . ReconciliationService::STALE_SECONDS . " seconds ago, or terminal)\n");
                exit(2);
            }
            $print(ActivationService::execute((int) $row['id'], $actor));
            exit(0);
        case 'rollback':
        case 'cancel':
            // final QA F-1: a release of held funds is an operator decision, audited with who and why
            if (strlen(trim($text('operator'))) < 2 || strlen(trim($text('reason'))) < 10) {
                fwrite(STDERR, "usage: php system/reseller/reconcile.php {$command} <ACT-...> --operator=<name> --reason=<text, at least 10 characters>\n");
                exit(5);
            }
            $operator = ActivationActor::operator($text('operator'), $text('reason'));
            $print($command === 'rollback' ? ReconciliationService::rollback($operator, $reference)
                // the web operator rule (OD-7): only a STALE activation is cancelled; checked again on the locked row
                : ReconciliationService::cancel($operator, $reference, ReconciliationService::STALE_SECONDS));
            exit(0);
        case 'drift':
            $found = \Reseller\Activation\DriftReport::collect(!in_array('--no-radius', $args, true));
            foreach ($found as $f) {
                echo json_encode($f, JSON_UNESCAPED_SLASHES) . "\n";
            }
            exit($found === [] ? 0 : 4);
        case 'expired-autorenew':
            foreach (\Reseller\Activation\DriftReport::autoRenewUnavailable() as $r) {
                echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
            }
            exit(0);
        case 'balance-held':
            foreach (\Reseller\Domain\LegacyCustomerBalanceService::held() as $r) {
                echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
            }
            exit(0);
        case 'balance-resolve':
            if (!ctype_digit($reference) || !in_array($args[2] ?? '', ['completed', 'refund'], true)
                || strlen(trim($text('operator'))) < 2 || strlen(trim($text('reason'))) < 10) {
                fwrite(STDERR, "usage: php system/reseller/reconcile.php balance-resolve <journal id> <completed|refund> --operator=<name> --reason=<text, at least 10 characters>\n");
                exit(5);
            }
            echo json_encode(['journal_id' => (int) $reference, 'resolved' => \Reseller\Domain\LegacyCustomerBalanceService::resolve((int) $reference, $args[2], $text('operator'), $text('reason'))]) . "\n";
            exit(0);
        // ---- WP9-OPS recovery commands (each decision exactly once, audited, nothing deleted)
        case 'finance-check':
            // read-only whole-database finance + activation integrity (tamper detector); exit 4 when anything is found
            $issues = \Reseller\Activation\DriftReport::financeIntegrity();
            foreach ($issues as $i) {
                echo json_encode(['issue' => $i], JSON_UNESCAPED_SLASHES) . "\n";
            }
            echo json_encode(['finance_check' => $issues === [] ? 'CONSISTENT' : 'ISSUES', 'count' => count($issues)]) . "\n";
            exit($issues === [] ? 0 : 4);
        case 'voucher-review':
            foreach (\Reseller\Domain\VoucherRedemptionService::reviewList() as $r) {
                echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
            }
            exit(0);
        case 'voucher-resolve':
            if (!ctype_digit($reference) || !in_array($args[2] ?? '', ['confirm', 'deny', 'release'], true)) {
                fwrite(STDERR, "usage: php system/reseller/reconcile.php voucher-resolve <voucher id> <confirm|deny|release> --operator=<name> --reason=<text>\n");
                exit(5);
            }
            echo json_encode(\Reseller\Domain\VoucherRedemptionService::reviewResolve((int) $reference, $args[2], $text('operator'), $text('reason'))) . "\n";
            exit(0);
        case 'session-end':
            // WP9 lock gate P1: re-send the Disconnect of a plan change whose running session kept the old policy
            echo json_encode(['reference' => $reference, 'disconnect' => ActivationService::endStaleSessions($reference, $actor)]) . "\n";
            exit(0);
        case 'admission-pending':
            foreach (\Reseller\Radius\AdmissionRecovery::pending() as $r) {
                echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
            }
            exit(0);
        case 'admission-release':
            echo json_encode(\Reseller\Radius\AdmissionRecovery::release($reference, (string) ($args[2] ?? ''), $text('operator'), $text('reason'), $text('evidence'))) . "\n";
            exit(0);
        default:
            fwrite(STDERR, "usage: php system/reseller/reconcile.php <stale|show|reconcile|resume|rollback|cancel|drift|expired-autorenew|balance-held|balance-resolve|finance-check|session-end|voucher-review|voucher-resolve|admission-pending|admission-release> [ACT-reference|id] [--seconds=N]\n");
            exit(5);
    }
} catch (DomainError $e) {
    fwrite(STDERR, 'refused: ' . $e->reason . ' ' . $e->getMessage() . "\n");
    exit(2);
} catch (\Throwable $e) {
    $ref = \Reseller\Security\ErrorReporter::report($e, 'reconcile-cli');
    fwrite(STDERR, 'failed: ref ' . $ref . "\n");
    exit(3);
}
