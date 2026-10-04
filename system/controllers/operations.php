<?php
// Controller: only reachable through the dispatcher (index.php); never API-allowed (WP6B owner decision OD-1).
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP6B — Operations Center (owner decisions OD-7 / OD-8): Admin / SuperAdmin (system.admin), read-only. Categories:
 * Needs attention, Waiting / held, Failed / uncertain, Informational / CLI required, Resolved / history. Every button
 * posts to its own accepted activation route; nothing here mutates.
 *
 *   overview    GET  category counts
 *   activations GET  attention + stale activations with their accepted actions
 *   drift       GET  drift findings on demand (run=1; radius=1 adds the RADIUS comparison)
 *   held        GET  legacy balance operations HELD (CLI decision)
 *   renewals    GET  auto-renew-unavailable records (informational)
 */

_admin();
$ui->assign('_system_menu', 'operations');
$ui->assign('_admin', $admin);

$action = $routes['1'] ?? '';
$nuxrActor = \Reseller\Security\ActorContext::current();
$nuxrPage = static fn(): int => max(1, (int) (is_string(_get('page')) && ctype_digit(_get('page')) ? _get('page') : 1));

try {
    switch ($action) {
        case 'activations':
            $ui->assign('nuxr_ops', \Reseller\Backoffice\OperationsDesk::activations($nuxrActor, $nuxrPage()));
            $view = 'activations';
            break;
        case 'drift':
            $run = _get('run') === '1';
            $ui->assign('nuxr_run', $run);
            $ui->assign('nuxr_radius', _get('radius') === '1');
            $ui->assign('nuxr_drift', $run ? \Reseller\Backoffice\OperationsDesk::drift($nuxrActor, _get('radius') === '1') : null);
            $view = 'drift';
            break;
        case 'held':
            $ui->assign('nuxr_held', \Reseller\Backoffice\OperationsDesk::held($nuxrActor));
            $view = 'held';
            break;
        case 'renewals':
            $ui->assign('nuxr_renewals', \Reseller\Backoffice\OperationsDesk::renewals($nuxrActor, $nuxrPage()));
            $ui->assign('nuxr_base', Text::url('operations/renewals&'));
            $view = 'renewals';
            break;
        case 'overview':
        default:
            $ui->assign('nuxr_counts', \Reseller\Backoffice\OperationsDesk::overview($nuxrActor));
            $view = 'overview';
            break;
    }
} catch (\Reseller\Domain\DomainError $e) {
    r2(getUrl('dashboard'), 'e', Lang::T($e->getMessage()));
}
$ui->assign('_title', Lang::T('Operations'));
$ui->assign('nuxr_tab', $view);
$ui->display('admin/operations/' . $view . '.tpl');
