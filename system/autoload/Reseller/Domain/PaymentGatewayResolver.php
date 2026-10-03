<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * The single payment-gateway resolution (WP5B §7; SEC-13) for order/buy, order/view/check and callback.php.
 * A gateway name is usable only when it matches ^[A-Za-z0-9_]{1,64}$, is configured in
 * config['payment_gateway'] and its file resolves (realpath) inside the payment-gateway directory. The
 * customer-balance pseudo gateway 'Balance' is never resolvable. Returns the file to include, or null.
 */
final class PaymentGatewayResolver
{
    public const BALANCE = 'Balance';

    public static function resolve(mixed $name, ?array $config = null, ?string $dir = null): ?string
    {
        global $PAYMENTGATEWAY_PATH;
        $config ??= $GLOBALS['config'] ?? [];
        $dir ??= (string) $PAYMENTGATEWAY_PATH;
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) || strcasecmp($name, self::BALANCE) === 0) {
            return null;
        }
        $configured = array_values(array_filter(array_map('trim', explode(',', (string) ($config['payment_gateway'] ?? '')))));
        if (!in_array($name, $configured, true)) {
            return null;
        }
        $base = realpath($dir);
        $file = realpath($dir . DIRECTORY_SEPARATOR . $name . '.php');
        if ($base === false || $file === false || !is_file($file) || dirname($file) !== $base) {
            return null;
        }
        return $file;
    }
}
