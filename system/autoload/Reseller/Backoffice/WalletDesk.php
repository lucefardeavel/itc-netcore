<?php
namespace Reseller\Backoffice;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Activation\ActivationReadService;
use Reseller\Domain\DomainError;
use Reseller\Finance\FinanceInput;
use Reseller\Finance\LedgerReadModel;
use Reseller\Finance\TransferService;
use Reseller\Finance\WalletService;
use Reseller\Finance\WalletPreview;
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

    // ------------------------------------------------------------------ money operations (review -> execute)

    /**
     * Provider funding / withdrawal form (Admin / SuperAdmin; wallet.fund_withdraw G).
     *
     * @return array<string,mixed>
     */
    public static function fundingForm(ActorContext $a, mixed $managerId, mixed $op): array
    {
        self::requireGlobal($a, C::WALLET_FUND_WITHDRAW);
        return ['op' => self::fundingOp($op), 'wallet' => LedgerReadModel::wallet($a, $managerId)];
    }

    /**
     * Review of a provider funding / withdrawal (no write): normalised amount, validated reason / external reference,
     * informational before / after, a new server reference and the confirm token.
     *
     * @param array<string,mixed> $in manager_id, op, amount, reason, external_reference
     * @return array<string,mixed>
     */
    public static function reviewFunding(ActorContext $a, string $key, array $in): array
    {
        self::requireGlobal($a, C::WALLET_FUND_WITHDRAW);
        $op = self::fundingOp($in['op'] ?? null);
        $wallet = LedgerReadModel::wallet($a, $in['manager_id'] ?? null);
        $amount = self::amount($in['amount'] ?? null);
        $preview = $op === 'fund' ? WalletPreview::fund($a, $wallet['id'], $amount) : WalletPreview::withdraw($a, $wallet['id'], $amount);
        $fields = [
            'op' => $op, 'target' => $wallet['id'], 'amount' => $preview['amount'], 'reason' => FinanceInput::reason($in['reason'] ?? null),
            'external' => FinanceInput::externalReference(self::blankToNull($in['external_reference'] ?? null)),
            'reference' => ConfirmToken::reference($op === 'fund' ? 'FND' : 'WDR'),
        ];
        return ['kind' => 'funding', 'op' => $op, 'post_route' => 'finance/funding-post', 'wallet' => $wallet, 'preview' => $preview, 'fields' => $fields,
            'token' => ConfirmToken::sign($key, $fields), 'typed' => $op === 'withdraw' ? $wallet['username'] : null];
    }

    /**
     * Executes a reviewed provider funding / withdrawal through the accepted TransferService (unchanged). A double
     * submit replays the same operation (same reference and payload).
     *
     * @param array<string,mixed> $in the reviewed fields + token (+ typed confirmation for a withdrawal)
     * @return array<string,mixed> the TransferService result
     */
    public static function executeFunding(ActorContext $a, string $key, array $in): array
    {
        $fields = self::posted($in, ['op', 'target', 'amount', 'reason', 'external', 'reference']);
        ConfirmToken::verify($key, $fields, $in['token'] ?? null);
        if ($fields['op'] === 'withdraw') {
            self::typed($in['confirm'] ?? null, LedgerReadModel::wallet($a, $fields['target'])['username']);
        }
        $r = $fields['op'] === 'fund'
            ? TransferService::fund($a, $fields['target'], $fields['amount'], $fields['reason'], $fields['reference'], self::blankToNull($fields['external']))
            : TransferService::withdraw($a, $fields['target'], $fields['amount'], $fields['reason'], $fields['reference'], self::blankToNull($fields['external']));
        return $r->toArray();
    }

    /**
     * Parent -> direct-child transfer form. An Agent always sends from its own wallet to one of its direct child
     * resellers; Admin / SuperAdmin send from the destination's actual parent (the accepted direct-child rule).
     *
     * @return array<string,mixed>
     */
    public static function sendForm(ActorContext $a, mixed $toId): array
    {
        [$from, $to, $children] = self::sendParties($a, $toId, true);
        return ['from' => $from, 'to' => $to, 'children' => $children];
    }

    /** @param array<string,mixed> $in to_id, amount, reason @return array<string,mixed> */
    public static function reviewSend(ActorContext $a, string $key, array $in): array
    {
        [$from, $to] = self::sendParties($a, $in['to_id'] ?? null, false);
        $amount = self::amount($in['amount'] ?? null);
        $preview = WalletPreview::transfer($a, $from['id'], $to['id'], $amount);
        $fields = [
            'op' => 'send', 'from' => $from['id'], 'to' => $to['id'], 'amount' => $preview['amount'],
            'reason' => FinanceInput::reason($in['reason'] ?? null), 'reference' => ConfirmToken::reference('TRF'),
        ];
        return ['kind' => 'send', 'op' => 'send', 'post_route' => 'finance/send-post', 'from' => $from, 'to' => $to, 'preview' => $preview, 'fields' => $fields,
            'token' => ConfirmToken::sign($key, $fields), 'typed' => null];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function executeSend(ActorContext $a, string $key, array $in): array
    {
        $fields = self::posted($in, ['op', 'from', 'to', 'amount', 'reason', 'reference']);
        ConfirmToken::verify($key, $fields, $in['token'] ?? null);
        if ($fields['op'] !== 'send') {
            throw new DomainError('REVIEW_EXPIRED');
        }
        return TransferService::transferToChild($a, $fields['from'], $fields['to'], $fields['amount'], $fields['reason'], $fields['reference'])->toArray();
    }

    // ------------------------------------------------------------------ administrative accounting corrections (OD-2)

    /** @return array<string,mixed> debt line form (wallet.debt_limit G) */
    public static function debtLimitForm(ActorContext $a, mixed $managerId): array
    {
        self::requireGlobal($a, C::WALLET_DEBT_LIMIT);
        return ['wallet' => LedgerReadModel::wallet($a, $managerId)];
    }

    /** @param array<string,mixed> $in manager_id, limit, reason @return array<string,mixed> */
    public static function reviewDebtLimit(ActorContext $a, string $key, array $in): array
    {
        self::requireGlobal($a, C::WALLET_DEBT_LIMIT);
        $wallet = LedgerReadModel::wallet($a, $in['manager_id'] ?? null);
        $preview = WalletPreview::debtLimit($a, $wallet['id'], self::amount($in['limit'] ?? null));
        $fields = ['op' => 'debt_limit', 'target' => $wallet['id'], 'amount' => $preview['amount'], 'reason' => FinanceInput::reason($in['reason'] ?? null),
            'decrease' => $preview['decrease'] ? '1' : '0'];
        return ['kind' => 'debt_limit', 'op' => 'debt_limit', 'post_route' => 'finance/debt-limit-post', 'wallet' => $wallet, 'preview' => $preview,
            'fields' => $fields, 'token' => ConfirmToken::sign($key, $fields), 'typed' => $preview['decrease'] ? $wallet['username'] : null,
            'blocked' => $preview['unchanged'] ? 'No change: the debt line already has this value.' : null];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> the resulting wallet view */
    public static function executeDebtLimit(ActorContext $a, string $key, array $in): array
    {
        $fields = self::posted($in, ['op', 'target', 'amount', 'reason', 'decrease']);
        ConfirmToken::verify($key, $fields, $in['token'] ?? null);
        if ($fields['op'] !== 'debt_limit') {
            throw new DomainError('REVIEW_EXPIRED');
        }
        if ($fields['decrease'] === '1') {
            self::typed($in['confirm'] ?? null, LedgerReadModel::wallet($a, $fields['target'])['username']);
        }
        return WalletService::setDebtLimit($a, $fields['target'], $fields['amount'], $fields['reason'])->toArray();
    }

    /** @return array<string,mixed> credit / debit adjustment form (wallet.adjust G) */
    public static function adjustForm(ActorContext $a, mixed $managerId, mixed $op): array
    {
        self::requireGlobal($a, C::WALLET_ADJUST);
        return ['op' => self::adjustOp($op), 'wallet' => LedgerReadModel::wallet($a, $managerId)];
    }

    /** @param array<string,mixed> $in manager_id, op (credit|debit), amount, reason, external_reference @return array<string,mixed> */
    public static function reviewAdjust(ActorContext $a, string $key, array $in): array
    {
        self::requireGlobal($a, C::WALLET_ADJUST);
        $op = self::adjustOp($in['op'] ?? null);
        $wallet = LedgerReadModel::wallet($a, $in['manager_id'] ?? null);
        $preview = WalletPreview::adjust($a, $wallet['id'], $op === 'credit', self::amount($in['amount'] ?? null));
        $fields = [
            'op' => $op, 'target' => $wallet['id'], 'amount' => $preview['amount'], 'reason' => FinanceInput::reason($in['reason'] ?? null),
            'external' => FinanceInput::externalReference(self::blankToNull($in['external_reference'] ?? null)),
            'reference' => ConfirmToken::reference($op === 'credit' ? 'ADC' : 'ADD'),
        ];
        return ['kind' => 'adjust', 'op' => $op, 'post_route' => 'finance/adjust-post', 'wallet' => $wallet, 'preview' => $preview, 'fields' => $fields,
            'token' => ConfirmToken::sign($key, $fields), 'typed' => $op === 'debit' ? $wallet['username'] : null,
            'blocked' => !$preview['exists'] ? 'This reseller has no wallet. Adjustments never open a wallet; use provider funding for the first credit.' : null];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> the TransferService result */
    public static function executeAdjust(ActorContext $a, string $key, array $in): array
    {
        $fields = self::posted($in, ['op', 'target', 'amount', 'reason', 'external', 'reference']);
        ConfirmToken::verify($key, $fields, $in['token'] ?? null);
        $op = self::adjustOp($fields['op']);
        if ($op === 'debit') {
            self::typed($in['confirm'] ?? null, LedgerReadModel::wallet($a, $fields['target'])['username']);
        }
        $r = $op === 'credit'
            ? TransferService::adjustCredit($a, $fields['target'], $fields['amount'], $fields['reason'], $fields['reference'], self::blankToNull($fields['external']))
            : TransferService::adjustDebit($a, $fields['target'], $fields['amount'], $fields['reason'], $fields['reference'], self::blankToNull($fields['external']));
        return $r->toArray();
    }

    /** @return array<string,mixed> reversal form of one existing transfer (wallet.reverse G) */
    public static function reverseForm(ActorContext $a, mixed $transferId): array
    {
        self::requireGlobal($a, C::WALLET_REVERSE);
        return ['preview' => self::reversal($a, $transferId)];
    }

    /** @param array<string,mixed> $in transfer_id, reason @return array<string,mixed> */
    public static function reviewReverse(ActorContext $a, string $key, array $in): array
    {
        self::requireGlobal($a, C::WALLET_REVERSE);
        $preview = self::reversal($a, $in['transfer_id'] ?? null);
        if ($preview['refusal'] !== null) {
            throw new DomainError($preview['refusal']);
        }
        $fields = ['op' => 'reverse', 'target' => $preview['transfer_id'], 'reason' => FinanceInput::reason($in['reason'] ?? null), 'reference' => ConfirmToken::reference('REV')];
        return ['kind' => 'reverse', 'op' => 'reverse', 'post_route' => 'finance/reverse-post', 'preview' => $preview, 'fields' => $fields,
            'token' => ConfirmToken::sign($key, $fields), 'typed' => $preview['reference'], 'blocked' => null];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> the compensating TransferService result */
    public static function executeReverse(ActorContext $a, string $key, array $in): array
    {
        $fields = self::posted($in, ['op', 'target', 'reason', 'reference']);
        ConfirmToken::verify($key, $fields, $in['token'] ?? null);
        if ($fields['op'] !== 'reverse') {
            throw new DomainError('REVIEW_EXPIRED');
        }
        self::typed($in['confirm'] ?? null, self::reversal($a, $fields['target'])['reference']);
        return TransferService::reverse($a, $fields['target'], $fields['reason'], $fields['reference'])->toArray();
    }

    /** The accepted reversal preview plus the parties' display identities. @return array<string,mixed> */
    private static function reversal(ActorContext $a, mixed $transferId): array
    {
        $p = TransferService::reversalPreview($a, $transferId);
        $t = LedgerReadModel::transfer($a, $p['transfer_id']);
        $p['from'] = $t['from'];
        $p['to'] = $t['to'];
        foreach (['debit', 'credit'] as $side) {
            if ($p[$side] !== null) {
                $p[$side]['wallet'] = LedgerReadModel::wallet($a, $p[$side]['manager_id']);
            }
        }
        return $p;
    }

    private static function adjustOp(mixed $op): string
    {
        if (!in_array($op, ['credit', 'debit'], true)) {
            throw new DomainError('NOT_FOUND');
        }
        return $op;
    }

    /**
     * The transfer parties: [source wallet, destination wallet|null, selectable children]. Offers only; the service
     * enforces source = self (Agent), the direct-child relation and both owners' state.
     *
     * @return array{0:array<string,mixed>,1:?array<string,mixed>,2:list<array<string,mixed>>}
     */
    private static function sendParties(ActorContext $a, mixed $toId, bool $allowNoTarget): array
    {
        $scope = PermissionService::scopeOf($a, C::WALLET_TRANSFER);
        if ($scope === 'C') {
            $from = LedgerReadModel::wallet($a, (int) $a->userId);
            $children = LedgerReadModel::wallets($a, ['parent' => (int) $a->userId], 1)['rows'];
            if ($toId === null || $toId === '') {
                if ($allowNoTarget) {
                    return [$from, null, $children];
                }
                throw new DomainError('NOT_FOUND');
            }
            foreach ($children as $c) {
                if ((string) $c['id'] === (string) $toId) {
                    return [$from, $c, $children];
                }
            }
            throw new DomainError('NOT_FOUND'); // not a direct child of the actor (the service refuses it as well)
        }
        if ($scope !== 'G') {
            throw new DomainError('FORBIDDEN');
        }
        $to = LedgerReadModel::wallet($a, $toId);
        if (($to['parent']['id'] ?? null) === null) {
            throw new DomainError('NOT_DIRECT_CHILD');
        }
        return [LedgerReadModel::wallet($a, $to['parent']['id']), $to, []];
    }

    /** Lossless digit mapping (Arabic-Indic / Extended Arabic-Indic digits, Arabic decimal separator) before the strict Money grammar. */
    public static function amount(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $map = ['٫' => '.'];
        foreach (['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'] as $i => $d) {
            $map[$d] = (string) $i;
        }
        foreach (['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'] as $i => $d) {
            $map[$d] = (string) $i;
        }
        return trim(strtr($raw, $map));
    }

    private static function fundingOp(mixed $op): string
    {
        if (!in_array($op, ['fund', 'withdraw'], true)) {
            throw new DomainError('NOT_FOUND');
        }
        return $op;
    }

    private static function requireGlobal(ActorContext $a, string $capability): void
    {
        if (PermissionService::scopeOf($a, $capability) !== 'G') {
            throw new DomainError('FORBIDDEN');
        }
    }

    /** @param array<string,mixed> $in @param list<string> $keys @return array<string,string> */
    private static function posted(array $in, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $in[$k] ?? '';
            $out[$k] = is_string($v) || is_int($v) ? (string) $v : '';
        }
        return $out;
    }

    private static function typed(mixed $typed, string $expected): void
    {
        if (!is_string($typed) || !hash_equals($expected, trim($typed))) {
            throw new DomainError('CONFIRMATION_MISMATCH');
        }
    }

    private static function blankToNull(mixed $v): mixed
    {
        return is_string($v) && trim($v) === '' ? null : $v;
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
