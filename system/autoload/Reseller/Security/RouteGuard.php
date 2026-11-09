<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Central route authorization (WP2 §5), evaluated in system/boot.php and system/api.php before any
 * controller code runs. Unmapped routes are denied for every role. Denials never throw into the
 * legacy crash handler: they render a uniform 403/404/405 response and end the request.
 */
final class RouteGuard
{
    /** @var array{handler:string,action:string,key:string,method:string,rule:?string}|null */
    private static ?array $last = null;

    /**
     * Resolves (handler, routes, GET params, method) to the RouteMap key and the applicable rule.
     *
     * @return array{handler:string,action:string,key:string,method:string,rule:?string,mapped:bool,method_allowed:bool}
     */
    public static function resolve(string $handler, array $routes, array $query, string $method): array
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $map = RouteMap::ROUTES[$handler] ?? null;
        $action = isset($routes[1]) && is_string($routes[1]) ? $routes[1] : '';
        $out = ['handler' => $handler, 'action' => $action, 'key' => '', 'method' => $method, 'rule' => null, 'mapped' => false, 'method_allowed' => false];
        if ($map === null) {
            return $out;
        }
        $key = self::actionKey($handler, $action, $map);
        foreach (RouteMap::VARIANTS[$handler . '/' . $key] ?? [] as [$type, $k, $v, $variant]) {
            $hit = $type === 'seg'
                ? (isset($routes[$k]) && $routes[$k] === $v)
                : array_key_exists($k, $query);
            if ($hit) {
                $key = $variant;
                break;
            }
        }
        $entry = $map[$key] ?? null;
        if ($entry === null || $key === '' || $key === '*') {
            return $out;
        }
        $out['key'] = $key;
        $out['mapped'] = true;
        if (is_string($entry)) {
            $rule = in_array($method, RouteMap::GET_POST, true) ? $entry : null;
        } else {
            $rule = $entry[$method] ?? null;
        }
        $out['rule'] = $rule;
        $out['method_allowed'] = $rule !== null;
        return $out;
    }

    /**
     * Authorizes the request or ends it. $api: request arrived through system/api.php.
     */
    public static function authorize(ActorContext $actor, string $handler, array $routes, array $query, string $method, bool $api): void
    {
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $handler)) {
            self::deny(404, 'invalid handler');
        }
        $r = self::resolve($handler, $routes, $query, $method);
        self::$last = $r;
        if (!$r['mapped']) {
            self::deny(404, 'unmapped route ' . $handler . '/' . $r['action']);
        }
        if ($api) {
            $allowed = RouteMap::API_ALLOWED[$handler] ?? null;
            $base = explode('#', $r['key'])[0];
            if ($allowed === null || ($allowed !== '*' && !in_array($base, $allowed, true))) {
                self::deny(404, 'route not available through the API');
            }
        }
        if (!$r['method_allowed']) {
            self::deny(405, 'method ' . $r['method'] . ' not allowed for ' . $handler . '/' . $r['key']);
        }
        $rule = (string) $r['rule'];
        switch ($rule) {
            case 'public':
            case 'customer':
                return;
            case 'deny':
                self::deny(403, 'route disabled');
                // no break (deny ends the request)
            case 'plugin':
                PluginRegistry::authorize($actor, isset($routes[1]) && is_string($routes[1]) ? $routes[1] : '');
                return;
            case 'staff':
                self::requireStaff($actor);
                return;
            default:
                self::requireStaff($actor);
                if (!PermissionService::can($actor, $rule)) {
                    self::deny(403, 'capability ' . $rule);
                }
                return;
        }
    }

    /** @return array{handler:string,action:string,key:string,method:string,rule:?string}|null */
    public static function last(): ?array
    {
        return self::$last;
    }

    public static function requireStaff(ActorContext $actor): void
    {
        if ($actor->denied) {
            self::deny(403, 'security context unavailable');
        }
        if (!$actor->isStaff()) {
            self::deny(401, 'staff authentication required');
        }
        if (!empty($GLOBALS['nuxr_must_change_password'])) {
            $h = $GLOBALS['handler'] ?? '';
            $a = $GLOBALS['routes'][1] ?? '';
            if (!($h === 'settings' && in_array($a, ['change-password', 'change-password-post'], true))) {
                self::deny(428, 'default credential must be changed');
            }
        }
    }

    /**
     * Uniform denial. 401 → login redirect (web) ; 403/404/405 → status page ; API → success:false.
     */
    public static function deny(int $code, string $reason = ''): never
    {
        ErrorReporter::log('access.denied', 'code=' . $code . ' ' . $reason);
        global $isApi, $ui;
        if (!empty($isApi)) {
            http_response_code($code === 428 ? 403 : $code);
            showResult(false, match ($code) {
                401 => 'Authentication required',
                404 => 'Not found',
                405 => 'Method not allowed',
                default => 'Forbidden',
            });
        }
        if ($code === 401) {
            r2(getUrl('login'));
        }
        if ($code === 428) {
            r2(getUrl('settings/change-password'), 'w', \Lang::T('For your security, set your own password before you continue.'));
        }
        http_response_code($code);
        if ($code === 405) {
            header('Allow: POST');
        }
        $title = match ($code) {
            404 => '404 Not Found',
            405 => '405 Method Not Allowed',
            default => '403 Forbidden',
        };
        $message = match ($code) {
            404 => 'The requested page was not found.',
            405 => 'This action must be submitted from its form or button.',
            default => 'You do not have permission to access this page.',
        };
        if (isset($ui) && is_object($ui) && method_exists($ui, 'display') && ActorContext::current()->isStaff()) {
            try {
                $ui->assign('_title', $title);
                $ui->assign('deny_title', $title);
                $ui->assign('deny_message', $message);
                $ui->display('admin/403.tpl');
                exit;
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'deny-render');
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title></head><body><h1>'
            . $title . '</h1><p>' . $message . '</p></body></html>';
        exit;
    }

    private static function actionKey(string $handler, string $action, array $map): string
    {
        if ($handler === 'pages') {
            if ($action === '') {
                return '';
            }
            if (str_contains($action, '-reset')) {
                return 'page-reset';
            }
            if (str_contains($action, '-post')) {
                return 'page-post';
            }
            return 'page';
        }
        if ($action === '') {
            return (string) ($map[''] ?? '');
        }
        if (str_contains($action, '#')) {
            return ''; // variant keys are never addressable directly
        }
        if (array_key_exists($action, $map) && $action !== '' && $action !== '*') {
            return $action;
        }
        return (string) ($map['*'] ?? '');
    }
}
