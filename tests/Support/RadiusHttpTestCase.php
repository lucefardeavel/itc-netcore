<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * WP4 HTTP tests: the disposable application (Wp2HttpTestCase) with RADIUS enabled. The FreeRADIUS schema is
 * loaded into the application database (the installer's layout) and the app copy's config.php gets the
 * 'radius' connection, plus the rlm_rest endpoint secret when restSecret() returns one.
 */
abstract class RadiusHttpTestCase extends Wp2HttpTestCase
{
    protected static function restSecret(): ?string
    {
        return null;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        (new FixtureBuilder(dirname(__DIR__, 2)))->loadSqlFile(static::$db->pdo, dirname(__DIR__, 2) . '/install/radius.sql');
        static::$db->strictSession();
        $have = (int) static::$db->value("SELECT COUNT(*) FROM tbl_appconfig WHERE setting = 'radius_enable'");
        if ($have === 0) {
            static::$db->insertRow('tbl_appconfig', ['setting' => 'radius_enable', 'value' => '1']);
        } else {
            static::$db->exec("UPDATE tbl_appconfig SET value = '1' WHERE setting = 'radius_enable'");
        }
        $extra = '$radius_host = ' . var_export(static::$db->host, true) . ";\n\$radius_user = 'root';\n"
            . '$radius_pass = ' . var_export(TestDatabase::rootPassword(), true) . ";\n"
            . '$radius_name = ' . var_export(static::$db->name, true) . ";\n";
        if (static::restSecret() !== null) {
            $extra .= '$radius_rest_secret = ' . var_export(static::restSecret(), true) . ";\n";
        }
        static::$app->writeConfig(static::$app->apiSecret(), $extra);
    }

    /** RADIUS endpoint request (rlm_rest form post) with optional extra headers. */
    protected function rest(string $action, array $data, array $headers = []): HttpResponse
    {
        return $this->client()->raw('POST', 'radius.php?action=' . rawurlencode($action), $data, $headers);
    }

    /** @return array<string,string> radcheck attribute => value */
    protected static function radcheck(string $username): array
    {
        $out = [];
        foreach (static::$db->rows('SELECT attribute, value FROM radcheck WHERE username = ? ORDER BY id', [$username]) as $r) {
            $out[$r['attribute']] = $r['value'];
        }
        return $out;
    }

    /** A FreeRADIUS accounting history row. */
    protected static function radacct(string $username, string $session, ?string $stop): void
    {
        static::$db->insertRow('radacct', [
            'acctsessionid' => $session, 'acctuniqueid' => substr(md5($username . $session), 0, 32), 'username' => $username,
            'nasipaddress' => '192.0.2.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 7200), 'acctstoptime' => $stop,
            'acctsessiontime' => 100, 'acctinputoctets' => 10, 'acctoutputoctets' => 20, 'framedipaddress' => '10.0.0.1',
            'calledstationid' => '', 'callingstationid' => '', 'acctterminatecause' => '', 'acctauthentic' => '', 'connectinfo_start' => '',
            'connectinfo_stop' => '', 'servicetype' => '', 'framedprotocol' => '', 'nasporttype' => '', 'realm' => '',
        ]);
    }

    protected static function radacctChecksum(): string
    {
        $rows = array_map('json_encode', static::$db->rows('SELECT * FROM radacct ORDER BY radacctid'));
        return hash('sha256', implode("\n", $rows));
    }
}
