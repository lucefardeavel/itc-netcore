<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

class Password
{

    public static function _crypt($password)
    {
        return sha1($password);
    }

    public static function _verify($user_input, $hashed_password)
    {
        if (sha1($user_input) == $hashed_password) {
            return true;
        }
        return false;
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
