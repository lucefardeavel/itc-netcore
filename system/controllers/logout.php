<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Pragma: no-cache");

run_hook('customer_logout'); #HOOK
if (session_status() == PHP_SESSION_NONE) session_start();
// WP2 / SEC-10: clear the server-side login token, the staff and customer state, and the session
$logoutAid = (int) ($_SESSION['aid'] ?? 0);
if ($logoutAid > 0) {
    $logoutUser = ORM::for_table('tbl_users')->find_one($logoutAid);
    if ($logoutUser) {
        $logoutUser->login_token = '';
        $logoutUser->save();
    }
}
Admin::removeCookie();
User::removeCookie();
$_SESSION = [];
session_destroy();
session_start();
session_regenerate_id(true);
\Reseller\Security\CsrfPolicy::rotate();
_alert(Lang::T('Logout Successful'), 'warning', "login");
