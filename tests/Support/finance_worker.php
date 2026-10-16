<?php
// CLI-only WP3A concurrency worker (development/test only; never part of a deployment). Runs ONE
// finance operation on its own database connection and prints the outcome as JSON.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\Money;
use Reseller\Finance\PricingService;
use Reseller\Finance\ReservationService;
use Reseller\Finance\SettlementFailure;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Security\ActorContext;

define('NUXR_RUNTIME', true);
$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (preg_match('/^Reseller\\\\(Security|Domain|Audit|Finance|Radius|Activation)\\\\[A-Za-z0-9]+$/', $class)) {
        $file = $root . '/system/autoload/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
require_once $root . '/system/orm.php';
// WP5B: the legacy classes and device path the bounded re-provision uses
$GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
$GLOBALS['_app_stage'] = 'Live';
$GLOBALS['legacy_devices_enabled'] = true; // WP9 release profile: the accepted legacy-driver contracts opt in
require_once $root . '/system/autoload/Package.php';
require_once $root . '/system/autoload/Text.php';

$op = json_decode((string) ($argv[2] ?? ''), true);
$pdo = new PDO('mysql:host=' . getenv('WP1_DB_HOST') . ';charset=utf8mb4;dbname=' . ($argv[1] ?? ''), 'root', (string) getenv('WP1_DB_ROOT_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => true,
]);
ORM::set_db($pdo);
ORM::set_db($pdo, 'radius'); // WP5A: the FreeRADIUS schema lives in the same test database
ini_set('error_log', '/dev/null');

$staff = static function (int $id) use ($pdo): ActorContext {
    $st = $pdo->prepare('SELECT * FROM tbl_users WHERE id = ?');
    $st->execute([$id]);
    return ActorContext::forStaff($st->fetch(PDO::FETCH_ASSOC), 'CLI');
};
if (isset($op['go_file'])) {
    $deadline = microtime(true) + 30;
    while (!is_file($op['go_file']) && microtime(true) < $deadline) {
        usleep(1000);
    }
}
if (isset($op['pause_at'], $op['pause_file'])) {
    // deterministic barrier inside a writer: at the named fault point (locks held, not committed) signal
    // "<pause_file>.entered" and wait until the test removes pause_file
    \Reseller\Finance\FinanceFaults::$hook = static function (string $point) use ($op): void {
        if ($point !== $op['pause_at']) {
            return;
        }
        touch($op['pause_file'] . '.entered');
        $deadline = microtime(true) + 30;
        while (is_file($op['pause_file']) && microtime(true) < $deadline) {
            usleep(2000);
            clearstatcache();
        }
    };
}
if (isset($op['pause_activation_at'], $op['pause_file'])) {
    // WP9-B / R-4: the same deterministic barrier at an activation fault point (e.g. activation.before_commit)
    \Reseller\Activation\ActivationFaults::$hook = static function (string $point) use ($op): void {
        if ($point !== $op['pause_activation_at']) {
            return;
        }
        touch($op['pause_file'] . '.entered');
        $deadline = microtime(true) + 60;
        while (is_file($op['pause_file']) && microtime(true) < $deadline) {
            usleep(2000);
            clearstatcache();
        }
    };
}
try {
    $r = match ($op['op']) {
        'fund' => TransferService::fund($staff($op['actor']), $op['manager'], $op['amount'], 'concurrency funding', $op['reference']),
        'withdraw' => TransferService::withdraw($staff($op['actor']), $op['manager'], $op['amount'], 'concurrency withdrawal', $op['reference']),
        'transfer' => TransferService::transferToChild($staff($op['actor']), $op['from'], $op['to'], $op['amount'], 'concurrency transfer', $op['reference']),
        'debt_limit' => WalletService::setDebtLimit($staff($op['actor']), $op['manager'], $op['limit'], 'concurrency debt limit'),
        // WP6B fix round 2: the reviewed (compare-and-set) debt-limit writer
        'debt_limit_if_current' => WalletService::setDebtLimitIfCurrent($staff($op['actor']), $op['manager'], $op['limit'], $op['expected'], 'reviewed debt limit'),
        'reverse' => TransferService::reverse($staff($op['actor']), $op['transfer'], 'concurrency reversal', $op['reference']),
        'reserve' => ReservationService::reserve((int) $op['activation'], (int) $op['manager'], Money::parsePositive($op['amount'])),
        'commit' => ReservationService::commit((int) $op['reservation'], FinanceActor::system('wp3a-concurrency')),
        'release' => ReservationService::release((int) $op['reservation'], FinanceActor::system('wp3a-concurrency'), 'confirmed unused'),
        'move' => ResellerTreeService::moveSubtree($staff($op['actor']), (int) $op['node'], $op['parent'] === null ? null : (int) $op['parent']),
        // WP3B pricing / settlement operations
        'adjust_credit' => TransferService::adjustCredit($staff($op['actor']), $op['manager'], $op['amount'], 'concurrency adjustment', $op['reference']),
        'adjust_debit' => TransferService::adjustDebit($staff($op['actor']), $op['manager'], $op['amount'], 'concurrency adjustment', $op['reference']),
        'commit_staff' => ReservationService::commit((int) $op['reservation'], FinanceActor::staff($staff($op['actor']))),
        'set_wholesale' => PricingService::setChildWholesale($staff($op['actor']), $op['manager'], $op['plan'], $op['cost']),
        'set_retail' => PricingService::setRetail($staff($op['actor']), $op['manager'], $op['plan'], $op['price']),
        'set_availability' => PricingService::setChildAvailability($staff($op['actor']), $op['manager'], $op['plan'], $op['enabled']),
        'set_base' => PricingService::setBaseCost($staff($op['actor']), $op['plan'], $op['cost']),
        // WP5A activation and the plan-delete race partner
        'activate' => \Reseller\Activation\ActivationService::create($staff($op['actor']), $op['customer'], $op['plan'], $op['action'], $op['nonce']),
        // WP5B reversal and bounded re-provision race partners
        'reverse_activation' => \Reseller\Activation\ActivationReversalService::reverse($staff($op['actor']), $op['reference'], $op['reason']),
        'reprovision' => \Reseller\Domain\LegacyReprovisionService::run($staff($op['actor']), (int) $op['customer'], $op['reprovision'], $op['requested'] ?? []),
        'reprovision_customer' => \Reseller\Domain\LegacyReprovisionService::run((static function (int $id) use ($pdo): ActorContext {
            $st = $pdo->prepare('SELECT * FROM tbl_customers WHERE id = ?');
            $st->execute([$id]);
            return ActorContext::forCustomer($st->fetch(PDO::FETCH_ASSOC), 'CLI');
        })((int) $op['customer']), (int) $op['customer'], $op['reprovision'], $op['requested'] ?? []),
        'plan_delete' => \Reseller\Domain\PlanGuard::deleteSerialized((int) $op['plan'], static function () use ($op): void {
            ORM::for_table('tbl_plans')->find_one((int) $op['plan'])->delete();
        }) ?? 'DELETED',
    };
    $out = ['ok' => true, 'result' => $r instanceof JsonSerializable ? $r->jsonSerialize() : $r];
} catch (DomainError $e) {
    $out = ['ok' => false, 'reason' => $e->reason];
} catch (SettlementFailure $e) {
    $out = ['ok' => false, 'reason' => 'SETTLEMENT_' . $e->kind . ':' . $e->reason];
} catch (Throwable $e) {
    $out = ['ok' => false, 'reason' => 'ERROR', 'error' => get_class($e) . ': ' . $e->getMessage()];
}
echo "\n@@FINANCE@@" . json_encode($out) . "\n";
