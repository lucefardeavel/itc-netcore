<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Tests\Support\RadiusTestCase;

/**
 * WP8 A3 rename integrity (plan rev. 3 §R2, F-1 … F-3, F-5; owner OD-A3R-9 / OD-A3R-11). The policy tables compare
 * usernames with utf8mb4_general_ci, so the rename works on EXACT bytes:
 *  - an equivalent rename (case / accent only) updates the subscriber's own rows in place, never deletes them;
 *  - another exact key that the collation treats as equal to the source or the target refuses the rename before
 *    anything is written (FAILED RADIUS_IDENTITY_AMBIGUOUS, every table byte-identical);
 *  - rows of every other key are never read into, moved or deleted.
 */
final class RadiusRenameIntegrityTest extends RadiusTestCase
{
    private const TABLES = ['radcheck', 'radreply', 'radusergroup'];

    private static function seed(string $u, string $marker): void
    {
        static::$radius->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?), (?, 'Simultaneous-Use', ':=', '1')")
            ->execute([$u, 'pw-' . $marker, $u]);
        static::$radius->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")->execute([$u, '1M/' . $marker]);
        static::$radius->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$u, 'plan_' . $marker]);
    }

    /** @return list<string> every policy row as "table|exact-username-hex|attribute|value" (sorted) */
    private static function rowsOf(?string $exact = null): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $cols = $t === 'radusergroup' ? "groupname AS a, priority AS v" : "attribute AS a, value AS v";
            foreach (static::rows("SELECT HEX(username) AS h, {$cols} FROM {$t}") as $r) {
                if ($exact === null || strtolower($r['h']) === bin2hex($exact)) {
                    $out[] = $t . '|' . strtolower($r['h']) . '|' . $r['a'] . '|' . $r['v'];
                }
            }
        }
        sort($out);
        return $out;
    }

    /** every row of every key other than these exact keys */
    private static function othersChecksum(string ...$exact): string
    {
        $hex = array_map('bin2hex', $exact);
        return hash('sha256', implode("\n", array_filter(self::rowsOf(), static fn(string $r): bool => !in_array(explode('|', $r)[1], $hex, true))));
    }

    private function provisioner(): RadiusProvisioner
    {
        return new RadiusProvisioner($this->repo());
    }

    protected function setUp(): void
    {
        foreach (self::TABLES as $t) {
            static::$radius->exec("DELETE FROM {$t}");
        }
        self::seed('bystander_one', 'b1');
        self::seed('BYSTANDER_TWO', 'b2');
    }

    public function testOrdinaryRenameMovesExactlyTheSourceRows(): void
    {
        self::seed('ri_alpha', 'a');
        $before = self::rowsOf('ri_alpha');
        $others = self::othersChecksum('ri_alpha', 'ri_beta');
        $r = $this->provisioner()->rename('ri_alpha', 'ri_beta');
        $this->assertSame([ProvisionResult::SUCCESS, 'OK'], [$r->status, $r->code]);
        $this->assertSame([], self::rowsOf('ri_alpha'));
        $this->assertSame(str_replace(bin2hex('ri_alpha'), bin2hex('ri_beta'), $before), self::rowsOf('ri_beta'), 'the same rows, re-keyed');
        $this->assertSame($others, self::othersChecksum('ri_alpha', 'ri_beta'), 'no other key touched');
    }

    public function testEquivalentRenameUpdatesInPlaceAndNeverDeletesTheSource(): void
    {
        self::seed('ri_case', 'c');
        $before = self::rowsOf('ri_case');
        $others = self::othersChecksum('ri_case', 'RI_CASE');
        $r = $this->provisioner()->rename('ri_case', 'RI_CASE');
        $this->assertSame([ProvisionResult::SUCCESS, 'OK'], [$r->status, $r->code], 'verified by exact bytes, not by the collation');
        $this->assertSame([], self::rowsOf('ri_case'), 'nothing left under the old bytes');
        $this->assertSame(str_replace(bin2hex('ri_case'), bin2hex('RI_CASE'), $before), self::rowsOf('RI_CASE'), 'the policy survived the rename (no gap, no loss)');
        $this->assertSame($others, self::othersChecksum('ri_case', 'RI_CASE'));
    }

    public function testAnotherKeyEquivalentToTheSourceRefusesBeforeAnyWrite(): void
    {
        self::seed('ri_src', 's');
        self::seed('RI_SRC', 'other');          // a different subscriber's key that the collation treats as equal
        $all = self::rowsOf();
        $r = $this->provisioner()->rename('ri_src', 'ri_dst');
        $this->assertSame([ProvisionResult::FAILED, 'RADIUS_IDENTITY_AMBIGUOUS', false], [$r->status, $r->code, $r->retryable]);
        $this->assertSame($all, self::rowsOf(), 'every table byte-identical: nothing applied');
    }

    public function testAnotherKeyEquivalentToTheTargetRefusesBeforeAnyWrite(): void
    {
        self::seed('ri_from', 'f');
        self::seed('RI_TO', 'other');           // equivalent to the target 'ri_to'
        $all = self::rowsOf();
        $r = $this->provisioner()->rename('ri_from', 'ri_to');
        $this->assertSame([ProvisionResult::FAILED, 'RADIUS_IDENTITY_AMBIGUOUS'], [$r->status, $r->code]);
        $this->assertSame($all, self::rowsOf());
    }

    public function testTheExactTargetKeyIsStillReplacedAsBefore(): void
    {
        self::seed('ri_old', 'o');
        self::seed('ri_new', 'stale');          // the exact target key: replaced (unchanged accepted behaviour)
        $others = self::othersChecksum('ri_old', 'ri_new');
        $r = $this->provisioner()->rename('ri_old', 'ri_new');
        $this->assertSame(ProvisionResult::SUCCESS, $r->status);
        $this->assertSame([], self::rowsOf('ri_old'));
        $this->assertNotContains('radcheck|' . bin2hex('ri_new') . '|Cleartext-Password|pw-stale', self::rowsOf('ri_new'));
        $this->assertContains('radcheck|' . bin2hex('ri_new') . '|Cleartext-Password|pw-o', self::rowsOf('ri_new'));
        $this->assertSame($others, self::othersChecksum('ri_old', 'ri_new'));
    }

    /** WP8 A3 owner D-3: without ownership of the exact target (replaceTarget false) nothing is replaced or moved */
    public function testAnExistingExactTargetIsRefusedWhenTheCallerDoesNotOwnIt(): void
    {
        self::seed('ri_mine', 'm');
        self::seed('ri_theirs', 't');
        $before = self::rowsOf();
        $r = $this->provisioner()->rename('ri_mine', 'ri_theirs', false);
        $this->assertSame([ProvisionResult::FAILED, 'RADIUS_IDENTITY_TAKEN'], [$r->status, $r->code]);
        $this->assertSame($before, self::rowsOf(), 'rolled back: the target and the source are byte-identical');
        // a free target is still renamed with replaceTarget false
        $r = $this->provisioner()->rename('ri_mine', 'ri_free', false);
        $this->assertSame(ProvisionResult::SUCCESS, $r->status);
        $this->assertSame([], self::rowsOf('ri_mine'));
        $this->assertContains('radcheck|' . bin2hex('ri_free') . '|Cleartext-Password|pw-m', self::rowsOf('ri_free'));
    }
}
