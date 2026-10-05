<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\WalletService;
use Reseller\Tests\Support\FinanceTestCase;
use Reseller\Tests\Support\FinanceWorkers;

/**
 * WP6B owner fix round 2: the reviewed debt-limit write is a compare-and-set inside the single accepted writer.
 * WalletService::setDebtLimitIfCurrent compares the expected (reviewed) current limit with the limit of the wallet row it
 * has locked FOR UPDATE, inside the tree-locked transaction, before creating, validating, updating or auditing anything.
 * Proven on the writer itself (no Backoffice pre-read involved), including a deterministic interleaving in which the
 * expected value is still the committed value when the writer is called and only changes while the writer waits for
 * its locks. The unconditional setDebtLimit keeps its accepted behaviour.
 */
final class DebtLimitReviewedWriterTest extends FinanceTestCase
{
    private function limit(int $id): ?string
    {
        $w = $this->wallet($id);
        return $w === null ? null : (string) $w['debt_limit'];
    }

    private function audits(int $id): int
    {
        return $this->rowCount('tbl_reseller_audit_events', "event_type = 'finance.debt_limit' AND target_user_id = ?", [$id]);
    }

    public function testTheWriterRefusesAStaleExpectedCurrentAndWritesNothing(): void
    {
        $id = $this->agent();
        WalletService::setDebtLimit($this->ctx('ad'), $id, '50', 'opening credit line');
        // another accepted operation moves the limit to 200 after a review classified 50 -> 100 as an increase
        WalletService::setDebtLimit($this->ctx('ad'), $id, '200', 'raised by another operator');
        $this->refused('DEBT_LIMIT_CHANGED', fn() => WalletService::setDebtLimitIfCurrent($this->ctx('ad'), $id, '100', '50.00', 'reviewed as an increase'));
        $this->assertSame('200.00', $this->limit($id), 'the limit stays 200');
        $this->assertSame(2, $this->audits($id), 'no audit event for a refused write');
        $this->assertSame(0, $this->rowCount('tbl_manager_ledger', 'manager_id = ?', [$id]), 'never a ledger entry');
        $this->assertSame(0, $this->rowCount('tbl_manager_transfers', 'from_manager_id = ? OR to_manager_id = ?', [$id, $id]));
        // an absent wallet is current 0.00: a different expectation refuses without creating the wallet
        $fresh = $this->agent();
        $this->refused('DEBT_LIMIT_CHANGED', fn() => WalletService::setDebtLimitIfCurrent($this->ctx('ad'), $fresh, '30', '5', 'stale'));
        $this->assertNull($this->wallet($fresh), 'no wallet created by a refused write');
        $v = WalletService::setDebtLimitIfCurrent($this->ctx('ad'), $fresh, '30', '0.00', 'opening line, reviewed against 0.00');
        $this->assertSame(['30.00', true], [$this->limit($fresh), $v->exists], 'expected 0.00 matches an absent wallet: the accepted rules open it');
    }

    public function testMatchingExpectationAppliesTheAcceptedRulesUnchanged(): void
    {
        $id = $this->funded('20');
        $v = WalletService::setDebtLimitIfCurrent($this->ctx('ad'), $id, '50', '0', 'increase, reviewed against 0');
        $this->assertSame(['50.00', '70.00'], [$this->limit($id), $v->available()->format()]);
        $this->assertSame(1, $this->audits($id));
        // expose 60 of credit (balance 20 + line 50, reserve 60), then the exposure floor applies exactly as in setDebtLimit
        ReservationService::reserve($this->activation($id, '60'), $id, Money::parsePositive('60'));
        $this->refused('DEBT_LIMIT_EXPOSURE', fn() => WalletService::setDebtLimitIfCurrent($this->ctx('ad'), $id, '39.99', '50.00', 'too low'));
        $this->refused('DEBT_LIMIT_EXPOSURE', fn() => WalletService::setDebtLimit($this->ctx('ad'), $id, '39.99', 'too low'));
        $v = WalletService::setDebtLimitIfCurrent($this->ctx('ad'), $id, '40', '50.00', 'confirmed decrease to the exact floor');
        $this->assertSame(['40.00', '0.00'], [$this->limit($id), $v->available()->format()]);
        // the same value again: an accepted no-op (no write, no audit) in both variants
        WalletService::setDebtLimitIfCurrent($this->ctx('ad'), $id, '40', '40.00', 'unchanged');
        WalletService::setDebtLimit($this->ctx('ad'), $id, '40.00', 'unchanged');
        $this->assertSame(2, $this->audits($id));
        $this->assertSame(1, $this->rowCount('tbl_manager_ledger', 'manager_id = ?', [$id]), 'only the funding entry: a debt line never posts');
        // capability and input rules are the writer's own, unchanged; the expectation is mandatory (never unconditional)
        $this->refused('FORBIDDEN', fn() => WalletService::setDebtLimitIfCurrent($this->ctx('A'), $id, '45', '40.00', 'agent'));
        foreach ([null, '', 'abc', '-1', 40] as $bad) {
            $this->refused('INVALID_AMOUNT', fn() => WalletService::setDebtLimitIfCurrent($this->ctx('ad'), $id, '45', $bad, 'no expectation'));
        }
        $this->assertSame('40.00', $this->limit($id));
    }

    /**
     * The interleaving of the owner's finding, on the writer: the committed limit is 50 when the reviewed write (expected
     * 50, new 100) is called - so any pre-read passes - while another accepted setDebtLimit(200) holds the locks
     * uncommitted. When that commits, the reviewed writer locks the row, sees 200 and refuses. The control run shows the
     * unconditional setDebtLimit (unchanged accepted behaviour) writing 100 in the same interleaving.
     */
    public function testTheExpectationIsComparedUnderTheWritersOwnLocks(): void
    {
        foreach (['debt_limit_if_current' => ['DEBT_LIMIT_CHANGED', '200.00'], 'debt_limit' => [null, '100.00']] as $op => [$refusal, $final]) {
            $id = $this->agent();
            WalletService::setDebtLimit($this->ctx('ad'), $id, '50', 'opening credit line');
            $pause = sys_get_temp_dir() . '/wp6b_dl_' . bin2hex(random_bytes(6));
            touch($pause);
            $reviewed = null;
            try {
                $other = FinanceWorkers::start(static::$db, ['op' => 'debt_limit', 'actor' => $this->sid('ad'), 'manager' => $id, 'limit' => '200',
                    'pause_at' => 'debt_limit.after_update', 'pause_file' => $pause]);
                $deadline = microtime(true) + 30;
                while (!is_file($pause . '.entered') && microtime(true) < $deadline) {
                    usleep(5000);
                }
                $this->assertFileExists($pause . '.entered', 'the other writer holds the tree lock and the wallet row, uncommitted');
                $this->assertSame('50.00', $this->limit($id), 'committed state when the reviewed write is called: a pre-read sees 50');
                $reviewed = FinanceWorkers::start(static::$db, ['op' => $op, 'actor' => $this->sid('ad'), 'manager' => $id, 'limit' => '100', 'expected' => '50.00']);
                FinanceWorkers::waitBlocked(static::$db, 1);
            } finally {
                @unlink($pause);
            }
            $first = FinanceWorkers::finish($other);
            $second = FinanceWorkers::finish($reviewed);
            @unlink($pause . '.entered');
            $this->assertTrue($first['ok'], json_encode($first));
            if ($refusal !== null) {
                $this->assertSame(['ok' => false, 'reason' => $refusal], $second, 'the reviewed writer refuses under its own locks');
                $this->assertSame(2, $this->audits($id), 'opening + the other operator; nothing for the refused write');
            } else {
                $this->assertTrue($second['ok'], 'control: the unconditional writer is unchanged');
                $this->assertSame(3, $this->audits($id));
            }
            $this->assertSame($final, $this->limit($id), "{$op}: final debt limit");
            $this->assertSame(0, $this->rowCount('tbl_manager_ledger', 'manager_id = ?', [$id]));
        }
    }

    public function testTheWebReviewedExecuteUsesTheConditionalWriter(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/system/autoload/Reseller/Backoffice/WalletDesk.php');
        $start = (int) strpos($src, 'public static function executeDebtLimit(');
        $body = substr($src, $start, (int) strpos($src, "\n    }\n", $start) - $start);
        $this->assertStringContainsString("WalletService::setDebtLimitIfCurrent(\$a, \$fields['target'], \$fields['amount'], \$fields['current'], \$fields['reason'])", $body);
        $this->assertStringNotContainsString('WalletService::setDebtLimit(', $src, 'no Backoffice path calls the unconditional writer');
        $this->assertStringContainsString("'DEBT_LIMIT_CHANGED' ? new DomainError('REVIEW_EXPIRED')", $body, 'reported to the operator as a stale review');
        // still the single debt-limit writer
        $ws = (string) file_get_contents(dirname(__DIR__, 3) . '/system/autoload/Reseller/Finance/WalletService.php');
        $this->assertSame(1, preg_match_all('/UPDATE tbl_manager_wallets SET debt_limit/', $ws), 'one locked UPDATE statement');
        $w = (int) strpos($ws, 'private static function writeDebtLimit(');
        $writer = substr($ws, $w, (int) strpos($ws, "\n    }\n", $w) - $w);
        $lock = strpos($writer, '$w = LockedWallet::lock($pdo, $id);');
        $check = strpos($writer, "throw new DomainError('DEBT_LIMIT_CHANGED')");
        $this->assertNotFalse($lock);
        $this->assertNotFalse($check);
        foreach (['LockedWallet::lock($pdo, $id, true)', 'DEBT_LIMIT_EXPOSURE', 'UPDATE tbl_manager_wallets', 'AuditLog::record'] as $later) {
            $this->assertGreaterThan($check, strpos($writer, $later), "the comparison precedes {$later}");
        }
        $this->assertGreaterThan($lock, $check, 'the comparison follows the FOR UPDATE lock');
        $this->assertStringContainsString('}, true);', $writer, 'inside the tree-locked transaction');
    }
}
