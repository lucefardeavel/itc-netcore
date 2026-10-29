<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Final QA S-2: a non-administrator renaming its OWN account through settings/users-edit-post used to answer "is this
 * username taken anywhere?" ("The username is already used." versus a successful rename) for any name, at no cost.
 * Report, Sales and Agent self-renames are now refused before the lookup, so a taken name (the administrator's login)
 * and a free name get the same refusal and nothing changes. Positive control: an administrator still renames itself.
 */
final class W10StaffSelfRenameTest extends Wp2HttpTestCase
{
    private function post(string $actor, string $newName): \Reseller\Tests\Support\HttpResponse
    {
        $id = static::$seed->staff[$actor];
        $row = static::$db->rows('SELECT fullname, user_type, phone, email FROM tbl_users WHERE id = ?', [$id])[0];
        return $this->staff($actor)->postWithToken('settings/users-edit-post', ['id' => $id, 'username' => $newName, 'fullname' => $row['fullname'],
            'user_type' => $row['user_type'], 'phone' => (string) $row['phone'], 'email' => (string) $row['email'], 'password' => '', 'cpassword' => ''], [], 'settings/users');
    }

    public function testNonAdministratorSelfRenameIsRefusedAlikeForTakenAndFreeNames(): void
    {
        foreach (['rp', 'A1', 'A'] as $actor) {
            $id = static::$seed->staff[$actor];
            // every staff() call logs in (tbl_logs; in tbl_users the last login and the bcrypt re-hash of the seed password,
            // SEC-17): the profile columns and the audit trail must not change
            $exclude = [...\Reseller\Tests\Support\DbProbe::DEFAULT_EXCLUDE, 'tbl_logs', 'tbl_users'];
            $profile = static fn(): array => static::$db->rows('SELECT username, fullname, user_type, root, status, phone, email FROM tbl_users ORDER BY id');
            $rowsBefore = $profile();
            $before = $this->snapshot($exclude);
            $taken = $this->post($actor, 'ad');                                   // the administrator's login
            $free = $this->post($actor, 'w10free' . bin2hex(random_bytes(3)));   // a name nobody holds
            $this->assertSame($actor, static::$db->value('SELECT username FROM tbl_users WHERE id = ?', [$id]), "{$actor}: not renamed");
            $this->assertUnchanged($before, "{$actor}: self-rename refused", $exclude);
            $this->assertSame($rowsBefore, $profile(), "{$actor}: no staff profile changed");
            $this->assertSame([$taken->status, $taken->header('location')], [$free->status, $free->header('location')],
                "{$actor}: a taken and a free name are answered the same way");
        }
    }

    public function testPositiveControlAnAdministratorRenamesItself(): void
    {
        $id = static::$seed->staff['ad'];
        $new = 'ad' . bin2hex(random_bytes(2));
        $this->post('ad', $new);
        $this->assertSame($new, static::$db->value('SELECT username FROM tbl_users WHERE id = ?', [$id]), 'Admin renamed itself');
        static::$db->exec("UPDATE tbl_users SET username = 'ad' WHERE id = " . $id);
    }
}
