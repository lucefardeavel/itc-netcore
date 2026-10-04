<?php
// Controller: only reachable through the dispatcher (index.php); never API-allowed (WP6B owner decision OD-1).
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP6B — reseller pricing web surface (owner decisions OD-1 / OD-4 / OD-9). Thin: authorized centrally (RouteGuard /
 * RouteMap) and again by the accepted PricingService behind Reseller\Backoffice\PricingDesk. Every figure is a
 * PricingReadModel projection (an Agent never sees provider base cost, provider settlement or anything above itself).
 * Wholesale (reseller cost) and retail (customer price) are always labelled separately; "price" is never used alone.
 *
 *   plans              GET   Admin: plans with provider base cost / resale; Agent: its own catalog (+csv)
 *   plan               GET   one plan across resellers (bounded page; Agent: itself and its direct children)
 *   catalog            GET   one reseller's catalog (bounded page) (+csv)
 *   base-cost-post     POST  Admin: provider base cost            (PricingService::setBaseCost)
 *   resale-post        POST  Admin: reseller commerce on / off     (PricingService::setResaleEnabled)
 *   wholesale-post     POST  child wholesale set / clear          (PricingService::setChildWholesale)
 *   retail-post        POST  retail set / clear                   (PricingService::setRetail)
 *   availability-post  POST  child availability                   (PricingService::setChildAvailability)
 */

_admin();
$ui->assign('_system_menu', 'pricing');
$ui->assign('_admin', $admin);

$action = $routes['1'] ?? '';
$nuxrActor = \Reseller\Security\ActorContext::current();
$nuxrPage = static fn(): int => max(1, (int) (is_string(_get('page')) && ctype_digit(_get('page')) ? _get('page') : 1));
$nuxrId = $routes['2'] ?? '';
$nuxrStr = static fn(string $k): ?string => is_string(_post($k)) ? _post($k) : null;
/** Back to the page the edit came from (plan grid or catalog), never a free-form URL. */
$nuxrBack = static function () use ($nuxrStr): string {
    $plan = $nuxrStr('plan_id');
    $manager = $nuxrStr('manager_id');
    return match ($nuxrStr('back')) {
        'plan' => 'pricing/plan/' . (ctype_digit((string) $plan) ? $plan : ''),
        'catalog' => 'pricing/catalog/' . (ctype_digit((string) $manager) ? $manager : ''),
        default => 'pricing',
    };
};
$nuxrDone = static function (array $result) use ($nuxrBack): void {
    $msg = $result['changed'] ? Lang::T('Saved.') : Lang::T('No change.');
    $affected = 0;
    foreach (($result['warnings'] ?? []) as $ids) {
        $affected += is_array($ids) ? count($ids) : 0;
    }
    if ($affected > 0) {
        $msg .= ' ' . str_replace('[n]', (string) $affected, Lang::T('[n] reseller(s) can no longer sell this plan: their retail is below their wholesale. Review their retail.'));
    }
    r2(getUrl($nuxrBack()), $affected > 0 ? 'w' : 's', $msg);
};

switch ($action) {
    case 'plan':
        try {
            $grid = \Reseller\Backoffice\PricingDesk::plan($nuxrActor, $nuxrId, $nuxrPage());
        } catch (\Reseller\Domain\DomainError $e) {
            r2(getUrl('pricing'), 'e', Lang::T($e->getMessage()));
        }
        $ui->assign('_title', Lang::T('Pricing') . ' · ' . $grid['plan']['name']);
        $ui->assign('nuxr_grid', $grid);
        $ui->assign('nuxr_base', Text::url('pricing/plan/' . $grid['plan']['id'] . '&'));
        $ui->display('admin/pricing/plan.tpl');
        break;

    case 'catalog':
        try {
            if (_get('format') === 'csv') {
                \Reseller\Backoffice\PricingDesk::exportCatalog($nuxrActor, $nuxrId);
                exit;
            }
            $cat = \Reseller\Backoffice\PricingDesk::catalog($nuxrActor, $nuxrId, $nuxrPage());
        } catch (\Reseller\Domain\DomainError $e) {
            r2(getUrl('pricing'), 'e', Lang::T($e->getMessage()));
        }
        $ui->assign('_title', Lang::T('Pricing') . ' · ' . $cat['target']['fullname']);
        $ui->assign('nuxr_cat', $cat);
        $ui->assign('nuxr_base', Text::url('pricing/catalog/' . $cat['target']['id'] . '&'));
        $ui->display('admin/pricing/catalog.tpl');
        break;

    case 'base-cost-post':
    case 'resale-post':
    case 'wholesale-post':
    case 'retail-post':
    case 'availability-post':
        try {
            $result = match ($action) {
                'base-cost-post' => \Reseller\Backoffice\PricingDesk::setBaseCost($nuxrActor, $nuxrStr('plan_id'), $nuxrStr('cost'), $nuxrStr('reason')),
                'resale-post' => \Reseller\Backoffice\PricingDesk::setResale($nuxrActor, $nuxrStr('plan_id'), $nuxrStr('enabled'), $nuxrStr('reason')),
                'wholesale-post' => \Reseller\Backoffice\PricingDesk::setWholesale($nuxrActor, $nuxrStr('manager_id'), $nuxrStr('plan_id'), $nuxrStr('cost'), $nuxrStr('reason')),
                'retail-post' => \Reseller\Backoffice\PricingDesk::setRetail($nuxrActor, $nuxrStr('manager_id'), $nuxrStr('plan_id'), $nuxrStr('price'), $nuxrStr('reason')),
                'availability-post' => \Reseller\Backoffice\PricingDesk::setAvailability($nuxrActor, $nuxrStr('manager_id'), $nuxrStr('plan_id'), $nuxrStr('state'), $nuxrStr('reason')),
            };
        } catch (\Reseller\Domain\DomainError $e) {
            r2(getUrl($nuxrBack()), 'e', Lang::T($e->getMessage()));
        }
        $nuxrDone($result);
        break;

    case 'plans':
    default:
        try {
            if (_get('format') === 'csv') {
                \Reseller\Backoffice\PricingDesk::exportCatalog($nuxrActor, (int) $nuxrActor->userId);
                exit;
            }
            $plans = \Reseller\Backoffice\PricingDesk::plans($nuxrActor, $nuxrPage());
        } catch (\Reseller\Domain\DomainError $e) {
            r2(getUrl('dashboard'), 'e', Lang::T($e->getMessage()));
        }
        $ui->assign('_title', Lang::T('Pricing'));
        $ui->assign('nuxr_plans', $plans);
        if ($plans['mode'] === 'own') {
            $ui->assign('nuxr_cat', $plans);
            $ui->assign('nuxr_base', Text::url('pricing&'));
            $ui->display('admin/pricing/catalog.tpl');
            break;
        }
        $ui->assign('nuxr_base', Text::url('pricing&'));
        $ui->display('admin/pricing/plans.tpl');
        break;
}
