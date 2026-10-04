<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ActorContext;

/**
 * WP6B (owner decisions OD-1 / OD-2): informational before / after figures for the review step of a wallet operation.
 * Read only and never authority: no lock, no write; the accepted services (TransferService, WalletService) re-check
 * everything atomically when the operator confirms. The arithmetic lives here, inside Reseller\Finance, so the web
 * gateway never calculates money. "hint" names the refusal the service would most likely return with the current
 * figures (presentation only).
 *
 * Every wallet is read through WalletService::view (wallet.read scope; NOT_FOUND outside it). Amounts are parsed with
 * the accepted Money grammar (INVALID_AMOUNT).
 */
final class WalletPreview
{
    /** @return array<string,mixed> */
    public static function fund(ActorContext $a, mixed $managerId, mixed $amount): array
    {
        $w = WalletService::view($a, $managerId);
        $m = Money::parsePositive($amount);
        $after = $w->balance->plus($m);
        return [
            'amount' => $m->format(), 'opens_wallet' => !$w->exists,
            'balance' => [$w->balance->format(), $after->format()],
            'available' => [$w->available()->format(), $w->available()->plus($m)->format()],
            'hint' => $after->isStorable() ? null : 'STORAGE_RANGE_EXCEEDED',
        ];
    }

    /** @return array<string,mixed> */
    public static function withdraw(ActorContext $a, mixed $managerId, mixed $amount): array
    {
        $w = WalletService::view($a, $managerId);
        $m = Money::parsePositive($amount);
        $available = $w->available()->minus($m);
        return [
            'amount' => $m->format(),
            'balance' => [$w->balance->format(), $w->balance->minus($m)->format()],
            'available' => [$w->available()->format(), $available->format()],
            'hint' => !$w->exists || $available->isNegative() ? 'INSUFFICIENT_AVAILABLE_CREDIT' : null,
        ];
    }

    /** @return array<string,mixed> source (available) and destination (balance) before / after a parent -> child transfer */
    public static function transfer(ActorContext $a, mixed $fromId, mixed $toId, mixed $amount): array
    {
        $from = WalletService::view($a, $fromId);
        $to = WalletService::view($a, $toId);
        $m = Money::parsePositive($amount);
        $fromAvailable = $from->available()->minus($m);
        $toBalance = $to->balance->plus($m);
        return [
            'amount' => $m->format(), 'opens_wallet' => !$to->exists,
            'from_available' => [$from->available()->format(), $fromAvailable->format()],
            'from_balance' => [$from->balance->format(), $from->balance->minus($m)->format()],
            'to_balance' => [$to->balance->format(), $toBalance->format()],
            'to_available' => [$to->available()->format(), $to->available()->plus($m)->format()],
            'hint' => !$from->exists || $fromAvailable->isNegative() ? 'INSUFFICIENT_AVAILABLE_CREDIT' : ($toBalance->isStorable() ? null : 'STORAGE_RANGE_EXCEEDED'),
        ];
    }

    /** @return array<string,mixed> the debt line now / proposed and the projected available credit */
    public static function debtLimit(ActorContext $a, mixed $managerId, mixed $limit): array
    {
        $w = WalletService::view($a, $managerId);
        $new = Money::parseNonNegative($limit);
        $projected = $w->balance->plus($new)->minus($w->reserved);
        return [
            'amount' => $new->format(), 'exists' => $w->exists, 'opens_wallet' => !$w->exists && $new->isPositive(),
            'balance' => $w->balance->format(), 'reserved' => $w->reserved->format(),
            'debt_limit' => [$w->debtLimit->format(), $new->format()],
            'available' => [$w->available()->format(), $projected->format()],
            'decrease' => $new->compare($w->debtLimit) < 0, 'unchanged' => $new->equals($w->debtLimit) && $w->exists,
            'hint' => $new->compare($w->debtLimit) < 0 && $projected->isNegative() ? 'DEBT_LIMIT_EXPOSURE' : null,
        ];
    }

    /** @return array<string,mixed> credit (+) or debit (-) adjustment of an existing wallet */
    public static function adjust(ActorContext $a, mixed $managerId, bool $credit, mixed $amount): array
    {
        $w = WalletService::view($a, $managerId);
        $m = Money::parsePositive($amount);
        $balance = $credit ? $w->balance->plus($m) : $w->balance->minus($m);
        $available = $credit ? $w->available()->plus($m) : $w->available()->minus($m);
        $hint = null;
        if (!$w->exists) {
            $hint = 'WALLET_NOT_FOUND';
        } elseif (!$credit && $available->isNegative()) {
            $hint = 'INSUFFICIENT_AVAILABLE_CREDIT';
        } elseif ($credit && !$balance->isStorable()) {
            $hint = 'STORAGE_RANGE_EXCEEDED';
        }
        return [
            'amount' => $m->format(), 'exists' => $w->exists,
            'balance' => [$w->balance->format(), $balance->format()],
            'available' => [$w->available()->format(), $available->format()],
            'hint' => $hint,
        ];
    }
}
