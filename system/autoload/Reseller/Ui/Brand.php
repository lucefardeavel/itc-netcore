<?php
namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Product identity (owner decision 2026-10-18: PRODUCT_NAME_APPROVED = ITC NetCore, BRAND = ITC). Presentation only:
 * the single source of the visible product name, tagline and version. Internal identifiers (database, tables, routes,
 * APIs, RADIUS dictionary / site names, file paths, cache namespaces) keep their names and never read this class.
 *
 * Two identities are kept apart:
 *  - the product (ITC NetCore, developed and maintained by ITC), shown in the footer, the About page, the sign-in page
 *    and the installer;
 *  - the operator (the ISP's own CompanyName setting), shown in titles, the shell and on invoices. When the operator has
 *    not configured a name (empty, or the stock PHPNuxBill placeholder of install/phpnuxbill.sql) the product name is shown.
 *
 * The upstream project (PHPNuxBill by iBNuX and contributors, GNU GPL version 2) stays credited wherever the product is
 * described; its licence and notices are not changed here (LIC-1 is open with counsel).
 */
final class Brand
{
    public const NAME = 'ITC NetCore';
    public const BRAND = 'ITC';
    /** The product word of the name (NAME = BRAND . ' ' . PRODUCT; guard-tested). */
    public const PRODUCT = 'NetCore';
    public const TAGLINE = 'ISP Management & Billing Platform';
    public const VERSION = 'V1.0';
    /** Developed and maintained by. */
    public const VENDOR = 'ITC';
    /** Interim text mark (no final logo is decided). */
    public const MARK = 'ITC';

    public const UPSTREAM = 'PHPNuxBill';
    public const UPSTREAM_AUTHOR = 'iBNuX';
    public const UPSTREAM_URL = 'https://github.com/hotspotbilling/phpnuxbill';
    /** As stated by LICENSE (no version statement exists; counsel question Q1). */
    public const LICENSE = 'GNU GPL v2';

    /** CompanyName values that mean "not configured": empty, or the stock default seeded by install/phpnuxbill.sql. */
    public const PLACEHOLDER_COMPANY = ['', 'PHPNuxBill'];

    /** The operator name to show: the configured CompanyName, or the product name when it is not configured. */
    public static function company(array $config): string
    {
        $name = trim((string) ($config['CompanyName'] ?? ''));
        return in_array($name, self::PLACEHOLDER_COMPANY, true) ? self::NAME : $name;
    }

    /**
     * Template data ($_brand).
     *
     * @param array<string,mixed> $config application configuration (only CompanyName is read)
     * @return array<string,mixed>
     */
    public static function view(array $config): array
    {
        $company = self::company($config);
        $isProduct = $company === self::NAME;
        return [
            'name' => self::NAME,
            'brand' => self::BRAND,
            'product' => self::PRODUCT,
            'tagline' => self::TAGLINE,
            'version' => self::VERSION,
            'vendor' => self::VENDOR,
            'company' => $company,
            // the operator's own initial, or the interim product text mark
            'mark' => $isProduct ? self::MARK : mb_substr($company, 0, 1),
            'company_is_product' => $isProduct,
            'upstream' => self::UPSTREAM,
            'upstream_author' => self::UPSTREAM_AUTHOR,
            'upstream_url' => self::UPSTREAM_URL,
            'license' => self::LICENSE,
        ];
    }
}
