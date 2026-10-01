<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * SEC-21: API authentication and response hygiene.
 *  - system/api.php discards cookies and session before anything else, so a browser identity can never
 *    ride on an API call (closes CSRF-through-the-API)
 *  - identity comes only from an a2 staff token (ApiToken); the global api_key and the legacy a./c./u.
 *    formats are refused
 *  - the token carries exactly the user's own capabilities and scope (same RouteGuard / DataScope),
 *    restricted further by RouteMap::API_ALLOWED
 *  - responses pass a recursive sanitizer: no password hashes, login tokens, secrets or config dumps
 */
final class ApiGuard
{
    private const SENSITIVE_KEY = '/pass|secret|login_token|salt|api_key|csrf|otp|signature|cookie/i';
    private const DROP_KEYS = ['_c', '_admin_raw', 'config'];

    /** @return ActorContext the API principal (anonymous when no token was presented) */
    public static function authenticate(mixed $token): ActorContext
    {
        $GLOBALS['nuxr_api_staff_id'] = 0;
        if (ApiToken::secret() === null) {
            ErrorReporter::log('api.disabled', 'no dedicated api_secret configured');
            http_response_code(503);
            showResult(false, 'API disabled: no dedicated signing secret is configured');
        }
        if (!is_string($token) || $token === '') {
            return ActorContext::anonymous('API');
        }
        $row = ApiToken::verify($token);
        if ($row === null) {
            ErrorReporter::log('api.token_rejected', 'format=' . substr($token, 0, 2));
            http_response_code(401);
            showResult(false, 'Token is invalid', [], ['login' => true]);
        }
        $GLOBALS['nuxr_api_staff_id'] = (int) $row['id'];
        return ActorContext::forStaff($row, 'API');
    }

    /** Bearer token from Authorization header or the legacy token parameter. */
    public static function presentedToken(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (is_string($h) && preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m)) {
            return $m[1];
        }
        $t = $_REQUEST['token'] ?? null;
        return is_string($t) ? trim($t) : null;
    }

    /** Recursive response sanitizer. */
    public static function sanitize(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 12) {
            return null;
        }
        if ($data instanceof \ORM) {
            $data = $data->as_array();
        } elseif ($data instanceof \IdiormResultSet) {
            $rows = [];
            foreach ($data as $r) {
                $rows[] = $r instanceof \ORM ? $r->as_array() : $r;
            }
            $data = $rows;
        } elseif (is_object($data)) {
            return null;
        }
        if (!is_array($data)) {
            return is_string($data) ? ErrorReporter::sanitize($data) : $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                if (in_array($k, self::DROP_KEYS, true) || preg_match(self::SENSITIVE_KEY, $k)) {
                    continue;
                }
                if ($k === '_admin' || $k === '_user') {
                    $v = self::identity($v);
                }
            }
            $out[$k] = self::sanitize($v, $depth + 1);
        }
        return $out;
    }

    private static function identity(mixed $v): ?array
    {
        if ($v instanceof \ORM) {
            $v = $v->as_array();
        }
        if (!is_array($v)) {
            return null;
        }
        return array_intersect_key($v, array_flip(['id', 'username', 'fullname', 'user_type', 'status']));
    }
}
