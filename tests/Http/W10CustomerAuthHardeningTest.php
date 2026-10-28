<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * Lab findings (ITC NetCore lab 2026-10-24):
 *  - D-5: the customer login told a Banned account's status before checking the password, so anyone knowing a
 *    username learned it is Banned. A wrong password must answer exactly like for any other account.
 *  - D-6: the legacy (not reseller-owned) password change validated the length of the CURRENT password instead of
 *    the new one; a new password longer than the 45-character column ended in an HTTP 500. The new password is
 *    validated (2 to 35 characters, the rule of the reseller-owned path) and the stored one is unchanged.
 */
final class W10CustomerAuthHardeningTest extends Wp2HttpTestCase
{
    private function setStatus(string $username, string $status): void
    {
        static::$db->exec("UPDATE tbl_customers SET status = '" . $status . "' WHERE username = '" . $username . "'");
    }

    public function testBannedStatusIsNotDisclosedWithoutTheRightPassword(): void
    {
        $active = $this->client()->loginCustomer('cNULL2', 'wrong-' . bin2hex(random_bytes(3)));
        $this->setStatus('cNULL', 'Banned');
        try {
            $wrong = $this->client()->loginCustomer('cNULL', 'wrong-' . bin2hex(random_bytes(3)));
            $right = $this->client()->loginCustomer('cNULL', Wp2Seed::customerPassword('cNULL'));
        } finally {
            $this->setStatus('cNULL', 'Active');
        }
        $this->assertFalse(str_contains($wrong->body, 'Banned'), 'wrong password: the status is not disclosed');
        $this->assertSame($active->status, $wrong->status, 'wrong password: same answer as for an Active account');
        $this->assertTrue(str_contains($right->body, 'Banned'), 'right password: the Banned status is still shown (login refused)');
        $this->assertFalse(str_contains($right->body, 'Login Successful'), 'a Banned account never logs in');
    }

    public function testLegacyPasswordChangeValidatesTheNewPassword(): void
    {
        $before = (string) static::$db->value("SELECT password FROM tbl_customers WHERE username = 'cNULL'");
        $c = $this->customer('cNULL');
        $long = str_repeat('a', 50);
        $r = $c->postWithToken('accounts/change-password-post', ['password' => Wp2Seed::customerPassword('cNULL'), 'npass' => $long, 'cnpass' => $long], [], 'accounts/change-password');
        $this->assertNotSame(500, $r->status, 'an overlong new password is a validation error, not a server error: ' . $r->excerpt());
        $this->assertSame($before, (string) static::$db->value("SELECT password FROM tbl_customers WHERE username = 'cNULL'"), 'password unchanged');
        $short = 'x';
        $c = $this->customer('cNULL');
        $c->postWithToken('accounts/change-password-post', ['password' => Wp2Seed::customerPassword('cNULL'), 'npass' => $short, 'cnpass' => $short], [], 'accounts/change-password');
        $this->assertSame($before, (string) static::$db->value("SELECT password FROM tbl_customers WHERE username = 'cNULL'"), 'a 1-character new password is refused');
    }
}
