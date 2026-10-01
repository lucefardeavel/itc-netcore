<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

_admin();
$ui->assign('_title', 'Pages');
$ui->assign('_system_menu', 'pages');

$action = $routes['1'];
$ui->assign('_admin', $admin);

// WP2 / OD-14 NEW-4: page and voucher-template names are allowlisted and canonicalized; every file
// path must resolve inside $PAGES_PATH (or $PAGES_PATH/vouchers). Files are only created for the
// built-in page allowlist. RouteGuard: viewing = SuperAdmin/Admin (system.admin); -post / -reset write
// Smarty-included template content and are therefore SuperAdmin-only (system.code, OD-12), POST only.
if (!is_dir($PAGES_PATH)) {
    @mkdir($PAGES_PATH, 0755, true);
}
$nuxrMode = 'view';
if (strpos((string) $action, "-reset") !== false) {
    $nuxrMode = 'reset';
    $action = str_replace("-reset", "", $action);
} else if (strpos((string) $action, "-post") !== false) {
    $nuxrMode = 'post';
    $action = str_replace("-post", "", $action);
}
$nuxrPage = \Reseller\Security\PathGuard::pageName($action, $PAGES_PATH, $nuxrMode !== 'reset');
if ($nuxrPage === null) {
    http_response_code(404);
    $ui->display('admin/404.tpl');
    die();
}
$action = $nuxrPage;
$path = $PAGES_PATH . DIRECTORY_SEPARATOR . $action . ".html";
if (!\Reseller\Security\PathGuard::inside($path, $PAGES_PATH)) {
    http_response_code(404);
    $ui->display('admin/404.tpl');
    die();
}

if ($nuxrMode === 'reset') {
    $temp = "pages_template/" . $action . ".html";
    if (file_exists($temp) && \Reseller\Security\PathGuard::inside($temp, 'pages_template', true)) {
        if (!copy($temp, $path)) {
            file_put_contents($path, Http::getData('https://raw.githubusercontent.com/hotspotbilling/phpnuxbill/master/pages_template/' . rawurlencode($action) . '.html'));
        }
    } else {
        file_put_contents($path, Http::getData('https://raw.githubusercontent.com/hotspotbilling/phpnuxbill/master/pages_template/' . rawurlencode($action) . '.html'));
    }
    r2(getUrl('pages/') . $action);
} else if ($nuxrMode === 'view') {
    $ui->assign("action", $action);
    run_hook('view_edit_pages'); #HOOK
    if (!file_exists($path) && in_array($action, \Reseller\Security\PathGuard::PAGES, true)) {
        $temp = "pages_template/" . $action . ".html";
        if (file_exists($temp)) {
            if (!copy($temp, $path)) {
                touch($path);
            }
        } else {
            touch($path);
        }
    }
    if (file_exists($path)) {
        if ($action == 'Voucher') {
            if (!file_exists("$PAGES_PATH/vouchers/")) {
                mkdir("$PAGES_PATH/vouchers/");
                if (file_exists("pages_template/vouchers/")) {
                    File::copyFolder("pages_template/vouchers/", "$PAGES_PATH/vouchers/");
                }
            }
            $ui->assign("vouchers", scandir("$PAGES_PATH/vouchers/"));
        }
        $html = file_get_contents($path);
        $ui->assign("htmls", str_replace(["<div", "</div>"], "", $html));
        $ui->assign("writeable", is_writable($path));
        $ui->assign("pageHeader", str_replace('_', ' ', $action));
        $ui->assign("PageFile", $action);
        $ui->display('admin/settings/page.tpl');
    } else
        $ui->display('admin/404.tpl');
} else {
    if (file_exists($path)) {
        $html = _post("html");
        $templateName = null;
        if (_post('template_save') == 'yes' && _post('template_name') !== '') {
            if (!is_dir($PAGES_PATH . DIRECTORY_SEPARATOR . 'vouchers')) {
                @mkdir($PAGES_PATH . DIRECTORY_SEPARATOR . 'vouchers', 0755, true);
            }
            $templateName = \Reseller\Security\PathGuard::voucherTemplateName(_post('template_name'));
            $templatePath = $templateName === null ? null : $PAGES_PATH . DIRECTORY_SEPARATOR . 'vouchers' . DIRECTORY_SEPARATOR . $templateName . '.html';
            if ($templatePath === null || !\Reseller\Security\PathGuard::inside($templatePath, $PAGES_PATH . DIRECTORY_SEPARATOR . 'vouchers')) {
                r2(getUrl('pages/') . $action, 'e', Lang::T('Invalid template name'));
            }
        }
        run_hook('save_pages'); #HOOK
        if (file_put_contents($path, $html)) {
            if ($templateName !== null) {
                file_put_contents($templatePath, $html);
            }
            r2(getUrl('pages/') . $action, 's', Lang::T("Saving page success"));
        } else {
            r2(getUrl('pages/') . $action, 'e', Lang::T("Failed to save page, make sure i can write to folder pages, <i>chmod 664 pages/*.html<i>"));
        }
    } else
        $ui->display('admin/404.tpl');
}
