<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

class Password
{

    /**
     * Staff password hash. WP9 / SEC-17: salted and adaptive (bcrypt, 60 characters: fits tbl_users.password
     * varchar(64) on every supported schema); unsalted SHA-1 is never written.
     */
    public static function _crypt($password)
    {
        return password_hash((string) $password, PASSWORD_BCRYPT);
    }

    public static function _verify($user_input, $hashed_password)
    {
        $hash = (string) $hashed_password;
        if ($hash !== '' && $hash[0] === '$') {
            return password_verify((string) $user_input, $hash);
        }
        // WP9 / SEC-17: a legacy unsalted SHA-1 hash (replaced at the next login), compared strictly: the former
        // loose == let two different passwords whose SHA-1 values are both "0e<digits>" log in for each other
        return preg_match('/^[0-9a-f]{40}$/', $hash) === 1 && hash_equals($hash, sha1((string) $user_input));
    }

    /** WP9 / SEC-17: true when a stored staff hash should be replaced by a current password_hash(). */
    public static function needsRehash($hashed_password)
    {
        $hash = (string) $hashed_password;
        return $hash === '' || $hash[0] !== '$' || password_needs_rehash($hash, PASSWORD_BCRYPT);
    }
    public static function _uverify($user_input, $hashed_password)
    {
        if ($user_input == $hashed_password) {
            return true;
        }
        return false;
    }
    public static function _gen()
    {
        $pass = substr(str_shuffle(str_repeat('ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz@#!123456789', 8)), 0, 8);
        return $pass;
    }

    /**
     * verify CHAP password (RFC 1994: MD5(id + password + challenge))
     * WP4: returns true only when the response MATCHES (the pre-WP4 code returned true on a mismatch, so any
     * CHAP response was accepted by radius.php); malformed input never verifies.
     * @param string $realPassword
     * @param string $CHAPassword "0x" + hex(id + 16-byte response)
     * @param string $CHAPChallenge "0x" + hex(challenge)
     * @return bool
     */
    public static function chap_verify($realPassword, $CHAPassword, $CHAPChallenge){
        $CHAPassword = strtolower((string) $CHAPassword);
        $CHAPChallenge = strtolower((string) $CHAPChallenge);
        if (!preg_match('/^0x[0-9a-f]{34}$/', $CHAPassword) || !preg_match('/^0x(?:[0-9a-f]{2}){1,253}$/', $CHAPChallenge)) {
            return false;
        }
        $CHAPassword = substr($CHAPassword, 2);
        $chapid = substr($CHAPassword, 0, 2);
        $result = hex2bin($chapid) . $realPassword . hex2bin(substr($CHAPChallenge, 2));
        $response = $chapid . md5($result);
        return hash_equals($response, $CHAPassword);
    }
}
