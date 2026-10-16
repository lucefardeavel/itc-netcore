<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0-1: the shared identity lock (CustomerIdentity::hold / assertAvailable) on both engines - mutual exclusion,
 * lock order, timeout (owner D-U0-5: web 10 s, RADIUS voucher authorization 3 s; D-U0-7: a second connection holds the
 * lock), nested calls, inverted-order deadlock handling, transaction boundaries, exception safety, release on success /
 * failure / process exit, the per-statement time bound and the candidate bound (fail closed). No SQL exhaustion, no
 * connection-fault injection: the only contention is a named lock held by a second session.
 */
final class U0LockTest extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
    }

    private const CI = '\Reseller\Domain\CustomerIdentity';

    private static function free(): bool
    {
        return (string) static::$db->value('SELECT IS_FREE_LOCK(?)', [self::identityLockName()]) === '1';
    }

    public function testHoldRefusesInsideATransactionAndAssertOutsideTheLock(): void
    {
        $a = static::$app->exec('$pdo = ORM::get_db(); $pdo->beginTransaction(); try { ' . self::CI . '::hold(fn() => 1); $result = "TAKEN"; } catch (\LogicException $e) { $result = "LOGIC"; } $pdo->rollBack();');
        $b = static::$app->exec('try { ' . self::CI . '::assertAvailable(ORM::get_db(), ["w9u0free"]); $result = "CHECKED"; } catch (\LogicException $e) { $result = "LOGIC"; }');
        self::record('U0 lock transaction boundary', $a === 'LOGIC' && $b === 'LOGIC' ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['hold_in_tx' => $a, 'assert_without_lock' => $b]);
        $this->assertSame('LOGIC', $a, 'the identity lock is never requested inside a transaction (R2)');
        $this->assertSame('LOGIC', $b, 'the availability check runs only under the identity lock');
        $this->assertTrue(self::free());
    }

    public function testMutualExclusionAcrossProcesses(): void
    {
        $code = static fn(string $tag): string => '$t0 = microtime(true); $r = ' . self::CI . '::hold(function (\PDO $pdo) { $in = microtime(true); $mine = (string) $pdo->query("SELECT IS_USED_LOCK(\'' . self::identityLockName() . '\') = CONNECTION_ID()")->fetchColumn(); usleep(1500000); return [$in, microtime(true), $mine]; }); $result = ["' . $tag . '", $t0, ...$r];';
        $h1 = static::$app->execStart($code('a'));
        $this->assertTrue(self::waitUntil(static fn(): bool => !self::free(), 10), 'the first process holds the lock');
        $h2 = static::$app->execStart($code('b'));
        $a = static::$app->execFinish($h1);
        $b = static::$app->execFinish($h2);
        // [tag, requested, entered, left, holder-is-me]
        $disjoint = $b[2] >= $a[3] || $a[2] >= $b[3];
        self::record('U0 lock mutual exclusion', $disjoint && $a[4] === '1' && $b[4] === '1' ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['a' => $a, 'b' => $b]);
        $this->assertTrue($disjoint, 'the two critical sections never overlap ' . json_encode([$a, $b]));
        $this->assertSame('1', $a[4]);
        $this->assertSame('1', $b[4]);
        $this->assertTrue(self::free(), 'released after success');
    }

    public function testNestedReentrantHoldUnderTheTreeLockIsReleased(): void
    {
        $r = static::$app->exec('$result = \Reseller\Domain\TreeSerialization::hold(fn() => ' . self::CI . '::hold(fn(\PDO $p) => ' . self::CI . '::hold(fn(\PDO $q) => (string) $q->query("SELECT IS_USED_LOCK(\'' . self::identityLockName() . '\') = CONNECTION_ID()")->fetchColumn())));');
        self::record('U0 lock nested (L-1 -> L-3 -> L-3)', $r === '1' && self::free() ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['inner_holder_is_me' => $r, 'free_after' => self::free()]);
        $this->assertSame('1', $r);
        $this->assertTrue(self::free(), 'nested holds release completely');
    }

    public function testReleaseOnExceptionAndRollbackOfAnOpenTransaction(): void
    {
        $x = self::tag();
        $e = static::$app->exec('try { ' . self::CI . '::hold(function () { throw new \RuntimeException("w9u0"); }); $result = "NO"; } catch (\RuntimeException $e) { $result = "THROWN"; }');
        $freeAfterThrow = self::free();
        $t = static::$app->exec(sprintf('$pdo = ORM::get_db(); ' . self::CI . '::hold(function (\PDO $pdo) { $pdo->beginTransaction(); $pdo->prepare("INSERT INTO tbl_customers (username, password, fullname, address, email, phonenumber) VALUES (?, \'x\', \'\', \'\', \'\', \'\')")->execute([%s]); }); $result = $pdo->inTransaction() ? "OPEN" : "CLOSED";', var_export($x, true)));
        $rows = count(self::holders($x));
        self::record('U0 lock exception safety / rollback at release', $e === 'THROWN' && $freeAfterThrow && $t === 'CLOSED' && $rows === 0 ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED',
            ['exception' => $e, 'free_after_exception' => $freeAfterThrow, 'transaction_after' => $t, 'uncommitted_rows_after' => $rows]);
        $this->assertSame('THROWN', $e);
        $this->assertTrue($freeAfterThrow, 'released after an exception');
        $this->assertSame('CLOSED', $t);
        $this->assertSame(0, $rows, 'a transaction left open inside the lock is rolled back, never committed (R4)');
        $this->assertTrue(self::free());
    }

    public function testReleaseOnProcessExitAndFatalError(): void
    {
        $out = [];
        foreach (['exit' => 'exit(3);', 'fatal' => 'w9u0_undefined_function();'] as $k => $stmt) {
            $h = static::$app->execStart(self::CI . '::hold(function () { ' . $stmt . ' }); $result = "RETURNED";');
            $this->assertTrue(self::waitUntil(static fn(): bool => !self::running($h), 15));
            try {
                static::$app->execFinish($h);
            } catch (\RuntimeException $e) {
                // no JSON result: the process ended inside the lock
            }
            $out[$k] = self::waitUntil(static fn(): bool => self::free(), 5);
        }
        self::record('U0 lock release on process exit / fatal error', !in_array(false, $out, true) ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['free_after' => $out]);
        $this->assertSame(['exit' => true, 'fatal' => true], $out, 'the server frees the named lock when the session ends');
    }

    /** owner D-U0-5 / D-U0-7: a second connection holds the identity lock; every acquirer waits its bound, then refuses with nothing written */
    public function testTimeoutFailsClosedForEveryAcquirerWithoutBlindRetry(): void
    {
        $x = self::tag();
        $vid = $this->voucher($x);
        $code3 = 'W9U0T' . strtoupper(bin2hex(random_bytes(4)));
        $vid3 = $this->voucher($code3);
        $c4 = $this->person(self::tag());
        $c5 = $this->person(self::tag(), '', 'A');
        $sa = $this->staff('sa');
        $holder = self::secondConnection();
        $this->assertSame('1', (string) $holder->query("SELECT GET_LOCK('" . self::identityLockName() . "', 0)")->fetchColumn(), 'the second connection holds the identity lock');
        $before = self::state(static::$db);
        $runs = [];
        try {
            $t = microtime(true);
            $runs['P6 RADIUS (3 s)'] = [(string) static::$app->exec(self::p6Code($x, true)), round(microtime(true) - $t, 1)];
            $t = microtime(true);
            $runs['P6 public (10 s)'] = [(string) static::$app->exec(self::p6Code($x)), round(microtime(true) - $t, 1)];
            $t = microtime(true);
            $runs['P1'] = [(string) static::$app->exec(self::p1Code('ad', self::tag())), round(microtime(true) - $t, 1)];
            $t = microtime(true);
            $runs['P3'] = [(string) static::$app->exec(self::p3Code($code3, self::tag())), round(microtime(true) - $t, 1)];
            $t = microtime(true);
            $reg = $this->client()->postWithToken('register/post', $this->p2Data(self::tag()), [], 'register');
            $runs['P2'] = ['HTTP ' . $reg->status . (str_contains($reg->body, 'Account already exists') ? ' account-exists' : '') . (str_contains($reg->body, 'Failed to register') ? ' failed-generic' : ''), round(microtime(true) - $t, 1)];
            $t = microtime(true);
            $runs['P4'] = ['HTTP ' . $this->p4($c4, ['pppoe_username' => self::tag()], $sa), round(microtime(true) - $t, 1)];
            $t = microtime(true);
            $runs['P5'] = [(string) static::$app->exec(self::p5Code($c5['id'], ['username' => self::tag()])), round(microtime(true) - $t, 1)];
        } finally {
            $holder->query("SELECT RELEASE_LOCK('" . self::identityLockName() . "')");
        }
        $d = self::delta($before, self::state(static::$db));
        $unchanged = ['voucher' => self::vstatus($vid), 'voucher_p3' => self::vstatus($vid3), 'p4_pppoe' => self::customerRow($c4['id'])['pppoe_username'], 'p5_username' => self::customerRow($c5['id'])['username'] === $c5['username']];
        // retry once after the lock is free: exactly one grant, one transaction
        $tx0 = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions');
        $retry = (string) static::$app->exec(self::p6Code($x));
        $replay = (string) static::$app->exec(self::p6Code($x));
        $tx1 = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions');
        $met = $d['business'] === [] && $unchanged === ['voucher' => '0', 'voucher_p3' => '0', 'p4_pppoe' => '', 'p5_username' => true]
            && $runs['P6 RADIUS (3 s)'][0] === 'REFUSED' && $runs['P6 public (10 s)'][0] === 'REFUSED' && $runs['P1'][0] === 'REFUSED LOCK_TIMEOUT'
            && $retry === 'GRANTED' && $replay === 'NOT_VALID' && $tx1 - $tx0 === 1;
        self::record('U0 lock timeout fails closed (all acquirers)', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED',
            ['runs' => $runs, 'state_delta' => $d, 'after' => $unchanged, 'retry' => $retry, 'replay' => $replay, 'transactions_added_by_retry' => $tx1 - $tx0]);
        $this->assertSame([], $d['business'], 'no business / finance / identity / RADIUS change while the lock was unavailable ' . json_encode($runs));
        $this->assertSame(['voucher' => '0', 'voucher_p3' => '0', 'p4_pppoe' => '', 'p5_username' => true], $unchanged);
        $this->assertSame('REFUSED', $runs['P6 RADIUS (3 s)'][0]);
        $this->assertGreaterThanOrEqual(2.5, $runs['P6 RADIUS (3 s)'][1]);
        $this->assertLessThan(8.0, $runs['P6 RADIUS (3 s)'][1], 'RADIUS authorization waits at most about 3 s');
        $this->assertSame('REFUSED', $runs['P6 public (10 s)'][0]);
        $this->assertGreaterThanOrEqual(9.5, $runs['P6 public (10 s)'][1]);
        $this->assertSame('REFUSED LOCK_TIMEOUT', $runs['P1'][0]);
        $this->assertStringStartsWith('REFUSED', $runs['P5'][0]);
        $this->assertStringContainsString('failed-generic', $runs['P2'][0], 'P2 refuses with a generic message');
        $this->assertSame('GRANTED', $retry, 'after the lock is free the same voucher is granted once');
        $this->assertSame('NOT_VALID', $replay);
        $this->assertSame(1, $tx1 - $tx0, 'one invoice, no blind repetition');
    }

    /** inverted lock order (test-only): the engines resolve it (MySQL deadlock error / MariaDB timeout), the product never inverts it */
    public function testInvertedOrderIsResolvedAndTheWriterCompletes(): void
    {
        $x = self::tag();
        $tree = 'nuxr_tree_' . substr(sha1(static::$db->name), 0, 20);
        $holder = self::secondConnection();
        $holder->query("SELECT GET_LOCK('" . self::identityLockName() . "', 0)");
        $h1 = static::$app->execStart(self::p1Code('ad', $x));   // takes L-1, then waits for L-3
        $this->assertTrue(self::waitUntil(static fn(): bool => self::userLockWaiters() >= 1, 8), 'P1 waits for the identity lock while holding the tree lock');
        $t = microtime(true);
        try {
            $got = (string) $holder->query("SELECT GET_LOCK('{$tree}', 3)")->fetchColumn();
        } catch (\PDOException $e) {
            $got = 'ERROR ' . ($e->errorInfo[1] ?? $e->getCode());
        }
        $waited = round(microtime(true) - $t, 1);
        $holder->query("SELECT RELEASE_LOCK('" . self::identityLockName() . "')");
        $p1 = (string) static::$app->execFinish($h1);
        $holder->query('SELECT RELEASE_ALL_LOCKS()');
        $resolved = $got !== '1' && str_starts_with($p1, 'CREATED');
        self::record('U0 lock inverted order (test-only) resolved', $resolved ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED',
            ['inverted_request' => $got, 'inverted_wait_s' => $waited, 'P1' => $p1, 'engine_behaviour' => str_starts_with($got, 'ERROR') ? 'deadlock detected' : 'timeout']);
        $this->assertNotSame('1', $got, 'the inverted request never obtains the tree lock held by the writer');
        $this->assertStringStartsWith('CREATED', $p1, 'the writer completes once the identity lock is free (no deadlock)');
        $this->assertTrue(self::free());
    }

    /** every acquirer in parallel on distinct names: lock order L-1 → L-2 → L-3 holds, no deadlock, no timeout, all accepted */
    public function testParallelMixedAcquirersNeverDeadlock(): void
    {
        $hs = [];
        for ($i = 0; $i < 3; $i++) {
            $hs["P1#{$i}"] = static::$app->execStart(self::p1Code('ad', self::tag()));
            $v = self::tag();
            $this->voucher($v);
            $hs["P6#{$i}"] = static::$app->execStart(self::p6Code($v, $i === 0));
        }
        for ($i = 0; $i < 2; $i++) {
            $o = $this->person(self::tag(), '', 'A');
            $hs["P5#{$i}"] = static::$app->execStart(self::p5Code($o['id'], ['username' => self::tag()]));
            $code = 'W9U0PP' . strtoupper(bin2hex(random_bytes(4)));
            $this->voucher($code);
            $hs["P3#{$i}"] = static::$app->execStart(self::p3Code($code, self::tag()));
            $hs["P2#{$i}"] = $this->bgPost($this->client(), 'register/post', $this->p2Data(self::tag()), 'register');
        }
        $t = microtime(true);
        $out = array_map(static fn(array $h): string => (string) static::$app->execFinish($h), $hs);
        $elapsed = round(microtime(true) - $t, 1);
        $ok = array_filter($out, static fn(string $o): bool => in_array($o, ['GRANTED', 'ACCEPTED NO_ACTIVE_PLAN', 'HTTP 302'], true) || str_starts_with($o, 'CREATED'));
        $log = (string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log');
        $reqs = preg_match_all('/request=([0-9a-f]+) [^\n]*outcome=CLAIMED_REVIEW/', $log, $m) ? $m[1] : [];
        $diag = array_values(array_filter(explode("\n", $log), static fn(string $l): bool => (bool) preg_match('/identity\.|device\.identity_refused|LOCK_TIMEOUT|voucher-service-check|radius\.deadlock_retry/', $l)
            || array_filter($reqs, static fn(string $r): bool => str_contains($l, $r) || str_contains($l, 'ref=' . substr($r, 0, 12))) !== []));
        self::record('U0 lock parallel mixed acquirers', count($ok) === count($out) ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['outcomes' => $out, 'elapsed_s' => $elapsed,
            'diagnostics' => array_map(static fn(string $l): string => preg_replace('/^\[[^\]]*\] /', '', $l), array_slice($diag, -20))]);
        $this->assertCount(count($out), $ok, 'all distinct-name acquirers succeed ' . json_encode($out));
        $this->assertTrue(self::free());
    }

    /** D-U0-2 bounds: a lookup statement is interrupted after its time bound; too many candidate rows fail closed */
    public function testStatementTimeBoundAndCandidateBoundFailClosed(): void
    {
        $heavy = 'SELECT COUNT(*) FROM information_schema.COLLATIONS a, information_schema.COLLATIONS b, information_schema.COLLATIONS c, information_schema.COLLATIONS d';
        $r = static::$app->exec('$m = new ReflectionMethod(' . self::CI . '::class, "bounded"); $t = microtime(true); try { $m->invoke(null, ORM::get_db(), ' . var_export($heavy, true) . ', []); $o = "NOT_INTERRUPTED"; } catch (\Throwable $e) { $o = "INTERRUPTED " . ($e->errorInfo[1] ?? $e->getCode()); } $result = [$o, round(microtime(true) - $t, 1)];');
        // 1001 legacy rows with a leading space: the candidate bound is exceeded -> the check cannot run -> refused
        $values = [];
        for ($i = 0; $i < 1001; $i++) {
            $values[] = "(' w9u0edge{$i}', 'x', '', '', '', '')";
        }
        static::$db->exec('INSERT INTO tbl_customers (username, password, fullname, address, email, phonenumber) VALUES ' . implode(',', $values));
        $x = self::tag();
        $vid = $this->voucher($x);
        $p6 = (string) static::$app->exec(self::p6Code($x));
        $p1 = (string) static::$app->exec(self::p1Code('ad', self::tag()));
        static::$db->exec("DELETE FROM tbl_customers WHERE username LIKE ' w9u0edge%'");
        $p6After = (string) static::$app->exec(self::p6Code($x));
        $met = str_starts_with($r[0], 'INTERRUPTED') && $r[1] < 5 && $p6 === 'REFUSED' && $p1 === 'REFUSED IDENTITY_CHECK_UNAVAILABLE' && $p6After === 'GRANTED';
        self::record('U0 bounds: statement time / candidate rows', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED',
            ['heavy_statement' => $r, 'p6_with_1001_edge_rows' => $p6, 'p1_with_1001_edge_rows' => $p1, 'p6_after_cleanup' => $p6After, 'voucher' => self::vstatus($vid)]);
        $this->assertStringStartsWith('INTERRUPTED', $r[0], 'the per-statement time bound interrupts a long lookup');
        $this->assertLessThan(5, $r[1]);
        $this->assertSame('REFUSED', $p6, 'more candidates than the bound: fail closed (open availability limit)');
        $this->assertSame('REFUSED IDENTITY_CHECK_UNAVAILABLE', $p1);
        $this->assertSame('GRANTED', $p6After, 'control: the same voucher after the legacy rows are gone');
    }
}
