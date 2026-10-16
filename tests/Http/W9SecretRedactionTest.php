<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 N6 / N-11 (AD-18 secret boundary): secrets stored in the DATABASE - the NAS shared secret (RADIUS needs it
 * recoverable) and the router API password - never reach the server log, like the config.php secrets. Positive
 * control: the surrounding text of the report is kept.
 */
final class W9SecretRedactionTest extends RadiusHttpTestCase
{
    public function testDatabaseSecretsAreRedactedFromReports(): void
    {
        $nas = 'w9-nas-secret-' . bin2hex(random_bytes(6));
        $router = 'w9-router-pw-' . bin2hex(random_bytes(6));
        static::$db->insertRow('nas', ['nasname' => '192.0.2.77', 'shortname' => 'w9', 'type' => 'other', 'ports' => 3799, 'secret' => $nas, 'description' => 'w9']);
        static::$db->exec('UPDATE tbl_routers SET password = ' . static::$db->db()->pdo->quote($router) . ' WHERE id = ' . static::$seed->routerId);
        $log = static::$app->dir . '/wp2_probe/php_error.log';
        $before = is_file($log) ? strlen((string) file_get_contents($log)) : 0;
        $ref = static::$app->exec(sprintf('$result = \Reseller\Security\ErrorReporter::report(new \RuntimeException("w9marker nas=%s router=%s"), "w9-redaction");', $nas, $router));
        $this->assertIsString($ref);
        $text = substr((string) file_get_contents($log), $before);
        $this->assertStringContainsString('w9marker', $text, 'positive control: the report was logged');
        $this->assertStringNotContainsString($nas, $text, 'NAS shared secret');
        $this->assertStringNotContainsString($router, $text, 'router API password');
    }
}
