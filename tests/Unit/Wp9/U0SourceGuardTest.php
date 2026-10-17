<?php
namespace Reseller\Tests\Unit\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP9 U0 source guard (static): the identity-lock contract stays wired the way U0 tested it.
 *  - the identity lock is taken only by the acquirers P1–P6 / P5r, and only through CustomerIdentity::hold;
 *  - P1 takes the tree lock, then the identity lock, then the transaction (never Tx::withTreeLock any more);
 *  - every acquiring write sits inside its hold (P2 save, P3 claim + insert, P4 save, P5 write, P5r write, P6 claim);
 *  - CustomerIdentity reads only (SELECT / GET_LOCK / RELEASE_LOCK / IS_USED_LOCK), never writes RADIUS or calls a device;
 *  - the runtime named locks are exactly the tree, voucher-username and identity locks;
 *  - test seams are never armed by runtime code.
 * The checker is mutation-checked: removing a hold or moving a write out of it is detected.
 * Final closure (owner WP9_U0_HARDENING_CONDITIONALLY_ACCEPTED): no rechargeUser notification carries a voucher-only
 * credential (N-07); the ownership count never runs inside an open transaction without the identity lock (§4); a
 * voucher-only grant is GRANTED only on this grant's own confirmed RADIUS write (§5).
 */
final class U0SourceGuardTest extends TestCase
{
    private const CI = 'system/autoload/Reseller/Domain/CustomerIdentity.php';

    private static function src(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel));
    }

    /** @return array<string,string> runtime PHP sources (system/, root *.php) */
    private static function runtime(): array
    {
        $root = dirname(__DIR__, 3);
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/system', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if (str_ends_with($rel, '.php') && !str_starts_with($rel, 'system/vendor/') && !str_contains($rel, '/PEAR2/')) {
                $out[$rel] = self::src($rel);
            }
        }
        foreach (glob($root . '/*.php') as $f) {
            $out[basename($f)] = self::src(basename($f));
        }
        return $out;
    }

    /** the source text of the CustomerIdentity::hold( … ) call starting at $pos (balanced parentheses) */
    private static function holdCall(string $src, int $pos): string
    {
        $open = strpos($src, '(', $pos);
        $depth = 0;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            $depth += $src[$i] === '(' ? 1 : ($src[$i] === ')' ? -1 : 0);
            if ($depth === 0) {
                return substr($src, $pos, $i - $pos + 1);
            }
        }
        return substr($src, $pos);
    }

    /** @return list<string> the bodies of every CustomerIdentity::hold( … ) call in $src */
    private static function holds(string $src): array
    {
        preg_match_all('/CustomerIdentity::hold\(/', $src, $m, PREG_OFFSET_CAPTURE);
        return array_map(static fn(array $hit): string => self::holdCall($src, $hit[1]), $m[0]);
    }

    /** @param array<string,list<string>> $required file => patterns that must appear inside one of its holds */
    private static function violations(array $sources, array $required): array
    {
        $bad = [];
        foreach ($required as $rel => $patterns) {
            $holds = self::holds($sources[$rel] ?? '');
            foreach ($patterns as $p) {
                if (!array_filter($holds, static fn(string $h): bool => (bool) preg_match($p, $h))) {
                    $bad[] = "{$rel}: {$p}";
                }
            }
        }
        return $bad;
    }

    private const REQUIRED = [
        'system/autoload/Reseller/Domain/CustomerService.php' => ['/assertAvailable\(/', '/INSERT INTO tbl_customers/'],
        'system/controllers/register.php' => ['/assertAvailable\(/', '/\$d->save\(\)/'],
        'system/autoload/Reseller/Domain/VoucherRedemptionService.php' => ['/assertAvailable\(\$pdo, \[\$target->username\]\);\s*return self::claim\(/', '/assertAvailable\(\$pdo, \[\$username\]\);.*self::claim\(\$code, false\).*INSERT INTO tbl_customers/s'],
        'system/controllers/customers.php' => ['/assertAvailable\(.*\$c->save\(\);/s'],
        'system/autoload/Reseller/Domain/LegacyReprovisionService.php' => ['/assertAvailable\(.*\$write\(\);/s', '/CustomerIdentity::conflicts\(.*return \$write\(\);/s'],
    ];

    public function testEveryAcquiringWriteRunsInsideTheIdentityLock(): void
    {
        $sources = self::runtime();
        $this->assertSame([], self::violations($sources, self::REQUIRED));
        $users = array_keys(array_filter($sources, static fn(string $s, string $rel): bool => $rel !== self::CI && str_contains($s, 'CustomerIdentity::hold('), ARRAY_FILTER_USE_BOTH));
        sort($users);
        $this->assertSame(['system/autoload/Reseller/Domain/CustomerService.php', 'system/autoload/Reseller/Domain/LegacyReprovisionService.php',
            'system/autoload/Reseller/Domain/VoucherRedemptionService.php', 'system/controllers/customers.php', 'system/controllers/register.php'], $users,
            'the identity lock is taken only by the U0 acquirers');
    }

    public function testCheckerDetectsAWriteMovedOutOfItsHold(): void
    {
        $sources = self::runtime();
        $rel = 'system/controllers/register.php';
        $mutated = $sources;
        $mutated[$rel] = str_replace("\\Reseller\\Domain\\CustomerIdentity::assertAvailable(\$pdo, [(string) \$d->username]);\n                    return (bool) \$d->save();",
            "return true;", $sources[$rel]);
        $this->assertNotSame($sources[$rel], $mutated[$rel], 'mutation applied');
        $this->assertNotSame([], self::violations($mutated, [$rel => self::REQUIRED[$rel]]), 'a save outside the hold is detected');
        $rel = 'system/autoload/Reseller/Domain/VoucherRedemptionService.php';
        $mutated[$rel] = preg_replace('/CustomerIdentity::hold\(static function \(\\\\PDO \$pdo\) use \(\$code, \$target, \$source, &\$candidateId\)/', 'call_user_func(static function (\PDO $pdo) use ($code, $target, $source, &$candidateId)', $sources[$rel]);
        $this->assertNotSame($sources[$rel], $mutated[$rel], 'mutation applied');
        $this->assertNotSame([], self::violations($mutated, [$rel => self::REQUIRED[$rel]]), 'a P6 claim outside the lock is detected');
    }

    public function testCreationTakesTreeThenIdentityThenTransaction(): void
    {
        $s = self::src('system/autoload/Reseller/Domain/CustomerService.php');
        $create = substr($s, strpos($s, 'public static function create('), strpos($s, 'public static function assignSales(') - strpos($s, 'public static function create('));
        $this->assertMatchesRegularExpression('/TreeSerialization::hold\(static fn\(\): int => CustomerIdentity::hold\(static fn\(\): int => Tx::run\(/', $create);
        $this->assertStringNotContainsString('Tx::withTreeLock(', $create, 'P1 never takes the identity lock inside a transaction');
        $p3 = self::src('system/autoload/Reseller/Domain/VoucherRedemptionService.php');
        $this->assertMatchesRegularExpression('/withUsernameLock\(\$pdo, \$username, static function[^{]*\{[^}]*\}\s*\n\s*\/\/[^\n]*\n[^\n]*\n\s*return CustomerIdentity::hold\(/', $p3, 'P3: L-2 then L-3');
        $p5 = self::src('system/autoload/Reseller/Domain/LegacyReprovisionService.php');
        $this->assertGreaterThan(strpos($p5, 'TreeSerialization::hold('), strpos($p5, 'CustomerIdentity::hold('), 'P5: L-1 then L-3');
    }

    public function testCustomerIdentityOnlyReadsAndNeverTouchesADevice(): void
    {
        $s = self::src(self::CI);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP)\s/i', preg_replace('/\/\*.*?\*\/|\/\/[^\n]*|^\s*\*[^\n]*$/ms', '', $s));
        $this->assertDoesNotMatchRegularExpression('/->(add_customer|sync_customer|change_username|remove_customer|applyUser|renameUser|suspendUser|removeUser)\(|RadiusProvisioner|CoAClient|curl_|fsockopen|exec\(/', $s);
        $this->assertStringContainsString("'SELECT GET_LOCK(?, ?)'", $s);
        $this->assertStringContainsString('MAX_EXECUTION_TIME(', $s);
        $this->assertStringContainsString('max_statement_time=', $s);
    }

    public function testRuntimeNamedLocksAndSeams(): void
    {
        $names = [];
        foreach (self::runtime() as $rel => $s) {
            if (preg_match_all("/'(nuxr_[a-z]+_)'/", $s, $m)) {
                foreach ($m[1] as $n) {
                    $names[$n] = true;
                }
            }
            $this->assertDoesNotMatchRegularExpression('/ActivationFaults::\$hook\s*=/', $s, "{$rel}: no runtime test seam");
        }
        ksort($names);
        // pre-existing: nuxr_bop_ (legacy balance-operation idempotency) and nuxr_mig_ (migration runner / readiness) never
        // call an identity acquirer. Final hardening: the balance grant (nuxr_bop_ held, no transaction) reaches the
        // ownership check (L-3) through Package::rechargeUser - order nuxr_bop_ -> L-3, never the reverse
        // WP9-B / R-4: + nuxr_svc_ (per-customer service lock, OUTERMOST: never taken inside a transaction or under the
        // tree / identity lock - ServiceLock::acquire refuses that order)
        // V1 quota (owner QUOTA_V1_GB_SUPPORT_APPROVED, 2026-10-17): + nuxr_quota_ (one accounting quota enforcer at a time,
        // system/reseller/quota.php, RADIUS connection). It never nests: no other named lock, no identity / tree / service
        // acquirer and no activation is taken under it (asserted below)
        $this->assertSame(['nuxr_bop_', 'nuxr_identity_', 'nuxr_mig_', 'nuxr_quota_', 'nuxr_svc_', 'nuxr_tree_', 'nuxr_user_'], array_keys($names), 'runtime named locks');
        $quota = self::src('system/reseller/quota.php');
        $this->assertSame(1, preg_match_all("/'nuxr_[a-z]+_'/", $quota), 'quota.php: its own lock only');
        foreach (['system/reseller/quota.php', 'system/autoload/Reseller/Radius/QuotaEnforcer.php', 'system/autoload/Reseller/Radius/SessionTermination.php'] as $rel) {
            $this->assertDoesNotMatchRegularExpression('/CustomerIdentity::|TreeSerialization::|ServiceLock::|VoucherRedemptionService::|ActivationService::|CustomerService::create\(|LegacyReprovisionService::run\(|GET_LOCK\(\?/', self::src($rel), "{$rel}: nothing nests under the quota lock");
        }
        $svc = self::src('system/autoload/Reseller/Domain/ServiceLock.php');
        $this->assertStringContainsString('$pdo->inTransaction() || TreeSerialization::held($pdo) || CustomerIdentity::held($pdo)', $svc);
        foreach (['system/autoload/Reseller/Domain/LegacyCustomerBalanceService.php', 'system/reseller/Migration/Runner.php', 'system/autoload/Reseller/Domain/SchemaReadiness.php'] as $rel) {
            $this->assertDoesNotMatchRegularExpression('/CustomerIdentity::|VoucherRedemptionService::|CustomerService::create\(|LegacyReprovisionService::run\(/', self::src($rel), "{$rel}: no identity acquirer under its lock");
        }
    }

    /** final hardening (D-U0-R1): every write of a subscriber's RADIUS login policy is preceded by the ownership check */
    public function testEveryPolicyWriterChecksSingleOwnershipFirst(): void
    {
        $radius = self::src('system/devices/Radius.php');
        foreach (['provision' => '->provision($policy)', 'customerDeactivate' => 'RadiusProvisioner(self::repository()))->suspend(',
            'customerDelete' => 'RadiusProvisioner(self::repository()))->remove('] as $fn => $write) {
            $body = substr($radius, strpos($radius, "function {$fn}("));
            $body = substr($body, 0, strpos($body, $write) ?: 0);
            $this->assertStringContainsString('self::singleOwner(', $body, "Radius::{$fn}: ownership check before the write");
        }
        $this->assertMatchesRegularExpression('/private static function singleOwner\(string \$key\): void\s*\{\s*try \{\s*\\\\Reseller\\\\Domain\\\\CustomerIdentity::assertSingleOwner\(\$key\);/', $radius);
        $act = self::src('system/autoload/Reseller/Activation/ActivationService.php');
        $prov = substr($act, strpos($act, 'public static function provision('));
        $this->assertLessThan(strpos($prov, '->applyPlan('), strpos($prov, 'CustomerIdentity::assertSingleOwner($policy->username)'), 'activation: ownership check before any RADIUS write');
        $lrs = self::src('system/autoload/Reseller/Domain/LegacyReprovisionService.php');
        $guard = substr($lrs, strpos($lrs, 'public static function assertDeviceAllowed('));
        $guard = substr($guard, 0, strpos($guard, 'public static function renamePairs('));
        $this->assertSame(2, substr_count($guard, 'self::singleOwner($row, $id);'), 'device guard: the ownership check on the permit path and after the WP8 owned-name refusal');
        $this->assertLessThan(strpos($guard, 'self::singleOwner($row, $id);', strpos($guard, 'self::deviceOwned(')), strpos($guard, "'RESELLER_OWNED_REFUSED'"), 'the WP8 refusal keeps precedence');
        $this->assertStringContainsString('CustomerIdentity::assertSingleOwner(', $guard);
        $rename = substr($lrs, strpos($lrs, 'public static function renameCheck('));
        $this->assertLessThan(strpos($rename, '->equivalentKeys('), strpos($rename, 'CustomerIdentity::assertSingleOwner($from)'), 'rename: the source key ownership first');
        $pkg = self::src('system/autoload/Package.php');
        $this->assertLessThan(strpos($pkg, "run_hook(\"recharge_user\")"), strpos($pkg, 'LegacyReprovisionService::assertDeviceAllowed($c)'), 'Package (pinned): the device guard is still pre-write');
        // mutation: dropping the check from provision() is detected
        $mutated = str_replace("        self::singleOwner(\$policy->username);\n", '', $radius);
        // the legacy deadlock retry re-runs ONLY the policy write, never the ownership check skipped, and only for 1213
        $prov = substr($radius, strpos($radius, 'function provision('));
        $this->assertMatchesRegularExpression("/=== ProvisionResult::FAILED && \\(int\\) \\(\\\$result->metadata\\['db_error'\\] \\?\\? 0\\) === 1213\\)/", $prov);
        $this->assertNotSame($radius, $mutated, 'mutation applied');
        $body = substr($mutated, strpos($mutated, 'function provision('));
        $this->assertStringNotContainsString('self::singleOwner(', substr($body, 0, strpos($body, '->provision($policy)')));
    }

    /** the source text of the call starting at $pos (balanced parentheses) */
    private static function call(string $src, int $pos): string
    {
        return self::holdCall($src, $pos);
    }

    /** @return list<string> N-07 violations: rechargeUser notifications that can carry the voucher-only code */
    private static function notificationLeaks(string $pkg): array
    {
        $body = substr($pkg, strpos($pkg, 'public static function rechargeUser('));
        $body = substr($body, 0, strpos($body, 'public static function rechargeBalance('));
        $out = [];
        if (!str_contains($body, "\$nuxrTg = \$isVoucher ? 'voucher_only' : 'u' . \$c['username'];")) {
            $out[] = 'no voucher-only Telegram name';
        }
        $n = 0;
        for ($pos = 0; ($pos = strpos($body, 'Message::sendTelegram(', $pos)) !== false; $pos++) {
            $n++;
            $call = preg_replace("/\\(\\\$isVoucher \\? 'voucher ' \\. \\\$inv : \\\$channel\\)/", '<redacted channel>', self::call($body, $pos));
            foreach (['$c[username]', "\$c['username']", '$channel', '$c[password]', "\$c['password']"] as $needle) {
                if (str_contains($call, $needle)) {
                    $out[] = "Telegram call {$n} interpolates {$needle}";
                }
            }
        }
        if ($n !== 4) {
            $out[] = "expected 4 Telegram calls, found {$n}";
        }
        if (!preg_match('/if \(!\$isVoucher\) \{\s*Message::sendInvoice\(\$c, \$t\);\s*\}/', $body) || substr_count($body, 'Message::sendInvoice(') !== 1) {
            $out[] = 'invoice notification sent for voucher-only';
        }
        return $out;
    }

    public function testNoRechargeNotificationCarriesAVoucherOnlyCredential(): void
    {
        $pkg = self::src('system/autoload/Package.php');
        $this->assertSame([], self::notificationLeaks($pkg));
        // mutation: the pre-N-07 forms are detected
        $this->assertNotSame([], self::notificationLeaks(str_replace('"#$nuxrTg $c[fullname] #buy', '"#u$c[username] $c[fullname] #buy', $pkg)));
        $this->assertNotSame([], self::notificationLeaks(str_replace("(\$isVoucher ? 'voucher ' . \$inv : \$channel)", '$channel', $pkg)));
        $this->assertNotSame([], self::notificationLeaks(preg_replace('/if \(!\$isVoucher\) \{\s*(Message::sendInvoice\(\$c, \$t\);)\s*\}/', '$1', $pkg)));
    }

    public function testOwnershipCountInsideAnOpenTransactionWithoutTheLockFailsClosed(): void
    {
        $ci = self::src(self::CI);
        $body = substr($ci, strpos($ci, 'public static function assertSingleOwner('));
        $body = substr($body, 0, strpos($body, 'public static function keysOf('));
        $held = strpos($body, '$held = self::held($pdo);');
        $tx = strpos($body, 'if ($pdo->inTransaction()) {');
        $this->assertNotFalse($held);
        $this->assertNotFalse($tx);
        $this->assertLessThan($tx, $held, 'a caller holding L-3 is counted in place first');
        $branch = substr($body, $tx, strpos($body, '}', $tx) - $tx);
        $this->assertStringContainsString("throw new DomainError('IDENTITY_CHECK_UNAVAILABLE');", $branch, 'open transaction without the lock: refused');
        $this->assertStringNotContainsString('$check(', $branch, 'no unlocked count inside a transaction');
    }

    public function testVoucherOnlyGrantNeedsThisGrantsConfirmedWrite(): void
    {
        $prov = self::src('system/autoload/Reseller/Radius/RadiusProvisioner.php');
        $this->assertMatchesRegularExpression('/public function provision\(RadiusPolicy \$policy\): ProvisionResult\s*\{\s*return self::\$results\[\$policy->username\] = \$this->run\(/', $prov);
        $vrs = self::src('system/autoload/Reseller/Domain/VoucherRedemptionService.php');
        $grant = substr($vrs, strpos($vrs, 'private static function grant('));
        $this->assertLessThan(strpos($grant, '\Package::rechargeUser('), strpos($grant, 'RadiusProvisioner::forgetProvision(trim($code));'), 'no earlier result counts');
        $this->assertStringContainsString('($why = self::unconfirmed($v, $code)) !== null', $grant);
        $this->assertLessThan(strpos($grant, "status = '1'"), strpos($grant, 'self::unconfirmed($v, $code)'), 'confirmation before the voucher is finalized');
        $check = substr($vrs, strpos($vrs, 'private static function unconfirmed('));
        $check = substr($check, 0, strpos($check, 'private static function candidate('));
        $this->assertStringContainsString('RadiusProvisioner::lastProvision(trim($code))', $check);
        $this->assertStringContainsString('if (!$r->isSuccess()) {', $check);
        $this->assertStringNotContainsString('servicePresent', $vrs, 'rows under the name alone are no longer proof');
    }

    public function testTheVoucherOnlyLoginGateNeverRecordsTheTypedCode(): void
    {
        $login = self::src('system/controllers/login.php');
        $this->assertStringContainsString("if (\\Reseller\\Domain\\ServiceGate::isBlockedUsername(\$voucher)) { \\Reseller\\Domain\\ServiceGate::refused('loginVoucherOnly'); r2(getUrl('login'), 'e', \\Reseller\\Domain\\ServiceGate::message()); }", $login);
        $this->assertDoesNotMatchRegularExpression('/ServiceGate::(guardUsername|refused)\([^;]*\$voucher/', $login, 'the voucher code is never passed to a refusal record');
    }

    /** @return list<string> R-FC-4: entry points that serve a voucher-only identity without the voucher state check */
    private static function voucherStateGaps(array $src): array
    {
        $out = [];
        $lrs = $src['system/autoload/Reseller/Domain/LegacyReprovisionService.php'];
        $guard = substr($lrs, strpos($lrs, 'public static function assertDeviceAllowed('));
        $guard = substr($guard, 0, strpos($guard, 'private static function singleOwner('));
        $check = strpos($guard, 'VoucherRedemptionService::voucherOnlyServiceRefusal(');
        if ($check === false || $check < strpos($guard, "'RESELLER_OWNED_REFUSED'") || $check > strrpos($guard, 'self::singleOwner($row, $id);')) {
            $out[] = 'device guard: voucher state check missing or out of order (after the WP8 refusal, before the ownership check)';
        }
        $login = $src['system/controllers/login.php'];
        $e12 = substr($login, strpos($login, "if (!empty(_post('voucher_only')))"));
        $e12 = substr($e12, 0, strpos($e12, '->add_customer($c, $p);'));
        if (!str_contains($e12, 'VoucherRedemptionService::voucherOnlyServiceRefusal($voucher) !== null')) {
            $out[] = 'login.php E12: re-activation without the voucher state check';
        }
        $rad = $src['radius.php'];
        $auth = substr($rad, strpos($rad, "case 'authenticate':"), strpos($rad, "case 'authorize':") - strpos($rad, "case 'authenticate':"));
        if (!str_contains($auth, "radius_active_recharge(\$username) && \\Reseller\\Domain\\VoucherRedemptionService::voucherOnlyServiceRefusal(\$username) === null")) {
            $out[] = 'radius.php E16: an active recharge authenticates without the voucher state check';
        }
        $authz = substr($rad, strpos($rad, "case 'authorize':"));
        $tur = strpos($authz, 'if ($tur) {');
        $authz = substr($authz, $tur, strpos($authz, 'process_radiust_rest($tur, $code);', $tur) - $tur);
        if (!str_contains($authz, "(int) \$tur['customer_id'] === 0 && \\Reseller\\Domain\\VoucherRedemptionService::voucherOnlyServiceRefusal((string) \$tur['username']) !== null")) {
            $out[] = 'radius.php E15: a voucher-only recharge row is answered without the voucher state check';
        }
        $vrs = $src['system/autoload/Reseller/Domain/VoucherRedemptionService.php'];
        $grant = substr($vrs, strpos($vrs, 'private static function grant('));
        if (!preg_match('/self::\$inFlight = \$target->customerId === 0 \? trim\(\$code\) : null;\s*try \{\s*\$result = \\\\Package::rechargeUser\(/', $grant)
            || !preg_match('/\} finally \{\s*self::\$inFlight = null;\s*\}/', $grant)) {
            $out[] = 'grant: the in-flight permit is not scoped to its own Package call';
        }
        return $out;
    }

    public function testEveryVoucherOnlyServingEntryPointChecksTheVoucherState(): void
    {
        $src = [];
        foreach (['system/autoload/Reseller/Domain/LegacyReprovisionService.php', 'system/controllers/login.php', 'radius.php', 'system/autoload/Reseller/Domain/VoucherRedemptionService.php'] as $f) {
            $src[$f] = self::src($f);
        }
        $this->assertSame([], self::voucherStateGaps($src));
        // mutation: removing any one check is detected
        $mut = [
            ['system/controllers/login.php', 'VoucherRedemptionService::voucherOnlyServiceRefusal($voucher) !== null', 'false'],
            ['radius.php', "radius_active_recharge(\$username) && \\Reseller\\Domain\\VoucherRedemptionService::voucherOnlyServiceRefusal(\$username) === null", 'radius_active_recharge($username)'],
            ['radius.php', "(int) \$tur['customer_id'] === 0 && \\Reseller\\Domain\\VoucherRedemptionService::voucherOnlyServiceRefusal((string) \$tur['username']) !== null", 'false'],
            ['system/autoload/Reseller/Domain/LegacyReprovisionService.php', '($why = VoucherRedemptionService::voucherOnlyServiceRefusal(', '($why = null) && ('],
            ['system/autoload/Reseller/Domain/VoucherRedemptionService.php', '            self::$inFlight = null;' . "\n        }", "        }"],
        ];
        foreach ($mut as [$f, $from, $to]) {
            $m = $src;
            $m[$f] = str_replace($from, $to, $src[$f], $n);
            $this->assertGreaterThanOrEqual(1, $n, "mutation applied: {$f}");
            $this->assertNotSame([], self::voucherStateGaps($m), "mutation detected: {$f}");
        }
    }

    /** @return list<string> R-FC-4E: the voucher-only service decision is made on the canonical identity, never on bytes alone */
    private static function canonicalDecisionGaps(string $vrs, string $ci): array
    {
        $out = [];
        $fn = substr($vrs, strpos($vrs, 'public static function voucherOnlyServiceRefusal('));
        $fn = substr($fn, 0, strpos($fn, '// ------------------------------------------------------------------ steps'));
        if (!str_contains($fn, 'CustomerIdentity::voucherIdentity(self::pdo(), $key)')) {
            $out[] = 'the decision does not use the canonical identity lookup';
        }
        if (preg_match('/BINARY\s+code/i', $fn)) {
            $out[] = 'a byte-exact voucher lookup decides';
        }
        if (!preg_match("/if \(\\\$key === ''\) \{\s*return 'VOUCHER_IDENTITY_INVALID';/", $fn) || !preg_match("/if \(\\\$id === null\) \{\s*return 'VOUCHER_STATE_UNAVAILABLE';/", $fn)) {
            $out[] = 'an empty identity or an unavailable lookup is not refused';
        }
        if (strpos($fn, "return 'VOUCHER_IN_REVIEW';") === false || strpos($fn, "return 'VOUCHER_IDENTITY_MISMATCH';") === false) {
            $out[] = 'an equivalent CLAIMED row or a variant spelling is not refused';
        }
        $lookup = substr($ci, strpos($ci, 'public static function voucherIdentity('));
        $lookup = substr($lookup, 0, strpos($lookup, 'public static function assertSingleOwner('));
        if (substr_count($lookup, 'self::bounded(') !== 2 || !str_contains($lookup, 'count($vouchers) > self::CANDIDATES || count($recharges) > self::CANDIDATES')) {
            $out[] = 'the canonical lookup is not bounded';
        }
        return $out;
    }

    public function testTheVoucherOnlyServiceDecisionUsesTheCanonicalIdentity(): void
    {
        $vrs = self::src('system/autoload/Reseller/Domain/VoucherRedemptionService.php');
        $ci = self::src(self::CI);
        $this->assertSame([], self::canonicalDecisionGaps($vrs, $ci));
        // mutation: the pre-R-FC-4E byte-exact decision, a dropped refusal and an unbounded lookup are detected
        $this->assertNotSame([], self::canonicalDecisionGaps(str_replace('CustomerIdentity::voucherIdentity(self::pdo(), $key)', "['vouchers' => [], 'recharges' => []]", $vrs), $ci));
        $this->assertNotSame([], self::canonicalDecisionGaps(str_replace("return 'VOUCHER_IN_REVIEW';", 'return null;', $vrs), $ci));
        $this->assertNotSame([], self::canonicalDecisionGaps($vrs, str_replace('count($vouchers) > self::CANDIDATES || ', '', $ci)));
    }
}

