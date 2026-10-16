<?php
namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ErrorReporter;
use Reseller\Security\PathGuard;

/**
 * WP9-D / NEW-8 (owner decision O8-A): editable page templates (pages/*.html, written by
 * pages/<name>-post, SYSTEM_CODE) are rendered by a SEPARATE Smarty instance with a security policy,
 * never compiled by the application's own unrestricted Smarty instance.
 *
 * The sandbox allows only: Lang / Text static methods from an allowlist, the default safe PHP
 * functions and modifiers, plain control-flow tags. It refuses {include}, {fetch}, {eval}, {insert},
 * {extends}, {php}, {function}, {config_load}, constants, super globals, streams and $smarty special
 * variables. Only allowlisted, non-secret variables are assigned (no $_c configuration array; a
 * reduced copy of public company settings only). A template the policy refuses renders as an empty
 * string and is reported through the error reporter (generic reference, no template text).
 */
final class PageTemplate
{
    /** public, non-secret configuration keys a page may read as {$_c.key} */
    public const PUBLIC_CONFIG = ['CompanyName', 'CompanyFooter', 'address', 'phone', 'note', 'currency_code', 'country_code_phone', 'language', 'timezone'];

    public static function policy(\Smarty $smarty): \Smarty_Security
    {
        $p = new \Smarty_Security($smarty);
        $p->secure_dir = [];
        $p->trusted_dir = [];
        $p->trusted_uri = [];
        $p->trusted_constants = [];
        $p->static_classes = ['Lang', 'Text'];
        $p->trusted_static_methods = [
            'Lang' => ['T', 'moneyFormat', 'dateFormat', 'dateTimeFormat', 'dateAndTimeFormat'],
            'Text' => ['url'],
        ];
        $p->trusted_static_properties = null;
        $p->php_functions = ['isset', 'empty', 'count', 'in_array', 'is_array', 'time'];
        $p->php_modifiers = ['escape', 'count', 'nl2br'];
        $p->allowed_tags = ['if', 'elseif', 'else', 'foreach', 'foreachelse', 'section', 'sectionelse', 'for', 'while', 'capture', 'strip', 'literal', 'ldelim', 'rdelim'];
        $p->disabled_tags = ['include', 'include_php', 'php', 'fetch', 'eval', 'insert', 'extends', 'block', 'function', 'call', 'config_load', 'setfilter', 'make_nocache', 'nocache'];
        $p->allowed_modifiers = ['escape', 'nl2br', 'upper', 'lower', 'truncate', 'date_format', 'default', 'string_format', 'replace', 'strip_tags', 'capitalize', 'cat', 'count'];
        $p->disabled_special_smarty_vars = ['template_object', 'template', 'current_dir', 'config', 'server', 'env', 'cookies', 'get', 'post', 'request', 'session', 'const', 'capture', 'section', 'foreach', 'block', 'ldelim', 'rdelim', 'now', 'version'];
        $p->streams = null;
        $p->allow_constants = false;
        $p->allow_super_globals = false;
        $p->max_template_nesting = 1;
        return $p;
    }

    /** Rendered HTML of the page template $name (an allowlisted page name), or '' */
    public static function render(string $name): string
    {
        $pagesPath = (string) ($GLOBALS['PAGES_PATH'] ?? '');
        if ($pagesPath === '' || !in_array($name, PathGuard::PAGES, true)) {
            return '';
        }
        $file = $pagesPath . DIRECTORY_SEPARATOR . $name . '.html';
        if (!is_file($file) || !PathGuard::inside($file, $pagesPath)) {
            return '';
        }
        return self::renderString((string) file_get_contents($file));
    }

    /** Renders template text in the sandbox (also used by tests). */
    public static function renderString(string $source): string
    {
        try {
            $sb = new \Smarty();
            $base = (string) ($GLOBALS['UI_PATH'] ?? sys_get_temp_dir());
            $compile = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'nuxr_pages' . DIRECTORY_SEPARATOR;
            if (!is_dir($compile)) {
                @mkdir($compile, 0755, true);
            }
            $sb->setCompileDir($compile);
            $sb->setCacheDir($compile);
            $sb->setTemplateDir([$compile]);
            $sb->caching = \Smarty::CACHING_OFF;
            $sb->enableSecurity(self::policy($sb));
            $config = is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [];
            $sb->assign('_c', array_intersect_key($config, array_flip(self::PUBLIC_CONFIG)));
            $sb->assign('app_url', defined('APP_URL') ? APP_URL : '');
            $sb->assign('_url', (defined('APP_URL') ? APP_URL : '') . '/?_route=');
            $sb->assign('_theme', (string) ($GLOBALS['_theme'] ?? ''));
            return $sb->fetch('string:' . $source);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'page-template');
            return '';
        }
    }

    /** Rendered HTML of a voucher design file (pages/vouchers/<file>, admin preview), or '' */
    public static function renderVoucherDesign(string $file): string
    {
        $dir = (string) ($GLOBALS['PAGES_PATH'] ?? '') . DIRECTORY_SEPARATOR . 'vouchers';
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if ($file === '' || str_contains($file, '/') || str_contains($file, '\\') || !is_file($path) || !PathGuard::inside($path, $dir)) {
            return '';
        }
        return self::renderString((string) file_get_contents($path));
    }

    /** Smarty function plugin {nuxr_page name="Announcement"} / {nuxr_page voucher=$file} for the application templates. */
    public static function plugin(array $params): string
    {
        if (isset($params['voucher'])) {
            return self::renderVoucherDesign((string) $params['voucher']);
        }
        return self::render((string) ($params['name'] ?? ''));
    }
}
