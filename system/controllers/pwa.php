<?php
// Controller: only reachable through the dispatcher (index.php); never API-allowed (WP7 owner decision OD-W7-2).
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP7 — public, read-only staff PWA support (owner decision OD-W7-2). Exactly two actions, both GET and public
 * (RouteMap), no capability, no business data, no mutation, not in RouteMap::API_ALLOWED:
 *
 *   manifest  GET  the web app manifest, built from public configuration only (identical for every visitor)
 *   ping      GET  the connectivity heartbeat: a fixed marker, Cache-Control: no-store
 *
 * Any other pwa/* action is a 404 with no body. The browser fetches the manifest without credentials and the heartbeat
 * client sends no cookies (credentials: 'omit'), so neither extends a staff session; the throwaway anonymous session
 * that index.php starts for such a request is destroyed here so neither leaves session files. A session identified by
 * a cookie is never touched.
 */

$action = $routes['1'] ?? '';

// Both actions are fetched without credentials. index.php has already started a session for such a request: when no
// session cookie identified the request, that throwaway anonymous session is discarded (file and Set-Cookie header).
// A session identified by a cookie is never touched.
$nuxrDisposeAnonymousSession = static function (): void {
    if (!isset($_COOKIE[session_name()]) && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
        header_remove('Set-Cookie');
    }
};

switch ($action) {
    case 'manifest':
        $nuxrDisposeAnonymousSession();
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(\Reseller\Ui\Pwa::manifest($config, \Reseller\Ui\Pwa::appPath(APP_URL)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    case 'ping':
        $nuxrDisposeAnonymousSession();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(\Reseller\Ui\Pwa::PING);
        break;

    case 'notfound':
    default:
        http_response_code(404);
        header('Cache-Control: no-store');
        break;
}
