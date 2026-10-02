<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\RadiusPacket;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\RadiusClient;
use Reseller\Tests\Support\TestDatabase;

/**
 * Contract: an activation completed by WP5A is what the real FreeRADIUS 3.2.8 (project configuration) serves.
 * The ORM 'radius' connection points at the database the test FreeRADIUS reads (nuxr_radius).
 */
final class ActivationContractTest extends ActivationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $host = (string) getenv('WP1_DB_HOST');
        FixtureBuilder::connect($host, TestDatabase::rootPassword())->exec('CREATE DATABASE IF NOT EXISTS `nuxr_radius` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $radius = FixtureBuilder::connect($host, TestDatabase::rootPassword(), 'nuxr_radius');
        (new FixtureBuilder(dirname(__DIR__, 3)))->loadSqlFile($radius, dirname(__DIR__, 3) . '/install/radius.sql');
        \ORM::set_db($radius, 'radius');
        RadiusClient::waitReady();
    }

    public function testCompletedActivationAuthenticatesAndRenewalExtendsIt(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $u = $this->username($c);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($u, 'pw-' . $u)?->code, 'nothing before the activation');
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $first = RadiusClient::access($u, 'pw-' . $u);
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $first?->code, 'served by FreeRADIUS');
        $this->assertEqualsWithDelta(30 * 86400, (int) $first->get('Session-Timeout'), 300, 'up to the 30-day expiry');
        $bw = static::$db->rows('SELECT b.* FROM tbl_bandwidth b JOIN tbl_plans p ON p.id_bw = b.id WHERE p.id = ?', [$plan])[0];
        $this->assertSame(\Reseller\Radius\RadiusPolicyBuilder::rateLimit($bw), $first->get('Mikrotik-Rate-Limit'), 'the plan rate reaches the NAS');
        $this->assertSame(RadiusPacket::ACCESS_REJECT, RadiusClient::access($u, 'wrong')?->code);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'RENEW')['status']);
        $second = RadiusClient::access($u, 'pw-' . $u);
        $this->assertEqualsWithDelta(60 * 86400, (int) $second->get('Session-Timeout'), 300, 'the renewal stacked 30 more days (C14)');
    }
}
