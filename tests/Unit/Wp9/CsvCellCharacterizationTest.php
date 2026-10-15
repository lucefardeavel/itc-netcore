<?php
namespace Tests\Unit\Wp9;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reseller\Ui\Format;

// The test bootstrap autoloads Reseller\{Security,Domain,Audit,Finance,Radius,Activation} only; Format is in Reseller\Ui.
require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/Format.php';

/**
 * WP9 T0-06b (owner: WP9_T0_PHP_COMPLETION_AUTHORIZED, 2026-10-15) - CHARACTERISATION of Format::csvCell as it is at
 * HEAD. Pure unit: no database, network, file or process. Format.php is not modified.
 *
 * Every case asserts the CURRENT output. Cases named "defect..." document behaviour that the WP9 plan expected to be
 * unsafe (PLAN N-07 / REPORT O-T0-4): when they pass, the defect is REPRODUCED (DEFECT_REPRODUCED), not accepted.
 * The neutralised and numeric cases are the positive controls.
 */
final class CsvCellCharacterizationTest extends TestCase
{
    /** @return array<string,array{0:mixed,1:string}> positive controls: a leading = + - @ TAB CR is neutralised */
    public static function neutralised(): array
    {
        return [
            'equals formula' => ['=1+1', "'=1+1"],
            'hyperlink formula' => ['=HYPERLINK("http://example.invalid","c")', "'=HYPERLINK(\"http://example.invalid\",\"c\")"],
            'plus formula' => ['+1+1', "'+1+1"],
            'at formula' => ['@SUM(1)', "'@SUM(1)"],
            'minus formula' => ['-1+1', "'-1+1"],
            'dde style' => ["=cmd|' /C calc'!A0", "'=cmd|' /C calc'!A0"],
            'leading tab' => ["\t=1", "'\t=1"],
            'leading carriage return' => ["\r=1", "'\r=1"],
            'explicit plus number' => ['+5', "'+5"],
        ];
    }

    /** @return array<string,array{0:mixed,1:string}> positive controls: values that must stay unchanged */
    public static function unchanged(): array
    {
        return [
            'negative decimal' => ['-12.5', '-12.5'],
            'negative integer' => ['-3', '-3'],
            'integer' => ['42', '42'],
            'plain text' => ['Ali Hendi', 'Ali Hendi'],
            'arabic text' => ['علي', 'علي'],
            'empty string' => ['', ''],
            'null' => [null, ''],
            'true' => [true, 'yes'],
            'false' => [false, 'no'],
            'formula char later' => ['a=1+1', 'a=1+1'],
        ];
    }

    /** @return array<string,array{0:mixed,1:string}> expected-unsafe cases (DEFECT_REPRODUCED when they pass) */
    public static function defects(): array
    {
        return [
            'leading space before equals' => [' =1+1', ' =1+1'],
            'leading newline before equals' => ["\n=1+1", "\n=1+1"],
            'leading space before at' => [' @SUM(1)', ' @SUM(1)'],
            'leading no-break space before equals' => ["\u{00A0}=1+1", "\u{00A0}=1+1"],
        ];
    }

    #[DataProvider('neutralised')]
    public function testNeutralisedLeadingFormulaCharacter(mixed $in, string $current): void
    {
        $this->assertSame($current, Format::csvCell($in));
    }

    #[DataProvider('unchanged')]
    public function testValuesThatStayUnchanged(mixed $in, string $current): void
    {
        $this->assertSame($current, Format::csvCell($in));
    }

    #[DataProvider('defects')]
    public function testDefectReproducedFormulaAfterLeadingWhitespaceIsNotNeutralised(mixed $in, string $current): void
    {
        // characterisation: the current output is the unchanged input (no apostrophe prefix)
        $this->assertSame($current, Format::csvCell($in));
        $this->assertStringStartsNotWith("'", Format::csvCell($in));
    }
}
