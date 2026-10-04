<?php
namespace Reseller\Backoffice;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerReadService;
use Reseller\Finance\LedgerReadModel;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;

/**
 * WP6B (owner decision OD-1): the web gateway of the reseller detail page. It composes the reseller read model
 * (Reseller\Domain) with the wallet card from the finance read model when the actor holds wallet.read for that
 * reseller (web only: reseller/detail is never API-reachable). No SQL, no money arithmetic, no authority.
 */
final class ResellerDesk
{
    /** @return array<string,mixed> */
    public static function detail(ActorContext $a, mixed $id): array
    {
        $node = ResellerReadService::detail($a, $id);
        $node['wallet'] = null;
        $node['wallet_actions'] = [];
        if ($node['user_type'] === 'Agent' && PermissionService::can($a, C::WALLET_READ)) {
            try {
                $node['wallet'] = LedgerReadModel::wallet($a, $node['id']);
                $node['wallet_actions'] = WalletDesk::actionsFor($a, $node['wallet']);
            } catch (DomainError) {
                $node['wallet'] = null; // outside the wallet.read scope or not a wallet owner: no card
            }
        }
        return $node;
    }
}
