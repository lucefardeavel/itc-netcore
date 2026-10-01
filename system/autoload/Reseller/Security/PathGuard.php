<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Canonicalization and allowlisting for client-influenced file names (OD-14: NEW-1 language files,
 * NEW-4 page and voucher-template names). Every accepted path must resolve inside its base directory.
 */
final class PathGuard
{
    /** Built-in editable pages (admin header menu + Message.php notification pages). */
    public const PAGES = [
        'Order_Voucher', 'Voucher', 'Announcement', 'Announcement_Customer', 'Registration_Info',
        'Payment_Info', 'Privacy_Policy', 'Terms_and_Conditions', 'Email',
    ];

    /** Returns the language name if it is a shipped language file, otherwise null. */
    public static function language(mixed $name, string $rootPath): ?string
    {
        if (!is_string($name) || !preg_match('/^[a-z][a-z_]{1,31}$/', $name) || $name === 'country') {
            return null;
        }
        $dir = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'lan';
        $file = $dir . DIRECTORY_SEPARATOR . $name . '.json';
        return self::inside($file, $dir, true) ? $name : null;
    }

    /**
     * Page name for pages/<name>.html. Allowed: the built-in allowlist, or (when $allowExisting) an
     * already existing page file with a safe name (plugin-registered pages).
     */
    public static function pageName(mixed $name, string $pagesPath, bool $allowExisting = true): ?string
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            return null;
        }
        if (in_array($name, self::PAGES, true)) {
            return $name;
        }
        if ($allowExisting) {
            $file = rtrim($pagesPath, '/\\') . DIRECTORY_SEPARATOR . $name . '.html';
            if (self::inside($file, $pagesPath, true)) {
                return $name;
            }
        }
        return null;
    }

    /** Voucher template name for pages/vouchers/<name>.html (spaces allowed, no separators/dots). */
    public static function voucherTemplateName(mixed $name): ?string
    {
        if (!is_string($name) || str_contains($name, "\0")) {
            return null;
        }
        $name = trim($name);
        return preg_match('/^[A-Za-z0-9 _-]{1,64}$/', $name) ? $name : null;
    }

    /** True if $path (existing or to be created) canonically resolves inside $dir. */
    public static function inside(string $path, string $dir, bool $mustExist = false): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            return false;
        }
        $real = realpath($path);
        if ($real === false) {
            if ($mustExist) {
                return false;
            }
            $parent = realpath(dirname($path));
            if ($parent === false) {
                return false;
            }
            $real = $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        return str_starts_with($real, $realDir . DIRECTORY_SEPARATOR);
    }
}
