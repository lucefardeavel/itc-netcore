<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RadiusHttpTestCase;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP9 U0 / final hardening (owner D-U0-R1 "READ-ONLY SURVEY"): dev/wp9/identity_survey.sql on SYNTHETIC duplicates of every
 * class the owner listed: username vs PPPoE username, case, accent, leading / trailing whitespace, TAB and the other
 * trimmed control characters (LF, CR, NUL, VT), an interior control character, old / suspended / orphaned RADIUS keys,
 * a RADIUS key with two owners, customer vs customer, voucher vs customer, unassigned vs reseller-owned. The survey is run
 * through the default utf8mb4 connection AND a utf8 (utf8mb3) connection (same answers), and statement 0 detects a
 * NO_BACKSLASH_ESCAPES session. Control: a unique name is never reported. The survey only reads.
 */
final class U0SurveyTest extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
    }

    /** @return list<string> */
    private static function statements(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/dev/wp9/identity_survey.sql');
        return array_values(array_filter(array_map('trim', explode(';', preg_replace('/^--[^\n]*$/m', '', $sql))), static fn(string $s): bool => $s !== ''));
    }

    /** @return list<list<array<string,mixed>>> */
    private static function survey(\PDO $pdo): array
    {
        return array_map(static function (string $s) use ($pdo): array {
            $st = $pdo->query($s);
            return $st->fetchAll(\PDO::FETCH_ASSOC);
        }, self::statements());
    }

    public function testCanonicalSurveyReportsEveryDuplicateClassAndOnlyReads(): void
    {
        $t = self::tag();
        $pairs = [
            'leading space' => [' ' . $t . 'a', $t . 'a'], 'trailing TAB' => [$t . "b\t", $t . 'b'], 'case' => [$t . 'c', strtoupper($t . 'c')],
            'accent' => [$t . 'dé', $t . 'de'], 'trailing CR' => [$t . "g\r", $t . 'g'], 'leading LF' => ["\n" . $t . 'h', $t . 'h'],
            'trailing NUL' => [$t . "i\0", $t . 'i'], 'trailing VT' => [$t . "j\x0B", $t . 'j'],
        ];
        foreach ($pairs as [$one, $two]) {
            $this->person($one);
            $this->person($two);
        }
        $this->person($t . 'e');
        $this->person(self::tag(), $t . 'e');                        // cross-field
        $this->voucher($t . 'f');
        $this->assertSame('GRANTED', (string) static::$app->exec(self::p6Code($t . 'f')));
        $this->person(strtoupper($t . 'f'));                        // customer over a live voucher identity (grandfathered)
        $this->person($t . 'k', '', 'A');                           // owned vs unassigned
        $this->person(strtoupper($t . 'k'));
        $this->person($t . "m\x01x");                               // interior control character
        static::$db->insertRow('radcheck', ['username' => $t . 'orphan', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'suspended-' . bin2hex(random_bytes(4))]);
        static::$db->insertRow('radcheck', ['username' => $t . 'a', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'x-' . bin2hex(random_bytes(4))]);
        $this->person($t . 'z');                                    // control: unique
        $this->provision($this->person($t . 'zz')['id']);            // control: a single owner with its RADIUS rows

        $before = self::state(static::$db);
        $r = self::survey(static::$db->pdo);
        $mb3 = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword(), static::$db->name);
        $mb3->exec('SET NAMES utf8');
        $r3 = self::survey($mb3);
        $nbe = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword(), static::$db->name);
        $nbe->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_BACKSLASH_ESCAPES')");
        $guard = $nbe->query(self::statements()[0])->fetch(\PDO::FETCH_ASSOC);
        $after = self::state(static::$db);

        $dup = [];
        foreach ($r[2] as $row) {
            // the engine may return any member of a collation group as its representative (e.g. the upper-case form)
            $dup[bin2hex(mb_strtolower((string) $row['k']))] = (string) $row['identities'];
        }
        $has = static fn(string $k): bool => isset($dup[bin2hex(mb_strtolower($k))]) || (bool) array_filter(array_keys($dup), static fn(string $h): bool => str_starts_with($h, bin2hex(mb_strtolower($k))));
        $expect = ['leading space' => $t . 'a', 'trailing TAB' => $t . 'b', 'case' => $t . 'c', 'accent' => $t . 'd', 'trailing CR' => $t . 'g', 'leading LF' => $t . 'h',
            'trailing NUL' => $t . 'i', 'trailing VT' => $t . 'j', 'cross-field' => $t . 'e', 'voucher vs customer' => $t . 'f', 'owned vs unassigned' => $t . 'k'];
        $found = array_map($has, $expect);
        $ownedLabel = (bool) array_filter($dup, static fn(string $ids): bool => str_contains($ids, '(owned)') && str_contains($ids, '(unassigned)'));
        $orphans = array_map(static fn(array $x): string => strtolower((string) $x['radius_key_hex']), $r[5]);
        $result = [
            'duplicate_classes_found' => $found,
            'unique_name_reported' => $has($t . 'z') || $has($t . 'zz'),
            'owned_vs_unassigned_labelled' => $ownedLabel,
            'interior_control_listed' => count($r[4]) >= 1,
            'orphan_radius_key_listed' => in_array(strtolower(bin2hex($t . 'orphan')), $orphans, true),
            'two_owner_radius_key_listed' => in_array(strtolower(bin2hex($t . 'a')), $orphans, true),
            'single_owner_radius_key_not_listed' => !in_array(strtolower(bin2hex($t . 'zz')), $orphans, true),
            'utf8mb3_connection_same_answers' => array_slice($r, 2) == array_slice($r3, 2),
            'session_guard_default' => $r[0][0],
            'session_guard_no_backslash_escapes' => $guard,
            'read_only' => $before === $after,
            'duplicate_keys' => array_map(static fn(string $ids): string => $ids, $dup),
            'voucher_rows' => static::$db->rows('SELECT id, HEX(code) AS code_hex, status, user FROM tbl_voucher WHERE code LIKE ?', [$t . 'f%']),
            'recharge_rows' => static::$db->rows('SELECT customer_id, HEX(username) AS u FROM tbl_user_recharges WHERE username LIKE ?', [$t . 'f%']),
        ];
        $met = !in_array(false, $found, true) && !$result['unique_name_reported'] && $ownedLabel && $result['interior_control_listed'] && $result['orphan_radius_key_listed']
            && $result['two_owner_radius_key_listed'] && $result['single_owner_radius_key_not_listed'] && $result['utf8mb3_connection_same_answers']
            && $r[0][0] == ['survey_session' => 'OK', 'escape_check' => 'OK'] && $guard['survey_session'] === 'ABORT_NO_BACKSLASH_ESCAPES' && $before === $after;
        self::record('U0 canonical survey (final hardening)', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $result);
        $this->assertSame(array_fill_keys(array_keys($expect), true), $found);
        $this->assertFalse($result['unique_name_reported'], 'a unique name is not reported');
        $this->assertTrue($ownedLabel, 'owned / unassigned holders are labelled');
        $this->assertTrue($result['interior_control_listed']);
        $this->assertTrue($result['orphan_radius_key_listed'], 'an orphaned (suspended) RADIUS key is listed');
        $this->assertTrue($result['two_owner_radius_key_listed'], 'a RADIUS key with two owners is listed');
        $this->assertTrue($result['single_owner_radius_key_not_listed']);
        $this->assertTrue($result['utf8mb3_connection_same_answers'], 'the connection character set does not change the answers');
        $this->assertSame(['survey_session' => 'OK', 'escape_check' => 'OK'], $r[0][0]);
        $this->assertSame('ABORT_NO_BACKSLASH_ESCAPES', $guard['survey_session'], 'a session that would mis-parse the escapes is flagged');
        $this->assertSame($before, $after, 'the survey only reads');
    }
}
