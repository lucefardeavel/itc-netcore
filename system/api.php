<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 *
 * This File is for API Access
 *
 * WP2 / SEC-21 / OD-3 / OD-15:
 *  - browser cookies and session are discarded before anything else (no ambient browser identity)
 *  - identity only from an actor-bound a2 staff token; the global api_key and the legacy a./c./u.
 *    token formats are refused; without a dedicated $api_secret every request fails closed
 *  - the same RouteGuard / PermissionService / DataScope as the web, plus an API route allowlist
 *  - every response passes a recursive sanitizer; exceptions return a generic message + reference
 **/


if ($_SERVER['REQUEST_METHOD'] === "OPTIONS" || $_SERVER['REQUEST_METHOD'] === "HEAD") {
    header('Access-Control-Allow-Origin: *');
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Access-Control-Request-Headers, Authorization");
    header("HTTP/1.1 200 OK");
    die();
}

// no ambient browser identity may reach API controllers
$_COOKIE = [];
$_SESSION = [];
unset($_GET['uid'], $_REQUEST['uid']);

$isApi = true;

include "../init.php";

// Dummy Class
$ui = new class($key)
{
    var $assign = [];
    function display($key)
    {
        global $req;
        showResult(true, $req, $this->getAll());
    }
    function assign($key, $value)
    {
        $this->assign[$key] = $value;
    }
    function get($key)
    {
        if (isset($this->assign[$key])) {
            return $this->assign[$key];
        }
        return '';
    }
    function getTemplateVars($key)
    {
        if (isset($this->assign[$key])) {
            return $this->assign[$key];
        }
        return '';
    }
    function getAll()
    {

        $result = [];
        foreach ($this->assign as $key => $value) {
            if($value instanceof ORM){
                $result[$key] = $value->as_array();
            }else if($value instanceof IdiormResultSet){
                $count = count($value);
                for($n=0;$n<$count;$n++){
                    foreach ($value[$n] as $k=>$v) {
                        $result[$key][$n][$k] = $v;
                    }
                }
            }else{
                $result[$key] = $value;
            }
        }
        return $result;
    }

    function fetch()
    {
        return "";
    }
};

$req = _get('r');
$routes = explode('/', $req);
$handler = $routes[0];

try {
    // a2.{uid}.{iat}.{mac} (Authorization: Bearer or token parameter); fails closed without a dedicated secret
    $nuxrActor = \Reseller\Security\ApiGuard::authenticate(\Reseller\Security\ApiGuard::presentedToken());
    \Reseller\Security\ActorContext::setCurrent($nuxrActor);
    $admin = Admin::_info();
    $nuxr_must_change_password = $admin && Admin::hasDefaultCredential($admin);

    if ($nuxrActor->isStaff()) {
        if (!isset($handler) || empty($handler) || $handler == 'isValid') {
            showResult(true, Lang::T("Token is valid"));
        }
        if ($handler == 'me') {
            showResult(true, "", ['id' => (int) $admin['id'], 'username' => $admin['username'], 'fullname' => $admin['fullname'], 'user_type' => $admin['user_type']]);
        }
    }

    $sys_render = File::pathFixer($root_path . 'system/controllers/' . $handler . '.php');
    if (preg_match('/^[a-z0-9_]{1,64}$/', (string) $handler) && file_exists($sys_render)) {
        \Reseller\Security\RouteGuard::authorize($nuxrActor, $handler, $routes, $_GET, $_SERVER['REQUEST_METHOD'] ?? 'GET', true);
        include($sys_render);
        showResult(true, $req, $ui->getAll());
    } else {
        showResult(false, Lang::T('Command not found'));
    }
} catch (Throwable $e) {
    $nuxrRef = \Reseller\Security\ErrorReporter::report($e, 'api');
    http_response_code(500);
    showResult(false, \Reseller\Security\ErrorReporter::publicMessage($nuxrRef), [], ['ref' => $nuxrRef]);
}
