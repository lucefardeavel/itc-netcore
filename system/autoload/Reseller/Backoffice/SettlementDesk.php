<?php
namespace Reseller\Backoffice;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Finance\SettlementReadModel;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Ui\Format;

/**
 * WP6B (owner decisions OD-1 / OD-4 / OD-6): the web gateway of the finance reports (activation sales, commissions,
 * provider settlement, reseller performance). It composes the authoritative SettlementReadModel (immutable ledger +
 * sealed snapshots, role-projected) and streams the same scoped queries as CSV. No SQL, no money arithmetic.
 */
final class SettlementDesk
{
    public const REPORTS = ['sales', 'commissions', 'settlement', 'performance'];

    /** @return array<string,mixed> one report page */
    public static function report(ActorContext $a, string $kind, array $f, int $page): array
    {
        return match ($kind) {
            'sales' => SettlementReadModel::sales($a, $f, $page),
            'commissions' => SettlementReadModel::commissions($a, $f, $page),
            'settlement' => SettlementReadModel::settlement($a, $f, $page),
            'performance' => SettlementReadModel::performance($a, $f, $page),
            default => throw new DomainError('NOT_FOUND'),
        };
    }

    /** The reports this actor may open (offers only; each route and read model re-checks). @return list<string> */
    public static function available(ActorContext $a): array
    {
        $out = [];
        if (PermissionService::can($a, C::LEDGER_READ)) {
            array_push($out, 'sales', 'commissions');
            if (PermissionService::scopeOf($a, C::LEDGER_READ) === 'G' && PermissionService::scopeOf($a, C::WALLET_READ) === 'G') {
                $out[] = 'settlement';
            }
        }
        if (PermissionService::can($a, C::WALLET_READ) && PermissionService::can($a, C::LEDGER_READ)) {
            $out[] = 'performance';
        }
        return $out;
    }

    /** Streams one report as CSV (same capability / scope / filters; caps enforced by the read model). */
    public static function export(ActorContext $a, string $kind, array $f): int
    {
        $t = static fn(string $s): string => \Lang::T($s);
        $party = static fn(?array $p): string => Format::party($p);
        switch ($kind) {
            case 'sales':
            case 'commissions':
                $cols = [[$t('Time') . ' (' . date_default_timezone_get() . ')', static fn($r) => \Reseller\Ui\Format::localTime($r['created_at'])], [$t('Activation'), static fn($r) => $r['reference']],
                    [$t('Plan'), static fn($r) => $r['plan']], [$kind === 'sales' ? $t('Reseller') : $t('Recipient'), static fn($r) => $party($r['manager'])]];
                if ($kind === 'commissions') {
                    $cols[] = [$t('Sold by'), static fn($r) => $party($r['seller'])];
                }
                $cols[] = [$kind === 'sales' ? $t('Wholesale debit (reseller cost)') : $t('Commission'), static fn($r) => $r['amount']];
                if ($kind === 'sales') {
                    $cols[] = [$t('Retail (customer price)'), static fn($r) => $r['retail']];
                }
                $cols[] = [$t('Reversed'), static fn($r) => $r['reversed']];
                return ExportStream::csv($a, $kind, null, $f, $cols, static fn(callable $sink): int => SettlementReadModel::exportLegs($a, $kind, $f, $sink), $kind . '.csv');
            case 'settlement':
                return ExportStream::csv($a, 'settlement', null, $f, [
                    [$t('Settled (UTC)'), static fn($r) => $r['settled_at']], [$t('Activation'), static fn($r) => $r['reference']],
                    [$t('Reseller'), static fn($r) => $party($r['seller'])], [$t('Plan'), static fn($r) => $r['plan']],
                    [$t('Wholesale debit (reseller cost)'), static fn($r) => $r['wholesale']], [$t('Commissions'), static fn($r) => $r['commissions']],
                    [$t('Provider settlement'), static fn($r) => $r['provider_settlement']], [$t('Conserved'), static fn($r) => $r['conserved']],
                    [$t('Status'), static fn($r) => $r['status']],
                ], static fn(callable $sink): int => SettlementReadModel::exportSettlement($a, $f, $sink), 'settlement.csv');
            case 'performance':
                // fix round 1 (OD-6): the authoritative scoped count is read before any output and refused above the
                // cap; never truncated (SettlementReadModel::exportPerformance, one read snapshot)
                return ExportStream::csv($a, 'performance', null, $f, [
                    [$t('Reseller'), static fn($r) => $r['username']], [$t('Full Name'), static fn($r) => $r['fullname']],
                    [$t('Activations'), static fn($r) => $r['activations']], [$t('Wholesale debited'), static fn($r) => $r['wholesale']],
                    [$t('Commissions received'), static fn($r) => $r['commissions']], [$t('Reversal entries'), static fn($r) => $r['reversals']],
                ], static fn(callable $sink): int => SettlementReadModel::exportPerformance($a, $f, $sink), 'performance.csv');
        }
        throw new DomainError('NOT_FOUND');
    }
}
