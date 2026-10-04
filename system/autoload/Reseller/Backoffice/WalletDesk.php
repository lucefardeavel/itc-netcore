<?php
namespace Reseller\Backoffice;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Activation\ActivationReadService;
use Reseller\Domain\DomainError;
use Reseller\Finance\LedgerReadModel;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Ui\Format;

/**
 * WP6B (owner decision OD-1): the web gateway of the wallet / ledger / transfer pages. It composes the authoritative
 * readers (Reseller\Finance read models, Reseller\Activation read service) into page models. It holds no authority and
 * no domain logic: no SQL, no money arithmetic, no authorization of its own beyond asking PermissionService which
 * actions to *offer* (the services decide).
 */
final class WalletDesk
{
    /** Wallet actions a page may offer (each has its own route and authoritative service). */
    public const ACTIONS = ['fund', 'withdraw', 'send', 'send_from_parent', 'debt_limit', 'adjust'];

    /**
     * Finance home: an Agent sees its own wallet (plus its direct children's wallets), Admin / SuperAdmin the scoped
     * wallet list.
     *
     * @return array<string,mixed>
     */
    public static function home(ActorContext $a): array
    {
        if (!PermissionService::can($a, C::WALLET_READ)) {
            throw new DomainError('FORBIDDEN');
        }
        if (PermissionService::scopeOf($a, C::WALLET_READ) !== 'G') {
            $own = self::wallet($a, (int) $a->userId);
            $own['children'] = LedgerReadModel::wallets($a, ['parent' => (int) $a->userId], 1);
            $own['can_send'] = PermissionService::scopeOf($a, C::WALLET_TRANSFER) === 'C' && $own['children']['total'] > 0;
            return ['mode' => 'own'] + $own;
        }
        return ['mode' => 'all', 'list' => LedgerReadModel::wallets($a, [], 1)];
    }

    /** @return array<string,mixed> */
    public static function wallets(ActorContext $a, array $filters, int $page): array
    {
        return LedgerReadModel::wallets($a, $filters, $page);
    }

    /**
     * One wallet page: identity / state, money (WalletService through the read model), the latest movements (when
     * ledger.read allows) and the actions the actor may be offered for it.
     *
     * @return array<string,mixed>
     */
    public static function wallet(ActorContext $a, mixed $managerId): array
    {
        $w = LedgerReadModel::wallet($a, $managerId);
        $recent = [];
        if (PermissionService::can($a, C::LEDGER_READ)) {
            $recent = LedgerReadModel::recent($a, $w['id'], 10);
        }
        return ['wallet' => $w, 'recent' => self::withActivations($a, $recent), 'actions' => self::actionsFor($a, $w)];
    }

    /** @return array<string,mixed> */
    public static function ledger(ActorContext $a, mixed $managerId, array $filters, int $page): array
    {
        $l = LedgerReadModel::ledger($a, $managerId, $filters, $page);
        $l['rows'] = self::withActivations($a, $l['rows']);
        return $l;
    }

    /** @return array<string,mixed> */
    public static function entry(ActorContext $a, mixed $entryId): array
    {
        $e = LedgerReadModel::entry($a, $entryId);
        return self::withActivations($a, [$e])[0];
    }

    /** @return array<string,mixed> */
    public static function transfers(ActorContext $a, array $filters, int $page): array
    {
        return LedgerReadModel::transfers($a, $filters, $page);
    }

    /** @return array<string,mixed> */
    public static function transfer(ActorContext $a, mixed $transferId): array
    {
        $t = LedgerReadModel::transfer($a, $transferId);
        $t['legs'] = self::withActivations($a, $t['legs']);
        $t['can_reverse'] = PermissionService::can($a, C::WALLET_REVERSE) && $t['reverses_transfer_id'] === null
            && $t['status'] === 'COMPLETED' && $t['reversed_by'] === null;
        return $t;
    }

    /**
     * Actions to offer on a wallet page. Each is only an offer: the route rule and the accepted service re-check
     * capability, scope, the direct-child rule, the owner state and the floor.
     *
     * @param array<string,mixed> $w
     * @return list<string>
     */
    public static function actionsFor(ActorContext $a, array $w): array
    {
        $out = [];
        $global = static fn(string $cap): bool => PermissionService::scopeOf($a, $cap) === 'G';
        if ($global(C::WALLET_FUND_WITHDRAW)) {
            array_push($out, 'fund', 'withdraw');
        }
        $parentId = $w['parent']['id'] ?? null;
        if (PermissionService::scopeOf($a, C::WALLET_TRANSFER) === 'C' && $parentId === (int) $a->userId) {
            $out[] = 'send'; // an Agent sends from its own wallet to this direct child
        } elseif ($global(C::WALLET_TRANSFER) && $parentId !== null) {
            $out[] = 'send_from_parent';
        }
        if ($global(C::WALLET_DEBT_LIMIT)) {
            $out[] = 'debt_limit';
        }
        if ($global(C::WALLET_ADJUST)) {
            $out[] = 'adjust';
        }
        return $out;
    }

    /**
     * Streams a CSV export of one finance list through the authoritative scoped export cursor (same capability, scope,
     * filters and query as the screen; Finance enforces the range / row caps). Throws DomainError before any output
     * when refused.
     *
     * @param array<string,string> $f
     */
    public static function export(ActorContext $a, string $kind, mixed $managerId, array $f): int
    {
        $party = static fn(?array $p): string => Format::party($p);
        $t = static fn(string $s): string => \Lang::T($s);
        switch ($kind) {
            case 'wallets':
                return ExportStream::csv($a, 'wallets', null, $f, [
                    [$t('Username'), static fn($r) => $r['username']], [$t('Full Name'), static fn($r) => $r['fullname']],
                    [$t('Status'), static fn($r) => $r['status']], [$t('Wallet exists'), static fn($r) => $r['exists']],
                    [$t('Balance'), static fn($r) => $r['balance']], [$t('Debt line'), static fn($r) => $r['debt_limit']],
                    [$t('Reserved'), static fn($r) => $r['reserved']], [$t('Available credit'), static fn($r) => $r['available']],
                ], static fn(callable $sink): int => LedgerReadModel::exportWallets($a, $f, $sink), 'wallets.csv');
            case 'ledger':
                $owner = LedgerReadModel::ledger($a, $managerId, [], 1)['owner'];
                return ExportStream::csv($a, 'ledger', (int) $owner['id'], $f, [
                    [$t('Time (UTC)'), static fn($r) => $r['created_at']], [$t('Entry'), static fn($r) => $r['id']],
                    [$t('Type'), static fn($r) => $t(Format::ledgerType($r['entry_type']))], [$t('Direction'), static fn($r) => $r['direction']],
                    [$t('Amount'), static fn($r) => $r['amount']], [$t('Balance before'), static fn($r) => $r['balance_before']],
                    [$t('Balance after'), static fn($r) => $r['balance_after']], [$t('Transfer'), static fn($r) => $r['transfer_id']],
                    [$t('Counterparty'), static fn($r) => $party($r['counterparty'])], [$t('Operator'), static fn($r) => $party($r['operator'])],
                    [$t('Reason'), static fn($r) => $r['reason']],
                ], static fn(callable $sink): int => LedgerReadModel::exportLedger($a, $managerId, $f, $sink), 'ledger-' . $owner['username'] . '.csv');
            case 'transfers':
                return ExportStream::csv($a, 'transfers', null, $f, [
                    [$t('Time (UTC)'), static fn($r) => $r['created_at']], [$t('Reference'), static fn($r) => $r['reference']],
                    [$t('Type'), static fn($r) => $t(Format::transferType($r['operation_type']))], [$t('Status'), static fn($r) => $r['status']],
                    [$t('Amount'), static fn($r) => $r['amount']], [$t('From'), static fn($r) => $party($r['from'])], [$t('To'), static fn($r) => $party($r['to'])],
                    [$t('Operator'), static fn($r) => $party($r['operator'])], [$t('Reason'), static fn($r) => $r['reason']],
                    [$t('External reference'), static fn($r) => $r['external_reference']],
                ], static fn(callable $sink): int => LedgerReadModel::exportTransfers($a, $f, $sink), 'transfers.csv');
        }
        throw new DomainError('NOT_FOUND');
    }

    /**
     * Adds the activation reference to ledger rows the actor may open (activation.read visibility; others keep none).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function withActivations(ActorContext $a, array $rows): array
    {
        $refs = ActivationReadService::references($a, array_values(array_filter(array_map(static fn(array $r) => $r['activation_id'] ?? null, $rows))));
        foreach ($rows as $i => $r) {
            $rows[$i]['activation_reference'] = isset($r['activation_id']) ? ($refs[$r['activation_id']] ?? null) : null;
        }
        return $rows;
    }
}
