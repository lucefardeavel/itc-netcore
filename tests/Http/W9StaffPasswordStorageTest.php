<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * WP9 final security audit, SEC-17 (GAP_ANALYSIS: staff passwords stored as unsalted SHA-1, never closed):
 *  - a staff password that is set or changed is stored with password_hash() (bcrypt), never as SHA-1;
 *  - an existing SHA-1 hash still logs in and is replaced by a password_hash() at that login (opportunistic upgrade);
 *    the installer-seeded default credential is not upgraded, so the change-password lock (SEC-18) stays in force;
 *  - the comparison is strict: two different passwords whose SHA-1 values are both "0e<digits>" (equal under PHP's
 *    loose ==) no longer log in for each other;
 *  - wrong passwords are refused for both formats.
 */
final class W9StaffPasswordStorageTest extends Wp2HttpTestCase
{
    /** two passwords whose SHA-1 hex digests are both 0e followed by digits only */
    private const MAGIC_A = 'aaroZmOk';
    private const MAGIC_B = 'aaK1STfY';

    private function hash(string $user): string
    {
        return (string) static::$db->value('SELECT password FROM tbl_users WHERE username = ?', [$user]);
    }

    private function setHash(string $user, string $hash): void
    {
        static::$db->pdo->prepare('UPDATE tbl_users SET password = ? WHERE username = ?')->execute([$hash, $user]);
    }

    private function loginOk(string $user, string $password): bool
    {
        $r = $this->client()->loginStaff($user, $password);
        return !str_contains($r->body, 'Invalid Username or Password');
    }

    public function testMagicHashesAreComparedStrictly(): void
    {
        $this->assertMatchesRegularExpression('/^0e[0-9]{38}$/', sha1(self::MAGIC_A));
        $this->assertMatchesRegularExpression('/^0e[0-9]{38}$/', sha1(self::MAGIC_B));
        $this->setHash('C1', sha1(self::MAGIC_B));
        $this->assertFalse($this->loginOk('C1', self::MAGIC_A), 'a different password with a 0e SHA-1 is refused');
        $this->assertSame(sha1(self::MAGIC_B), $this->hash('C1'), 'nothing changed by the refused login');
        $this->assertTrue($this->loginOk('C1', self::MAGIC_B), 'positive control: the right password');
        $this->setHash('C1', sha1(Wp2Seed::password('C1')));
    }

    public function testLegacySha1LogsInOnceAndIsUpgraded(): void
    {
        $this->setHash('A2', sha1(Wp2Seed::password('A2')));
        $this->assertFalse($this->loginOk('A2', 'wrong-' . Wp2Seed::password('A2')), 'legacy format: wrong password refused');
        $this->assertSame(sha1(Wp2Seed::password('A2')), $this->hash('A2'));
        $this->assertTrue($this->loginOk('A2', Wp2Seed::password('A2')), 'legacy SHA-1 still logs in');
        $h = $this->hash('A2');
        $this->assertStringStartsWith('$2y$', $h, 'upgraded to password_hash at the login');
        $this->assertTrue(password_verify(Wp2Seed::password('A2'), $h));
        $this->assertTrue($this->loginOk('A2', Wp2Seed::password('A2')), 'the upgraded hash logs in');
        $this->assertSame($h, $this->hash('A2'), 'a current hash is not rewritten');
        $this->assertFalse($this->loginOk('A2', 'wrong-' . Wp2Seed::password('A2')), 'modern format: wrong password refused');
        $this->assertSame(200, $this->staff('A2')->get('dashboard')->status, 'the session works with the upgraded hash');
    }

    public function testChangedAndCreatedPasswordsUseModernHashing(): void
    {
        $c = $this->staff('A1');
        $r = $c->postWithToken('settings/change-password-post', ['password' => Wp2Seed::password('A1'), 'npass' => 'W9-new-pass', 'cnpass' => 'W9-new-pass'], [], 'settings/change-password');
        $this->assertSame(302, $r->status, $r->excerpt());
        $h = $this->hash('A1');
        $this->assertStringStartsWith('$2y$', $h, 'changed password stored with password_hash');
        $this->assertNotSame(sha1('W9-new-pass'), $h);
        $this->assertTrue($this->loginOk('A1', 'W9-new-pass'));
        $this->setHash('A1', sha1(Wp2Seed::password('A1')));

        $created = static::$app->exec('$ad = \Reseller\Security\ActorContext::forStaff(ORM::for_table("tbl_users")->where("username", "ad")->find_one()->as_array(), "CLI");'
            . '$result = \Reseller\Domain\StaffService::create($ad, ["username" => "w9pw' . bin2hex(random_bytes(3)) . '", "fullname" => "W9 pw", "password" => "W9-created-pass", "user_type" => "Report"]);');
        $h = (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [(int) $created]);
        $this->assertStringStartsWith('$2y$', $h, 'created staff stored with password_hash');
        $this->assertTrue(password_verify('W9-created-pass', $h));
    }

    public function testDefaultCredentialIsNotUpgradedAndStaysLocked(): void
    {
        $before = $this->hash('admin');
        $this->setHash('admin', sha1('admin'));
        try {
            $c = $this->client();
            $c->loginStaff('admin', 'admin');
            $this->assertSame(sha1('admin'), $this->hash('admin'), 'the seeded default stays recognisable');
            $r = $c->get('dashboard');
            $this->assertSame(302, $r->status, 'the default credential must still be changed first');
            $this->assertStringContainsString('change-password', (string) $r->location());
        } finally {
            $this->setHash('admin', $before);
        }
    }
}
