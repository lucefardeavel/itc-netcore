<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\CustomerSearch;

/**
 * UX-11 (lab 2026-10-25): "أحمد" found أحمد كريم but "احمد" found nothing (plain LIKE, no Arabic folding), and a phone typed
 * with Arabic-Indic digits never matched. The term is folded (normalize()) and, for an Arabic term only, one more OR branch
 * per name column compares the column folded by the same constant map. The branch stays inside the one parenthesized
 * search group: the scope predicate the callers apply first is untouched, and a Latin term's predicate is unchanged.
 */
final class CustomerSearchArabicTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testNormalizeFoldsTheArabicSpellingVariants(): void
    {
        $this->assertSame('احمد', CustomerSearch::normalize('أحمد'));
        $this->assertSame('احمد', CustomerSearch::normalize('إحمد'));
        $this->assertSame('امنه', CustomerSearch::normalize('آمنة'));
        $this->assertSame('الله', CustomerSearch::normalize('ٱلله'));
        $this->assertSame('مصطفي', CustomerSearch::normalize('مصطفى'));
        $this->assertSame('فاطمه', CustomerSearch::normalize('فاطمة'));
        $this->assertSame('كريم', CustomerSearch::normalize('کریم'), 'Persian / Kurdish keheh and farsi yeh');
        $this->assertSame('زاخوه', CustomerSearch::normalize('زاخوە'), 'Kurdish ae');
        $this->assertSame('محمد', CustomerSearch::normalize('مـحـمـد'), 'tatweel removed');
        $this->assertSame('محمد', CustomerSearch::normalize('مُحَمَّدٌ'), 'harakat removed');
        $this->assertSame('رحمن', CustomerSearch::normalize('رحمٰن'), 'superscript alef removed');
        $this->assertSame('07701234567', CustomerSearch::normalize('٠٧٧٠١٢٣٤٥٦٧'));
        $this->assertSame('07701234567', CustomerSearch::normalize('۰۷۷۰۱۲۳۴۵۶۷'));
        $this->assertSame('Ahmed 0770_%', CustomerSearch::normalize('Ahmed 0770_%'), 'Latin text is unchanged');
        $this->assertSame('احمد كريم', CustomerSearch::normalize(CustomerSearch::normalize('أحمد كريم')), 'idempotent');
    }

    public function testTermReadsArabicIndicDigitsAsAscii(): void
    {
        $this->assertSame('07701234567', CustomerSearch::term('٠٧٧٠١٢٣٤٥٦٧'));
        $this->assertSame('0770 123', CustomerSearch::term('  ۰۷۷۰   ۱۲۳ '));
        $this->assertSame('أحمد', CustomerSearch::term('أحمد'), 'the term keeps its letters (the original branches still match the exact spelling)');
        $this->assertNull(CustomerSearch::term('٧'), 'one digit is still too short');
    }

    public function testFoldSqlIsTheFixedReplaceChainOfTheSameMap(): void
    {
        $sql = CustomerSearch::foldSql('tbl_customers.fullname');
        $this->assertSame(count(CustomerSearch::ARABIC_FOLD), substr_count($sql, 'REPLACE('), 'one REPLACE per map entry');
        foreach (CustomerSearch::ARABIC_FOLD as $from => $to) {
            $this->assertStringContainsString(", '{$from}', '{$to}')", $sql);
        }
        $this->assertStringNotContainsStringIgnoringCase('REGEXP', $sql, 'plain REPLACE only (engine-neutral)');
        $this->assertStringNotContainsStringIgnoringCase('COLLATE', $sql);
        $this->assertStringNotContainsString('?', $sql);
        $this->expectException(\InvalidArgumentException::class);
        CustomerSearch::foldSql("tbl_customers.fullname) OR (1=1");
    }

    /** @return list<string> top-level OR branches of a predicate "(a OR b ...)" (quoted literals skipped) */
    private static function branches(string $pred): array
    {
        if (!str_starts_with($pred, '(') || !str_ends_with($pred, ')')) {
            throw new \RuntimeException('not a parenthesized group: ' . $pred);
        }
        $out = [];
        $depth = 0;
        $quoted = false;
        $cur = '';
        $inner = substr($pred, 1, -1);
        for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
            $ch = $inner[$i];
            if ($ch === "'") {
                $quoted = !$quoted;
            } elseif (!$quoted && $ch === '(') {
                $depth++;
            } elseif (!$quoted && $ch === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new \RuntimeException('the predicate group closes early: ' . $pred);
                }
            }
            if (!$quoted && $depth === 0 && substr($inner, $i, 4) === ' OR ') {
                $out[] = $cur;
                $cur = '';
                $i += 3;
                continue;
            }
            $cur .= $ch;
        }
        if ($depth !== 0 || $quoted) {
            throw new \RuntimeException('unbalanced predicate: ' . $pred);
        }
        $out[] = $cur;
        return $out;
    }

    public function testALatinTermKeepsTheOriginalPredicateExactly(): void
    {
        [$sql, $params] = CustomerSearch::predicate('karim', 'tbl_customers', true);
        $this->assertSame('(tbl_customers.username LIKE ? OR tbl_customers.fullname LIKE ? OR tbl_customers.pppoe_username LIKE ? OR tbl_customers.email LIKE ? OR '
            . 'tbl_customers.phonenumber LIKE ? OR tbl_customers.pppoe_ip LIKE ? OR tbl_customers.address LIKE ?)', $sql);
        $this->assertSame(['%karim%', '%karim%', '%karim%', '%karim%', '%karim%', 'karim%', '%karim%'], $params);
        [$sql] = CustomerSearch::predicate('0770 123');
        $this->assertStringNotContainsString('REPLACE(', $sql, 'no folded branch without Arabic letters');
        [$sql, $params] = CustomerSearch::predicate('#42');
        $this->assertSame(['(tbl_customers.id = ?)', [42]], [$sql, $params]);
    }

    public function testAnArabicTermAddsOnlyTheFoldedNameBranches(): void
    {
        [$plain] = CustomerSearch::predicate('karim', 'c', true);
        [$sql, $params] = CustomerSearch::predicate('أحمد', 'c', true);
        $b = self::branches($sql);
        $this->assertSame(array_slice(self::branches($plain), 0, 7), array_slice($b, 0, 7), 'the original branches, in order');
        $this->assertSame([CustomerSearch::foldSql('c.fullname') . ' LIKE ?', CustomerSearch::foldSql('c.address') . ' LIKE ?'], array_slice($b, 7), 'plus the folded name branches only');
        $this->assertSame(substr_count($sql, '?'), count($params), 'every value is bound');
        $this->assertSame(['%أحمد%', '%أحمد%', '%أحمد%', '%أحمد%', '%أحمد%', 'أحمد%', '%أحمد%', '%احمد%', '%احمد%'], $params);
        $this->assertStringNotContainsString('أحمد', $sql, 'the term is never part of the SQL text');

        [$sql, $params] = CustomerSearch::predicate('احمد كريم', 'c', false);
        $this->assertCount(7, self::branches($sql), 'without the address column: one folded branch');
        $this->assertSame('%احمد كريم%', end($params));
    }

    public function testTheFoldedBranchEscapesWildcardsAndCannotBreakTheGroup(): void
    {
        [$sql, $params] = CustomerSearch::predicate("أحمد%_' OR 1=1 -- )", 'c', true);
        $this->assertCount(9, self::branches($sql), 'still one parenthesized OR group');
        $this->assertStringNotContainsString('1=1', $sql);
        $this->assertSame('%احمد\\%\\_\' OR 1=1 -- )%', end($params), 'LIKE wildcards literal; quote and parenthesis are bound data');
        [$sql, $params] = CustomerSearch::predicate('ـًـ', 'c', true);
        $this->assertStringNotContainsString('REPLACE(', $sql, 'a term that folds to nothing never becomes a match-all branch');
        $this->assertNotContains('%%', $params);
    }

    public function testTheArabicDigitPhoneTermReachesThePhoneBranches(): void
    {
        $term = CustomerSearch::term('٠٧٧٠١٢٣٤٥٦٧');
        [$sql, $params] = CustomerSearch::predicate((string) $term);
        $this->assertContains('%07701234567%', $params);
        $this->assertStringContainsString('tbl_customers.phonenumber LIKE ?', $sql);
        [, $params] = CustomerSearch::predicate((string) CustomerSearch::term('٠٧٧٠ ١٢٣'));
        $this->assertContains('%0770123%', $params, 'the digits-only phone branch (\D with /u)');
    }

    public function testCallersStillApplyTheScopeBeforeTheSearchGroup(): void
    {
        $r = self::root();
        $files = [
            'system/controllers/search_user.php' => "/DataScope::scopeCustomers\\(ORM::for_table\\('tbl_customers'\\), \\\$nuxrActor, \\\\Reseller\\\\Security\\\\Capability::CUSTOMER_READ\\)\\s*->where_raw\\(\\\$nuxrPred, \\\$nuxrParams\\)/",
            'system/autoload/Reseller/Ui/Subscriber.php' => "/DataScope::scopeCustomers\\(\\\\ORM::for_table\\('tbl_customers'\\), \\\$a, \\\\Reseller\\\\Security\\\\Capability::CUSTOMER_READ\\)\\s*->where_raw\\(\\\$pred, \\\$params\\)/",
            'system/controllers/customers.php' => "/DataScope::scopeCustomers\\(\\\$query, \\\$nuxrActor, \\\\Reseller\\\\Security\\\\Capability::CUSTOMER_READ\\);\\s*\\\$nuxrTerm = \\\\Reseller\\\\Domain\\\\CustomerSearch::term/",
        ];
        foreach ($files as $f => $re) {
            $this->assertMatchesRegularExpression($re, (string) file_get_contents($r . '/' . $f), "{$f}: the scope predicate is applied before the search group");
        }
    }
}
