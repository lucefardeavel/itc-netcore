<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/RadiusRepositoryTest.php';

/** The same contract with the FreeRADIUS schema in a separate database (the 'radius' connection elsewhere). */
final class RadiusRepositorySeparateDbTest extends RadiusRepositoryTest
{
    protected static function separateRadiusDatabase(): bool
    {
        return true;
    }

    public function testTheRadiusTablesAreOnlyInTheSeparateDatabase(): void
    {
        $this->assertNotSame(static::$db->name, static::$radiusDb->name);
        $app = static::$app->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'radcheck'")->fetchColumn();
        $this->assertSame(0, (int) $app, 'no FreeRADIUS table in the application database');
    }
}
