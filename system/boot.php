<?php

/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)

 **/

try {
    require_once 'init.php';
} catch (Throwable $e) {
    // WP2 / OD-14: never show the exception, trace or paths; log server-side with a reference
    $nuxrRef = class_exists('\Reseller\Security\ErrorReporter') ? \Reseller\Security\ErrorReporter::report($e, 'init') : bin2hex(random_bytes(6));
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    die('<!doctype html><html><head><meta charset="utf-8"><title>500 Internal Server Error</title></head><body><h1>Internal Server Error</h1><p>An internal error occurred. Please contact the administrator and quote reference ' . htmlspecialchars($nuxrRef) . '.</p></body></html>');
}

function _notify($msg, $type = 'e')
{
    $_SESSION['ntype'] = $type;
    $_SESSION['notify'] = $msg;
}

$ui = new Smarty();
// Product identity (ITC NetCore) and the operator name to show; presentation only (Reseller\Ui\Brand)
$ui->assign('_brand', \Reseller\Ui\Brand::view(is_array($config ?? null) ? $config : []));
// WP9-D / NEW-8 (O8-A): editable page templates are rendered only by the sandboxed Smarty instance
$ui->registerPlugin('function', 'nuxr_page', [\Reseller\Ui\PageTemplate::class, 'plugin']);
$ui->assign('_kolaps', $_COOKIE['kolaps']);
if (!empty($config['theme']) && $config['theme'] != 'default') {
    $_theme = APP_URL . '/' . $UI_PATH . '/themes/' . $config['theme'];
    $ui->setTemplateDir([
        'custom' => File::pathFixer($UI_PATH . '/ui_custom/'),
        'theme' => File::pathFixer($UI_PATH . '/themes/' . $config['theme']),
        'default' => File::pathFixer($UI_PATH . '/ui/')
    ]);
} else {
    $_theme = APP_URL . '/' . $UI_PATH . '/ui';
    $ui->setTemplateDir([
        'custom' => File::pathFixer($UI_PATH . '/ui_custom/'),
        'default' => File::pathFixer($UI_PATH . '/ui/')
    ]);
}
$ui->assign('_theme', $_theme);
$ui->addTemplateDir($PAYMENTGATEWAY_PATH . File::pathFixer('/ui/'), 'pg');
$ui->addTemplateDir($PLUGIN_PATH . File::pathFixer('/ui/'), 'plugin');
$ui->setCompileDir(File::pathFixer($UI_PATH . '/compiled/'));
$ui->setConfigDir(File::pathFixer($UI_PATH . '/conf/'));
$ui->setCacheDir(File::pathFixer($UI_PATH . '/cache/'));
$ui->assign('app_url', APP_URL);
$ui->assign('_domain', str_replace('www.', '', parse_url(APP_URL, PHP_URL_HOST)));
$ui->assign('_url', APP_URL . '/?_route=');
$ui->assign('_path', __DIR__);
$ui->assign('_c', $config);
$ui->assign('user_language', $config['language']);
// WP6A (OD-F): document direction / language of the adaptive shell; presentation helpers for templates
$ui->assign('nuxr_dir', \Reseller\Ui\Format::dir($config['language'] ?? null));
$ui->assign('nuxr_lang', \Reseller\Ui\Format::iso($config['language'] ?? null));
$ui->registerClass('NuxrUi', '\\Reseller\\Ui\\Format');
$ui->assign('UPLOAD_PATH', str_replace($root_path, '',  $UPLOAD_PATH));
$ui->assign('CACHE_PATH', str_replace($root_path, '',  $CACHE_PATH));
$ui->assign('PAGES_PATH', str_replace($root_path, '',  $PAGES_PATH));
$ui->assign('_system_menu', 'dashboard');
// WP2 / OD-8: one stable CSRF token per session, available to every template (pre-auth forms too)
$ui->assign('csrf_token', \Reseller\Security\CsrfPolicy::token());

function _msglog($type, $msg)
{
    $_SESSION['ntype'] = $type;
    $_SESSION['notify'] = $msg;
}

if (isset($_SESSION['notify'])) {
    $notify = $_SESSION['notify'];
    $ntype = $_SESSION['ntype'];
    $ui->assign('notify', $notify);
    $ui->assign('notify_t', $ntype);
    unset($_SESSION['notify']);
    unset($_SESSION['ntype']);
}

if (!isset($_GET['_route'])) {
    $req = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $len = strlen(ltrim(parse_url(APP_URL, PHP_URL_PATH), '/'));
    if ($len > 0) {
        $req = ltrim(substr($req, $len), '/');
    }
} else {
    // Routing Engine
    $req = _get('_route');
}

$routes = explode('/', $req);
$ui->assign('_routes', $routes);
$handler = $routes[0];
if ($handler == '') {
    $handler = 'default';
}
try {
    // WP9-D / NEW-7 + N-03: ?uid= accepts only a signed, short-lived login-link token (never a cookie token)
    if (!empty($_GET['uid']) && is_string($_GET['uid'])) {
        User::loginWithLink($_GET['uid']);
    }
    $admin = Admin::_info();

    // ---- WP2 security pipeline (before any controller code) ----
    // 1. authoritative principal for this request
    if ($admin) {
        \Reseller\Security\ActorContext::setCurrent(\Reseller\Security\ActorContext::forStaff($admin->as_array(), 'WEB'));
        $nuxr_must_change_password = Admin::hasDefaultCredential($admin);
    } elseif (User::getID()) {
        $nuxrCustomer = ORM::for_table('tbl_customers')->find_one(User::getID());
        \Reseller\Security\ActorContext::setCurrent($nuxrCustomer
            ? \Reseller\Security\ActorContext::forCustomer($nuxrCustomer->as_array(), 'WEB')
            : \Reseller\Security\ActorContext::anonymous('WEB'));
        unset($nuxrCustomer);
    } else {
        \Reseller\Security\ActorContext::setCurrent(\Reseller\Security\ActorContext::anonymous('WEB'));
    }
    $nuxrActor = \Reseller\Security\ActorContext::current();
    // 2. plugin menu registry snapshot (OD-9) before the menus are discarded below
    \Reseller\Security\PluginRegistry::capture($menu_registered);
    // 3. central CSRF (every browser POST) and route authorization (unmapped = denied for all roles)
    $nuxrGuardHandler = $handler;
    $nuxrGuardRoutes = $routes;
    if ($handler === 'default') {
        // default.php re-includes dashboard / home / login: authorize what it will actually run
        $nuxrGuardHandler = Admin::getID() ? 'dashboard' : (User::getID() ? 'home' : 'login');
        $nuxrGuardRoutes = [$nuxrGuardHandler];
    }
    if (preg_match('/^[a-z0-9_]{1,64}$/', $nuxrGuardHandler)) {
        \Reseller\Security\CsrfPolicy::enforceWeb($nuxrGuardHandler, $_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
    $sys_render = $root_path . File::pathFixer('system/controllers/' . $handler . '.php');
    if (preg_match('/^[a-z0-9_]{1,64}$/', $handler) && file_exists($sys_render)) {
        \Reseller\Security\RouteGuard::authorize($nuxrActor, $nuxrGuardHandler, $nuxrGuardRoutes, $_GET, $_SERVER['REQUEST_METHOD'] ?? 'GET', false);
        $ui->assign('_can', \Reseller\Security\PermissionService::flags($nuxrActor));
        if ($nuxrActor->isGlobalAdmin() && !\Reseller\Domain\SchemaReadiness::isReady()) {
            $ui->assign('nuxr_schema_not_ready', true);
        }
        unset($nuxrGuardHandler, $nuxrGuardRoutes);
        $menus = array();
        // "name" => $name,
        // "admin" => $admin,
        // "position" => $position,
        // "function" => $function
        $ui->assign('_system_menu', $routes[0]);
        foreach ($menu_registered as $menu) {
            if ($menu['admin'] && _admin(false)) {
                // WP2 / OD-9: an empty auth list means SuperAdmin/Admin only (same rule as dispatch)
                $menuRoles = count($menu['auth']) == 0 ? ['SuperAdmin', 'Admin'] : $menu['auth'];
                if (in_array($admin['user_type'], $menuRoles)) {
                    $menus[$menu['position']] .= '<li' . (($routes[1] == $menu['function']) ? ' class="active"' : '') . '><a href="' . getUrl('plugin/' . $menu['function']) . '">';
                    if (!empty($menu['icon'])) {
                        $menus[$menu['position']] .= '<i class="' . $menu['icon'] . '"></i>';
                    }
                    if (!empty($menu['label'])) {
                        $menus[$menu['position']] .= '<span class="pull-right-container">';
                        $menus[$menu['position']] .= '<small class="label pull-right bg-' . $menu['color'] . '">' . $menu['label'] . '</small></span>';
                    }
                    $menus[$menu['position']] .= '<span class="text">' . $menu['name'] . '</span></a></li>';
                }
            } else if (!$menu['admin'] && _auth(false)) {
                $menus[$menu['position']] .= '<li' . (($routes[1] == $menu['function']) ? ' class="active"' : '') . '><a href="' . getUrl('plugin/' . $menu['function']) . '">';
                if (!empty($menu['icon'])) {
                    $menus[$menu['position']] .= '<i class="' . $menu['icon'] . '"></i>';
                }
                if (!empty($menu['label'])) {
                    $menus[$menu['position']] .= '<span class="pull-right-container">';
                    $menus[$menu['position']] .= '<small class="label pull-right bg-' . $menu['color'] . '">' . $menu['label'] . '</small></span>';
                }
                $menus[$menu['position']] .= '<span class="text">' . $menu['name'] . '</span></a></li>';
            }
        }
        foreach ($menus as $k => $v) {
            $ui->assign('_MENU_' . $k, $v);
        }
        // WP6A: the adaptive shell's navigation (presentation only; every route stays authorized by RouteGuard)
        if ($nuxrActor->isStaff()) {
            $ui->assign('_nav', \Reseller\Ui\Navigation::for($nuxrActor, $config, $menus, $routes));
        }
        unset($menus, $menu_registered);
        include($sys_render);
    } else {
        if( empty($_SERVER["HTTP_SEC_FETCH_DEST"]) || $_SERVER["HTTP_SEC_FETCH_DEST"] != 'document' ){
            // header 404
            header("HTTP/1.0 404 Not Found");
            header("Content-Type: text/html; charset=utf-8");
            echo "404 Not Found";
            die();
        }else{
            r2(getUrl('login'));
        }
    }
} catch (Throwable $e) {
    // WP2 / OD-14: no role (SuperAdmin included) sees the exception text, SQL, paths or trace.
    // Details go to the sanitized server log; Telegram receives only a summary with the reference.
    $nuxrRef = \Reseller\Security\ErrorReporter::report($e, 'web');
    if (!headers_sent()) {
        http_response_code(500);
    }
    try {
        $ui->assign('error_title', Lang::T('Internal Error'));
        $ui->assign('error_message', \Reseller\Security\ErrorReporter::publicMessage($nuxrRef));
        $ui->assign('error_ref', $nuxrRef);
        if (empty($_SESSION['aid'])) {
            $ui->display('customer/error.tpl');
            die();
        }
        $ui->display('admin/error.tpl');
    } catch (Throwable $e2) {
        echo htmlspecialchars(\Reseller\Security\ErrorReporter::publicMessage($nuxrRef));
    }
    die();
}
