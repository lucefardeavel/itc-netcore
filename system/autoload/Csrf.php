<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

/**
 * Legacy CSRF facade. WP2 / OD-8: delegates to Reseller\Security\CsrfPolicy — one stable token per
 * session, always enforced for browser requests (the csrf_enabled setting is ignored). The API uses
 * bearer tokens instead (cookies and session are discarded there).
 */
class Csrf
{
    public static function generateToken($length = 16)
    {
        return bin2hex(random_bytes($length));
    }

    public static function validateToken($token, $storedToken)
    {
        return is_string($token) && is_string($storedToken) && $storedToken !== '' && hash_equals($storedToken, $token);
    }

    public static function check($token)
    {
        global $isApi;
        if ($isApi) {
            return true;
        }
        // a request that carries the token in the X-CSRF-Token header (script/AJAX) satisfies the
        // legacy per-controller check too (the central policy already verified it)
        if (!is_string($token) || $token === '') {
            $token = \Reseller\Security\CsrfPolicy::requestToken();
        }
        return \Reseller\Security\CsrfPolicy::check(is_string($token) ? $token : null);
    }

    /** Returns the session's stable token (no per-render regeneration: multiple tabs keep working). */
    public static function generateAndStoreToken()
    {
        return \Reseller\Security\CsrfPolicy::token();
    }

    public static function clearToken()
    {
        \Reseller\Security\CsrfPolicy::rotate();
    }
}
