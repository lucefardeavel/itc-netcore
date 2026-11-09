<?php
namespace Reseller\Tests\Unit\Ux;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Ui\Format;

/** V1.0.1: plan validity in words with the Arabic number agreement ("1 أشهر" read wrong), on every page that shows it. */
final class ValidityFormatTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['Format', 'Navigation'] as $c) {
            require_once $root . "/system/autoload/Reseller/Ui/{$c}.php";
        }
    }

    public function testArabicAgreement(): void
    {
        $cases = [
            [1, 'Months', 'شهر واحد'], [2, 'Months', 'شهران'], [3, 'Months', '3 أشهر'], [12, 'Months', '12 شهراً'],
            [1, 'Hrs', 'ساعة واحدة'], [2, 'Hrs', 'ساعتان'], [5, 'Hrs', '5 ساعات'], [24, 'Hrs', '24 ساعة'],
            [1, 'Mins', 'دقيقة واحدة'], [30, 'Mins', '30 دقيقة'], [10, 'Mins', '10 دقائق'], [100, 'Mins', '100 دقيقة'],
            [1, 'Days', 'يوم واحد'], [30, 'Days', '30 يوماً'], [7, 'Days', '7 أيام'],
        ];
        foreach ($cases as [$n, $unit, $want]) {
            $this->assertSame($want, Format::validity($n, $unit, 'arabic'), "{$n} {$unit}");
        }
        $this->assertSame('1 Months', Format::validity('1', 'Months', 'english'));
    }

    public function testNoPageWritesTheNumberAndTheUnitApart(): void
    {
        $root = dirname(__DIR__, 3);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/ui/ui', \FilesystemIterator::SKIP_DOTS));
        $left = [];
        foreach ($it as $f) {
            if ($f->getExtension() === 'tpl' && preg_match("/\\{\\\$\\w+(\\[[^\\]]+\\]|\\.\\w+)*\\['?validity'?\\]\\S*\\}\\s*\\{(Lang::T\\()?\\\$\\w+\\['validity_unit'\\]/",
                (string) file_get_contents($f->getPathname()))) {
                $left[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertSame([], $left);
        $this->assertSame(2, substr_count((string) file_get_contents($root . '/system/controllers/plan.php'), '\\Reseller\\Ui\\Format::validity((int) $vs[\'validity\'], $unit)'), 'printed cards');
    }
}
