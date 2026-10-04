<?php
// Controller: only reachable through the dispatcher (index.php / system/api.php).
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP5A — reseller activation (minimal staff flow; owner decision A7). Every action is authorized centrally
 * (RouteGuard / RouteMap) and again inside Reseller\Activation (capability, customer scope, actor
 * revalidation inside the transaction). Mutations are POST + CSRF. No prices are taken from the browser.
 *
 *   create       GET   form / live server-calculated confirmation (WP6A OD-B: ActivationPreviewService)
 *   create-post  POST  create or replay (idempotency nonce) and run the activation; the confirmed quote's
 *                      fingerprint is re-checked server-side (ACTIVATION_PREVIEW_CHANGED, nothing written)
 *   view         GET   state and event timeline (WP6A OD-A: ActivationReadService, display fields only)
 *   list         GET   WP6A OD-C: activations visible to the actor (read only, paginated)
 *   reconcile    POST  Admin / SuperAdmin: read RADIUS back and settle or release
 *   rollback     POST  Admin / SuperAdmin: restore the previous RADIUS state and release
 *   reverse      POST  Admin / SuperAdmin (WP5B, OD-11): reverse a COMPLETED activation (reason required)
 */

_admin();
$ui->assign('_title', Lang::T('Activation'));
$ui->assign('_system_menu', 'customers');
$ui->assign('_admin', $admin);

$action = $routes['1'] ?? '';
$nuxrActor = \Reseller\Security\ActorContext::current();
// WP6A (OD-B): per-session server key of the confirmation fingerprint (never sent to the browser)
if (!isset($_SESSION['nuxr_activation_preview_key']) || !is_string($_SESSION['nuxr_activation_preview_key']) || strlen($_SESSION['nuxr_activation_preview_key']) !== 64) {
    $_SESSION['nuxr_activation_preview_key'] = bin2hex(random_bytes(32));
}
$nuxrPreviewKey = $_SESSION['nuxr_activation_preview_key'];

$nuxrFail = static function (\Reseller\Domain\DomainError $e, string $back): void {
    r2(getUrl($back), 'e', Lang::T($e->getMessage()));
};

switch ($action) {
    case 'create-post':
        // eligibility before any business work (also enforced centrally and inside ActivationService)
        if (!\Reseller\Activation\ActivationService::eligible($nuxrActor)) {
            \Reseller\Security\RouteGuard::deny(403, 'activation actor not eligible');
        }
        $customerId = _post('customer_id');
        $planId = _post('plan_id');
        try {
            $out = \Reseller\Activation\ActivationService::create($nuxrActor, $customerId, $planId, _post('expected_action'), _post('nonce'),
                \Reseller\Activation\PreviewBinding::of(_post('preview'), $nuxrPreviewKey));
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'activation/create&customer=' . (\Reseller\Security\DataScope::validId($customerId) ? (int) $customerId : 0)
                . (\Reseller\Security\DataScope::validId($planId) ? '&plan=' . (int) $planId : ''));
        }
        $type = $out['status'] === 'COMPLETED' ? 's' : ($out['status'] === 'FAILED' ? 'e' : 'w');
        r2(getUrl('activation/view/' . $out['reference']), $type, Lang::T('Activation') . ' ' . $out['reference'] . ': ' . $out['status']);
        break;

    case 'create':
        if (!\Reseller\Activation\ActivationService::eligible($nuxrActor)) {
            \Reseller\Security\RouteGuard::deny(403, 'activation actor not eligible');
        }
        $customerId = _get('customer');
        $planId = _get('plan');
        $preview = null;
        $plans = [];
        $customer = null;
        // WP6A: customer picker (scoped search; only customers this actor may activate) instead of a typed id
        $nuxrTerm = \Reseller\Domain\CustomerSearch::term(_get('q'));
        if (!\Reseller\Security\DataScope::validId($customerId) && $nuxrTerm !== null) {
            $ui->assign('nuxr_picked', \Reseller\Ui\Subscriber::activatable($nuxrActor, $nuxrTerm));
        }
        $ui->assign('nuxr_q', $nuxrTerm ?? '');
        try {
            // only the plans this actor can sell in this context (Sales: for the selected customer's owner)
            $plans = \Reseller\Activation\ActivationService::catalog($nuxrActor, \Reseller\Security\DataScope::validId($customerId) ? $customerId : null);
            if (\Reseller\Security\DataScope::validId($customerId)) {
                $customer = \Reseller\Security\DataScope::findCustomer($nuxrActor, \Reseller\Security\Capability::CUSTOMER_READ, $customerId);
            }
            if (\Reseller\Security\DataScope::validId($customerId) && \Reseller\Security\DataScope::validId($planId)) {
                $preview = \Reseller\Activation\ActivationPreviewService::quote($nuxrActor, $customerId, $planId, $nuxrPreviewKey);
            }
        } catch (\Reseller\Domain\DomainError $e) {
            if ($e->reason === 'FORBIDDEN') {
                \Reseller\Security\RouteGuard::deny(403, 'activation actor not eligible');
            }
            $ui->assign('nuxr_error', Lang::T($e->getMessage()));
        }
        $ui->assign('_title', Lang::T('Activate / Renew'));
        $ui->assign('nuxr_customer', $customer ? ['id' => (int) $customer['id'], 'username' => (string) $customer['username'], 'fullname' => (string) $customer['fullname']] : null);
        $ui->assign('nuxr_plan_cards', \Reseller\Ui\Subscriber::planCards($plans));
        $ui->assign('nuxr_plans', $plans);
        $ui->assign('nuxr_customer_id', \Reseller\Security\DataScope::validId($customerId) ? (int) $customerId : '');
        $ui->assign('nuxr_plan_id', \Reseller\Security\DataScope::validId($planId) ? (int) $planId : '');
        $ui->assign('nuxr_preview', $preview);
        $ui->assign('nuxr_nonce', bin2hex(random_bytes(16)));
        $ui->display('admin/activation/create.tpl');
        break;

    case 'list':
        $nuxrGroup = _get('group');
        $nuxrGroup = is_string($nuxrGroup) && isset(\Reseller\Activation\ActivationReadService::GROUPS[$nuxrGroup]) ? $nuxrGroup : '';
        try {
            $list = \Reseller\Activation\ActivationReadService::list($nuxrActor, ['group' => $nuxrGroup], (int) _get('page', 1));
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'dashboard');
        }
        $ui->assign('_title', Lang::T('Activations'));
        $ui->assign('nuxr_list', $list);
        $ui->assign('nuxr_group', $nuxrGroup);
        $ui->display('admin/activation/list.tpl');
        break;

    case 'view':
    default:
        // the bare controller and unknown actions are the read action (RouteMap '' / '*' => view, activation.read)
        try {
            $view = \Reseller\Activation\ActivationReadService::view($nuxrActor, $routes['2'] ?? '');
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, \Reseller\Security\PermissionService::can($nuxrActor, \Reseller\Security\Capability::ACTIVATION_CREATE) ? 'activation/create' : 'dashboard');
        }
        $ui->assign('_title', Lang::T('Activation') . ' ' . $view['activation']['reference']);
        $ui->assign('nuxr_activation', $view['activation']);
        $ui->assign('nuxr_events', $view['events']);
        $ui->assign('nuxr_settlement', $view['settlement']);
        $ui->assign('nuxr_progress', \Reseller\Ui\Format::progress($view['events'], (string) $view['activation']['status']));
        // WP6B slice 0: only the operator actions the accepted primitives accept in this state (no dead actions)
        $ui->assign('nuxr_actions', \Reseller\Activation\ActivationOperatorService::actionsFor($nuxrActor, $view['activation']['reference']));
        $ui->display('admin/activation/view.tpl');
        break;

    case 'reverse':
        $reference = (string) ($routes['2'] ?? '');
        $back = 'activation/view/' . (preg_match('/^ACT-[0-9A-F]{16}$/D', $reference) ? $reference : '');
        try {
            $row = \Reseller\Activation\ActivationReversalService::reverse($nuxrActor, $reference, _post('reason'));
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, $back);
        }
        r2(getUrl('activation/view/' . $row['reference']), 's', Lang::T('Activation') . ' ' . $row['reference'] . ': ' . $row['status']);
        break;

    case 'reconcile':
    case 'rollback':
        $reference = (string) ($routes['2'] ?? '');
        try {
            $actor = \Reseller\Activation\ReconciliationService::staffActor($nuxrActor);
            $row = $action === 'reconcile'
                ? \Reseller\Activation\ReconciliationService::reconcile($actor, $reference)
                : \Reseller\Activation\ReconciliationService::rollback($actor, $reference);
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'activation/view/' . (preg_match('/^ACT-[0-9A-F]{16}$/D', $reference) ? $reference : ''));
        }
        if ($row['error_code'] === \Reseller\Activation\ReconciliationService::AMBIGUOUS) {
            // never presented as "not applied": nothing was released, the operator decides
            r2(getUrl('activation/view/' . $row['reference']), 'w', Lang::T(\Reseller\Activation\ReconciliationService::AMBIGUOUS_MESSAGE));
        }
        r2(getUrl('activation/view/' . $row['reference']), 's', Lang::T('Activation') . ' ' . $row['reference'] . ': ' . $row['status']);
        break;
}
