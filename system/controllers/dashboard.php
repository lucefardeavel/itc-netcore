<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

_admin();
$ui->assign('_title', Lang::T('Dashboard'));
$ui->assign('_admin', $admin);

if (isset($_GET['refresh'])) {
    $files = scandir($CACHE_PATH);
    foreach ($files as $file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if (is_file($CACHE_PATH . DIRECTORY_SEPARATOR . $file) && $ext == 'temp') {
            unlink($CACHE_PATH . DIRECTORY_SEPARATOR . $file);
        }
    }
    r2(getUrl('dashboard'), 's', 'Data Refreshed');
}

$tipeUser = _req("user");
if (empty($tipeUser)) {
    $tipeUser = 'Admin';
}
$ui->assign('tipeUser', $tipeUser);

$reset_day = $config['reset_day'];
if (empty($reset_day)) {
    $reset_day = 1;
}
//first day of month
if (date("d") >= $reset_day) {
    $start_date = date('Y-m-' . $reset_day);
} else {
    $start_date = date('Y-m-' . $reset_day, strtotime("-1 MONTH"));
}

$current_date = date('Y-m-d');
$ui->assign('start_date', $start_date);
$ui->assign('current_date', $current_date);

$tipeUser = $admin['user_type'];
if (in_array($tipeUser, ['SuperAdmin', 'Admin'])) {
    $tipeUser = 'Admin';
}

// WP2 / OD-4 / SEC-06: the legacy widgets compute global (unscoped) figures and activity logs, so they
// are rendered only for global scopes. Agent/Sales/Report dashboards get the scope-safe allowlist,
// which is empty in V1 (scoped widgets are WP6B).
$nuxrDashboardGlobal = \Reseller\Security\PermissionService::scopeOf(\Reseller\Security\ActorContext::current(), \Reseller\Security\Capability::REPORT_READ) === 'G';
// WP6A (OD-L, OD-H): the scoped dashboard foundation is the landing view for every role; the legacy global widgets
// (charts) stay available to global scopes on their own tab, so the landing page loads no chart bundle
$nuxrWidgetsView = $nuxrDashboardGlobal && _get('widgets') === '1';
$ui->assign('nuxr_widgets_view', $nuxrWidgetsView);
$ui->assign('nuxr_widgets_available', $nuxrDashboardGlobal);
$ui->assign('nuxr_charts', $nuxrWidgetsView);
if (!$nuxrWidgetsView) {
    $nuxrActor = \Reseller\Security\ActorContext::current();
    $ui->assign('nuxr_home', \Reseller\Ui\Dashboard::foundation($nuxrActor));
    $ui->assign('_title', Lang::T('Dashboard'));
    run_hook('view_dashboard'); #HOOK
    $ui->display('admin/dashboard.tpl');
    return;
}
$widgets = $nuxrDashboardGlobal ? ORM::for_table('tbl_widgets')->where("enabled", 1)->where('user', $tipeUser)->order_by_asc("orders")->findArray() : [];
$count = count($widgets);
for ($i = 0; $i < $count; $i++) {
    try{
        if(preg_match('/^[A-Za-z0-9_]+$/', (string) $widgets[$i]['widget']) && file_exists($WIDGET_PATH . DIRECTORY_SEPARATOR . $widgets[$i]['widget'].".php")){
            require_once $WIDGET_PATH . DIRECTORY_SEPARATOR . $widgets[$i]['widget'].".php";
            $widgets[$i]['content'] = (new $widgets[$i]['widget'])->getWidget($widgets[$i]);
        }else{
            $widgets[$i]['content'] = "Widget not found";
        }
    } catch (Throwable $e) {
        // WP2 / OD-14: generic text + reference; details in the sanitized server log
        $widgets[$i]['content'] = htmlspecialchars(\Reseller\Security\ErrorReporter::publicMessage(\Reseller\Security\ErrorReporter::report($e, 'widget')));
    }
}

$ui->assign('widgets', $widgets);
run_hook('view_dashboard'); #HOOK
$ui->display('admin/dashboard.tpl');