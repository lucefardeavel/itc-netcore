<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

_admin();
$ui->assign('_title', Lang::T('Recharge Account'));
$ui->assign('_system_menu', 'plan');

$action = $routes['1'];
$ui->assign('_admin', $admin);
// WP2: authoritative principal; object lookups are capability + DataScope in one query
$nuxrActor = \Reseller\Security\ActorContext::current();
$nuxrC = \Reseller\Security\Capability::class;

$appUrl = APP_URL;

$select2_customer = <<<EOT
<script>
document.addEventListener("DOMContentLoaded", function(event) {
    $('#personSelect').select2({
        theme: "bootstrap",
        ajax: {
            url: function(params) {
                if(params.term != undefined){
                    return '{$appUrl}/?_route=autoload/customer_select2&s='+params.term;
                }else{
                    return '{$appUrl}/?_route=autoload/customer_select2';
                }
            }
        }
    });
});
</script>
EOT;
getUrl('docs');
switch ($action) {
    case 'sync':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        set_time_limit(-1);
        // WP2 / OD-11 (E19a): no sync at all while ownership cannot be evaluated
        if (!\Reseller\Domain\SchemaReadiness::isReady()) {
            \Reseller\Domain\ServiceGate::refused('planSyncAll');
            r2(getUrl('plan/list'), 'e', \Reseller\Domain\ServiceGate::message());
        }
        $turs = ORM::for_table('tbl_user_recharges')->where('status', 'on')->find_many();
        $log = '';
        $router = '';
        $resellerSynced = [];
        foreach ($turs as $tur) {
            // WP5B (OD-10, E19a): reseller-owned customers are re-provisioned once each by LegacyReprovisionService
            if (\Reseller\Domain\LegacyReprovisionService::owned($tur['customer_id'])) {
                if (!isset($resellerSynced[(int) $tur['customer_id']])) {
                    $resellerSynced[(int) $tur['customer_id']] = true;
                    try {
                        \Reseller\Domain\LegacyReprovisionService::run(\Reseller\Security\ActorContext::current(), (int) $tur['customer_id'], \Reseller\Domain\LegacyReprovisionService::ADMIN_BULK_SYNC_ROW);
                        $log .= "DONE (reseller-managed) : " . htmlspecialchars((string) $tur['username']) . "<br>";
                    } catch (\Reseller\Domain\DomainError $e) {
                        $log .= "NOT SYNCED (reseller-managed) : " . htmlspecialchars((string) $tur['username']) . ' ' . htmlspecialchars($e->reason) . "<br>";
                    }
                }
                continue;
            }
            if (\Reseller\Domain\ServiceGate::isBlockedCustomerId($tur['customer_id'])) {
                \Reseller\Domain\ServiceGate::refused('planSyncAll', $tur['customer_id']);
                $log .= "SKIPPED (reseller-managed) : " . htmlspecialchars((string) $tur['username']) . "<br>";
                continue;
            }
            $p = ORM::for_table('tbl_plans')->findOne($tur['plan_id']);
            if ($p) {
                $c = ORM::for_table('tbl_customers')->findOne($tur['customer_id']);
                if ($c) {
                    $dvc = Package::getDevice($p);
                    if ($_app_stage != 'demo') {
                        if (file_exists($dvc)) {
                            require_once $dvc;
                            if (method_exists($dvc, 'sync_customer')) {
                                (new $p['device'])->sync_customer($c, $p);
                            } else {
                                (new $p['device'])->add_customer($c, $p);
                            }
                        } else {
                            new Exception(Lang::T("Devices Not Found"));
                        }
                    }
                    $log .= "DONE : $tur[username], $ptur[namebp], $tur[type], $tur[routers]<br>";
                } else {
                    $log .= "Customer NOT FOUND : $tur[username], $tur[namebp], $tur[type], $tur[routers]<br>";
                }
            } else {
                $log .= "PLAN NOT FOUND : $tur[username], $tur[namebp], $tur[type], $tur[routers]<br>";
            }
        }
        r2(getUrl('plan/list'), 's', $log);
    case 'recharge':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $ui->assign('xfooter', $select2_customer);
        if (isset($routes['2']) && !empty($routes['2'])) {
            $ui->assign('cust', ORM::for_table('tbl_customers')->find_one($routes['2']));
        }
        $usings = explode(',', $config['payment_usings']);
        $usings = array_filter(array_unique($usings));
        if (count($usings) == 0) {
            $usings[] = Lang::T('Cash');
        }
        $ui->assign('usings', $usings);
        run_hook('view_recharge'); #HOOK
        $ui->display('admin/plan/recharge.tpl');
        break;

    case 'recharge-confirm':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id_customer = _post('id_customer');
        $server = _post('server');
        $planId = _post('plan');
        $using = _post('using');
        // WP5B (OD-6, E1): a reseller-owned customer is charged through the reseller activation (wallet, pricing,
        // RADIUS): the legacy action writes nothing and the web flow continues at activation/create; recharge
        // "zero" / "balance" and the API are refused
        if (\Reseller\Domain\LegacyReprovisionService::owned($id_customer)) {
            if (\Reseller\Security\ActorContext::current()->channel === 'API' || in_array($using, ['zero', 'balance'], true)) {
                \Reseller\Domain\ServiceGate::guardCustomer($id_customer, 'planRechargeConfirm', 'plan/recharge');
            }
            r2(getUrl('activation/create&customer=' . (int) $id_customer . '&plan=' . (int) $planId), 'w', Lang::T('Reseller-managed customers are activated through the reseller activation.'));
        }
        // WP5B: "zero" is a free grant: only with service.recharge_free (checked again at recharge-post)
        if ($using == 'zero' && !\Reseller\Security\PermissionService::can(\Reseller\Security\ActorContext::current(), \Reseller\Security\Capability::SERVICE_RECHARGE_FREE)) {
            r2(getUrl('plan/recharge'), 'e', Lang::T('You do not have permission to access this page'));
        }
        $ui->assign('nuxr_nonce', bin2hex(random_bytes(16)));

        $msg = '';
        if ($id_customer == '' or $server == '' or $planId == '' or $using == '') {
            $msg .= Lang::T('All field is required') . '<br>';
        }

        if ($msg == '') {
            $gateway = 'Recharge';
            $channel = $admin['fullname'];
            $cust = User::_info($id_customer);
            $plan = ORM::for_table('tbl_plans')->find_one($planId);
            list($bills, $add_cost) = User::getBills($id_customer);
            $add_inv = User::getAttribute("Invoice", $id_customer);
            if (!empty($add_inv)) {
                $plan['price'] = $add_inv;
            }

            // Tax calculation start
            $tax_enable = isset($config['enable_tax']) ? $config['enable_tax'] : 'no';
            $tax_rate_setting = isset($config['tax_rate']) ? $config['tax_rate'] : null;
            $custom_tax_rate = isset($config['custom_tax_rate']) ? (float) $config['custom_tax_rate'] : null;

            if ($tax_rate_setting === 'custom') {
                $tax_rate = $custom_tax_rate;
            } else {
                $tax_rate = $tax_rate_setting;
            }

            if ($tax_enable === 'yes') {
                $tax = Package::tax($plan['price'], $tax_rate);
            } else {
                $tax = 0;
            }
            // Tax calculation stop
            $total_cost = $plan['price'] + $add_cost + $tax;

            if ($using == 'balance' && $config['enable_balance'] == 'yes') {
                if (!$cust) {
                    r2(getUrl('plan/recharge'), 'e', Lang::T('Customer not found'));
                }
                if (!$plan) {
                    r2(getUrl('plan/recharge'), 'e', Lang::T('Plan not found'));
                }
                if ($cust['balance'] < $total_cost) {
                    r2(getUrl('plan/recharge'), 'e', Lang::T('insufficient balance'));
                }
                $gateway = 'Recharge Balance';
            }
            if ($using == 'zero') {
                $zero = 1;
                $gateway = 'Recharge Zero';
            }
            $usings = explode(',', $config['payment_usings']);
            $usings = array_filter(array_unique($usings));
            if (count($usings) == 0) {
                $usings[] = Lang::T('Cash');
            }
            if ($tax_enable === 'yes') {
                $ui->assign('tax', $tax);
            }
            $ui->assign('usings', $usings);
            $ui->assign('bills', $bills);
            $ui->assign('add_cost', $add_cost);
            $ui->assign('cust', $cust);
            $ui->assign('gateway', $gateway);
            $ui->assign('channel', $channel);
            $ui->assign('server', $server);
            $ui->assign('using', $using);
            $ui->assign('plan', $plan);
            $ui->assign('add_inv', $add_inv);
            $ui->display('admin/plan/recharge-confirm.tpl');
        } else {
            r2(getUrl('plan/recharge'), 'e', $msg);
        }
        break;

    case 'recharge-post':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id_customer = _post('id_customer');
        $server = _post('server');
        $planId = _post('plan');
        $using = _post('using');
        $svoucher = _post('svoucher');
        // WP5B (OD-6, E1): a reseller-owned customer is charged through the reseller activation (wallet, pricing,
        // RADIUS): the legacy action writes nothing and the web flow continues at activation/create; recharge
        // "zero" / "balance" and the API are refused
        if (\Reseller\Domain\LegacyReprovisionService::owned($id_customer)) {
            if (\Reseller\Security\ActorContext::current()->channel === 'API' || in_array($using, ['zero', 'balance'], true)) {
                \Reseller\Domain\ServiceGate::guardCustomer($id_customer, 'planRechargePost', 'plan/recharge');
            }
            r2(getUrl('activation/create&customer=' . (int) $id_customer . '&plan=' . (int) $planId), 'w', Lang::T('Reseller-managed customers are activated through the reseller activation.'));
        }
        \Reseller\Domain\ServiceGate::guardCustomer($id_customer, 'planRechargePost', 'plan/recharge');
        if ($using == 'zero' && !\Reseller\Security\PermissionService::can(\Reseller\Security\ActorContext::current(), \Reseller\Security\Capability::SERVICE_RECHARGE_FREE)) {
            r2(getUrl('plan/recharge'), 'e', Lang::T('You do not have permission to access this page'));
        }

        $plan = ORM::for_table('tbl_plans')->find_one($planId);

        if (!empty(App::getVoucherValue($svoucher))) {
            $username = App::getVoucherValue($svoucher);
            $in = ORM::for_table('tbl_transactions')->where('username', $username)->order_by_desc('id')->find_one();
            Package::createInvoice($in);
            $ui->display('admin/plan/invoice.tpl');
            die();
        }

        $msg = '';
        if ($id_customer == '' or $server == '' or $planId == '' or $using == '') {
            $msg .= Lang::T('All field is required') . '<br>';
        }

        if ($msg == '') {
            $gateway = ucwords($using);
            $channel = $admin['fullname'];
            $cust = User::_info($id_customer);
            list($bills, $add_cost) = User::getBills($id_customer);

            // Tax calculation start
            $tax_enable = isset($config['enable_tax']) ? $config['enable_tax'] : 'no';
            $tax_rate_setting = isset($config['tax_rate']) ? $config['tax_rate'] : null;
            $custom_tax_rate = isset($config['custom_tax_rate']) ? (float) $config['custom_tax_rate'] : null;

            if ($tax_rate_setting === 'custom') {
                $tax_rate = $custom_tax_rate;
            } else {
                $tax_rate = $tax_rate_setting;
            }

            if ($tax_enable === 'yes') {
                $tax = Package::tax($plan['price'], $tax_rate);
            } else {
                $tax = 0;
            }
            // Tax calculation stop
            $total_cost = $plan['price'] + $add_cost + $tax;

            if ($using == 'balance') {
                //$plan = ORM::for_table('tbl_plans')->find_one($planId);
                if ($config['enable_balance'] != 'yes') {
                    r2(getUrl('plan/recharge'), 'e', Lang::T("Balance not enabled"));
                }
                if (!$cust) {
                    r2(getUrl('plan/recharge'), 'e', Lang::T('Customer not found'));
                }
                if (!$plan) {
                    r2(getUrl('plan/recharge'), 'e', Lang::T('Plan not found'));
                }
                $gateway = 'Recharge Balance';
            }
            if ($using == 'zero') {
                $add_cost = 0;
                $zero = 1;
                $gateway = 'Recharge Zero';
            }
            $nuxrGranted = false;
            if ($using == 'balance') {
                // WP5B §7a.2 (E1, unassigned): the confirmation total debited exactly once per request nonce under the
                // customer row lock, HELD before the grant; replays never debit or grant again (no Balance::*)
                try {
                    $nuxrAdmin = \Reseller\Domain\LegacyCustomerBalanceService::purchase([
                        'kind' => 'admin', 'key' => \Reseller\Domain\LegacyCustomerBalanceService::key('admin', (int) $admin['id'], (string) _post('nuxr_nonce')),
                        'payer_id' => (int) $cust['id'], 'target_id' => (int) $cust['id'], 'plan_id' => (int) $planId, 'routers' => (string) $server,
                        'amount' => $total_cost, 'grant' => [$gateway, $channel],
                    ]);
                } catch (\Reseller\Domain\DomainError $e) {
                    r2(getUrl('plan/recharge'), 'e', $e->reason === 'INSUFFICIENT_BALANCE' ? Lang::T('insufficient balance') : Lang::T($e->getMessage()));
                }
                if ($nuxrAdmin['status'] === 'HELD') {
                    r2(getUrl('plan/recharge'), 'w', Lang::T('The recharge is held for review; the amount was debited and is not lost') . ' (#' . $nuxrAdmin['journal_id'] . ')');
                }
                $nuxrGranted = $nuxrAdmin['status'] === 'COMPLETED';
            } else {
                $nuxrGranted = (bool) Package::rechargeUser($id_customer, $server, $planId, $gateway, $channel);
            }
            if ($nuxrGranted) {
                $in = ORM::for_table('tbl_transactions')->where('username', $cust['username'])->order_by_desc('id')->find_one();
                Package::createInvoice($in);
                App::setVoucher($svoucher, $cust['username']);
                $ui->display('admin/plan/invoice.tpl');
                _log('[' . $admin['username'] . ']: ' . 'Recharge ' . $cust['username'] . ' [' . $in['plan_name'] . '][' . Lang::moneyFormat($in['price']) . ']', $admin['user_type'], $admin['id']);
            } else {
                r2(getUrl('plan/recharge'), 'e', "Failed to recharge account");
            }
        } else {
            r2(getUrl('plan/recharge'), 'e', $msg);
        }
        break;

    case 'view':
        $id = $routes['2'];
        // WP2 / SEC-06: invoice visible only if its customer (by user_id) is in scope; G for user_id 0
        $in = false;
        if (\Reseller\Security\DataScope::validId($id)) {
            $in = \Reseller\Security\DataScope::scopeByCustomerColumn(ORM::for_table('tbl_transactions'), $nuxrActor, $nuxrC::INVOICE_READ, 'tbl_transactions.user_id')
                ->where('tbl_transactions.id', (int) $id)->find_one();
        }
        if (!$in) {
            r2(getUrl('plan/list'), 'e', Lang::T('Data Not Found'));
        }
        $id = (int) $in['id'];
        $ui->assign('in', $in);
        if (!empty($routes['3']) && $routes['3'] == 'send') {
            \Reseller\Security\PermissionService::require($nuxrActor, $nuxrC::INVOICE_SEND);
            $c = \Reseller\Security\DataScope::findCustomer($nuxrActor, $nuxrC::INVOICE_SEND, $in['user_id']);
            if ($c) {
                Message::sendInvoice($c, $in);
                r2(getUrl('plan/view/') . $id, 's', "Success send to customer");
            }
            r2(getUrl('plan/view/') . $id, 'd', "Customer not found");
        }
        Package::createInvoice($in);
        $UPLOAD_URL_PATH = str_replace($root_path, '', $UPLOAD_PATH);
        $logo = '';
        if (file_exists($UPLOAD_PATH . DIRECTORY_SEPARATOR . 'logo.png')) {
            $logo = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'logo.png';
            $imgsize = getimagesize($logo);
            $width = $imgsize[0];
            $height = $imgsize[1];
            $ui->assign('wlogo', $width);
            $ui->assign('hlogo', $height);
        }

        $ui->assign('public_url', getUrl("voucher/invoice/$id/" . (\Reseller\Security\AppKey::voucherInvoiceSignature($id) ?? '')));
        $ui->assign('logo', $logo);
        $ui->assign('_title', Lang::T('View Invoice'));
        $ui->display('admin/plan/invoice.tpl');
        break;


    case 'print':
        $content = $_POST['content'];
        if (!empty($content)) {
            if ($_POST['nux'] == 'print') {
                //header("Location: nux://print?text=".urlencode($content));
                $ui->assign('nuxprint', "nux://print?text=" . urlencode($content));
            }
            $ui->assign('content', $content);
        } else {
            $id = _post('id');
            if (empty($id)) {
                $id = $routes['2'];
            }
            $d = false;
            if (\Reseller\Security\DataScope::validId($id)) {
                $d = \Reseller\Security\DataScope::scopeByCustomerColumn(ORM::for_table('tbl_transactions'), $nuxrActor, $nuxrC::INVOICE_READ, 'tbl_transactions.user_id')
                    ->where('tbl_transactions.id', (int) $id)->find_one();
            }
            if (!$d) {
                r2(getUrl('plan/list'), 'e', Lang::T('Data Not Found'));
            }
            $ui->assign('in', $d);
            $ui->assign('date', Lang::dateAndTimeFormat($d['recharged_on'], $d['recharged_time']));
        }
        run_hook('print_invoice'); #HOOK
        $ui->display('admin/plan/invoice-print.tpl');
        break;

    case 'edit':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id = $routes['2'];
        $d = ORM::for_table('tbl_user_recharges')->find_one($id);
        if ($d) {
            $ui->assign('d', $d);
            $p = ORM::for_table('tbl_plans')->find_one($d['plan_id']);
            if (in_array($admin['user_type'], array('SuperAdmin', 'Admin'))) {
                $ps = ORM::for_table('tbl_plans')
                    ->where('type', $p['type'])
                    ->where('is_radius', $p['is_radius'])
                    ->find_many();
            } else {
                $ps = ORM::for_table('tbl_plans')
                    ->where("enabled", 1)
                    ->where('is_radius', $p['is_radius'])
                    ->where('type', $p['type'])
                    ->find_many();
            }
            $ui->assign('p', $ps);
            run_hook('view_edit_customer_plan'); #HOOK
            $ui->assign('_title', Lang::T('Edit Plan'));
            $ui->display('admin/plan/edit.tpl');
        } else {
            r2(getUrl('plan/list'), 'e', Lang::T('Account Not Found'));
        }
        break;

    case 'delete':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id = $routes['2'];
        $d = ORM::for_table('tbl_user_recharges')->find_one($id);
        if ($d) {
            // WP8 A5 (R-WP8-CARRY-01): a reseller-owned service ends only by reversal or expiry, never here
            \Reseller\Domain\ServiceGate::guardCustomer($d['customer_id'], 'planDelete', 'plan/list');
            // WP8 A5 (D-A5-1): a zero / missing customer id would make User::_info() fall back to the session customer
            // (Admin "login as customer"); the device target must be exactly the row's existing customer
            if ((int) $d['customer_id'] <= 0 || !ORM::for_table('tbl_customers')->find_one((int) $d['customer_id'])) {
                \Reseller\Domain\ServiceGate::refused('planDeleteIdentity', $d['customer_id']);
                r2(getUrl('plan/list'), 'e', Lang::T('Account Not Found'));
            }
            run_hook('delete_customer_active_plan'); #HOOK
            $p = ORM::for_table('tbl_plans')->find_one($d['plan_id']);
            $c = User::_info($d['customer_id']);
            if (!$c || (int) $c['id'] !== (int) $d['customer_id']) {
                \Reseller\Domain\ServiceGate::refused('planDeleteIdentity', $d['customer_id']);
                r2(getUrl('plan/list'), 'e', Lang::T('Account Not Found'));
            }
            $dvc = Package::getDevice($p);
            if ($_app_stage != 'demo') {
                if (file_exists($dvc)) {
                    require_once $dvc;
                    (new $p['device'])->remove_customer($c, $p);
                } else {
                    new Exception(Lang::T("Devices Not Found"));
                }
            }
            $d->delete();
            _log('[' . $admin['username'] . ']: ' . 'Delete Plan for Customer ' . $c['username'] . '  [' . $in['plan_name'] . '][' . Lang::moneyFormat($in['price']) . ']', $admin['user_type'], $admin['id']);
            r2(getUrl('plan/list'), 's', Lang::T('Data Deleted Successfully'));
        }
        break;

    case 'edit-post':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id_plan = _post('id_plan');
        $recharged_on = _post('recharged_on');
        $expiration = _post('expiration');
        $time = _post('time');

        $id = _post('id');
        $d = ORM::for_table('tbl_user_recharges')->find_one($id);
        if ($d) {
        } else {
            $msg .= Lang::T('Data Not Found') . '<br>';
        }
        $oldPlanID = $d['plan_id'];
        $newPlan = ORM::for_table('tbl_plans')->where('id', $id_plan)->find_one();
        if ($newPlan) {
        } else {
            $msg .= ' Plan Not Found<br>';
        }
        if ($msg == '') {
            // WP2 / OD-11 (E5): editing an active plan can re-provision/extend service
            \Reseller\Domain\ServiceGate::guardCustomer($d['customer_id'], 'planEditPost', 'plan/list');
            run_hook('edit_customer_plan'); #HOOK
            $nuxrExpiryChanged = $d['expiration'] != $expiration || $d['time'] != $time;
            // Lab finding F6-2: for a customer that is not Active, an edit that would provision (turn the recharge on,
            // change the plan of a live recharge, or move the expiry of a live recharge, which syncs RADIUS) is refused
            // before anything is saved; data corrections that provision nothing stay possible
            $nuxrEditCustomer = (int) $d['customer_id'] > 0 ? ORM::for_table('tbl_customers')->findOne($d['customer_id']) : null;
            if ($nuxrEditCustomer && $nuxrEditCustomer['status'] != 'Active') {
                $nuxrTurnsOn = $d['status'] == 'off' && strtotime($expiration . ' ' . $time) > time();
                $nuxrLive = $d['status'] == 'on' && ($oldPlanID != $id_plan || $nuxrExpiryChanged);
                if ($nuxrTurnsOn || $nuxrLive) {
                    r2(getUrl('plan/edit/') . $id, 'e', Lang::T('This account status') . ' : ' . Lang::T($nuxrEditCustomer['status']));
                }
            }
            $d->expiration = $expiration;
            $d->time = $time;
            if ($d['status'] == 'off') {
                if (strtotime($expiration . ' ' . $time) > time()) {
                    $d->status = 'on';
                }
            }
            // plan different then do something
            if ($oldPlanID != $id_plan) {
                $d->plan_id = $newPlan['id'];
                $d->namebp = $newPlan['name_plan'];
            }
            // WP4: save first, so the device provisions the saved billing expiry
            $d->save();
            if ($oldPlanID != $id_plan) {
                $customer = User::_info($d['customer_id']);
                //remove from old plan
                if ($d['status'] == 'on') {
                    $p = ORM::for_table('tbl_plans')->find_one($oldPlanID);
                    $dvc = Package::getDevice($p);
                    if ($_app_stage != 'demo') {
                        if (file_exists($dvc)) {
                            require_once $dvc;
                            $p['plan_expired'] = 0;
                            (new $p['device'])->remove_customer($customer, $p);
                        } else {
                            new Exception(Lang::T("Devices Not Found"));
                        }
                    }
                    //add new plan
                    $dvc = Package::getDevice($newPlan);
                    if ($_app_stage != 'demo') {
                        if (file_exists($dvc)) {
                            require_once $dvc;
                            (new $newPlan['device'])->add_customer($customer, $newPlan);
                        } else {
                            new Exception(Lang::T("Devices Not Found"));
                        }
                    }
                }
            } elseif ($nuxrExpiryChanged && $d['status'] == 'on' && $newPlan['device'] == 'Radius' && $_app_stage != 'demo') {
                // WP4: the RADIUS Expiration follows the edited billing expiry (it never did before)
                $dvc = Package::getDevice($newPlan);
                if (file_exists($dvc)) {
                    require_once $dvc;
                    (new $newPlan['device'])->sync_customer(User::_info($d['customer_id']), $newPlan);
                }
            }
            _log('[' . $admin['username'] . ']: ' . 'Edit Plan for Customer ' . $d['username'] . ' to [' . $d['namebp'] . '][' . Lang::moneyFormat($p['price']) . ']', $admin['user_type'], $admin['id']);
            \Reseller\Domain\ServiceAudit::record('service.plan_edit', $d['customer_id'], ['recharge_id' => (int) $d['id'], 'plan_id' => (int) $d['plan_id'], 'expiration' => (string) $d['expiration']]); // WP5B OD-7
            r2(getUrl('plan/list'), 's', Lang::T('Data Updated Successfully'));
        } else {
            r2(getUrl('plan/edit/') . $id, 'e', $msg);
        }
        break;

    case 'voucher':
        $ui->assign('_title', Lang::T('Voucher Cards'));
        $search = _req('search');
        $router = _req('router');
        $customer = _req('customer');
        $plan = _req('plan');
        $status = _req('status');
        $ui->assign('router', $router);
        $ui->assign('customer', $customer);
        $ui->assign('status', $status);
        $ui->assign('plan', $plan);
        $ui->assign('_system_menu', 'cards');

        $query = ORM::for_table('tbl_plans')
            ->inner_join('tbl_voucher', ['tbl_plans.id', '=', 'tbl_voucher.id_plan']);
        // WP2 / C8: Agent sees vouchers generated in its subtree (closure), Sales its own; G all
        \Reseller\Security\DataScope::scopeVouchers($query, $nuxrActor, $nuxrC::VOUCHER_READ, 'tbl_voucher.generated_by');

        if (!empty($router)) {
            $query->where('tbl_voucher.routers', $router);
        }

        if ($status == '1' || $status == '0') {
            $query->where('tbl_voucher.status', $status);
        }

        if (!empty($plan)) {
            $query->where('tbl_voucher.id_plan', $plan);
        }

        if (!empty($customer)) {
            $query->where('tbl_voucher.user', $customer);
        }

        $append_url = "&search=" . urlencode($search) . "&router=" . urlencode($router) . "&customer=" . urlencode($customer) . "&plan=" . urlencode($plan) . "&status=" . urlencode($status);

        // option customers
        $ui->assign('customers', \Reseller\Security\DataScope::scopeVouchers(ORM::for_table('tbl_voucher'), $nuxrActor, $nuxrC::VOUCHER_READ)->distinct()->select("user")->whereNotEqual("user", '0')->findArray());
        // option plans
        $plns = \Reseller\Security\DataScope::scopeVouchers(ORM::for_table('tbl_voucher'), $nuxrActor, $nuxrC::VOUCHER_READ)->distinct()->select("id_plan")->findArray();
        if (count($plns) > 0) {
            $ui->assign('plans', ORM::for_table('tbl_plans')->selects(["id", 'name_plan'])->where_in('id', array_column($plns, 'id_plan'))->findArray());
        } else {
            $ui->assign('plans', []);
        }
        $ui->assign('routers', array_column(\Reseller\Security\DataScope::scopeVouchers(ORM::for_table('tbl_voucher'), $nuxrActor, $nuxrC::VOUCHER_READ)->distinct()->select("routers")->findArray(), 'routers'));

        if ($search != '') {
            $query->where_like('tbl_voucher.code', '%' . $search . '%');
        }
        $d = Paginator::findMany($query, ["search" => $search], 10, $append_url);
        // WP6C (fix round 1, owner amendment A): a voucher's customer is linked only when the accepted customer scope
        // (DataScope, the same lookup customers/viewu uses) finds that customer for the actor; otherwise plain text.
        // The generator identity is always plain text (staff visibility is not proven by the voucher scope).
        $nuxrCustomerView = [];
        foreach ($d as $k) {
            $u = (string) $k['user'];
            if ($u !== '' && $u !== '0' && !isset($nuxrCustomerView[$u])) {
                $nuxrCustomerView[$u] = \Reseller\Security\DataScope::findCustomerByUsername($nuxrActor, $nuxrC::CUSTOMER_READ, $u) !== null;
            }
        }
        $ui->assign('nuxr_customer_view', $nuxrCustomerView);
        // extract admin
        $admins = [];
        foreach ($d as $k) {
            if (!empty($k['generated_by'])) {
                $admins[] = $k['generated_by'];
            }
        }
        if (count($admins) > 0) {
            $adms = ORM::for_table('tbl_users')->where_in('id', $admins)->find_many();
            unset($admins);
            foreach ($adms as $adm) {
                $tipe = $adm['user_type'];
                if ($tipe == 'Sales') {
                    $tipe = ' [S]';
                } else if ($tipe == 'Agent') {
                    $tipe = ' [A]';
                } else {
                    $tipe = '';
                }
                $admins[$adm['id']] = $adm['fullname'] . $tipe;
            }
        }

        $ui->assign('admins', $admins);
        $ui->assign('d', $d);
        $ui->assign('search', $search);
        $ui->assign('page', $page);
        run_hook('view_list_voucher'); #HOOK
        $ui->display('admin/voucher/list.tpl');
        break;

    case 'add-voucher':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $ui->assign('_title', Lang::T('Add Vouchers'));
        $c = ORM::for_table('tbl_customers')->find_many();
        $ui->assign('c', $c);
        $p = ORM::for_table('tbl_plans')->where('enabled', '1')->find_many();
        $ui->assign('p', $p);
        $r = ORM::for_table('tbl_routers')->where('enabled', '1')->find_many();
        $ui->assign('r', $r);
        run_hook('view_add_voucher'); #HOOK
        $ui->display('admin/voucher/add.tpl');
        break;

    case 'remove-voucher':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $time3months = strtotime('-3 months');
        $d = ORM::for_table('tbl_voucher')->where_equal('status', '1')
            ->where_raw("UNIX_TIMESTAMP(used_date) < $time3months")
            ->findMany();
        if ($d) {
            $jml = 0;
            foreach ($d as $v) {
                if (!ORM::for_table('tbl_user_recharges')->where_equal("method", 'Voucher - ' . $v['code'])->findOne()) {
                    $v->delete();
                    $jml++;
                }
            }
            \Reseller\Domain\VoucherLogin::pruneOrphans();
            r2(getUrl('plan/voucher'), 's', "$jml " . Lang::T('Data Deleted Successfully'));
        }
    case 'print-voucher':
        $printFormat = _post('format') === 'a4' ? 'a4' : 'thermal80';
        // UX 2026-11-09: card data for the 80 mm thermal print (plan-joined voucher rows: id / type are the voucher's)
        $nuxrCard = static function ($vs): array {
            $unit = (string) $vs['validity_unit'];
            return [
                'id' => (int) $vs['id'],
                'code' => (string) $vs['code'],
                'kind' => stripos((string) $vs['type'], 'hotspot') !== false ? 'hotspot' : 'activation',
                'plan' => (string) $vs['name_plan'],
                'price' => Lang::moneyFormat($vs['price']),
                'validity' => ((int) $vs['validity'] > 0 && $unit !== '') ? \Reseller\Ui\Format::validity((int) $vs['validity'], $unit) : '',
            ];
        };
        $from_id = _post('from_id');
        $planid = _post('planid');
        $pagebreak = _post('pagebreak');
        $limit = _post('limit');
        $vpl = _post('vpl');
        $selected_datetime = _post('selected_datetime');
        if (empty($vpl)) {
            $vpl = 3;
        }
        if ($pagebreak < 1)
            $pagebreak = 12;

        if ($limit < 1)
            $limit = $pagebreak * 2;
        if (empty($from_id)) {
            $from_id = 0;
        }

        if ($from_id > 0 && $planid > 0) {
            $v = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where('tbl_plans.id', $planid)
                ->where_gt('tbl_voucher.id', $from_id)
                ->limit($limit);
            $vc = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where('tbl_plans.id', $planid)
                ->where_gt('tbl_voucher.id', $from_id);
        } else if ($from_id == 0 && $planid > 0) {
            $v = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where('tbl_plans.id', $planid)
                ->limit($limit);
            $vc = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where('tbl_plans.id', $planid);
        } else if ($from_id > 0 && $planid == 0) {
            $v = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where_gt('tbl_voucher.id', $from_id)
                ->limit($limit);
            $vc = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where_gt('tbl_voucher.id', $from_id);
        } else if ($from_id > 0 && $planid == 0 && $selected_datetime != '') {
            $v = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where_raw("DATE(created_at) = ?", [$selected_datetime])
                ->where_gt('tbl_voucher.id', $from_id)
                ->limit($limit);
            $vc = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where_gt('tbl_voucher.id', $from_id);
        } else {
            $v = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->limit($limit);
            $vc = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0');
        }
        if (!empty($selected_datetime)) {
            $v = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0')
                ->where('tbl_voucher.created_at', $selected_datetime)
                ->limit($limit);
            $vc = ORM::for_table('tbl_plans')
                ->left_outer_join('tbl_voucher', array('tbl_plans.id', '=', 'tbl_voucher.id_plan'))
                ->where('tbl_voucher.status', '0');
        }
        // WP2 / C8: closure-based voucher scope (never the legacy root field)
        $v = \Reseller\Security\DataScope::scopeVouchers($v, $nuxrActor, $nuxrC::VOUCHER_READ)->find_many();
        $vc = \Reseller\Security\DataScope::scopeVouchers($vc, $nuxrActor, $nuxrC::VOUCHER_READ)->count();
        $template = file_get_contents("pages/Voucher.html");
        $template = str_replace('[[company_name]]', $config['CompanyName'], $template);

        $ui->assign('_title', Lang::T('Hotspot Voucher'));
        $ui->assign('from_id', $from_id);
        $ui->assign('vpl', $vpl);
        $ui->assign('pagebreak', $pagebreak);

        $plans = ORM::for_table('tbl_plans')->find_many();
        $ui->assign('plans', $plans);
        $ui->assign('limit', $limit);
        $ui->assign('planid', $planid);

        $createdate = \Reseller\Security\DataScope::scopeVouchers(ORM::for_table('tbl_voucher'), $nuxrActor, $nuxrC::VOUCHER_READ)
            ->select_expr(
                "CASE WHEN DATE(created_at) = CURDATE() THEN 'Today' ELSE DATE(created_at) END",
                'created_datetime'
            )
            // portable zero-date exclusion (MySQL 8.0 strict mode rejects comparing a TIMESTAMP with '0')
            ->where_gt('created_at', '1000-01-01 00:00:00')
            ->select_expr('COUNT(*)', 'voucher_count')
            ->group_by('created_datetime')
            ->order_by_desc('created_datetime')
            ->find_array();

        $ui->assign('createdate', $createdate);

        if ($printFormat === 'thermal80') {
            $ui->assign('_title', Lang::T('Print vouchers'));
            $nuxrCards = array_map($nuxrCard, is_array($v) ? $v : iterator_to_array($v));
            $nuxrPw = \Reseller\Domain\VoucherLogin::passwordsFor(array_column($nuxrCards, 'id'));
            foreach ($nuxrCards as &$nuxrC) { $nuxrC['password'] = $nuxrPw[$nuxrC['id']] ?? ''; }
            unset($nuxrC);
            $ui->assign('cards', $nuxrCards);
            $ui->assign('vc', $vc);
            $ui->assign('selected_datetime', $selected_datetime);
            run_hook('view_print_voucher'); #HOOK
            $ui->display('admin/print/voucher-80mm.tpl');
            break;
        }

        $voucher = [];
        $n = 1;
        $nuxrPw = \Reseller\Domain\VoucherLogin::passwordsFor(array_map(static fn($r) => (int) $r['id'], is_array($v) ? $v : iterator_to_array($v)));
        foreach ($v as $vs) {
            $temp = $template;
            $temp = str_replace('[[qrcode]]', '<img src="qrcode/?data=' . $vs['code'] . '">', $temp);
            $temp = str_replace('[[price]]', Lang::moneyFormat($vs['price']), $temp);
            $temp = str_replace('[[voucher_code]]', htmlspecialchars($vs['code'] . (isset($nuxrPw[(int) $vs['id']]) ? ' / ' . $nuxrPw[(int) $vs['id']] : ''), ENT_QUOTES), $temp);
            $temp = str_replace('[[plan]]', $vs['name_plan'], $temp);
            $temp = str_replace('[[counter]]', $n, $temp);
            $voucher[] = $temp;
            $n++;
        }

        $ui->assign('voucher', $voucher);
        $ui->assign('vc', $vc);
        $ui->assign('selected_datetime', $selected_datetime);

        //for counting pagebreak
        $ui->assign('jml', 0);
        run_hook('view_print_voucher'); #HOOK
        $ui->display('admin/print/voucher.tpl');
        break;
    case 'voucher-post':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        if ($_app_stage == 'Demo') {
            r2(getUrl('plan/add-voucher/'), 'e', 'You cannot perform this action in Demo mode');
        }

        $type = _post('type');
        $plan = _post('plan');
        $voucher_format = _post('voucher_format');
        $prefix = _post('prefix');
        $server = _post('server');
        $numbervoucher = _post('numbervoucher');
        $lengthcode = _post('lengthcode');
        $printNow = _post('print_now', 'no');
        $printFormat = _post('print_format') === 'a4' ? 'a4' : 'thermal80';
        // R0008: hotspot cards are "code only" (default) or "user name and password"
        $loginStyle = $type === 'Hotspot' && _post('login_style') === \Reseller\Domain\VoucherLogin::STYLE_USERPASS
            ? \Reseller\Domain\VoucherLogin::STYLE_USERPASS : \Reseller\Domain\VoucherLogin::STYLE_CODE;
        $passwordLength = (int) _post('password_length', '6');
        $voucherPerPage = _post('voucher_per_page', '36');

        $msg = '';
        if (empty($type) || empty($plan) || empty($server) || empty($numbervoucher) || empty($lengthcode)) {
            $msg .= Lang::T('All fields are required') . '<br>';
        }
        if (!Validator::UnsignedNumber($numbervoucher)) {
            $msg .= 'The Number of Vouchers must be a number' . '<br>';
        }
        if (!Validator::UnsignedNumber($lengthcode)) {
            $msg .= 'The Length Code must be a number' . '<br>';
        }
        if ($loginStyle === \Reseller\Domain\VoucherLogin::STYLE_USERPASS && !\Reseller\Domain\VoucherLogin::available()) {
            $msg .= Lang::T('Cards with a user name and password need the database update (migrate.php).') . '<br>';
        }
        if ($loginStyle === \Reseller\Domain\VoucherLogin::STYLE_USERPASS
            && ($passwordLength < \Reseller\Domain\VoucherLogin::MIN_LENGTH || $passwordLength > \Reseller\Domain\VoucherLogin::MAX_LENGTH)) {
            $msg .= Lang::T('The card password must be 4 to 12 digits.') . '<br>';
        }

        if ($msg == '') {
            // Update or create voucher prefix
            if (!empty($prefix)) {
                $d = ORM::for_table('tbl_appconfig')->where('setting', 'voucher_prefix')->find_one();
                if ($d) {
                    $d->value = $prefix;
                    $d->save();
                } else {
                    $d = ORM::for_table('tbl_appconfig')->create();
                    $d->setting = 'voucher_prefix';
                    $d->value = $prefix;
                    $d->save();
                }
            }

            run_hook('create_voucher'); // HOOK
            $vouchers = [];
            $newVoucherIds = [];

            if ($voucher_format == 'numbers') {
                if ($lengthcode < 6) {
                    $msg .= 'The Length Code must be more than 6 for numbers' . '<br>';
                }
                $vouchers = generateUniqueNumericVouchers($numbervoucher, $lengthcode);
            } else {
                for ($i = 0; $i < $numbervoucher; $i++) {
                    // WP5B (E6): CSPRNG codes
                    $code = strtoupper(substr(bin2hex(random_bytes(16)), 0, $lengthcode));
                    if ($voucher_format == 'low') {
                        $code = strtolower($code);
                    } else if ($voucher_format == 'rand') {
                        $code = implode('', array_map(static fn(string $ch): string => random_int(0, 1) ? strtolower($ch) : $ch, str_split($code)));
                    }
                    $vouchers[] = $code;
                }
            }
            // WP5B (E6): never a duplicate of an existing code or within the batch (regenerated with the CSPRNG)
            foreach ($vouchers as $i => $code) {
                for ($try = 0; $try < 20 && (count(array_keys($vouchers, $code, true)) > 1
                    || ORM::for_table('tbl_voucher')->where_raw('BINARY code = ?', [$prefix . $code])->count() > 0); $try++) {
                    $code = $voucher_format == 'numbers'
                        ? implode('', array_map(static fn(): string => (string) random_int(0, 9), range(1, max(1, (int) $lengthcode))))
                        : strtoupper(substr(bin2hex(random_bytes(16)), 0, $lengthcode));
                    $vouchers[$i] = $code;
                }
            }
            // WP9-A1 / U0 (OBS-W9-V1, P7): a voucher code is also a RADIUS identity (voucher-only redemption), so a code never
            // equals an identity in use (canonical RADIUS key: any customer's username / PPPoE username, a live voucher
            // identity, a RADIUS key with policy rows). Colliding codes are regenerated with the CSPRNG (bounded, unique
            // within the batch and among vouchers); when a collision remains or the check cannot run, no voucher of the
            // batch is created. Unused codes are not identities, so this best-effort check takes no identity lock: a later
            // collision is refused at redemption (P6) under the lock.
            for ($round = 0; ; $round++) {
                $taken = \Reseller\Domain\CustomerIdentity::conflicts(ORM::get_db(), array_map(static fn($c): string => $prefix . $c, $vouchers));
                if ($taken === null || $taken === [] || $round >= 20) {
                    break;
                }
                foreach ($vouchers as $i => $code) {
                    if (!isset($taken[trim($prefix . $code)])) {
                        continue;
                    }
                    for ($try = 0; $try < 20 && ($try === 0 || count(array_keys($vouchers, $code, true)) > 1
                        || ORM::for_table('tbl_voucher')->where_raw('BINARY code = ?', [$prefix . $code])->count() > 0); $try++) {
                        $code = $voucher_format == 'numbers'
                            ? implode('', array_map(static fn(): string => (string) random_int(0, 9), range(1, max(1, (int) $lengthcode))))
                            : strtoupper(substr(bin2hex(random_bytes(16)), 0, $lengthcode));
                        $vouchers[$i] = $code;
                    }
                }
            }
            if ($taken !== []) {
                r2(getUrl('plan/add-voucher/'), 'e', 'Voucher codes could not be generated without matching an existing username; no voucher was created');
            }

            foreach ($vouchers as $code) {
                $d = ORM::for_table('tbl_voucher')->create();
                $d->type = $type;
                $d->routers = $server;
                $d->id_plan = $plan;
                $d->code = "$prefix$code";
                $d->user = '0';
                $d->status = '0';
                $d->generated_by = $admin['id'];
                $d->save();
                if ($loginStyle === \Reseller\Domain\VoucherLogin::STYLE_USERPASS) {
                    try {
                        \Reseller\Domain\VoucherLogin::create((int) $d->id(), \Reseller\Domain\VoucherLogin::generate($passwordLength));
                    } catch (\Throwable $e) {
                        // never leave a code-only card behind a batch the operator asked as user name and password
                        // (conditional on the unused status, like every voucher delete)
                        ORM::for_table('tbl_voucher')->where('id', $d->id())->where('status', '0')->delete_many();
                        r2(getUrl('plan/voucher'), 'e', Lang::T('The card passwords could not be stored; the remaining cards were not created.'));
                    }
                }
                $newVoucherIds[] = $d->id();
            }

            if ($printNow == 'yes' && count($newVoucherIds) > 0 && $printFormat === 'thermal80') {
                // UX 2026-11-09: card data for the 80 mm thermal print (plan-joined voucher rows: id / type are the voucher's)
                $nuxrCard = static function ($vs): array {
                    $unit = (string) $vs['validity_unit'];
                    return [
                        'id' => (int) $vs['id'],
                        'code' => (string) $vs['code'],
                        'kind' => stripos((string) $vs['type'], 'hotspot') !== false ? 'hotspot' : 'activation',
                        'plan' => (string) $vs['name_plan'],
                        'price' => Lang::moneyFormat($vs['price']),
                        'validity' => ((int) $vs['validity'] > 0 && $unit !== '') ? \Reseller\Ui\Format::validity((int) $vs['validity'], $unit) : '',
                    ];
                };
                $rows = ORM::for_table('tbl_plans')
                    ->left_outer_join('tbl_voucher', ['tbl_plans.id', '=', 'tbl_voucher.id_plan'])
                    ->where_in('tbl_voucher.id', $newVoucherIds)
                    ->order_by_asc('tbl_voucher.id')
                    ->find_many();
                $ui->assign('_title', Lang::T('Print vouchers'));
                $nuxrCards = array_map($nuxrCard, is_array($rows) ? $rows : iterator_to_array($rows));
                $nuxrPw = \Reseller\Domain\VoucherLogin::passwordsFor(array_column($nuxrCards, 'id'));
                foreach ($nuxrCards as &$nuxrC) { $nuxrC['password'] = $nuxrPw[$nuxrC['id']] ?? ''; }
                unset($nuxrC);
                $ui->assign('cards', $nuxrCards);
                $ui->assign('vc', count($newVoucherIds));
                $ui->assign('plans', ORM::for_table('tbl_plans')->find_many());
                $ui->assign('planid', $plan);
                $ui->assign('limit', count($newVoucherIds));
                $ui->assign('from_id', 0);
                $ui->assign('createdate', []);
                $ui->assign('selected_datetime', '');
                $ui->display('admin/print/voucher-80mm.tpl');
                die();
            }
            if ($printNow == 'yes' && count($newVoucherIds) > 0) {
                $template = file_get_contents("pages/Voucher.html");
                $template = str_replace('[[company_name]]', $config['CompanyName'], $template);

                $vouchersToPrint = ORM::for_table('tbl_voucher')
                    ->left_outer_join('tbl_plans', ['tbl_plans.id', '=', 'tbl_voucher.id_plan'])
                    ->where_in('tbl_voucher.id', $newVoucherIds)
                    ->find_many();

                $voucherHtmls = [];
                $n = 1;

                foreach ($vouchersToPrint as $vs) {
                    $temp = $template;
                    $temp = str_replace('[[qrcode]]', '<img src="qrcode/?data=' . $vs['code'] . '">', $temp);
                    $temp = str_replace('[[price]]', Lang::moneyFormat($vs['price']), $temp);
                    $temp = str_replace('[[voucher_code]]', $vs['code'], $temp);
                    $temp = str_replace('[[plan]]', $vs['name_plan'], $temp);
                    $temp = str_replace('[[counter]]', $n, $temp);
                    $voucherHtmls[] = $temp;
                    $n++;
                }

                $vc = count($voucherHtmls);
                $ui->assign('voucher', $voucherHtmls);
                $ui->assign('vc', $vc);
                $ui->assign('jml', 0);
                $ui->assign('from_id', 0);
                $ui->assign('vpl', '3');
                $ui->assign('pagebreak', $voucherPerPage);
                $ui->display('admin/print/voucher.tpl');
            }

            if ($numbervoucher == 1) {
                r2(getUrl('plan/voucher-view/') . $d->id(), 's', Lang::T('Create Vouchers Successfully'));
            }

            r2(getUrl('plan/voucher'), 's', Lang::T('Create Vouchers Successfully'));
        } else {
            r2(getUrl('plan/add-voucher/') . $id, 'e', $msg);
        }
        break;

    case 'voucher-delete-many':
        header('Content-Type: application/json');

        $admin = Admin::_info();

        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            echo json_encode(['status' => 'error', 'message' => Lang::T('You do not have permission to access this page')]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $voucherIds = json_decode($_POST['voucherIds'], true);

            if (is_array($voucherIds) && !empty($voucherIds)) {
                $voucherIds = array_map('intval', $voucherIds);

                try {
                    // WP5B §6.4: CLAIMED vouchers (redemption in progress or under review) are never deleted
                    $skipped = ORM::for_table('tbl_voucher')->where_in('id', $voucherIds)->where('status', \Reseller\Domain\VoucherRedemptionService::CLAIMED)->count();
                    ORM::for_table('tbl_voucher')
                        ->where_in('id', $voucherIds)
                        ->where_not_equal('status', \Reseller\Domain\VoucherRedemptionService::CLAIMED)
                        ->delete_many();
                    \Reseller\Domain\VoucherLogin::pruneOrphans();
                } catch (Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => Lang::T('Failed to delete vouchers.')]);
                    exit;
                }

                // Return success response
                echo json_encode(['status' => 'success', 'skipped' => (int) $skipped, 'message' => Lang::T("Vouchers Deleted Successfully.")
                    . ($skipped > 0 ? ' ' . $skipped . ' ' . Lang::T('claimed voucher(s) kept for review.') : '')]);
                exit;
            } else {
                echo json_encode(['status' => 'error', 'message' => Lang::T("Invalid or missing voucher IDs.")]);
                exit;
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => Lang::T("Invalid request method.")]);
        }
        break;

    case 'voucher-view':
        $id = $routes[2];
        $voucher = false;
        if (\Reseller\Security\DataScope::validId($id)) {
            $voucher = \Reseller\Security\DataScope::scopeVouchers(ORM::for_table('tbl_voucher'), $nuxrActor, $nuxrC::VOUCHER_READ)
                ->where('tbl_voucher.id', (int) $id)->find_one();
        }
        if (!$voucher) {
            r2(getUrl('plan/voucher/'), 'e', Lang::T('Voucher Not Found'));
        }
        $plan = ORM::for_table('tbl_plans')->find_one($voucher['id_plan']);
        if ($voucher && $plan) {
            $nuxrCardPw = \Reseller\Domain\VoucherLogin::passwordsFor([(int) $voucher['id']])[(int) $voucher['id']] ?? null;
            $content = Lang::pad($config['CompanyName'], ' ', 2) . "\n";
            $content .= Lang::pad($config['address'], ' ', 2) . "\n";
            $content .= Lang::pad($config['phone'], ' ', 2) . "\n";
            $content .= Lang::pad("", '=') . "\n";
            $content .= Lang::pads('ID', $voucher['id'], ' ') . "\n";
            $content .= Lang::pads(Lang::T('Code'), $voucher['code'], ' ') . "\n";
            if ($nuxrCardPw !== null) {
                $content .= Lang::pads(Lang::T('Password'), $nuxrCardPw, ' ') . "\n";
            }
            $content .= Lang::pads(Lang::T('Plan Name'), $plan['name_plan'], ' ') . "\n";
            $content .= Lang::pads(Lang::T('Type'), $voucher['type'], ' ') . "\n";
            $content .= Lang::pads(Lang::T('Plan Price'), Lang::moneyFormat($plan['price']), ' ') . "\n";
            $content .= Lang::pads(Lang::T('Sales'), $admin['fullname'] . ' #' . $admin['id'], ' ') . "\n";
            $content .= Lang::pad("", '=') . "\n";
            $content .= Lang::pad($config['note'], ' ', 2) . "\n";
            $ui->assign('print', $content);
            $config['printer_cols'] = 30;
            $content = Lang::pad($config['CompanyName'], ' ', 2) . "\n";
            $content .= Lang::pad($config['address'], ' ', 2) . "\n";
            $content .= Lang::pad($config['phone'], ' ', 2) . "\n";
            $content .= Lang::pad("", '=') . "\n";
            $content .= Lang::pads('ID', $voucher['id'], ' ') . "\n";
            $content .= Lang::pads(Lang::T('Code'), $voucher['code'], ' ') . "\n";
            if ($nuxrCardPw !== null) {
                $content .= Lang::pads(Lang::T('Password'), $nuxrCardPw, ' ') . "\n";
            }
            $content .= Lang::pads(Lang::T('Plan Name'), $plan['name_plan'], ' ') . "\n";
            $content .= Lang::pads(Lang::T('Type'), $voucher['type'], ' ') . "\n";
            $content .= Lang::pads(Lang::T('Plan Price'), Lang::moneyFormat($plan['price']), ' ') . "\n";
            $content .= Lang::pads(Lang::T('Sales'), $admin['fullname'] . ' #' . $admin['id'], ' ') . "\n";
            $content .= Lang::pad("", '=') . "\n";
            $content .= Lang::pad($config['note'], ' ', 2) . "\n";
            $ui->assign('_title', Lang::T('View'));
            $ui->assign('whatsapp', urlencode("```$content```"));
            $ui->display('admin/voucher/view.tpl');
        } else {
            r2(getUrl('plan/voucher/'), 'e', Lang::T('Voucher Not Found'));
        }
        break;
    case 'voucher-delete':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id = $routes['2'];
        run_hook('delete_voucher'); #HOOK
        $d = ORM::for_table('tbl_voucher')->find_one($id);
        if ($d) {
            // WP5B §6.4: a CLAIMED voucher (redemption in progress or under review) is never deleted
            if ((string) $d['status'] === \Reseller\Domain\VoucherRedemptionService::CLAIMED) {
                r2(getUrl('plan/voucher'), 'e', Lang::T('This voucher is being redeemed or under review and cannot be deleted'));
            }
            ORM::for_table('tbl_voucher')->where('id', $d['id'])->where_not_equal('status', \Reseller\Domain\VoucherRedemptionService::CLAIMED)->delete_many();
            \Reseller\Domain\VoucherLogin::pruneOrphans();
            r2(getUrl('plan/voucher'), 's', Lang::T('Data Deleted Successfully'));
        }
        break;

    case 'refill':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $ui->assign('xfooter', $select2_customer);
        $ui->assign('_title', Lang::T('Refill Account'));
        run_hook('view_refill'); #HOOK
        $ui->display('admin/plan/refill.tpl');

        break;

    case 'refill-post':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $code = Text::alphanumeric(_post('code'), "-_.,");
        // WP2 / OD-11 (E2): no voucher refill for reseller-owned customers
        \Reseller\Domain\ServiceGate::guardCustomer(_post('id_customer'), 'planRefillPost', 'plan/refill');
        $user = ORM::for_table('tbl_customers')->where('id', _post('id_customer'))->find_one();
        if (!$user) {
            r2(getUrl('plan/refill'), 'e', Lang::T('Customer not found'));
        }

        run_hook('refill_customer'); #HOOK
        // WP5B (E2; SEC-23): the single atomic voucher redemption path (claim -> grant -> finalize), audited for the staff actor
        $out = \Reseller\Domain\VoucherRedemptionService::redeem((string) $code, \Reseller\Domain\RedemptionTarget::customer($user), \Reseller\Domain\RedemptionSource::staff(\Reseller\Security\ActorContext::current()));
        if ($out['status'] === \Reseller\Domain\VoucherRedemptionService::GRANTED) {
            $in = ORM::for_table('tbl_transactions')->where('username', $user['username'])->order_by_desc('id')->find_one();
            Package::createInvoice($in);
            $ui->display('admin/plan/invoice.tpl');
        } elseif ($out['status'] === \Reseller\Domain\VoucherRedemptionService::NOT_VALID) {
            r2(getUrl('plan/refill'), 'e', Lang::T('Voucher Not Valid'));
        } elseif (($out['reason'] ?? '') === 'CUSTOMER_NOT_ACTIVE') {
            r2(getUrl('plan/refill'), 'e', Lang::T('This account status') . ' : ' . Lang::T((string) $out['customer_status']));
        } else {
            r2(getUrl('plan/refill'), 'e', "Failed to refill account");
        }
        break;
    case 'deposit':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $ui->assign('_title', Lang::T('Refill Balance'));
        $ui->assign('xfooter', $select2_customer);
        if (in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            $ui->assign('p', ORM::for_table('tbl_plans')->where('type', 'Balance')->find_many());
        } else {
            $ui->assign('p', ORM::for_table('tbl_plans')->where('enabled', '1')->where('type', 'Balance')->find_many());
        }
        run_hook('view_deposit'); #HOOK
        $ui->display('admin/plan/deposit.tpl');
        break;
    case 'deposit-post':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $user = _post('id_customer');
        $amount = _post('amount');
        $plan = _post('id_plan');
        $note = _post('note');
        $svoucher = _req('svoucher');
        $c = ORM::for_table('tbl_customers')->find_one($user);
        if (App::getVoucherValue($svoucher)) {
            $in = ORM::for_table('tbl_transactions')->find_one(App::getVoucherValue($svoucher));
            Package::createInvoice($in);
            $ui->display('admin/plan/invoice.tpl');
            die();
        }

        // WP2 / OD-11 (E3): no legacy balance deposit for reseller-owned customers
        \Reseller\Domain\ServiceGate::guardCustomer($user, 'planDepositPost', 'plan/deposit');
        run_hook('deposit_customer'); #HOOK
        if (!empty($user) && strlen($amount) > 0 && $amount != 0) {
            $plan = [];
            $plan['name_plan'] = Lang::T('Balance');
            $plan['price'] = $amount;
            $trxId = Package::rechargeBalance($c, $plan, "Deposit", $admin['fullname'], $note);
            if ($trxId > 0) {
                \Reseller\Domain\ServiceAudit::record('service.deposit', $c['id'], ['transaction_id' => (int) $trxId, 'amount' => (string) $amount]); // WP5B OD-7
                $in = ORM::for_table('tbl_transactions')->find_one($trxId);
                Package::createInvoice($in);
                if (!empty($svoucher)) {
                    App::setVoucher($svoucher, $trxId);
                }
                $ui->display('admin/plan/invoice.tpl');
            } else {
                r2(getUrl('plan/refill'), 'e', "Failed to refill account");
            }
        } else if (!empty($user) && !empty($plan)) {
            $p = ORM::for_table('tbl_plans')->find_one($plan);
            $trxId = Package::rechargeBalance($c, $p, "Deposit", $admin['fullname'], $note);
            if ($trxId > 0) {
                \Reseller\Domain\ServiceAudit::record('service.deposit', $c['id'], ['transaction_id' => (int) $trxId, 'plan_id' => (int) $p['id']]); // WP5B OD-7
                $in = ORM::for_table('tbl_transactions')->find_one($trxId);
                Package::createInvoice($in);
                if (!empty($svoucher)) {
                    App::setVoucher($svoucher, $trxId);
                }
                $ui->display('admin/plan/invoice.tpl');
            } else {
                r2(getUrl('plan/refill'), 'e', "Failed to refill account");
            }
        } else {
            r2(getUrl('plan/refill'), 'e', "All field is required");
        }
        break;
    case 'extend':
        $id = $routes[2];
        $days = $routes[3];
        // WP2: bounded integer days (the value is interpolated into a date expression)
        if (!is_string($days) || !preg_match('/^[1-9][0-9]{0,2}$/', $days) || (int) $days > 366 || !\Reseller\Security\DataScope::validId($id)) {
            r2(getUrl('plan'), 'e', Lang::T('Invalid input'));
        }
        $days = (int) $days;
        $svoucher = $_GET['svoucher'];
        if (App::getVoucherValue($svoucher)) {
            r2(getUrl('plan'), 's', "Extend already done");
        }
        $tur = ORM::for_table('tbl_user_recharges')->find_one($id);
        if (!$tur) {
            r2(getUrl('plan'), 'e', Lang::T('Data Not Found'));
        }
        // WP2 / OD-11 (E4): no legacy extend for reseller-owned customers
        \Reseller\Domain\ServiceGate::guardCustomer($tur['customer_id'], 'planExtend', 'plan');
        $status = $tur['status'];
        // Lab finding F6-1: an extend turns the service on and provisions it; like every other recharge path, it is
        // refused for a customer that is not Active (judged before the one-time extend token is consumed)
        $nuxrExtendCustomer = (int) $tur['customer_id'] > 0 ? ORM::for_table('tbl_customers')->findOne($tur['customer_id']) : null;
        if ($nuxrExtendCustomer && $nuxrExtendCustomer['status'] != 'Active') {
            r2(getUrl('plan'), 'e', Lang::T('This account status') . ' : ' . Lang::T($nuxrExtendCustomer['status']));
        }
        if ($status == 'off') {
            if (strtotime($tur['expiration'] . ' ' . $tur['time']) > time()) {
                // not expired
                $expiration = date('Y-m-d', strtotime($tur['expiration'] . " +$days day"));
            } else {
                //expired
                $expiration = date('Y-m-d', strtotime(" +$days day"));
            }
            App::setVoucher($svoucher, $id);
            $c = ORM::for_table('tbl_customers')->findOne($tur['customer_id']);
            if ($c) {
                $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
                if ($p) {
                    // WP4: save first, so the device provisions the saved billing expiry
                    $tur->expiration = $expiration;
                    $tur->status = "on";
                    $tur->save();
                    $dvc = Package::getDevice($p);
                    if ($_app_stage != 'demo') {
                        if (file_exists($dvc)) {
                            require_once $dvc;
                            global $isChangePlan;
                            $isChangePlan = true;
                            (new $p['device'])->add_customer($c, $p);
                        } else {
                            new Exception(Lang::T("Devices Not Found"));
                        }
                    }
                } else {
                    r2(getUrl('plan'), 's', "Plan not found");
                }
            } else {
                r2(getUrl('plan'), 's', "Customer not found");
            }
            Message::sendTelegram("#u$tur[username] #id$tur[customer_id]  #extend by $admin[fullname] #" . $p['type'] . " \n" . $p['name_plan'] .
                "\nLocation: " . $p['routers'] .
                "\nCustomer: " . $c['fullname'] .
                "\nNew Expired: " . Lang::dateAndTimeFormat($expiration, $tur['time']));
            _log("$admin[fullname] extend Customer $tur[customer_id] $tur[username] #$tur[customer_id] for $days days", $admin['user_type'], $admin['id']);
            \Reseller\Domain\ServiceAudit::record('service.extend', $tur['customer_id'], ['recharge_id' => (int) $tur['id'], 'days' => $days, 'expiration' => $expiration]); // WP5B OD-7
            r2(getUrl('plan'), 's', "Extend until $expiration");
        } else {
            r2(getUrl('plan'), 's', "Customer is not expired yet");
        }
        break;
    default:
        $ui->assign('_title', Lang::T('Active subscriptions'));
        $search = _post('search');
        $status = _req('status');
        $router = _req('router');
        $plan = _req('plan');
        $append_url = "&search=" . urlencode($search)
            . "&status=" . urlencode($status)
            . "&router=" . urlencode($type3)
            . "&plan=" . urlencode($plan);
        $ui->assign('append_url', $append_url);
        $ui->assign('plan', $plan);
        $ui->assign('status', $status);
        $ui->assign('router', $router);
        $ui->assign('search', $search);
        $nuxrScopeRech = static fn() => \Reseller\Security\DataScope::scopeByCustomerColumn(ORM::for_table('tbl_user_recharges'), $nuxrActor, $nuxrC::CUSTOMER_READ, 'tbl_user_recharges.customer_id');
        $ui->assign('routers', array_column($nuxrScopeRech()->distinct()->select("routers")->whereNotEqual('routers', '')->findArray(), 'routers'));

        $plns = $nuxrScopeRech()->distinct()->select("plan_id")->findArray();
        $ids = array_column($plns, 'plan_id');
        if (count($ids)) {
            $ui->assign('plans', ORM::for_table('tbl_plans')->select("id")->select('name_plan')->where_id_in($ids)->findArray());
        } else {
            $ui->assign('plans', []);
        }
        $query = $nuxrScopeRech()->order_by_desc('id');

        if ($search != '') {
            $query->where_like("username", "%$search%");
        }
        if (!empty($router)) {
            $query->where('routers', $router);
        }
        if (!empty($plan)) {
            $query->where('plan_id', $plan);
        }
        if (!empty($status) && $status != '-') {
            $query->where('status', $status);
        }
        $d = Paginator::findMany($query, ['search' => $search], 25, $append_url);
        run_hook('view_list_billing'); #HOOK
        $ui->assign('d', $d);
        $ui->display('admin/plan/active.tpl');
        break;
}
