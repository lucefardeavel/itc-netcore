<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\Tx;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\LedgerService;
use Reseller\Finance\LockedWallet;
use Reseller\Finance\Money;
use Reseller\Finance\ReservationService;
use Reseller\Finance\TransferService;
use Reseller\Tests\Support\FinanceTestCase;

/** WP3A §7 / P1: append-only ledger, derived keys, continuity, scoped reads, compensation only. */
final class LedgerTest extends FinanceTestCase
{
    public function testContinuityAcrossEveryOperationType(): void
    {
        $p = $this->funded('500');
        $c = $this->agent($p);
        TransferService::adjustCredit($this->ctx('ad'), $p, '0.05', 'rounding credit', self::ref());
        $w = TransferService::withdraw($this->ctx('ad'), $p, '100.05', 'payout', self::ref());
        TransferService::transferToChild($this->ctx($p), $p, $c, '150', 'stock', self::ref());
        TransferService::adjustDebit($this->ctx('ad'), $c, '0.01', 'fee', self::ref());
        $r = ReservationService::reserve($this->activation($c), $c, Money::parsePositive('49.99'));
        ReservationService::commit($r->reservationId, FinanceActor::system('activation-service'));
        TransferService::reverse($this->ctx('ad'), $w->transferId, 'payout was not executed', self::ref());
        foreach ([$p, $c] as $m) {
            $this->assertSame([], LedgerService::continuity(static::$svc, $m), "continuity of {$m}");
        }
        $this->assertSame(['500.00', '500.05', '400.00', '250.00', '350.05'], array_column($this->ledger($p), 'balance_after'));
        $this->assertSame(['FUNDING', 'ADJUSTMENT', 'WITHDRAWAL', 'TRANSFER_OUT', 'REVERSAL'], array_column($this->ledger($p), 'entry_type'));
        $this->assertSame(['150.00', '149.99', '100.00'], array_column($this->ledger($c), 'balance_after'));
        $this->assertSame(['TRANSFER_IN', 'ADJUSTMENT', 'ACTIVATION_DEBIT'], array_column($this->ledger($c), 'entry_type'));
        // a wallet balance that drifts from its ledger is detected (test-only tamper on the disposable database)
        static::$db->exec("UPDATE tbl_manager_wallets SET balance = balance + 1 WHERE manager_id = {$c}");
        $this->assertSame(['wallet balance 101.00 != last balance_after 100.00'], LedgerService::continuity(static::$svc, $c));
        static::$db->exec("UPDATE tbl_manager_wallets SET balance = balance - 1 WHERE manager_id = {$c}");
        $this->assertSame([], LedgerService::continuity(static::$svc, $c));
    }

    public function testEntryKeysAreDerivedAndUnique(): void
    {
        $id = $this->funded('10');
        $keys = array_column(static::$db->rows('SELECT entry_key FROM tbl_manager_ledger'), 'entry_key');
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertMatchesRegularExpression('/^trf:\d+:credit$/', $this->ledger($id)[0]['entry_key']);
        // a duplicate posting of the same operation leg is impossible (unique key)
        $tid = (int) $this->ledger($id)[0]['transfer_id'];
        try {
            Tx::run(function (\PDO $pdo) use ($id, $tid) {
                $w = LockedWallet::lock($pdo, $id);
                LedgerService::postTransferLeg($pdo, $w, $tid, 'credit');
            });
            $this->fail('second posting of the same leg');
        } catch (\PDOException $e) {
            $this->assertSame(1062, (int) ($e->errorInfo[1] ?? 0), 'duplicate entry key');
        }
        $this->assertSame('10.00', $this->balance($id));
        // a leg that does not match the operation row is refused before any write
        try {
            Tx::run(function (\PDO $pdo) use ($id, $tid) {
                LedgerService::postTransferLeg($pdo, LockedWallet::lock($pdo, $id), $tid, 'debit');
            });
            $this->fail('debit leg of a funding');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    /** No generic write helper: the ledger API takes authoritative ids only, never amounts/keys/balances. */
    public function testNoGenericOrMutatingLedgerApi(): void
    {
        $rc = new \ReflectionClass(LedgerService::class);
        $public = array_map(static fn(\ReflectionMethod $m) => $m->getName(), $rc->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($public);
        $this->assertSame(['continuity', 'entries', 'entry', 'postActivationDebit', 'postTransferLeg'], $public);
        foreach (['postTransferLeg', 'postActivationDebit'] as $m) {
            $params = array_map(static fn(\ReflectionParameter $p) => strtolower($p->getName()), $rc->getMethod($m)->getParameters());
            foreach ($params as $p) {
                $this->assertDoesNotMatchRegularExpression('/amount|key|balance|operator|customer|before|after/', $p, "{$m}({$p})");
            }
        }
        foreach ($public as $m) {
            $this->assertDoesNotMatchRegularExpression('/update|delete|edit|remove|truncate|set/i', $m);
        }
        $this->assertTrue($rc->getMethod('insert')->isPrivate());
        $this->assertFalse((new \ReflectionClass(LockedWallet::class))->getConstructor()->isPublic(), 'wallet state comes only from a locking read');
    }

    /** The balance write is a compare-and-set on the locked balance: a forged LockedWallet cannot post. */
    public function testForgedWalletStateCannotBePosted(): void
    {
        $id = $this->funded('10');
        $tid = (int) $this->ledger($id)[0]['transfer_id'];
        $before = $this->checksums();
        try {
            Tx::run(function (\PDO $pdo) use ($id, $tid) {
                $forged = LockedWallet::lock($pdo, $id)->withBalance(Money::parsePositive('1000000'));
                LedgerService::postTransferLeg($pdo, $forged, $tid, 'credit');
            });
            $this->fail('forged state posted');
        } catch (\Reseller\Domain\DomainError $e) {
            $this->assertSame('LEDGER_STATE_MISMATCH', $e->reason);
        }
        $this->assertSame($before, $this->checksums());
        $this->assertSame('10.00', $this->balance($id));
    }

    public function testLedgerReadScope(): void
    {
        foreach (['A', 'B', 'G', 'B2', 'C'] as $u) {
            if ($this->wallet($this->sid($u)) === null) {
                TransferService::fund($this->ctx('ad'), $this->sid($u), '3', 'fixture', self::ref());
            }
        }
        $b = $this->ctx('B');
        $this->assertNotEmpty(LedgerService::entries($b, $this->sid('B')));
        $this->assertNotEmpty(LedgerService::entries($b, $this->sid('G')));
        $gEntry = LedgerService::entries($b, $this->sid('G'))[0];
        $this->assertSame($gEntry, LedgerService::entry($b, $gEntry['id']));
        foreach (['B2', 'A', 'C'] as $u) {
            $this->refused('NOT_FOUND', fn() => LedgerService::entries($b, $this->sid($u)), false);
            $foreign = (int) static::$db->value('SELECT id FROM tbl_manager_ledger WHERE manager_id = ? ORDER BY id LIMIT 1', [$this->sid($u)]);
            $this->assertGreaterThan(0, $foreign);
            $this->refused('NOT_FOUND', fn() => LedgerService::entry($b, $foreign), false);
        }
        $this->assertNotEmpty(LedgerService::entries($this->ctx('ad'), $this->sid('C')));
        foreach (['A1', 'rp', 'E'] as $u) {
            $this->refused('FORBIDDEN', fn() => LedgerService::entries($this->ctx($u), $this->sid('B')), false);
        }
        $this->assertSame([], LedgerService::entries($this->ctx('F'), $this->sid('F')), 'a frozen Agent keeps read visibility (no FORBIDDEN)');
    }

    public function testCorrectionsOnlyByCompensation(): void
    {
        $id = $this->agent();
        $f = TransferService::fund($this->ctx('ad'), $id, '12', 'fund', self::ref());
        $snapshot = static::$db->rows('SELECT * FROM tbl_manager_ledger WHERE manager_id = ? ORDER BY id', [$id]);
        TransferService::reverse($this->ctx('ad'), $f->transferId, 'wrong amount', self::ref());
        TransferService::fund($this->ctx('ad'), $id, '21', 'correct amount', self::ref());
        $after = static::$db->rows('SELECT * FROM tbl_manager_ledger WHERE manager_id = ? ORDER BY id', [$id]);
        $this->assertSame($snapshot[0], $after[0], 'history is never rewritten');
        $this->assertCount(3, $after);
        $this->assertSame(['FUNDING', 'REVERSAL', 'FUNDING'], array_column($after, 'entry_type'));
        $this->assertSame('21.00', $this->balance($id));
        foreach ($after as $e) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}$/', (string) $e['created_at']);
            $this->assertLessThanOrEqual(5, abs(strtotime((string) $e['created_at'] . ' UTC') - time()), 'UTC timestamps');
        }
    }
}
