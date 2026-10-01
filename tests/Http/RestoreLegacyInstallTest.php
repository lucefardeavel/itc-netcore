<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/** C24: on a pure legacy installation (no reseller schema) the restore stays available, hardened. */
final class RestoreLegacyInstallTest extends Wp2HttpTestCase
{
    protected static function migrate(): bool
    {
        return false;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$db->exec("UPDATE tbl_users SET password = '" . sha1('legacy-admin-pw') . "' WHERE username = 'admin'");
    }

    public function testHardenedLegacyRestore(): void
    {
        $c = $this->client();
        $c->loginStaff('admin', 'legacy-admin-pw');
        $this->assertSame(200, $c->get('dashboard')->status);
        $globalFk = (string) static::$db->value('SELECT @@GLOBAL.foreign_key_checks');
        // malicious / project table keys → rejected, nothing truncated
        $bw = (int) static::$db->value('SELECT COUNT(*) FROM tbl_bandwidth');
        static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'keep_me', 'rate_down' => 1, 'rate_down_unit' => 'Kbps', 'rate_up' => 1, 'rate_up_unit' => 'Kbps']);
        foreach ([['tbl_bandwidth; DROP TABLE tbl_users' => []], ['tbl_manager_tree' => []], ['no_such_table' => []], ['tbl_bandwidth' => 'notarray']] as $payload) {
            $f = tempnam(sys_get_temp_dir(), 'wp2bk');
            file_put_contents($f, json_encode($payload));
            $r = $c->postMultipart('settings/dbrestore', ['csrf_token' => $c->token()], ['json' => $f]);
            @unlink($f);
            $this->assertSame(302, $r->status);
            $this->assertSame($bw + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_bandwidth'), json_encode($payload));
        }
        // a valid legacy backup restores
        $f = tempnam(sys_get_temp_dir(), 'wp2bk');
        file_put_contents($f, json_encode(['tbl_bandwidth' => [['id' => 77, 'name_bw' => 'restored_bw', 'rate_down' => 2, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']]]));
        $r = $c->postMultipart('settings/dbrestore', ['csrf_token' => $c->token()], ['json' => $f]);
        @unlink($f);
        $this->assertSame(302, $r->status);
        $this->assertSame(['restored_bw'], array_column(static::$db->rows('SELECT name_bw FROM tbl_bandwidth'), 'name_bw'));
        $this->assertSame($globalFk, (string) static::$db->value('SELECT @@GLOBAL.foreign_key_checks'), 'GLOBAL setting untouched');
    }
}
