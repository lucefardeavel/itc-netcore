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
        $this->assertSame(['nuxr_bop_', 'nuxr_identity_', 'nuxr_mig_', 'nuxr_tree_', 'nuxr_user_'], array_keys($names), 'runtime named locks');
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
        $this->assertLessThan(strpos($pkg, "run_hook(\"recharge_user\")"), strpos($pkg, 'LegacyReprovisionService::assertDeviceAllowed($c)'), 'Package (pinned, unchanged): the device guard is still pre-write');
        // mutation: dropping the check from provision() is detected
        $mutated = str_replace("        self::singleOwner(\$policy->username);\n", '', $radius);
        // the legacy deadlock retry re-runs ONLY the policy write, never the ownership check skipped, and only for 1213
        $prov = substr($radius, strpos($radius, 'function provision('));
        $this->assertMatchesRegularExpression("/=== ProvisionResult::FAILED && \\(int\\) \\(\\\$result->metadata\\['db_error'\\] \\?\\? 0\\) === 1213\\)/", $prov);
        $this->assertNotSame($radius, $mutated, 'mutation applied');
        $body = substr($mutated, strpos($mutated, 'function provision('));
        $this->assertStringNotContainsString('self::singleOwner(', substr($body, 0, strpos($body, '->provision($policy)')));
    }
}
