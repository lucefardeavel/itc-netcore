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

    /**
     * UX-14: languages offered in the customer portal menu, each by its own (native) name. Presentation only: the other
     * shipped files stay on disk and the language switch keeps validating against them (PathGuard::language).
     */
    public const PORTAL_LANGUAGES = ['arabic' => 'العربية', 'english' => 'English'];

    /** Activation states by presentation tone (StatusBadge); every ActivationState has exactly one. */
    public const STATE_TONE = [
        'CREATED' => 'processing', 'VALIDATED' => 'processing', 'FUNDS_RESERVED' => 'processing', 'PROVISIONING' => 'processing',
        'PROVISIONED' => 'processing', 'VERIFYING' => 'processing', 'VERIFIED' => 'processing', 'FINANCIAL_COMMITTING' => 'processing',
        'RETRY_PENDING' => 'processing', 'COMPLETED' => 'success', 'FAILED' => 'danger', 'CANCELLED' => 'neutral', 'ROLLED_BACK' => 'neutral',
        'RECONCILIATION_REQUIRED' => 'warning', 'ROLLBACK_PENDING' => 'warning', 'REVERSAL_PENDING' => 'warning', 'REVERSED' => 'neutral',
    ];

    /** Plain-language label (English source key, translated by Lang::T) of each activation state. */
    public const STATE_LABEL = [
        'CREATED' => 'Request created', 'VALIDATED' => 'Validated', 'FUNDS_RESERVED' => 'Credit reserved', 'PROVISIONING' => 'Applying to the network',
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

    /** Native name of a language file (العربية / English); other names as before (ucwords). */
    public static function languageName(?string $language): string
    {
        return self::PORTAL_LANGUAGES[(string) $language] ?? ucwords((string) $language);
    }

    /** "IQD 12,500" / "IQD 12,500.50" from a server amount string; '' for null. Latin digits (OD-F). */
    /**
     * WP6C (owner amendment A): presentation-only action visibility for templates - true when the actor may open $route
     * under the accepted RouteMap rule (Navigation::allowed). Never authorization: the route is checked again server-side.
     */
    public static function allowed(string $route, string $method = 'GET'): bool
    {
        return Navigation::allowed(\Reseller\Security\ActorContext::current(), $route, $method);
    }

    /** WP6C: the shell data for a page rendered before the dispatcher assigned it (403 / 404). @return array<string,mixed> */
    public static function deniedShell(): array
    {
        return Navigation::deniedShell();
    }

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

    /** WP6B: plain-language labels of ledger entry types (the finance read model's ENTRY_TYPES). */
    public const LEDGER_TYPE = [
        'FUNDING' => 'Provider funding', 'WITHDRAWAL' => 'Provider withdrawal', 'ACTIVATION_DEBIT' => 'Activation (wholesale)',
        'COMMISSION_CREDIT' => 'Commission', 'TRANSFER_OUT' => 'Transfer sent', 'TRANSFER_IN' => 'Transfer received',
        'REFUND' => 'Refund', 'REVERSAL' => 'Reversal (compensating entry)', 'ADJUSTMENT' => 'Accounting adjustment',
    ];

    /** WP6B: plain-language labels of transfer operation types. */
    public const TRANSFER_TYPE = [
        'PROVIDER_FUNDING' => 'Provider funding', 'PROVIDER_WITHDRAWAL' => 'Provider withdrawal', 'ADJUSTMENT_CREDIT' => 'Credit adjustment',
        'ADJUSTMENT_DEBIT' => 'Debit adjustment', 'PARENT_CHILD_TRANSFER' => 'Transfer to child reseller',
    ];

    public static function ledgerType(?string $type): string
    {
        return self::LEDGER_TYPE[(string) $type] ?? (string) $type;
    }

    public static function transferType(?string $type): string
    {
        return self::TRANSFER_TYPE[(string) $type] ?? (string) $type;
    }

    /**
     * WP6B: display text of a party from the finance read model ({id, username, fullname, label}): the username when
     * it is inside the viewer's scope, otherwise the translated role label only (never an id or a name).
     *
     * @param array<string,mixed>|null $party
     */
    public static function party(?array $party): string
    {
        if ($party === null) {
            return '';
        }
        if (($party['username'] ?? null) !== null) {
            return (string) $party['username'];
        }
        return \Lang::T(['provider' => 'Provider (administrator)', 'upstream' => 'Upstream reseller', 'staff' => 'Staff'][(string) ($party['label'] ?? '')] ?? 'Not shown');
    }

    /**
     * WP6B: CSV cell text with spreadsheet formula injection neutralised (leading = + - @ tab CR).
     * WP9-D / N-07a: also when the formula character follows leading whitespace or invisible characters
     * (space, newline, no-break / zero-width spaces, BOM), which spreadsheets skip before evaluating.
     */
    public static function csvCell(mixed $v): string
    {
        $t = $v === null ? '' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
        if (preg_match('/^-?\d+(\.\d+)?$/D', $t)) {
            return $t;
        }
        return preg_match('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]*[=+\-@\t\r]/u', $t) === 1
            || preg_match('/^[=+\-@\t\r]/', $t) === 1 ? "'" . $t : $t;
    }

    /**
     * WP9-D / N-07a: one RFC 4180 CSV line (every field quoted, inner quotes doubled, formula neutralised
     * per csvCell) for the legacy exports that used to echo raw values between quotes.
     * @param array<int|string,mixed> $cells
     */
    public static function csvLine(array $cells, string $sep = ','): string
    {
        return implode($sep, array_map(static fn($c): string => '"' . str_replace('"', '""', self::csvCell($c)) . '"', array_values($cells))) . "\n";
    }

    public static function icon(?string $tone): string
    {
        return self::TONE_ICON[(string) $tone] ?? 'fa-circle';
    }

    public static function isNegative(mixed $amount): bool
    {
        return is_string($amount) && str_starts_with($amount, '-');
    }

    /** Plain-language label of the server-derived activation action. */
    public static function actionLabel(?string $action): string
    {
        return ['NEW' => 'New activation', 'RENEW' => 'Renewal', 'CHANGE_PLAN' => 'Plan change', 'REACTIVATE' => 'Reactivation'][(string) $action] ?? (string) $action;
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

    /**
     * UX-15: a day count in words for the interface language. Arabic: 1 "يوم واحد", 2 "يومان", 3-10 "N أيام", 11-99 "N يوماً"
     * (by the last two digits above 100: "100 يوم", "103 أيام", "365 يوماً"); otherwise "1 day" / "N days". Display only.
     */
    public static function days(int|string $n, ?string $language = null): string
    {
        $n = (int) $n;
        $language ??= (string) ($GLOBALS['config']['language'] ?? '');
        if ($language === 'arabic') {
            $r = abs($n) % 100;
            return match (true) {
                $n === 1 => 'يوم واحد',
                $n === 2 => 'يومان',
                $r >= 3 && $r <= 10 => $n . ' أيام',
                $r >= 11 => $n . ' يوماً',
                default => $n . ' يوم',
            };
        }
        $word = $n === 1 ? 'day' : 'days';
        return $n . ' ' . ($language !== 'english' && class_exists('Lang', false) ? \Lang::T($word) : $word);
    }

    /**
     * UX-15 (display only): whole days of paid service lost when a CHANGE_PLAN starts at $start while the current period
     * runs until $currentExpiry (a started day counts), or null when nothing is lost. Both values come from the server quote;
     * the result is never sent back and the server never reads it (the policy "CHANGE_PLAN starts now, no proration" is
     * unchanged: docs/WP5A_ACTIVATION_MODEL.md).
     */
    public static function lostDays(?string $currentExpiry, ?string $start): ?int
    {
        $f = static fn(?string $v): ?\DateTimeImmutable => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/D', $v)
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', strlen($v) === 10 ? $v . ' 23:59:59' : (strlen($v) === 16 ? $v . ':00' : $v)) ?: null) : null;
        $until = $f($currentExpiry);
        $from = $f($start);
        if ($until === null || $from === null || $until <= $from) {
            return null;
        }
        return (int) ceil(($until->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    /**
     * UX-15: the plan-change warning ("N days of paid service (until Y-m-d) will be lost ..."), plain text: the caller escapes
     * it. Only the date part of $until is shown (the full instant is on the "Current expiry" line; a lone date stays in order
     * inside right-to-left text).
     */
    public static function lostDaysNotice(int $days, string $until, ?string $language = null): string
    {
        $text = class_exists('Lang', false)
            ? \Lang::T('[days] of paid service (until [date]) will be lost: the current period is replaced now and the remaining time is not refunded or carried over.')
            : '[days] of paid service (until [date]) will be lost: the current period is replaced now and the remaining time is not refunded or carried over.';
        return strtr($text, ['[days]' => self::days($days, $language), '[date]' => substr($until, 0, 10)]);
    }

    /**
     * D8 (owner V1_OWNER_DECISIONS_APPROVED): a voucher code is a credential. Display surfaces that do not need the full code
     * (reports, receipts, print views, the customer's activation list) show "Voucher - AB****YZ" for the payment method
     * "Voucher - <code>"; the stored value is never changed (audit and financial records keep the original) and exports keep it.
     */
    public static function method(mixed $method): string
    {
        $m = (string) $method;
        return preg_match('/^(Voucher - )(.+)$/Ds', $m, $x) === 1 ? $x[1] . self::maskCode($x[2]) : $m;
    }

    /** the holder name of a recharge: a voucher-only recharge is held by its CODE (customer 0, username = code) - masked like {@see method()} */
    public static function holder(mixed $username, mixed $method): string
    {
        $u = (string) $username;
        return $u !== '' && (string) $method === 'Voucher - ' . $u ? self::maskCode($u) : $u;
    }

    /** first two and last two characters, never the length of the rest */
    public static function maskCode(string $code): string
    {
        $n = mb_strlen($code);
        return $n <= 4 ? '****' : mb_substr($code, 0, 2) . '****' . mb_substr($code, -2);
    }

    /**
     * "1.2 GB" style size for octet counters (display only). Decimal units (1 GB = 1,000,000,000 bytes), the same units a
     * plan's data quota is defined in (QuotaPolicy::bytes), so a 50 GB plan reads "50 GB" and never "46.6 GB".
     */
    public static function bytes(int $n): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $n;
        while ($v >= 1000 && $i < count($u) - 1) {
            $v /= 1000;
            $i++;
        }
        if ($i === 0) {
            return $n . ' ' . $u[0];
        }
        $s = number_format($v, 1, '.', '');
        return (str_ends_with($s, '.0') ? substr($s, 0, -2) : $s) . ' ' . $u[$i];
    }

    /** "2h 05m" style duration (display only). */
    public static function duration(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        return ($d > 0 ? $d . 'd ' : '') . $h . 'h ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'm';
    }

    /** The safe, translatable message of a domain reason code, or null (raw provisioning text is never shown). */
    public static function errorText(?string $code): ?string
    {
        return \Reseller\Domain\DomainError::MESSAGES[(string) $code] ?? null;
    }

    /**
     * Activation progress from the PERSISTED event timeline (never simulated): Request created, Credit reserved,
     * Provisioning, Verifying, Completed; each step done / current / failed / todo.
     *
     * @param list<array<string,mixed>> $events
     * @return list<array{label:string,state:string}>
     */
    public static function progress(array $events, string $status): array
    {
        $reached = array_flip(array_map(static fn(array $e): string => (string) $e['to_state'], $events));
        $steps = [
            ['Request created', ['CREATED', 'VALIDATED']],
            ['Credit reserved', ['FUNDS_RESERVED']],
            ['Provisioning', ['PROVISIONING', 'PROVISIONED', 'RETRY_PENDING']],
            ['Verifying', ['VERIFYING', 'VERIFIED']],
            ['Completed', ['FINANCIAL_COMMITTING', 'COMPLETED']],
        ];
        $last = -1;
        foreach ($steps as $i => [, $states]) {
            foreach ($states as $st) {
                if (isset($reached[$st])) {
                    $last = $i;
                }
            }
        }
        $done = $status === 'COMPLETED' || isset($reached['COMPLETED']);
        $stopped = in_array($status, ['FAILED', 'CANCELLED', 'RECONCILIATION_REQUIRED', 'ROLLBACK_PENDING', 'ROLLED_BACK'], true);
        $out = [];
        foreach ($steps as $i => [$label]) {
            $state = $done || $i < $last ? 'done' : ($i === $last ? ($stopped ? 'failed' : 'current') : 'todo');
            $out[] = ['label' => $label, 'state' => $state];
        }
        return $out;
    }
}
