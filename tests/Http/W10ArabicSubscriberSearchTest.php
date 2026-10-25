<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab finding UX-11 (ITC NetCore lab 2026-10-25): "أحمد" found أحمد كريم but "احمد" (the spelling most keyboards produce)
 * found nothing, and a phone typed with Arabic-Indic digits never matched. CustomerSearch now folds the Arabic spelling
 * variants (alef forms, teh marbuta, alef maksura, Persian / Kurdish letters, tatweel, harakat) on both sides and reads
 * Arabic-Indic digits as ASCII, on every search surface (quick search, customer list, activation picker). The folding is
 * one more OR branch inside the same search group: a subscriber outside the caller's scope is still never returned.
 */
final class W10ArabicSubscriberSearchTest extends Wp2HttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $c = static::$seed->customers;
        $set = static fn(string $u, array $cols) => static::$db->pdo->prepare('UPDATE tbl_customers SET ' . implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($cols))) . ' WHERE id = ?')
            ->execute([...array_values($cols), $c[$u]]);
        // in A's tree (A / A1); the same name and phone in C's tree, which A must never see
        $set('cA1', ['fullname' => 'أحمد كريم', 'phonenumber' => '07701234567']);
        $set('cC1', ['fullname' => 'أحمد كريم', 'phonenumber' => '07701234567']);
        $set('cA2', ['fullname' => 'سارة علي', 'address' => 'إربيل، شارع الجامعة']);
    }

    /** @return list<int> customer ids in the quick-search fragment */
    private function quick(string $actor, string $q): array
    {
        $r = $this->staff($actor)->get('search_user', ['query' => $q]);
        $this->assertSame(200, $r->status, $r->excerpt());
        preg_match_all('#customers/view/(\d+)#', $r->body, $m);
        $ids = array_values(array_unique(array_map('intval', $m[1])));
        sort($ids);
        return $ids;
    }

    /** @return list<int> customer ids on the first customer-list page */
    private function listed(string $actor, string $q): array
    {
        $r = $this->staff($actor)->get('customers/list', ['search' => $q, 'filter' => 'All']);
        $this->assertSame(200, $r->status, $r->excerpt() . substr(static::$app->serverLog(), -1500));
        preg_match_all('#customers/view/(\d+)#', $r->body, $m);
        $ids = array_values(array_unique(array_map('intval', $m[1])));
        sort($ids);
        return $ids;
    }

    public function testArabicSpellingVariantsAndArabicDigitsFindTheSubscriberWithinScope(): void
    {
        $c = static::$seed->customers;
        $both = [$c['cA1'], $c['cC1']];
        sort($both);
        foreach (['أحمد', 'احمد', 'إحمد', 'احمد كريم', 'أحمد كريم', 'اَحْمَد', '٠٧٧٠١٢٣٤٥٦٧', '۰۷۷۰۱۲۳۴۵۶۷', '07701234567'] as $q) {
            $this->assertSame([$c['cA1']], $this->quick('A', $q), "quick search A '{$q}': own subscriber only");
            $this->assertSame([$c['cA1']], $this->listed('A', $q), "list search A '{$q}': own subscriber only");
            $this->assertSame([$c['cA1']], $this->quick('A1', $q), "quick search Sales A1 '{$q}': assigned subscriber");
            $this->assertSame([], $this->quick('A2', $q), "quick search Sales A2 '{$q}': not assigned");
            $this->assertSame([$c['cC1']], $this->quick('C', $q), "quick search C '{$q}': never A's subscriber");
            $this->assertSame([], $this->quick('B', $q), "quick search B '{$q}': neither tree is B's");
            $this->assertSame($both, $this->quick('ad', $q), "quick search Admin '{$q}'");
        }
        $this->assertSame([], $this->quick('A', 'احمد سالم'), 'folding never matches a different name');
    }

    public function testTheFoldedAddressMatchesInTheCustomerList(): void
    {
        $c = static::$seed->customers;
        $this->assertSame([$c['cA2']], $this->listed('A', 'اربيل'), 'address: إربيل found by اربيل');
        $this->assertSame([], $this->listed('C', 'اربيل'), 'never outside the scope');
    }

    public function testTheActivationPickerUsesTheSameScopedSearch(): void
    {
        $c = static::$seed->customers;
        foreach (['احمد', '٠٧٧٠١٢٣٤٥٦٧'] as $q) {
            $page = $this->staff('A')->get('activation/create', ['q' => $q]);
            $this->assertSame(200, $page->status, $page->excerpt());
            preg_match_all('#activation/create&(?:amp;)?customer=(\d+)#', $page->body, $m);
            $this->assertSame([$c['cA1']], array_values(array_unique(array_map('intval', $m[1]))), "activation picker A '{$q}'");
        }
    }
}
