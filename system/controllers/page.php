<?php
/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

_auth();
$ui->assign('_title', Lang::T('Order Voucher'));
$ui->assign('_system_menu', 'order');

$action = $routes['1'];
$user = User::_info();
$ui->assign('_user', $user);

// WP2 / NEW-4: the customer page viewer accepts only allowlisted/existing page names inside $PAGES_PATH
$nuxrPage = \Reseller\Security\PathGuard::pageName($action, $PAGES_PATH, true);
if ($nuxrPage !== null && file_exists($PAGES_PATH . DIRECTORY_SEPARATOR . $nuxrPage . ".html")) {
    $ui->assign("PageFile", $nuxrPage);
    $ui->assign("pageHeader", $nuxrPage);
    run_hook('customer_view_page'); #HOOK
    $ui->display('customer/pages.tpl');
} else
    $ui->display('customer/404.tpl');
