<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Arabic-first interface (owner, 2026-10-18): every literal string the application passes to Lang::T - in every template and
 * in the application PHP (third-party vendor code, plugins and payment gateways excluded) - has an entry in
 * system/lan/arabic.json, so no screen of the Arabic interface falls back to English. A new string without its Arabic
 * entry fails here.
 */
final class ArabicCatalogueTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> literal => file */
    private static function literals(): array
    {
        $root = self::root();
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/ui/ui', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.tpl')) {
                $files[] = $f->getPathname();
            }
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/system', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (str_ends_with($p, '.php') && !preg_match('#/system/(vendor|plugin|paymentgateway)/#', $p)) {
                $files[] = $p;
            }
        }
        $out = [];
        foreach ($files as $p) {
            preg_match_all('/Lang::T\(\s*([\'"])(.*?)(?<!\\\\)\1\s*\)/s', (string) file_get_contents($p), $m);
            foreach ($m[2] as $s) {
                $s = (string) preg_replace('/\s+/', ' ', str_replace("\\'", "'", $s));
                if (str_contains($s, '$') || !preg_match('/[A-Za-z]/', $s)) {
                    continue; // a variable or a non-text argument
                }
                $out[$s] ??= substr($p, strlen($root) + 1);
            }
        }
        return $out;
    }

    public function testEveryInterfaceStringHasAnArabicEntry(): void
    {
        $raw = (string) file_get_contents(self::root() . '/system/lan/arabic.json');
        $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $literals = self::literals();
        $this->assertGreaterThan(1500, count($literals), 'the scan finds the interface strings');
        $missing = [];
        foreach ($literals as $s => $file) {
            if (!isset($catalog[$s]) && !isset($catalog[(string) preg_replace('/[^A-Za-z0-9]/', '_', $s)])) {
                $missing[] = "{$file}: {$s}";
            }
        }
        $this->assertSame([], $missing, 'strings without an Arabic entry in system/lan/arabic.json');
    }

    public function testTheCatalogueIsWellFormed(): void
    {
        $raw = (string) file_get_contents(self::root() . '/system/lan/arabic.json');
        $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/m', $raw, $keys);
        $this->assertSame(count($keys[1]), count(array_unique($keys[1])), 'no key is defined twice (a duplicate silently overrides)');
        foreach ($catalog as $k => $v) {
            $this->assertIsString($v, $k);
            $this->assertNotSame('', trim($v), "{$k} has an empty translation");
        }
    }

    /** @return array<string,mixed> */
    private static function catalog(): array
    {
        return json_decode((string) file_get_contents(self::root() . '/system/lan/arabic.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function arabic(array $catalog, string $s): ?string
    {
        $s = (string) preg_replace('/\s+/', ' ', $s);
        return $catalog[$s] ?? $catalog[(string) preg_replace('/[^A-Za-z0-9]/', '_', $s)] ?? null;
    }

    public function testEveryDomainRefusalHasAnArabicEntry(): void
    {
        // UI polish 2026-10-29: DomainError messages reach the screen through Lang::T($e->getMessage()), which the literal scan
        // above cannot see (e.g. CUSTOMER_IDENTITY_TAKEN and the static IP refusals of R-HF-CARRY-01 were shown in English)
        $src = (string) file_get_contents(self::root() . '/system/autoload/Reseller/Domain/DomainError.php');
        $this->assertSame(1, preg_match('/MESSAGES = \[(.*?)\n    \];/s', $src, $block));
        preg_match_all("/^\s*'([A-Z_]+)' => '((?:[^'\\\\]|\\\\.)*)',/m", $block[1], $m, PREG_SET_ORDER);
        $this->assertGreaterThan(80, count($m), 'the scan finds the refusal messages');
        $catalog = self::catalog();
        $missing = [];
        foreach ($m as [, $code, $msg]) {
            if (self::arabic($catalog, stripslashes($msg)) === null) {
                $missing[] = "{$code}: {$msg}";
            }
        }
        $this->assertSame([], $missing, 'DomainError messages without an Arabic entry');
    }

    public function testEveryLiteralRefusalDetailHasAnArabicEntry(): void
    {
        // Round 3 (2026-10-29): a literal detail passed as new DomainError('CODE', '...') replaces the standard message and
        // reaches the screen the same way (Lang::T($e->getMessage())); the scan above sees only DomainError::MESSAGES
        $root = self::root();
        $details = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/system', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (!str_ends_with($p, '.php') || preg_match('#/system/(vendor|plugin|paymentgateway)/#', $p)) {
                continue;
            }
            preg_match_all("/new\\s+(?:\\\\?Reseller\\\\Domain\\\\)?DomainError\\(\\s*'[A-Z_0-9]+'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", (string) file_get_contents($p), $m);
            foreach ($m[1] as $s) {
                $details[stripslashes($s)] ??= substr($p, strlen($root) + 1);
            }
        }
        $this->assertGreaterThan(30, count($details), 'the scan finds the literal refusal details');
        $catalog = self::catalog();
        $missing = [];
        foreach ($details as $s => $file) {
            if (self::arabic($catalog, $s) === null) {
                $missing[] = "{$file}: {$s}";
            }
        }
        $this->assertSame([], $missing, 'literal DomainError details without an Arabic entry');
    }

    public function testTheEntriesOfOneStaffMenuHaveDistinctArabicLabels(): void
    {
        // UI polish 2026-10-29: "Refill Customer" (voucher redemption) and "Recharge Customer" (package activation) shared one
        // Arabic label, as did "Vouchers" and "Coupons"
        $src = (string) file_get_contents(self::root() . '/system/autoload/Reseller/Ui/Navigation.php');
        preg_match_all("/self::group\\('(\\w+)',(.*?), (?:'[A-Z_]+'|null), (?:'[A-Z_]+'|null)\\);/s", $src, $groups, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(10, count($groups), 'the scan finds the menu groups');
        $catalog = self::catalog();
        foreach ($groups as [, $id, $body]) {
            preg_match_all("/\\[\\s*'[^']+'\\s*,\\s*'([^']+)'\\s*,\\s*'fa-/", $body, $items);
            $labels = [];
            foreach ($items[1] as $en) {
                $labels[$en] = self::arabic($catalog, $en) ?? $en;
            }
            $this->assertSame(array_values($labels), array_values(array_unique($labels)), "menu group {$id}: one Arabic label per entry");
        }
        $this->assertNotSame(self::arabic($catalog, 'Refill Customer'), self::arabic($catalog, 'Recharge Customer'));
        $this->assertNotSame(self::arabic($catalog, 'Vouchers'), self::arabic($catalog, 'Coupons'));
    }

    public function testActivationNoticesShowTheTranslatedState(): void
    {
        $src = (string) file_get_contents(self::root() . '/system/controllers/activation.php');
        $this->assertDoesNotMatchRegularExpression("/': ' \\. \\$(out|row)\\['status'\\]/", $src, 'a raw state code (COMPLETED, FAILED ...) is never shown to the user');
        $this->assertGreaterThanOrEqual(4, substr_count($src, "Lang::T(\\Reseller\\Ui\\Format::stateLabel("));
    }
}
