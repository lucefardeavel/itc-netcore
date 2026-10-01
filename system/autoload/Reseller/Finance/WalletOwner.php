<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\Tx;

/**
 * Wallet-owner rules (WP3A §3/§16). Only an enrolled, consistent, Active reseller Agent without an
 * inactive ancestor may own a wallet or be the target of a business mutation. SuperAdmin, Admin,
 * Sales and Report are never wallet owners. Callers run under the accepted WP2 tree lock, so status
 * and closure cannot change between this check and COMMIT.
 */
final class WalletOwner
{
    /**
     * Valid owner for a business mutation (funding, withdrawal, adjustment, debt limit, transfer,
     * reservation). Admin callers get no exception (owner decision P4).
     *
     * @return array<string,mixed> the tbl_users row (id, username, user_type, status, root)
     */
    public static function assertValid(\PDO $pdo, int $id): array
    {
        $u = self::user($pdo, $id);
        if ($u['user_type'] !== 'Agent') {
            throw new DomainError('WALLET_OWNER_INVALID');
        }
        $anc = Tx::rows(
            $pdo,
            'SELECT t.ancestor_id, t.depth, u.user_type, u.status FROM tbl_manager_tree t '
            . 'JOIN tbl_users u ON u.id = t.ancestor_id WHERE t.descendant_id = ? ORDER BY t.depth',
            [$id]
        );
        if ($anc === [] || (int) $anc[0]['depth'] !== 0 || (int) $anc[0]['ancestor_id'] !== $id) {
            throw new DomainError('WALLET_OWNER_INVALID'); // not enrolled (C20)
        }
        $parent = null;
        foreach ($anc as $a) {
            if ((int) $a['depth'] === 1) {
                $parent = $a;
            }
        }
        $consistent = $parent === null
            ? (int) $u['root'] === 0
            : ((int) $parent['ancestor_id'] === (int) $u['root'] && $parent['user_type'] === 'Agent');
        if (!$consistent) {
            throw new DomainError('WALLET_OWNER_INVALID');
        }
        if ($u['status'] !== 'Active') {
            throw new DomainError('WALLET_OWNER_FROZEN');
        }
        foreach ($anc as $a) {
            if ((int) $a['depth'] > 0 && $a['status'] !== 'Active') {
                throw new DomainError('WALLET_OWNER_FROZEN');
            }
        }
        return $u;
    }

    /**
     * Historical counterparty of an administrative reversal (owner decision P4): the account must
     * still be identifiable as a reseller Agent; inactive / frozen status does not block the correction.
     *
     * @return array<string,mixed>
     */
    public static function assertIdentifiable(\PDO $pdo, int $id): array
    {
        $u = self::user($pdo, $id);
        if ($u['user_type'] !== 'Agent') {
            throw new DomainError('WALLET_OWNER_INVALID');
        }
        return $u;
    }

    /** Identity snapshot for audit (no credentials). @param array<string,mixed> $u */
    public static function snapshot(array $u): array
    {
        return [
            'id' => (int) $u['id'], 'username' => (string) $u['username'], 'user_type' => (string) $u['user_type'],
            'status' => (string) $u['status'], 'root' => (int) ($u['root'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private static function user(\PDO $pdo, int $id): array
    {
        return Tx::row($pdo, 'SELECT id, username, user_type, status, root FROM tbl_users WHERE id = ?', [$id])
            ?? throw new DomainError('NOT_FOUND');
    }
}
