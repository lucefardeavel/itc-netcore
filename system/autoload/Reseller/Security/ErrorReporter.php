<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Owner decision OD-14 (error handling): no role — SuperAdmin included — receives raw stack traces,
 * SQL errors, filesystem paths or secrets in a web/API response. Callers get a generic message plus a
 * reference id; the detailed, sanitized record stays in the server log. Telegram (if configured)
 * receives only a sanitized one-line summary with the reference id.
 */
final class ErrorReporter
{
    private static ?string $requestId = null;

    /** Request correlation id (32 hex chars), shared by audit records and error references. */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(16));
        }
        return self::$requestId;
    }

    /** Logs a sanitized record server-side, notifies Telegram with a summary only; returns the reference. */
    public static function report(\Throwable $e, string $context = ''): string
    {
        $ref = substr(self::requestId(), 0, 12) . '-' . bin2hex(random_bytes(3));
        $route = self::route();
        $line = sprintf(
            '[nuxr] ref=%s class=%s route=%s context=%s message=%s at %s:%d trace=%s',
            $ref,
            get_class($e),
            $route,
            $context,
            self::sanitize($e->getMessage()),
            basename($e->getFile()),
            $e->getLine(),
            self::sanitize(str_replace("\n", ' | ', $e->getTraceAsString()))
        );
        @error_log($line);
        self::notify($ref, get_class($e), $route);
        return $ref;
    }

    /** Server-side only log line for non-exception security events (sanitized). */
    public static function log(string $event, string $detail = ''): void
    {
        @error_log('[nuxr] event=' . $event . ' request=' . self::requestId() . ' ' . self::sanitize($detail));
    }

    /**
     * Web-reachable CLI scripts (cron): under a non-CLI SAPI the detailed run output (usernames,
     * notification text, error messages) is kept out of the HTTP response and logged sanitized.
     */
    public static function suppressWebOutput(string $label): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        ob_start();
        register_shutdown_function(static function () use ($label): void {
            $out = (string) ob_get_clean();
            $ref = substr(self::requestId(), 0, 12);
            @error_log('[nuxr] event=' . $label . '.web_run ref=' . $ref . ' output=' . self::sanitize(str_replace("\n", ' | ', substr($out, 0, 4000))));
            echo '<pre>' . htmlspecialchars($label) . ' finished; details are in the server log (ref ' . $ref . ').</pre>';
        });
    }

    public static function publicMessage(string $ref): string
    {
        return 'An internal error occurred. Please contact the administrator and quote reference ' . $ref . '.';
    }

    /** Sanitized summary for an operator channel (Telegram): never message text, SQL, paths or traces. */
    public static function notify(string $ref, string $class, string $route): void
    {
        if (!class_exists('Message', true) || !method_exists('Message', 'sendTelegram')) {
            return;
        }
        try {
            \Message::sendTelegram('System error ref=' . $ref . ' class=' . preg_replace('/[^A-Za-z0-9_\\\\]/', '', $class) . ' route=' . $route);
        } catch (\Throwable) {
            // notification failure must never surface
        }
    }

    /** Removes known secret values and credential-like fragments from free text. */
    public static function sanitize(string $text): string
    {
        foreach (self::secretValues() as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }
        // DSN / credential fragments that drivers sometimes include
        $text = preg_replace("/(password|passwd|pwd|secret|token|api_key)(\\s*[=:]\\s*)('[^']*'|\"[^\"]*\"|\\S+)/i", '$1$2[redacted]', $text) ?? $text;
        return $text;
    }

    /** @return list<string> known secret values of this installation (length >= 4) */
    private static function secretValues(): array
    {
        $out = [];
        // WP9-D / NEW-7 + N-11: every configured secret of this installation, incl. the application key and the
        // FreeRADIUS rlm_rest secret
        foreach (['db_pass', 'api_secret', 'radius_pass', 'app_secret', 'radius_rest_secret'] as $g) {
            if (isset($GLOBALS[$g]) && is_string($GLOBALS[$g]) && strlen($GLOBALS[$g]) >= 4) {
                $out[] = $GLOBALS[$g];
            }
        }
        $config = $GLOBALS['config'] ?? [];
        if (is_array($config)) {
            foreach ($config as $k => $v) {
                if (is_string($v) && strlen($v) >= 8 && preg_match('/pass|secret|token|key/i', (string) $k)) {
                    $out[] = $v;
                }
            }
        }
        usort($out, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return array_values(array_unique($out));
    }

    private static function route(): string
    {
        $r = $GLOBALS['routes'] ?? null;
        if (is_array($r)) {
            return preg_replace('/[^A-Za-z0-9_\/-]/', '', implode('/', array_slice($r, 0, 2))) ?: '-';
        }
        return PHP_SAPI === 'cli' ? 'cli' : '-';
    }
}
