<?php
namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP7 staff PWA presentation data (owner decisions OD-W7-1 / OD-W7-2, plan amendments B / C / O). Pure builders only:
 * no I/O, no session, no actor, no business data. The manifest is built from public configuration alone, so it is the
 * same for every visitor (the browser fetches it without credentials). Identity assets are replaceable presentation
 * files under ui/ui/images/pwa/ (interim icons, OD-W7-1): replacing them never touches the service worker, routing,
 * cache safety, permissions or business services.
 */
final class Pwa
{
    /** Single PWA asset version: equals the service worker VERSION and the nuxr-pwa.js asset query (guard-tested). */
    public const VERSION = 'wp7.1';

    public const THEME_COLOR = '#ffffff';       // the WP6C top bar
    public const BACKGROUND_COLOR = '#f3f5fa';  // --nx-bg

    /** Interim identity icons (OD-W7-1), relative to the application root. */
    public const ICONS = [
        ['src' => 'ui/ui/images/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'ui/ui/images/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'ui/ui/images/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];

    public const APPLE_TOUCH_ICON = 'ui/ui/images/pwa/icon-180.png';

    /** Body of pwa/ping: a fixed marker, nothing about the visitor, the server or the business. */
    public const PING = ['app' => 'nuxr', 'ok' => true];

    /**
     * The web app manifest for the staff application.
     *
     * @param array<string,mixed> $config application configuration (only CompanyName and language are read)
     * @param string $appPath path of the installation root, e.g. '' or '/billing' (from APP_URL)
     * @return array<string,mixed>
     */
    public static function manifest(array $config, string $appPath): array
    {
        $name = trim((string) ($config['CompanyName'] ?? ''));
        if ($name === '') {
            $name = 'PHPNuxBill';
        }
        $short = mb_strlen($name) > 12 ? rtrim(mb_substr($name, 0, 12)) : $name;
        $path = rtrim($appPath, '/');
        return [
            // stable per installation, never per user (the start route of this installation)
            'id' => $path . '/?_route=admin',
            'name' => $name,
            'short_name' => $short,
            'lang' => Format::iso($config['language'] ?? null),
            'dir' => Format::dir($config['language'] ?? null),
            // the existing staff login: unauthenticated -> staff login, authenticated staff -> dashboard (admin.php)
            'start_url' => './?_route=admin',
            'scope' => './',
            'display' => 'standalone',
            'theme_color' => self::THEME_COLOR,
            'background_color' => self::BACKGROUND_COLOR,
            'icons' => self::ICONS,
        ];
    }

    /** Path component of the installation root ('' for a root installation). */
    public static function appPath(string $appUrl): string
    {
        $p = (string) parse_url($appUrl, PHP_URL_PATH);
        return rtrim($p, '/');
    }
}
