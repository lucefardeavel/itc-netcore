<?php
/**
 * Activation reconciliation tool (WP5A, owner decision A4). CLI ONLY; acts as the SYSTEM actor.
 *
 * Nothing here is automatic: `stale` only reports; every other command acts on one activation reference.
 *
 * Usage:
 *   php system/reseller/reconcile.php stale [--seconds=300]      list non-terminal activations not updated for N seconds
 *   php system/reseller/reconcile.php show <ACT-...>             state and event timeline
 *   php system/reseller/reconcile.php reconcile <ACT-...> [--stale-seconds=300]
 *                       delivery already verified -> retry the settlement only (RADIUS is not consulted);
 *                       never verified -> RADIUS == target: settle once; == captured prior state: release,
 *                       FAILED (NOT_APPLIED); anything else: EXTERNAL_STATE_AMBIGUOUS, nothing changed (exit 4)
 *   php system/reseller/reconcile.php resume <ACT-...>           continue from FUNDS_RESERVED / RETRY_PENDING / VERIFIED
 *   php system/reseller/reconcile.php rollback <ACT-...>         restore the captured prior RADIUS state; release only once verified
 *   php system/reseller/reconcile.php cancel <ACT-...>           before provisioning: release, CANCELLED
 *
 * Exit codes: 0 OK, 2 refused (state / not found), 3 failed, 4 external state ambiguous (funds held), 5 usage.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
            $row = ReconciliationService::reconcile($actor, $reference, $option('stale-seconds', ReconciliationService::STALE_SECONDS));
            $print($row);
            if ($row['error_code'] === ReconciliationService::AMBIGUOUS) {
                fwrite(STDERR, ReconciliationService::AMBIGUOUS . ': ' . ReconciliationService::AMBIGUOUS_MESSAGE . "\n");
                exit(4);
            }
            exit(0);
        case 'resume':
            $row = ActivationStore::byReference(\ORM::get_db(), $reference) ?? throw new DomainError('NOT_FOUND');
            $print(ActivationService::execute((int) $row['id'], $actor));
            exit(0);
        case 'rollback':
            $print(ReconciliationService::rollback($actor, $reference));
            exit(0);
        case 'cancel':
            $print(ReconciliationService::cancel($actor, $reference));
            exit(0);
        default:
            fwrite(STDERR, "usage: php system/reseller/reconcile.php <stale|show|reconcile|resume|rollback|cancel> [ACT-reference] [--seconds=N]\n");
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
