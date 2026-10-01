<?php
/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

_admin();
$ui->assign('_title', 'Community');
$ui->assign('_system_menu', 'community');

$action = $routes['1'];
$ui->assign('_admin', $admin);

switch ($action) {
    // WP2 / OD-2: the legacy rollback (download + overwrite of application code) is removed.
    default:
        $ui->display('admin/community.tpl');
}