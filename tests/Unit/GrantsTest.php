<?php
namespace Reseller\Tests\Unit;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Migration\Grants;

final class GrantsTest extends TestCase
{
    public function testGlobalAllPrivilegesIsSufficient(): void
    {
        $this->assertSame([], Grants::missing(['GRANT ALL PRIVILEGES ON *.* TO `root`@`%` WITH GRANT OPTION'], 'nuxbill'));
    }

    public function testDatabaseLevelExplicitPrivileges(): void
    {
        $lines = ['GRANT USAGE ON *.* TO `app`@`%`', 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER ON `nuxbill`.* TO `app`@`%`'];
        $this->assertSame([], Grants::missing($lines, 'nuxbill'));
    }

    public function testEscapedUnderscoreMatchesLiteralOnly(): void
    {
        $lines = ['GRANT ALL PRIVILEGES ON `nb\_app`.* TO `app`@`%`'];
        $this->assertSame([], Grants::missing($lines, 'nb_app'));
        $this->assertNotSame([], Grants::missing($lines, 'nbXapp'));
    }

    public function testWildcardPattern(): void
    {
        $this->assertSame([], Grants::missing(['GRANT ALL PRIVILEGES ON `nux%`.* TO `app`@`%`'], 'nuxbill'));
    }

    public function testMissingReferencesIsReported(): void
    {
        $lines = ['GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON `nuxbill`.* TO `app`@`%`'];
        $this->assertSame(['REFERENCES'], Grants::missing($lines, 'nuxbill'));
    }

    public function testOtherDatabaseOrTableLevelDoesNotCount(): void
    {
        $lines = ['GRANT ALL PRIVILEGES ON `other`.* TO `app`@`%`', 'GRANT ALL PRIVILEGES ON `nuxbill`.`tbl_users` TO `app`@`%`'];
        $this->assertSame(Grants::REQUIRED, Grants::missing($lines, 'nuxbill'));
    }

    public function testRoleOnlyGrantsAreNotAssumed(): void
    {
        $this->assertSame(Grants::REQUIRED, Grants::missing(['GRANT `dba_role`@`%` TO `app`@`%`'], 'nuxbill'));
    }
}
