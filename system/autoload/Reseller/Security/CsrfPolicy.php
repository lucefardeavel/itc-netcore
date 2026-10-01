<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * OD-8 / SEC-09: central CSRF enforcement for every browser request, independent of the legacy
 * csrf_enabled setting.
 *
 *  - one stable token per session (also for anonymous visitors: index.php starts a session for
 *    everyone, so pre-auth login/register/forgot forms carry a token); rotated on login/logout
 *  - every non-API POST must present it (body field csrf_token or header X-CSRF-Token)
 *  - GET/HEAD to a mutating route is refused by RouteGuard (405) before any side effect
 *  - exempt: system/api.php (bearer token, cookies discarded) and callback/* (server-to-server)
 */
final class CsrfPolicy
{
    private const KEY = 'nuxr_csrf';
    public const EXEMPT_HANDLERS = ['callback'];
    private const PUBLIC_FORMS = ['login', 'admin', 'register', 'forgot'];

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY]) || strlen($_SESSION[self::KEY]) !== 64) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function rotate(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::KEY]);
        }
        return self::token();
    }

    public static function check(?string $token): bool
    {
        if ($token === null || $token === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $stored = $_SESSION[self::KEY] ?? null;
        return is_string($stored) && strlen($stored) === 64 && hash_equals($stored, $token);
    }

    public static function requestToken(): ?string
    {
        $t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        return is_string($t) ? $t : null;
    }

    /** Web dispatcher hook: refuses a POST without a valid token before any controller code runs. */
    public static function enforceWeb(string $handler, string $method): void
    {
        if (strtoupper($method) !== 'POST' || in_array($handler, self::EXEMPT_HANDLERS, true)) {
            return;
        }
        if (self::check(self::requestToken())) {
            return;
        }
        ErrorReporter::log('csrf.rejected', 'handler=' . $handler);
        if (in_array($handler, self::PUBLIC_FORMS, true)) {
            $_SESSION['ntype'] = 'e';
            $_SESSION['notify'] = 'Invalid or expired security token. Please try again.';
            header('Location: ' . getUrl($handler === 'admin' ? 'admin' : $handler));
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>403 Forbidden</title></head><body>'
            . '<h1>403 Forbidden</h1><p>Invalid or expired security token. Reload the page and try again.</p></body></html>';
        exit;
    }
}
