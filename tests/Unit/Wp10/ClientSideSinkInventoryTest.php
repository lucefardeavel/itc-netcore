<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * D9 (owner V1_OWNER_DECISIONS_APPROVED) - jQuery 1.11.1 assessment, enforced:
 * the known jQuery CVEs (2015-9251, 2019-11358, 2020-11022/11023) need attacker-influenced data to reach a DOM-HTML sink
 * (.html(), innerHTML, DataTables cells, SweetAlert titles) or a cross-origin script request. This test pins the reviewed
 * inventory of HTML sinks in the templates, so a new one cannot appear unreviewed, and checks the sinks that were fixed:
 *  - the remote version.json (third-party data) is shown as text and only as a restricted token;
 *  - the bulk-message history escapes every value that came from a customer record or the gateway.
 */
final class ClientSideSinkInventoryTest extends TestCase
{
    /** reviewed: static markup, same-origin server HTML produced by this application, or escaped values (see D9 assessment) */
    private const HTML_CALLS = [
        'admin/hotspot/add.tpl' => 2, 'admin/hotspot/edit.tpl' => 2, 'admin/message/bulk.tpl' => 6, 'admin/pppoe/add.tpl' => 3,
        'admin/pppoe/edit.tpl' => 2, 'admin/vpn/add.tpl' => 2, 'admin/vpn/edit.tpl' => 2, 'customer/footer.tpl' => 2,
        'widget/customers/active_internet_plan.tpl' => 1,
    ];
    private const INNER_HTML_ASSIGNMENTS = [
        'admin/alert.tpl' => 1, 'admin/customers/add.tpl' => 1, 'admin/customers/edit.tpl' => 1, 'admin/footer.tpl' => 1,
        'admin/plan/deposit.tpl' => 1, 'admin/plan/invoice.tpl' => 1, 'admin/voucher/view.tpl' => 1, 'customer/footer.tpl' => 3,
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3) . '/ui/ui';
    }

    /** @return array<string,int> relative path => matches */
    private static function sinks(string $regex): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (substr((string) $f, -4) !== '.tpl') {
                continue;
            }
            $n = preg_match_all($regex, (string) file_get_contents((string) $f));
            if ($n > 0) {
                $out[substr((string) $f, strlen(self::root()) + 1)] = $n;
            }
        }
        ksort($out);
        return $out;
    }

    public function testHtmlSinksAreTheReviewedOnes(): void
    {
        $expected = self::HTML_CALLS;
        ksort($expected);
        $this->assertSame($expected, self::sinks('/\.html\((?!\))/'), 'a new .html(<value>) call needs a review (D9): add it here only when its input is static, same-origin server HTML or escaped');
        $expected = self::INNER_HTML_ASSIGNMENTS;
        ksort($expected);
        $this->assertSame($expected, self::sinks('/innerHTML\s*=(?!=)/'), 'a new innerHTML assignment needs a review (D9)');
    }

    public function testRemoteVersionDataIsTextAndARestrictedToken(): void
    {
        foreach (['admin/dashboard.tpl', 'admin/community.tpl'] as $f) {
            $src = (string) file_get_contents(self::root() . '/' . $f);
            $this->assertStringContainsString('nuxrSafeVersion', $src, $f);
            $this->assertDoesNotMatchRegularExpression('/\.html\(/', $src, "{$f}: no HTML sink");
            $this->assertDoesNotMatchRegularExpression('/data\.version(?!\))/', preg_replace('/nuxrSafeVersion\(data\.version\)/', '', $src), "{$f}: data.version is only used through nuxrSafeVersion()");
        }
        $dash = (string) file_get_contents(self::root() . '/admin/dashboard.tpl');
        $this->assertStringContainsString('var latestVersion = nuxrSafeVersion(data.version);', $dash, 'the value that reaches the SweetAlert title is the restricted token');
        $this->assertStringContainsString('var localVersion = nuxrSafeVersion(data.version);', $dash);
    }

    public function testBulkMessageHistoryEscapesEveryValueFromARecord(): void
    {
        $src = (string) file_get_contents(self::root() . '/admin/message/bulk.tpl');
        $this->assertStringContainsString('function nuxrEsc(', $src);
        $this->assertSame(1, preg_match('/historyTable\.row\.add\(\[(.*?)\]\)\.draw/s', $src, $m), 'the row.add call');
        foreach (['msg.name', 'msg.phone', 'msg.status', 'msg.message', 'msg.router', 'msg.service'] as $field) {
            $this->assertMatchesRegularExpression('/nuxrEsc\([^\n]*' . preg_quote($field, '/') . '/', $m[1], "{$field} is escaped");
        }
        $this->assertDoesNotMatchRegularExpression('/^\s*msg\.[a-z]+,?\s*$/m', $m[1], 'no raw msg.* cell');
    }
}
