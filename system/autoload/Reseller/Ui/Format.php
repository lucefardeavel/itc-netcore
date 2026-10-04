<?php
namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP6A presentation helpers (OD-F): document direction and language, and display formatting of server values.
 * Formatting only: amounts arrive as server strings ("d.dd") and are never computed here.
 */
final class Format
{
    /** Languages written right-to-left (system/lan/<name>.json). */
    public const RTL = ['arabic'];

    /** Activation states by presentation tone (StatusBadge); every ActivationState has exactly one. */
    public const STATE_TONE = [
        'CREATED' => 'processing', 'VALIDATED' => 'processing', 'FUNDS_RESERVED' => 'processing', 'PROVISIONING' => 'processing',
        'PROVISIONED' => 'processing', 'VERIFYING' => 'processing', 'VERIFIED' => 'processing', 'FINANCIAL_COMMITTING' => 'processing',
        'RETRY_PENDING' => 'processing', 'COMPLETED' => 'success', 'FAILED' => 'danger', 'CANCELLED' => 'neutral', 'ROLLED_BACK' => 'neutral',
        'RECONCILIATION_REQUIRED' => 'warning', 'ROLLBACK_PENDING' => 'warning', 'REVERSAL_PENDING' => 'warning', 'REVERSED' => 'neutral',
    ];

    /** Plain-language label (English source key, translated by Lang::T) of each activation state. */
    public const STATE_LABEL = [
        'CREATED' => 'Request created', 'VALIDATED' => 'Request created', 'FUNDS_RESERVED' => 'Credit reserved', 'PROVISIONING' => 'Applying to the network',
        'PROVISIONED' => 'Applied to the network', 'VERIFYING' => 'Verifying', 'VERIFIED' => 'Verified', 'FINANCIAL_COMMITTING' => 'Settling',
        'RETRY_PENDING' => 'Retrying', 'COMPLETED' => 'Completed', 'FAILED' => 'Failed', 'CANCELLED' => 'Cancelled', 'ROLLED_BACK' => 'Rolled back',
        'RECONCILIATION_REQUIRED' => 'Checking - reconciliation required', 'ROLLBACK_PENDING' => 'Rollback pending', 'REVERSAL_PENDING' => 'Reversal pending',
        'REVERSED' => 'Reversed',
    ];

    public const TONE_ICON = ['processing' => 'fa-spinner', 'success' => 'fa-check-circle', 'danger' => 'fa-times-circle', 'warning' => 'fa-exclamation-triangle', 'neutral' => 'fa-minus-circle', 'info' => 'fa-info-circle'];

    public static function dir(?string $language): string
    {
        return in_array((string) $language, self::RTL, true) ? 'rtl' : 'ltr';
    }

    /** ISO 639-1 code of a shipped language (system/lan/country.json), 'en' when unknown. */
    public static function iso(?string $language): string
    {
        $iso = \Lang::getIsoLang()[(string) $language] ?? null;
        return is_string($iso) && preg_match('/^[a-z]{2,3}$/', $iso) ? $iso : 'en';
    }

    /** "IQD 12,500" / "IQD 12,500.50" from a server amount string; '' for null. Latin digits (OD-F). */
    public static function money(mixed $amount): string
    {
        global $config;
        if (!is_string($amount) || !preg_match('/^(-?)(\d+)\.(\d{2})$/D', $amount, $m)) {
            return '';
        }
        $sep = (string) ($config['thousands_sep'] ?? ',');
        $dec = (string) ($config['dec_point'] ?? '.');
        $int = strrev(implode($sep === '' ? ',' : $sep, str_split(strrev($m[2]), 3)));
        $text = $m[1] . $int . ($m[3] !== '00' ? $dec . $m[3] : '');
        $code = trim((string) ($config['currency_code'] ?? ''));
        return $code !== '' ? $code . ' ' . $text : $text;
    }

    public static function tone(?string $state): string
    {
        return self::STATE_TONE[(string) $state] ?? 'neutral';
    }

    public static function stateLabel(?string $state): string
    {
        return self::STATE_LABEL[(string) $state] ?? (string) $state;
    }

    /** Days from today to a Y-m-d date (negative when past), or null. */
    public static function daysUntil(?string $date): ?int
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        return $d === false ? null : (int) (new \DateTimeImmutable('today'))->diff($d)->format('%r%a');
    }
}
