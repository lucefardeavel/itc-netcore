<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP9-D / N-07a: the legacy exports (customers/csv, customers/csv-prepaid, logs/list-csv, logs/radius-csv,
 * logs/message-csv) wrote raw values between quotes. Values that an Agent / Sales / customer can set
 * (full name, address, e-mail, log text) could break the CSV structure (quote / separator injection)
 * or start a spreadsheet formula. Every exported row must parse to the header's column count and no
 * cell may start a formula (also after leading whitespace) unless neutralised with a leading quote.
 */
final class W9CsvExportInjectionTest extends Wp2HttpTestCase
{
    private const HOSTILE_NAME = 'x","=HYPERLINK("http://e.invalid","c")';
    private const HOSTILE_ADDRESS = ' =1+1';
    private const HOSTILE_EMAIL = '@SUM(1)';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $id = static::$seed->customers['cNULL'];
        $pdo = static::$db->db()->pdo;
        $pdo->prepare('UPDATE tbl_customers SET fullname = ?, address = ?, email = ? WHERE id = ?')
            ->execute([self::HOSTILE_NAME, self::HOSTILE_ADDRESS, self::HOSTILE_EMAIL, $id]);
        $pdo->prepare('INSERT INTO tbl_logs (date, type, description, userid, ip) VALUES (NOW(), ?, ?, 0, ?)')
            ->execute(['Admin', "a\";\"b\n=cmd|' /C calc'!A0", '127.0.0.1']);
    }

    /** @return list<list<string>> */
    private function parse(string $csv, string $sep): array
    {
        $h = fopen('php://memory', 'w+');
        fwrite($h, $csv);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, null, $sep, '"', '')) !== false) {
            if ($r !== [null]) {
                $rows[] = $r;
            }
        }
        fclose($h);
        return $rows;
    }

    private function assertSafeCsv(string $csv, string $sep, string $what): array
    {
        $rows = $this->parse($csv, $sep);
        $this->assertGreaterThan(1, count($rows), $what . ': ' . substr($csv, 0, 300));
        $n = count($rows[0]);
        foreach ($rows as $i => $r) {
            $this->assertCount($n, $r, "{$what}: row {$i} has a different column count (structure injection)");
            foreach ($r as $cell) {
                if (preg_match('/^-?\d+(\.\d+)?$/D', (string) $cell)) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/^[\s\x{00A0}]*[=+\-@]/u', (string) $cell, "{$what}: un-neutralised formula cell");
            }
        }
        return $rows;
    }

    public function testCustomerExportsAreStructurallySafe(): void
    {
        $c = $this->staff('ad');
        $token = $c->token('customers/list');
        $r = $c->post('customers/csv', ['token' => $token, 'csrf_token' => $token]);
        $this->assertSame(200, $r->status, $r->excerpt());
        $rows = $this->assertSafeCsv($r->body, ',', 'customers/csv');
        $cells = array_merge(...$rows);
        $this->assertContains("'" . self::HOSTILE_EMAIL, $cells, 'the hostile value is kept, neutralised');
        $this->assertContains(self::HOSTILE_NAME, $cells, 'quotes inside a value stay inside one cell');
        $this->assertContains("'" . self::HOSTILE_ADDRESS, $cells, 'a formula after leading whitespace is neutralised');
        $token = $c->token('customers/list');
        $r = $c->post('customers/csv-prepaid', ['token' => $token, 'csrf_token' => $token]);
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertSafeCsv($r->body, ',', 'customers/csv-prepaid');
    }

    public function testLogExportIsStructurallySafe(): void
    {
        $r = $this->staff('ad')->get('logs/list-csv');
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertSafeCsv($r->body, ';', 'logs/list-csv');
    }
}
