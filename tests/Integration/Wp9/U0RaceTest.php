<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 race matrix (test-first: U0-0 records the races on the unfixed code, U0-5 proves them closed).
 *
 * Contract for one canonical identity X (owner §4; AM-W9-2): at most ONE acquirer obtains X (a customer holding X as
 * username / PPPoE username, a voucher-only identity granted for a code equal to X, a new P3 customer), and no
 * pre-existing identity's RADIUS policy is changed by another acquirer. Every case runs two REAL writers in separate
 * processes with a deterministic barrier (U0Fixture) and records its interleaving before asserting the contract.
 *
 * Families: A voucher in flight vs customer writers; B customer writer before commit vs voucher-only redemption;
 * C voucher vs voucher (codes equal under the collation, D-U0-6); D customer vs customer; G rename-away vs acquisition
 * (U0 gap G3). Isolation: REPEATABLE READ by default, READ COMMITTED when WP9_ISOLATION=READ-COMMITTED.
 */
final class U0RaceTest extends RadiusHttpTestCase
{
    use U0Fixture;

    private static string $isolation = 'REPEATABLE-READ';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
        if (getenv('WP9_ISOLATION') === 'READ-COMMITTED') {
            static::$db->pdo->exec('SET GLOBAL TRANSACTION ISOLATION LEVEL READ COMMITTED');
            self::$isolation = 'READ-COMMITTED';
        }
    }

    public static function tearDownAfterClass(): void
    {
        static::$db?->pdo->exec('SET GLOBAL TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        parent::tearDownAfterClass();
    }

    /**
     * records and asserts the single-acquirer contract for X. Integrity (refined after the first U0-0 run, which judged only
     * one side of the G cases): a granted voucher-only identity holds ITS policy (password = code) and every customer
     * holder keeps its own; $integrity adds case-specific checks.
     */
    private function judge(string $case, string $x, array $outcomes, int $grantedVouchers, array $extra = [], array $integrity = []): void
    {
        if ($grantedVouchers === 1 && in_array('GRANTED', array_map('strval', $outcomes), true) && !isset($integrity['voucher_policy_is_its_own'])) {
            $integrity['voucher_policy_is_its_own'] = self::password(trim($x)) === $x;
        }
        $holders = self::holders($x);
        $acquirers = count($holders) + $grantedVouchers;
        $intact = !in_array(false, $integrity, true);
        $met = $acquirers <= 1 && $intact;
        self::record("U0 race {$case}", $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['isolation' => self::$isolation, 'name' => $x, 'outcomes' => $outcomes,
            'customers_holding_name' => count($holders), 'voucher_identities_granted' => $grantedVouchers, 'policy_integrity' => $integrity] + $extra);
        $this->assertLessThanOrEqual(1, $acquirers, "{$case}: one identity, one holder " . json_encode($outcomes));
        $this->assertTrue($intact, "{$case}: no other identity's RADIUS policy changed " . json_encode($integrity));
    }

    /** evidence: the isolation level the application sessions actually run with */
    public function testApplicationSessionIsolationLevel(): void
    {
        $level = (string) static::$app->exec('$v = ORM::get_db()->query("SELECT VERSION()")->fetchColumn(); $result = ORM::get_db()->query(stripos($v, "mariadb") !== false ? "SELECT @@SESSION.tx_isolation" : "SELECT @@SESSION.transaction_isolation")->fetchColumn();');
        self::record('U0 race application session isolation', $level === self::$isolation ? 'CONTROL_PASS' : 'CONTROL_FAIL', ['expected' => self::$isolation, 'observed' => $level]);
        $this->assertSame(self::$isolation, $level);
    }

    // ------------------------------------------------------------------ A: voucher in flight (claimed, grant paused)

    /** starts a voucher-only redemption of $code and pauses it inside the grant (after the claim / identity check) */
    private function pausedVoucher(string $code): array
    {
        $vid = $this->voucher($code);
        self::pause('recharge');
        $h = static::$app->execStart(self::p6Code($code));
        $this->assertTrue(self::waitUntil(static fn(): bool => self::arrived('recharge'), 20), 'barrier: the voucher grant reached the device step');
        return [$vid, $h];
    }

    private function finishVoucher(array $h): string
    {
        self::go('recharge');
        return (string) static::$app->execFinish($h);
    }

    public function testA1VoucherInFlightVersusP1CreationAndProvisioning(): void
    {
        $x = self::tag();
        [$vid, $h] = $this->pausedVoucher($x);
        $p1 = (string) static::$app->exec(self::p1Code('ad', $x));
        $prov = str_starts_with($p1, 'CREATED') ? $this->provision((int) substr($p1, 8)) : null;
        $p6 = $this->finishVoucher($h);
        $cid = str_starts_with($p1, 'CREATED') ? (int) substr($p1, 8) : 0;
        $integrity = $cid > 0 ? ['customer_password_is_its_own' => self::password($x) === self::customerRow($cid)['password']] : [];
        $this->judge('A1 voucher in flight vs P1 create + provision', $x, ['P6' => $p6, 'P1' => $p1, 'provision' => $prov, 'voucher_status' => self::vstatus($vid)],
            $p6 === 'GRANTED' ? 1 : 0, [], $integrity);
    }

    public function testA2VoucherInFlightVersusP2PublicRegistration(): void
    {
        $x = self::tag();
        [$vid, $h] = $this->pausedVoucher($x);
        $p2 = $this->p2($x);
        $p6 = $this->finishVoucher($h);
        $this->judge('A2 voucher in flight vs P2 register', $x, ['P6' => $p6, 'P2' => 'HTTP ' . $p2, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    public function testA3VoucherInFlightVersusP3VoucherWithNewUsername(): void
    {
        $x = self::tag();
        [$vid, $h] = $this->pausedVoucher($x);
        $code2 = 'W9U0P3' . strtoupper(bin2hex(random_bytes(4)));
        $this->voucher($code2);
        $p3 = (string) static::$app->exec(self::p3Code($code2, $x));
        $p6 = $this->finishVoucher($h);
        $this->judge('A3 voucher in flight vs P3 new username', $x, ['P6' => $p6, 'P3' => $p3, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    public function testA4VoucherInFlightVersusP4RenameIntoTheName(): void
    {
        $x = self::tag();
        $c = $this->person(self::tag());
        $this->provision($c['id']);
        [$vid, $h] = $this->pausedVoucher($x);
        $p4 = $this->p4($c, ['username' => $x]);
        $p6 = $this->finishVoucher($h);
        $renamed = self::customerRow($c['id'])['username'] === $x;
        $integrity = $renamed ? ['renamed_customer_password_is_its_own' => self::password($x) === $c['password']] : [];
        $this->judge('A4 voucher in flight vs P4 rename into X', $x, ['P6' => $p6, 'P4' => 'HTTP ' . $p4, 'renamed' => $renamed, 'voucher_status' => self::vstatus($vid)],
            $p6 === 'GRANTED' ? 1 : 0, [], $integrity);
    }

    public function testA5VoucherInFlightVersusP5OwnedRenameIntoTheName(): void
    {
        $x = self::tag();
        $c = $this->person(self::tag());
        $this->provision($c['id']);
        static::$db->pdo->prepare('UPDATE tbl_customers SET manager_id = ? WHERE id = ?')->execute([$this->sid('A'), $c['id']]);
        [$vid, $h] = $this->pausedVoucher($x);
        $p5 = (string) static::$app->exec(self::p5Code($c['id'], ['username' => $x]));
        $p6 = $this->finishVoucher($h);
        $renamed = self::customerRow($c['id'])['username'] === $x;
        $integrity = $renamed ? ['renamed_customer_password_is_its_own' => self::password($x) === $c['password']] : [];
        $this->judge('A5 voucher in flight vs P5 owned rename into X', $x, ['P6' => $p6, 'P5' => $p5, 'renamed' => $renamed, 'voucher_status' => self::vstatus($vid)],
            $p6 === 'GRANTED' ? 1 : 0, [], $integrity);
    }

    // ------------------------------------------------------------------ B: customer writer paused before commit vs P6

    /**
     * Holds $lockSql on a second connection, starts $writer, waits until it blocks, starts the voucher-only redemption of
     * X, waits until that one has finished or waits for the identity lock, then releases the barrier.
     *
     * @return array{0:mixed,1:string,2:bool,3:int}  [writer outcome, P6 outcome, P6 finished while the writer was paused, voucher id]
     */
    private function writerPausedVersusVoucher(string $lockSql, callable $startWriter, string $x): array
    {
        $vid = $this->voucher($x);
        $lock = self::secondConnection();
        $lock->exec($lockSql);
        try {
            $hw = $startWriter();
            $this->assertTrue(self::waitUntil(static fn(): bool => self::lockWaiters() >= 1, 20), 'barrier: the writer waits on the table lock');
            $h6 = static::$app->execStart(self::p6Code($x));
            self::waitUntil(static fn(): bool => !self::running($h6) || self::userLockWaiters() >= 1, 8);
            $p6Early = !self::running($h6);
        } finally {
            $lock->exec('UNLOCK TABLES');
        }
        $w = static::$app->execFinish($hw);
        $p6 = (string) static::$app->execFinish($h6);
        return [$w, $p6, $p6Early, $vid];
    }

    public function testB1P1BeforeCommitVersusVoucher(): void
    {
        $x = self::tag();
        [$w, $p6, $early, $vid] = $this->writerPausedVersusVoucher('LOCK TABLES tbl_reseller_audit_events WRITE', fn(): array => static::$app->execStart(self::p1Code('ad', $x)), $x);
        $this->judge('B1 P1 paused before COMMIT vs voucher', $x, ['P1' => $w, 'P6' => $p6, 'p6_finished_while_P1_paused' => $early, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    public function testB2P2BeforeInsertVersusVoucher(): void
    {
        $x = self::tag();
        $client = $this->client();
        [$w, $p6, $early, $vid] = $this->writerPausedVersusVoucher('LOCK TABLES tbl_customers READ', fn(): array => $this->bgPost($client, 'register/post', $this->p2Data($x), 'register'), $x);
        $this->judge('B2 P2 paused at INSERT vs voucher', $x, ['P2' => $w, 'P6' => $p6, 'p6_finished_while_P2_paused' => $early, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    public function testB3P3BeforeInsertVersusVoucher(): void
    {
        $x = self::tag();
        $code2 = 'W9U0B3' . strtoupper(bin2hex(random_bytes(4)));
        $this->voucher($code2);
        [$w, $p6, $early, $vid] = $this->writerPausedVersusVoucher('LOCK TABLES tbl_customers READ', fn(): array => static::$app->execStart(self::p3Code($code2, $x)), $x);
        $this->judge('B3 P3 paused at INSERT vs voucher', $x, ['P3' => $w, 'P6' => $p6, 'p6_finished_while_P3_paused' => $early, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    public function testB4P4BeforeSaveVersusVoucher(): void
    {
        $x = self::tag();
        $c = $this->person(self::tag());
        $sa = $this->staff('sa');
        [$w, $p6, $early, $vid] = $this->writerPausedVersusVoucher('LOCK TABLES tbl_customers READ', fn(): array => $this->bgPost($sa, 'customers/edit-post', $this->p4Data($c, ['pppoe_username' => $x]), 'dashboard'), $x);
        $this->judge('B4 P4 (pppoe = X) paused at save vs voucher', $x, ['P4' => $w, 'P6' => $p6, 'p6_finished_while_P4_paused' => $early, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    public function testB5P5BeforeWriteVersusVoucher(): void
    {
        $x = self::tag();
        $c = $this->person(self::tag(), '', 'A');
        [$w, $p6, $early, $vid] = $this->writerPausedVersusVoucher('LOCK TABLES tbl_customers READ', fn(): array => static::$app->execStart(self::p5Code($c['id'], ['pppoe_username' => $x])), $x);
        $this->judge('B5 P5 (pppoe = X) paused at write vs voucher', $x, ['P5' => $w, 'P6' => $p6, 'p6_finished_while_P5_paused' => $early, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    // ------------------------------------------------------------------ C: voucher vs voucher (D-U0-6)

    public static function equalCodes(): array
    {
        return ['case' => ['{t}ab', '{T}AB'], 'accent' => ['{t}é', '{t}e'], 'trailing space' => ['{t}', '{t} ']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('equalCodes')]
    public function testC1VoucherInFlightVersusAnEqualCode(string $a, string $b): void
    {
        $t = self::tag();
        $f = static fn(string $s): string => str_replace(['{t}', '{T}'], [$t, strtoupper($t)], $s);
        [$vid, $h] = $this->pausedVoucher($f($a));
        $vid2 = $this->voucher($f($b));
        $second = (string) static::$app->exec(self::p6Code($f($b)));
        $first = $this->finishVoucher($h);
        $granted = (int) ($first === 'GRANTED') + (int) ($second === 'GRANTED');
        $this->judge('C1 voucher in flight vs equal code (' . $this->dataName() . ')', $f($a), ['first' => $first, 'second' => $second,
            'statuses' => [self::vstatus($vid), self::vstatus($vid2)]], $granted);
    }

    public function testC2SimultaneousRedemptionsOfEqualCodes(): void
    {
        $t = self::tag();
        $this->voucher($t . 'q');
        $this->voucher(strtoupper($t) . 'Q');
        $h1 = static::$app->execStart(self::p6Code($t . 'q'));
        $h2 = static::$app->execStart(self::p6Code(strtoupper($t) . 'Q'));
        $o = [(string) static::$app->execFinish($h1), (string) static::$app->execFinish($h2)];
        $this->judge('C2 simultaneous redemptions of equal codes', $t . 'q', ['outcomes' => $o], count(array_filter($o, static fn(string $s): bool => $s === 'GRANTED')));
    }

    // ------------------------------------------------------------------ D: customer vs customer

    public function testD1P1BeforeCommitVersusP2(): void
    {
        $x = self::tag();
        $lock = self::secondConnection();
        $lock->exec('LOCK TABLES tbl_reseller_audit_events WRITE');
        try {
            $h1 = static::$app->execStart(self::p1Code('ad', $x));
            $this->assertTrue(self::waitUntil(static fn(): bool => self::lockWaiters() >= 1, 20), 'barrier: P1 waits before COMMIT');
            $h2 = $this->bgPost($this->client(), 'register/post', $this->p2Data(strtoupper($x)), 'register');
            self::waitUntil(static fn(): bool => !self::running($h2) || self::userLockWaiters() >= 1, 8);
        } finally {
            $lock->exec('UNLOCK TABLES');
        }
        $this->judge('D1 P1 paused before COMMIT vs P2 (upper case)', $x, ['P1' => static::$app->execFinish($h1), 'P2' => static::$app->execFinish($h2)], 0);
    }

    public function testD2P1BeforeCommitVersusP3(): void
    {
        $x = self::tag();
        $code = 'W9U0D2' . strtoupper(bin2hex(random_bytes(4)));
        $this->voucher($code);
        $lock = self::secondConnection();
        $lock->exec('LOCK TABLES tbl_reseller_audit_events WRITE');
        try {
            $h1 = static::$app->execStart(self::p1Code('ad', 'w9u0n' . bin2hex(random_bytes(2)), $x));
            $this->assertTrue(self::waitUntil(static fn(): bool => self::lockWaiters() >= 1, 20), 'barrier: P1 waits before COMMIT');
            $h3 = static::$app->execStart(self::p3Code($code, $x));
            self::waitUntil(static fn(): bool => !self::running($h3) || self::userLockWaiters() >= 1, 8);
        } finally {
            $lock->exec('UNLOCK TABLES');
        }
        $this->judge('D2 P1 (pppoe = X) paused before COMMIT vs P3 new username X', $x, ['P1' => static::$app->execFinish($h1), 'P3' => static::$app->execFinish($h3)], 0);
    }

    public function testD3P2BeforeInsertVersusP3(): void
    {
        $x = self::tag();
        $code = 'W9U0D3' . strtoupper(bin2hex(random_bytes(4)));
        $this->voucher($code);
        $lock = self::secondConnection();
        $lock->exec('LOCK TABLES tbl_customers READ');
        try {
            $h2 = $this->bgPost($this->client(), 'register/post', $this->p2Data($x), 'register');
            $this->assertTrue(self::waitUntil(static fn(): bool => self::lockWaiters() >= 1, 20), 'barrier: P2 waits at INSERT');
            $h3 = static::$app->execStart(self::p3Code($code, strtoupper($x)));
            self::waitUntil(static fn(): bool => self::lockWaiters() >= 2 || !self::running($h3), 8);
        } finally {
            $lock->exec('UNLOCK TABLES');
        }
        $this->judge('D3 P2 paused at INSERT vs P3 (upper case)', $x, ['P2' => static::$app->execFinish($h2), 'P3' => static::$app->execFinish($h3)], 0);
    }

    public function testD4P4BeforeSaveVersusP1CrossField(): void
    {
        $x = self::tag();
        $c = $this->person(self::tag());
        $lock = self::secondConnection();
        $lock->exec('LOCK TABLES tbl_customers READ');
        try {
            $h4 = $this->bgPost($this->staff('sa'), 'customers/edit-post', $this->p4Data($c, ['pppoe_username' => $x]), 'dashboard');
            $this->assertTrue(self::waitUntil(static fn(): bool => self::lockWaiters() >= 1, 20), 'barrier: P4 waits at save');
            $h1 = static::$app->execStart(self::p1Code('ad', $x));
            self::waitUntil(static fn(): bool => self::lockWaiters() >= 2 || !self::running($h1), 8);
        } finally {
            $lock->exec('UNLOCK TABLES');
        }
        $this->judge('D4 P4 (pppoe = X) paused at save vs P1 username X', $x, ['P4' => static::$app->execFinish($h4), 'P1' => static::$app->execFinish($h1)], 0);
    }

    public function testD5P5BeforeWriteVersusP1CrossField(): void
    {
        $x = self::tag();
        $c = $this->person(self::tag(), '', 'A');
        $lock = self::secondConnection();
        $lock->exec('LOCK TABLES tbl_customers READ');
        try {
            $h5 = static::$app->execStart(self::p5Code($c['id'], ['pppoe_username' => $x]));
            $this->assertTrue(self::waitUntil(static fn(): bool => self::lockWaiters() >= 1, 20), 'barrier: P5 waits at write');
            $h1 = static::$app->execStart(self::p1Code('ad', $x));
            self::waitUntil(static fn(): bool => self::lockWaiters() >= 2 || !self::running($h1), 8);
        } finally {
            $lock->exec('UNLOCK TABLES');
        }
        $this->judge('D5 P5 (pppoe = X) paused at write vs P1 username X', $x, ['P5' => static::$app->execFinish($h5), 'P1' => static::$app->execFinish($h1)], 0);
    }

    // ------------------------------------------------------------------ G: rename-away (old name X) vs acquisition of X

    /** a customer named X with an active plan on the barrier device, renamed X -> Y and paused before the RADIUS rename */
    private function renameAwayPaused(bool $owned): array
    {
        $x = self::tag();
        $y = self::tag();
        $c = $this->person($x);
        $this->provision($c['id'], self::$barrierHotspot);
        $this->assertSame($c['password'], self::password($x), 'control: the customer policy is under X');
        if ($owned) {
            static::$db->pdo->prepare('UPDATE tbl_customers SET manager_id = ? WHERE id = ?')->execute([$this->sid('A'), $c['id']]);
        }
        self::pause('rename');
        $h = $owned ? static::$app->execStart(self::p5Code($c['id'], ['username' => $y]))
            : $this->bgPost($this->staff('sa'), 'customers/edit-post', $this->p4Data($c, ['username' => $y]), 'dashboard');
        $this->assertTrue(self::waitUntil(static fn(): bool => self::arrived('rename'), 25), 'barrier: the rename paused after the save, before the RADIUS rename');
        return [$x, $y, $c, $h];
    }

    private function judgeRename(string $case, string $x, string $y, array $c, array $outcomes, int $grantedVouchers, ?int $newCustomer = null): void
    {
        $now = self::customerRow($c['id']);
        $integrity = ['renamed_customer_password_is_its_own' => self::password((string) $now['username']) === $c['password']];
        if ($newCustomer !== null) {
            $integrity['new_customer_password_is_its_own'] = self::password($x) === self::customerRow($newCustomer)['password'];
        }
        $this->judge($case, $x, $outcomes + ['renamed_to' => $now['username'] === $y ? 'Y' : $now['username']], $grantedVouchers, [], $integrity);
    }

    public function testG1P4RenameAwayVersusVoucherOfTheOldName(): void
    {
        [$x, $y, $c, $h] = $this->renameAwayPaused(false);
        $vid = $this->voucher($x);
        $p6 = (string) static::$app->exec(self::p6Code($x));
        self::go('rename');
        $p4 = static::$app->execFinish($h);
        $this->judgeRename('G1 P4 rename X->Y paused vs voucher X', $x, $y, $c, ['P4' => $p4, 'P6' => $p6, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    public function testG2P5RenameAwayVersusVoucherOfTheOldName(): void
    {
        [$x, $y, $c, $h] = $this->renameAwayPaused(true);
        $vid = $this->voucher($x);
        $p6 = (string) static::$app->exec(self::p6Code($x));
        self::go('rename');
        $p5 = static::$app->execFinish($h);
        $this->judgeRename('G2 P5 rename X->Y paused vs voucher X', $x, $y, $c, ['P5' => $p5, 'P6' => $p6, 'voucher_status' => self::vstatus($vid)], $p6 === 'GRANTED' ? 1 : 0);
    }

    public function testG3P4RenameAwayVersusP1CreationOfTheOldName(): void
    {
        [$x, $y, $c, $h] = $this->renameAwayPaused(false);
        $p1 = (string) static::$app->exec(self::p1Code('ad', $x));
        $prov = str_starts_with($p1, 'CREATED') ? $this->provision((int) substr($p1, 8)) : null;
        self::go('rename');
        $p4 = static::$app->execFinish($h);
        $this->judgeRename('G3 P4 rename X->Y paused vs P1 create X + provision', $x, $y, $c, ['P4' => $p4, 'P1' => $p1, 'provision' => $prov], 0,
            str_starts_with($p1, 'CREATED') ? (int) substr($p1, 8) : null);
    }
}
