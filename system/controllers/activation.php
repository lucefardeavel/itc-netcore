<?php
// Controller: only reachable through the dispatcher (index.php / system/api.php).
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP5A — reseller activation (minimal staff flow; owner decision A7). Every action is authorized centrally
 * (RouteGuard / RouteMap) and again inside Reseller\Activation (capability, customer scope, actor
 * revalidation inside the transaction). Mutations are POST + CSRF. No prices are taken from the browser.
 *
 *   create       GET   form / server-derived preview (customer, plan)
 *   create-post  POST  create or replay (idempotency nonce) and run the activation
 *   view         GET   state and event timeline
 *   reconcile    POST  Admin / SuperAdmin: read RADIUS back and settle or release
 *   rollback     POST  Admin / SuperAdmin: restore the previous RADIUS state and release
 */

_admin();
$ui->assign('_title', Lang::T('Activation'));
$ui->assign('_system_menu', 'customers');
$ui->assign('_admin', $admin);

$action = $routes['1'] ?? '';
$nuxrActor = \Reseller\Security\ActorContext::current();

$nuxrFail = static function (\Reseller\Domain\DomainError $e, string $back): void {
    r2(getUrl($back), 'e', Lang::T($e->getMessage()));
};

switch ($action) {
    case 'create-post':
        $customerId = _post('customer_id');
        try {
            $out = \Reseller\Activation\ActivationService::create($nuxrActor, $customerId, _post('plan_id'), _post('expected_action'), _post('nonce'));
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'activation/create&customer=' . (\Reseller\Security\DataScope::validId($customerId) ? (int) $customerId : 0));
        }
        $type = $out['status'] === 'COMPLETED' ? 's' : ($out['status'] === 'FAILED' ? 'e' : 'w');
        r2(getUrl('activation/view/' . $out['reference']), $type, Lang::T('Activation') . ' ' . $out['reference'] . ': ' . $out['status']);
        break;

    case 'create':
        $customerId = _get('customer');
        $planId = _get('plan');
        $preview = null;
        if (\Reseller\Security\DataScope::validId($customerId) && \Reseller\Security\DataScope::validId($planId)) {
            try {
                $preview = \Reseller\Activation\ActivationService::preview($nuxrActor, $customerId, $planId);
            } catch (\Reseller\Domain\DomainError $e) {
                $ui->assign('nuxr_error', Lang::T($e->getMessage()));
            }
        }
        $plans = ORM::for_table('tbl_plans')->where('device', 'Radius')->where('enabled', 1)->where_in('type', ['Hotspot', 'PPPOE'])->order_by_asc('name_plan')->find_array();
        $ui->assign('nuxr_plans', $plans);
        $ui->assign('nuxr_customer_id', \Reseller\Security\DataScope::validId($customerId) ? (int) $customerId : '');
        $ui->assign('nuxr_plan_id', \Reseller\Security\DataScope::validId($planId) ? (int) $planId : '');
        $ui->assign('nuxr_preview', $preview);
        $ui->assign('nuxr_nonce', bin2hex(random_bytes(16)));
        $ui->display('admin/activation/create.tpl');
        break;

    case 'view':
    default:
        // the bare controller and unknown actions are the read action (RouteMap '' / '*' => view, activation.read)
        try {
            $view = \Reseller\Activation\ActivationService::status($nuxrActor, $routes['2'] ?? '');
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, \Reseller\Security\PermissionService::can($nuxrActor, \Reseller\Security\Capability::ACTIVATION_CREATE) ? 'activation/create' : 'dashboard');
        }
        $ui->assign('nuxr_activation', $view['activation']);
        $ui->assign('nuxr_events', $view['events']);
        $ui->assign('nuxr_can_reconcile', \Reseller\Security\PermissionService::scopeOf($nuxrActor, \Reseller\Security\Capability::ACTIVATION_CREATE) === 'G');
        $ui->display('admin/activation/view.tpl');
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
        r2(getUrl('activation/view/' . $row['reference']), 's', Lang::T('Activation') . ' ' . $row['reference'] . ': ' . $row['status']);
        break;
}
