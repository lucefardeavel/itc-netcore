<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

/**
 * Staff authentication.
 *
 * WP2 / SEC-10 / OD-15 hardening:
 *  - the tbl_users row is re-read on every request; a missing or non-Active user is unauthenticated
 *    and the session is cleared explicitly (disable takes effect immediately)
 *  - the session is bound to a fingerprint of the stored password hash and the "remember" cookie MAC
 *    covers the password hash: a password change invalidates every other session and cookie
 *  - constant-time comparisons; validateToken() is false for a missing user
 *  - API requests never read cookies or the session: their identity comes only from an a2 token
 *    (Reseller\Security\ApiGuard), exposed here through $GLOBALS['nuxr_api_staff_id']
 */
class Admin
{
    /** @var array<int,array<string,mixed>> per-request cache of verified, Active user rows */
    private static $verified = [];

    /** tbl_meta name of a staff remember-cookie device record (D-9) */
    private const DEVICE_META = 'nuxr_staff_device';

    public static function getID()
    {
        global $config, $isApi;

        if ($isApi) {
            $id = (int) ($GLOBALS['nuxr_api_staff_id'] ?? 0);
            return ($id > 0 && self::activeRow($id) !== null) ? $id : 0;
        }

        $enable_session_timeout = $config['enable_session_timeout'] == 1;
        $session_timeout_duration = $config['session_timeout_duration'] ? intval($config['session_timeout_duration'] * 60) : intval(60 * 60); // Convert minutes to seconds
        if (!empty($_SESSION['aid'])) {
            $row = self::activeRow($_SESSION['aid']);
            if ($row === null || !\Reseller\Security\AppKey::available() || !hash_equals(self::sessionFingerprint($row), (string) ($_SESSION['aid_pwfp'] ?? ''))) {
                self::logoutSession();
                return 0;
            }
            if ($enable_session_timeout && !empty($_SESSION['aid_expiration'])) {
                if ($_SESSION['aid_expiration'] <= time()) {
                    // Session expired, log out the user
                    self::logoutSession();
                    _alert(Lang::T('Session has expired. Please log in again.'), 'danger', "admin");
                    return 0;
                }
                // extend timeout duration
                $_SESSION['aid_expiration'] = time() + $session_timeout_duration;
            }
            if (!self::validateToken($row['id'], $_COOKIE['aid'] ?? '')) {
                self::logoutSession();
                _alert(Lang::T('Token has expired. Please log in again.'), 'danger', "admin");
                return 0;
            }
            return (int) $row['id'];
        }
        // Check if the cookie is set and valid
        if (isset($_COOKIE['aid']) && is_string($_COOKIE['aid'])) {
            $tmp = explode('.', $_COOKIE['aid']);
            if (count($tmp) === 3 && preg_match('/^[1-9][0-9]{0,9}$/', $tmp[0]) && preg_match('/^[1-9][0-9]{0,11}$/', $tmp[1])) {
                $row = self::activeRow($tmp[0]);
                if ($row !== null && \Reseller\Security\AppKey::available()
                    && hash_equals(self::cookieMac((int) $tmp[0], (int) $tmp[1], (string) $row['password']), $tmp[2])
                    && time() - (int) $tmp[1] < 86400 * 7 && (int) $tmp[1] <= time() + 60
                    && self::validateToken($row['id'], $_COOKIE['aid'])
                    && self::deviceRecord((int) $row['id'], $_COOKIE['aid']) !== null) {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                    }
                    $_SESSION['aid'] = (int) $row['id'];
                    $_SESSION['aid_pwfp'] = self::sessionFingerprint($row);
                    if ($enable_session_timeout) {
                        $_SESSION['aid_expiration'] = time() + $session_timeout_duration;
                    }
                    return (int) $row['id'];
                }
            }
            self::removeCookie();
        }

        return 0;
    }

    /**
     * Establishes a fresh authenticated staff session (new session id, fingerprint, CSRF token)
     * and returns the remember cookie value.
     */
    public static function setCookie($aid)
    {
        global $config;
        $enable_session_timeout = $config['enable_session_timeout'];
        $session_timeout_duration = intval($config['session_timeout_duration']) * 60; // Convert minutes to seconds

        $row = self::activeRow($aid, true);
        // WP9-D / NEW-7: no staff session or cookie without the dedicated application key (fail closed)
        if ($row === null || !\Reseller\Security\AppKey::available()) {
            return '';
        }
        $time = time();
        $token = $row['id'] . '.' . $time . '.' . self::cookieMac((int) $row['id'], $time, (string) $row['password']);
        // D-9: one device record per cookie; a second login of the same user in the same second would mint the same
        // cookie, so the issue time steps back until the token is unique (still valid, a few seconds shorter)
        for ($i = 0; $i < 60 && self::deviceRecord((int) $row['id'], $token) !== null; $i++) {
            $time--;
            $token = $row['id'] . '.' . $time . '.' . self::cookieMac((int) $row['id'], $time, (string) $row['password']);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        // Detect the current protocol
        $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        // Set cookie with security flags
        setcookie('aid', $token, [
            'expires' => time() + 86400 * 7, // 7 days
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax', // or Strict
        ]);

        $_SESSION['aid'] = (int) $row['id'];
        $_SESSION['aid_pwfp'] = self::sessionFingerprint($row);
        if ($enable_session_timeout) {
            $_SESSION['aid_expiration'] = $time + $session_timeout_duration;
        }
        \Reseller\Security\CsrfPolicy::rotate();
        self::upsertToken($row['id'], $token);
        self::registerDevice((int) $row['id'], $token, $time);

        return $token;
    }

    /**
     * D-9 (owner direction D-17): per-device server-side record of a staff remember cookie, so that logout revokes THIS
     * device only and a captured cookie cannot be replayed after it. Stored in the generic tbl_meta table (no schema
     * change): tbl = tbl_users, tbl_id = staff id, value = sha256(cookie) ':' issue time. Records older than the cookie
     * lifetime are pruned at each login.
     */
    public static function registerDevice(int $aid, string $token, int $time): void
    {
        ORM::for_table('tbl_meta')->where('tbl', 'tbl_users')->where('tbl_id', $aid)->where('name', self::DEVICE_META)
            ->where_raw('CAST(SUBSTRING_INDEX(`value`, \':\', -1) AS UNSIGNED) < ?', [time() - 86400 * 7])->delete_many();
        $m = ORM::for_table('tbl_meta')->create();
        $m->tbl = 'tbl_users';
        $m->tbl_id = $aid;
        $m->name = self::DEVICE_META;
        $m->value = hash('sha256', $token) . ':' . $time;
        $m->save();
    }

    /** Revokes the device record of this cookie (logout). Deleting by the cookie's own hash touches no other device. */
    public static function revokeDevice(string $token): void
    {
        $tmp = explode('.', $token);
        if (count($tmp) === 3 && preg_match('/^[1-9][0-9]{0,9}$/', $tmp[0])) {
            ORM::for_table('tbl_meta')->where('tbl', 'tbl_users')->where('tbl_id', (int) $tmp[0])->where('name', self::DEVICE_META)
                ->where_like('value', hash('sha256', $token) . ':%')->delete_many();
        }
    }

    /** @return mixed the device record of this cookie, or null when it was never issued, expired or revoked */
    private static function deviceRecord(int $aid, string $token)
    {
        $m = ORM::for_table('tbl_meta')->where('tbl', 'tbl_users')->where('tbl_id', $aid)->where('name', self::DEVICE_META)
            ->where_like('value', hash('sha256', $token) . ':%')->find_one();
        return $m ?: null;
    }

    /** Removes the staff cookie AND the staff session state, whether or not a cookie was sent. */
    public static function removeCookie()
    {
        $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if (isset($_COOKIE['aid']) && !headers_sent()) {
            setcookie('aid', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE['aid']);
        self::logoutSession();
    }

    /** Clears staff session keys and rotates the session id and CSRF token. */
    public static function logoutSession()
    {
        unset($_SESSION['aid'], $_SESSION['aid_pwfp'], $_SESSION['aid_expiration']);
        self::$verified = [];
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        \Reseller\Security\CsrfPolicy::rotate();
    }

    public static function _info($id = 0)
    {
        if (empty($id) && $id == 0) {
            $id = Admin::getID();
        }
        if ($id) {
            return ORM::for_table('tbl_users')->find_one($id);
        } else {
            return null;
        }
    }

    public static function upsertToken($aid, $token)
    {
        $query = ORM::for_table('tbl_users')->findOne($aid);
        if ($query) {
            $query->login_token = sha1($token);
            $query->save();
        }
    }

    public static function validateToken($aid, $cookieToken)
    {
        global $config;
        $query = ORM::for_table('tbl_users')->select('login_token')->findOne($aid);
        if (empty($query)) {
            return false;
        }
        if ($config['single_session'] != 'yes') {
            return true; // For multi-session, any token is valid
        }
        return is_string($cookieToken) && $cookieToken !== '' && is_string($query->login_token)
            && hash_equals($query->login_token, sha1($cookieToken));
    }

    /** True when the stored hash is the installer-seeded default credential (SEC-18 minimal). */
    public static function hasDefaultCredential($row)
    {
        return isset($row['password']) && hash_equals('d033e22ae348aeb5660fc2140aec35850c4da997', (string) $row['password']);
    }

    /** @return array<string,mixed>|null the authoritative row if the user exists and is Active */
    public static function activeRow($id, $fresh = false)
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }
        $id = (int) $id;
        if (!$fresh && isset(self::$verified[$id])) {
            return self::$verified[$id];
        }
        $st = ORM::get_db()->prepare('SELECT * FROM tbl_users WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false || ($row['status'] ?? '') !== 'Active') {
            unset(self::$verified[$id]);
            return null;
        }
        return self::$verified[$id] = $row;
    }

    public static function resetCache()
    {
        self::$verified = [];
    }

    // WP9-D / NEW-7 (O7-A): purpose subkeys of the dedicated application key, never $db_pass; callers check
    // AppKey::available() first, the '' fallback never verifies
    private static function sessionFingerprint(array $row)
    {
        return \Reseller\Security\AppKey::mac(\Reseller\Security\AppKey::STAFF_SESSION, 'nuxr-admin-session|' . $row['id'] . '|' . $row['password']) ?? '';
    }

    private static function cookieMac(int $id, int $time, string $passwordHash)
    {
        return \Reseller\Security\AppKey::mac(\Reseller\Security\AppKey::STAFF_COOKIE, 'nuxr-admin-cookie|v3|' . $id . '|' . $time . '|' . $passwordHash) ?? '';
    }
}
