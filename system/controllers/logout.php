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
// UX-7: decide where the user lands BEFORE the session is cleared: staff (a staff session or a staff remember cookie,
// without a customer session) -> the staff login; a customer -> the customer login
$logoutStaff = $logoutAid > 0
    || (empty($_SESSION['uid']) && isset($_COOKIE['aid']) && is_string($_COOKIE['aid']) && $_COOKIE['aid'] !== '');
$logoutTarget = $logoutStaff ? 'admin' : 'login';
if ($logoutAid > 0) {
    $logoutUser = ORM::for_table('tbl_users')->find_one($logoutAid);
    if ($logoutUser) {
        $logoutUser->login_token = '';
        $logoutUser->save();
    }
}
// D-9: revoke this device's remember cookie server-side (other devices keep theirs)
if (isset($_COOKIE['aid']) && is_string($_COOKIE['aid'])) {
    Admin::revokeDevice($_COOKIE['aid']);
}
Admin::removeCookie();
User::removeCookie();
$_SESSION = [];
session_destroy();
session_start();
session_regenerate_id(true);
\Reseller\Security\CsrfPolicy::rotate();
// UX 2026-11-09: back to the sign-in page with a short notice (no intermediate countdown page)
r2(getUrl($logoutTarget), 's', Lang::T('Logout Successful'));
