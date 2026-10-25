<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

_admin();
$ui->assign('_title', Lang::T('Customer'));
$ui->assign('_system_menu', 'customers');

$action = $routes['1'];
$ui->assign('_admin', $admin);
// WP2: authoritative principal; every customer lookup below is capability + DataScope in one query
$nuxrActor = \Reseller\Security\ActorContext::current();

if (empty($action)) {
    $action = 'list';
}

$leafletpickerHeader = <<<EOT
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css">
EOT;

switch ($action) {
    case 'csv':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $csrf_token = _req('token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('customers'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }

        $cs = ORM::for_table('tbl_customers')
            ->select('tbl_customers.id', 'id')
            ->select('tbl_customers.username', 'username')
            ->select('fullname')
            ->select('address')
            ->select('phonenumber')
            ->select('email')
            ->select('balance')
            ->select('service_type')
            ->order_by_asc('tbl_customers.id')
            ->find_array();

        $h = false;
        set_time_limit(-1);
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header("Content-type: text/csv");
        header('Content-Disposition: attachment;filename="phpnuxbill_customers_' . date('Y-m-d_H_i') . '.csv"');
        header('Content-Transfer-Encoding: binary');

        $headers = [
            'id',
            'username',
            'fullname',
            'address',
            'phonenumber',
            'email',
            'balance',
            'service_type',
        ];

        if (!$h) {
            echo \Reseller\Ui\Format::csvLine($headers, ',');
            $h = true;
        }

        foreach ($cs as $c) {
            $row = [
                $c['id'],
                $c['username'],
                $c['fullname'],
                $c['address'],
                $c['phonenumber'],
                $c['email'],
                $c['balance'],
                $c['service_type'],
            ];
            echo \Reseller\Ui\Format::csvLine($row, ',');
        }
        break;
        //case csv-prepaid can be moved later to (plan.php)  php file dealing with prepaid users
    case 'csv-prepaid':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }

        $cs = ORM::for_table('tbl_customers')
            ->select('tbl_customers.id', 'id')
            ->select('tbl_customers.username', 'username')
            ->select('fullname')
            ->select('address')
            ->select('phonenumber')
            ->select('email')
            ->select('balance')
            ->select('service_type')
            ->select('namebp')
            ->select('routers')
            ->select('tbl_user_recharges.status', 'status') // WP9-D: was ambiguous (tbl_customers.status) -> SQL error 500
            ->select('method', 'Payment')
            ->left_outer_join('tbl_user_recharges', array('tbl_customers.id', '=', 'tbl_user_recharges.customer_id'))
            ->order_by_asc('tbl_customers.id')
            ->find_array();

        $h = false;
        set_time_limit(-1);
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header("Content-type: text/csv");
        header('Content-Disposition: attachment;filename="phpnuxbill_prepaid_users' . date('Y-m-d_H_i') . '.csv"');
        header('Content-Transfer-Encoding: binary');

        $headers = [
            'id',
            'username',
            'fullname',
            'address',
            'phonenumber',
            'email',
            'balance',
            'service_type',
            'namebp',
            'routers',
            'status',
            'Payment'
        ];

        if (!$h) {
            echo \Reseller\Ui\Format::csvLine($headers, ',');
            $h = true;
        }

        foreach ($cs as $c) {
            $row = [
                $c['id'],
                $c['username'],
                $c['fullname'],
                $c['address'],
                $c['phonenumber'],
                $c['email'],
                $c['balance'],
                $c['service_type'],
                $c['namebp'],
                $c['routers'],
                $c['status'],
                $c['Payment']
            ];
            echo \Reseller\Ui\Format::csvLine($row, ',');
        }
        break;
    case 'add':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        // WP2: ownership choices are offered by role; the server re-validates everything on add-post
        // WP6A: no eager map assets (the map loads when the operator opens it); Admin gets the owner -> Sales map
        $nuxrCreateScope = \Reseller\Security\PermissionService::scopeOf($nuxrActor, \Reseller\Security\Capability::CUSTOMER_CREATE);
        $ui->assign('nuxr_create_scope', $nuxrCreateScope);
        if ($nuxrCreateScope === 'G') {
            $nuxrOwners = \Reseller\Domain\CustomerService::ownerOptions();
            $ui->assign('nuxr_owner_options', $nuxrOwners);
            $nuxrOwnerSales = [];
            foreach ($nuxrOwners as $o) {
                $nuxrOwnerSales[(int) $o['id']] = \Reseller\Domain\CustomerService::salesOptions((int) $o['id']);
            }
            $ui->assign('nuxr_owner_sales', $nuxrOwnerSales);
        } elseif ($nuxrCreateScope === 'T') {
            $ui->assign('nuxr_sales_options', \Reseller\Domain\CustomerService::salesOptions((int) $nuxrActor->userId));
        } elseif ($nuxrCreateScope === 'A' && $nuxrActor->managerId !== null) {
            $nuxrParent = ORM::for_table('tbl_users')->select_many('id', 'username')->find_one((int) $nuxrActor->managerId);
            $ui->assign('nuxr_parent_owner', $nuxrParent ? $nuxrParent['username'] : null);
        }
        // UX-12: non-secret fields of a refused add-post, shown once (never a password) and only while fresh: a refill
        // older than 5 minutes (a later, unrelated add) is dropped, never offered for another subscriber
        $nuxrStored = $_SESSION['nuxr_customer_add_refill'] ?? null;
        unset($_SESSION['nuxr_customer_add_refill']);
        $nuxrRefill = null;
        if (is_array($nuxrStored) && isset($nuxrStored['at'], $nuxrStored['fields']) && is_int($nuxrStored['at'])
            && is_array($nuxrStored['fields']) && time() - $nuxrStored['at'] >= 0 && time() - $nuxrStored['at'] <= 300) {
            $nuxrRefill = $nuxrStored['fields'];
        }
        $nuxrForm = array_fill_keys(['fullname', 'phonenumber', 'email', 'address', 'service_type', 'account_type', 'username', 'pppoe_username', 'pppoe_ip'], '');
        foreach ($nuxrForm as $k => $unused) {
            if (is_array($nuxrRefill) && isset($nuxrRefill[$k]) && is_string($nuxrRefill[$k])) {
                $nuxrForm[$k] = $nuxrRefill[$k];
            }
        }
        $ui->assign('nuxr_refill', $nuxrForm);
        run_hook('view_add_customer'); #HOOK
        $ui->assign('csrf_token',  Csrf::generateAndStoreToken());
        $ui->display('admin/customers/add.tpl');
        break;
    case 'recharge':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent', 'Sales'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id_customer = $routes['2'];
        $plan_id = $routes['3'];
        $csrf_token = _req('token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('customers/view/') . $id_customer, 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        // WP5B (OD-6, E1): a reseller-owned customer continues at activation/create (web) or is refused (API)
        if (\Reseller\Domain\LegacyReprovisionService::owned($id_customer)) {
            if (\Reseller\Security\ActorContext::current()->channel === 'API') {
                \Reseller\Domain\ServiceGate::guardCustomer($id_customer, 'customerRecharge', 'customers/view/' . (int) $id_customer);
            }
            r2(getUrl('activation/create&customer=' . (int) $id_customer . '&plan=' . (int) $plan_id), 'w', Lang::T('Reseller-managed customers are activated through the reseller activation.'));
        }
        \Reseller\Domain\ServiceGate::guardCustomer($id_customer, 'customerRecharge', 'customers/view/' . (int) $id_customer);
        $b = ORM::for_table('tbl_user_recharges')->where('customer_id', $id_customer)->where('plan_id', $plan_id)->find_one();
        if ($b) {
            $gateway = 'Recharge';
            $channel = $admin['fullname'];
            $cust = User::_info($id_customer);
            $plan = ORM::for_table('tbl_plans')->find_one($b['plan_id']);
			$add_inv = User::getAttribute("Invoice", $id_customer);
			if (!empty($add_inv)) {
				$plan['price'] = $add_inv;
			}
            $tax_enable = isset($config['enable_tax']) ? $config['enable_tax'] : 'no';
            $tax_rate_setting = isset($config['tax_rate']) ? $config['tax_rate'] : null;
            $custom_tax_rate = isset($config['custom_tax_rate']) ? (float)$config['custom_tax_rate'] : null;
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
            list($bills, $add_cost) = User::getBills($id_customer);
            if ($using == 'balance' && $config['enable_balance'] == 'yes') {
                if (!$cust) {
                    r2(getUrl('plan/recharge'), 'e', Lang::T('Customer not found'));
                }
                if (!$plan) {
                    r2(getUrl('plan/recharge'), 'e', Lang::T('Plan not found'));
                }
                if ($cust['balance'] < ($plan['price'] + $add_cost + $tax)) {
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
            $abills = User::getAttributes("Bill");
            if ($tax_enable === 'yes') {
                $ui->assign('tax', $tax);
            }
            $ui->assign('usings', $usings);
            $ui->assign('abills', $abills);
            $ui->assign('bills', $bills);
            $ui->assign('add_cost', $add_cost);
            $ui->assign('cust', $cust);
            $ui->assign('gateway', $gateway);
            $ui->assign('channel', $channel);
            $ui->assign('server', $b['routers']);
            $ui->assign('plan', $plan);
			$ui->assign('add_inv', $add_inv);
            $ui->assign('csrf_token',  Csrf::generateAndStoreToken());
            $ui->assign('nuxr_nonce', bin2hex(random_bytes(16)));
            $ui->display('admin/plan/recharge-confirm.tpl');
        } else {
            r2(getUrl('customers/view/') . $id_customer, 'e', 'Cannot find active plan');
        }
        break;
    case 'deactivate':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id_customer = $routes['2'];
        $plan_id = $routes['3'];
        $csrf_token = _req('token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('customers/view/') . $id_customer, 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        // WP8 A5 (R-WP8-CARRY-01): a reseller-owned service ends only by reversal or expiry, never here
        \Reseller\Domain\ServiceGate::guardCustomer($id_customer, 'customerDeactivate', 'customers/view/' . (int) $id_customer);
        // WP8 A5 (D-A5-1): a zero / missing / malformed id would match legacy customer_id 0 rows and make User::_info()
        // fall back to the session customer (Admin "login as customer"): only a canonical, existing customer id
        if (!ctype_digit((string) $id_customer) || (int) $id_customer <= 0 || !ORM::for_table('tbl_customers')->find_one((int) $id_customer)) {
            \Reseller\Domain\ServiceGate::refused('customerDeactivateIdentity', $id_customer);
            r2(getUrl('customers/list'), 'e', Lang::T('Account Not Found'));
        }
        $b = ORM::for_table('tbl_user_recharges')->where('customer_id', $id_customer)->where('plan_id', $plan_id)->find_one();
        if ($b) {
            $p = ORM::for_table('tbl_plans')->where('id', $b['plan_id'])->find_one();
            if ($p) {
                $p = ORM::for_table('tbl_plans')->where('id', $b['plan_id'])->find_one();
                $c = User::_info($id_customer);
                if (!$c || (int) $c['id'] !== (int) $id_customer || (int) $b['customer_id'] !== (int) $id_customer) {
                    \Reseller\Domain\ServiceGate::refused('customerDeactivateIdentity', $id_customer);
                    r2(getUrl('customers/list'), 'e', Lang::T('Account Not Found'));
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
                $b->status = 'off';
                $b->expiration = date('Y-m-d');
                $b->time = date('H:i:s');
                $b->save();
                _log('Admin ' . $admin['username'] . ' Deactivate ' . $b['namebp'] . ' for ' . $b['username'], 'User', $b['customer_id']);
                Message::sendTelegram('Admin ' . $admin['username'] . ' Deactivate ' . $b['namebp'] . ' for u' . $b['username']);
                r2(getUrl('customers/view/') . $id_customer, 's', 'Success deactivate customer to Mikrotik');
            }
        }
        r2(getUrl('customers/view/') . $id_customer, 'e', 'Cannot find active plan');
        break;
    case 'sync':
        $id_customer = $routes['2'];
        $csrf_token = _req('token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('customers/view/') . $id_customer, 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        // WP5B (OD-10, E19b): a reseller-owned customer is re-provisioned only by LegacyReprovisionService
        // (serialized with activations and reversals, never a grant); the legacy sync stays for the others
        if (\Reseller\Domain\LegacyReprovisionService::owned($id_customer)) {
            \Reseller\Domain\LegacyReprovisionService::web(\Reseller\Domain\LegacyReprovisionService::ADMIN_SYNC, $id_customer, [], 'customers/view/' . (int) $id_customer);
        }
        \Reseller\Domain\ServiceGate::guardCustomer($id_customer, 'customerSync', 'customers/view/' . (int) $id_customer);
        $bs = ORM::for_table('tbl_user_recharges')->where('customer_id', $id_customer)->where('status', 'on')->findMany();
        if ($bs) {
            $routers = [];
            foreach ($bs as $b) {
                $c = ORM::for_table('tbl_customers')->find_one($id_customer);
                $p = ORM::for_table('tbl_plans')->where('id', $b['plan_id'])->find_one();
                if ($p) {
                    $routers[] = $b['routers'];
                    $dvc = Package::getDevice($p);
                    if ($_app_stage != 'demo') {
                        if (file_exists($dvc)) {
                            require_once $dvc;
                            if (method_exists($dvc, 'sync_customer')) {
                                (new $p['device'])->sync_customer($c, $p);
                            }else{
                                (new $p['device'])->add_customer($c, $p);
                            }
                        } else {
                            new Exception(Lang::T("Devices Not Found"));
                        }
                    }
                }
            }
            r2(getUrl('customers/view/') . $id_customer, 's', 'Sync success to ' . implode(", ", $routers));
        }
        r2(getUrl('customers/view/') . $id_customer, 'e', 'Cannot find active plan');
        break;
    case 'login':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id = $routes['2'];
        $csrf_token = _req('token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('customers/view/') . $id, 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        $customer = ORM::for_table('tbl_customers')->find_one($id);
        if ($customer) {
            $_SESSION['uid'] = $id;
            User::setCookie($id);
            _alert("You are logging in as $customer[fullname],<br>don't logout just close tab.", 'info', "home", 10);
        }
        _alert(Lang::T('Customer not found'), 'danger', "customers");
        break;
    case 'viewu':
        // WP2 / SEC-06: out-of-scope and nonexistent customers are indistinguishable
        $customer = \Reseller\Security\DataScope::findCustomerByUsername($nuxrActor, \Reseller\Security\Capability::CUSTOMER_READ, $routes['2'] ?? null);
    case 'view':
        $id = $routes['2'];
        run_hook('view_customer'); #HOOK
        if (!$customer) {
            $customer = \Reseller\Security\DataScope::findCustomer($nuxrActor, \Reseller\Security\Capability::CUSTOMER_READ, $id);
        }
        if ($customer) {
            // Fetch the Customers Attributes values from the tbl_customer_custom_fields table
            $customFields = ORM::for_table('tbl_customers_fields')
                ->where('customer_id', $customer['id'])
                ->find_many();
            // WP6A Subscriber 360 tabs: overview (default), activations (reseller read model), invoices (legacy
            // transactions; the former "activation" tab key still opens it), order (payment gateway rows)
            $v = $routes['3'] ?? '';
            $v = $v === 'activation' ? 'invoices' : $v;
            if (!in_array($v, ['overview', 'activations', 'invoices', 'order'], true)) {
                $v = 'overview';
            }
            switch ($v) {
                case 'order':
                    $v = 'order';
                    // WP2: by customer id only (usernames are mutable and reusable)
                    $query = ORM::for_table('tbl_payment_gateway')->where('user_id', $customer['id'])->order_by_desc('id');
                    $order = Paginator::findMany($query);

                    $ui->assign('order', $order);
                    break;
                case 'invoices':
                    // WP2: by customer id only (usernames are mutable and reusable)
                    $query = ORM::for_table('tbl_transactions')->where('user_id', $customer['id'])->order_by_desc('id');
                    $activation = Paginator::findMany($query);

                    $ui->assign('activation', $activation);
                    break;
            }
            $ui->assign('packages', User::_billing($customer['id']));
            // WP2: ownership panel (cosmetic flags only; every action is re-authorized server-side)
            $nuxrOwner = $customer['manager_id'] ? ORM::for_table('tbl_users')->select_many('id', 'username', 'fullname')->find_one($customer['manager_id']) : null;
            $nuxrSales = $customer['assigned_sales_id'] ? ORM::for_table('tbl_users')->select_many('id', 'username', 'fullname')->find_one($customer['assigned_sales_id']) : null;
            $ui->assign('nuxr_owner', $nuxrOwner ? $nuxrOwner->as_array() : null);
            $ui->assign('nuxr_sales', $nuxrSales ? $nuxrSales->as_array() : null);
            if (\Reseller\Security\PermissionService::can($nuxrActor, \Reseller\Security\Capability::CUSTOMER_ASSIGN_SALES) && $customer['manager_id']) {
                $ui->assign('nuxr_sales_options', \Reseller\Domain\CustomerService::salesOptions((int) $customer['manager_id']));
            }
            if (\Reseller\Security\PermissionService::can($nuxrActor, \Reseller\Security\Capability::CUSTOMER_TRANSFER_OWNER)) {
                $ui->assign('nuxr_owner_options', \Reseller\Domain\CustomerService::ownerOptions());
            }
            // WP6A (OD-A, OD-D, OD-E): read-model activation history and flags, RADIUS session state from existing
            // accounting data, and the actions this actor can actually perform (the server re-authorizes all of them)
            $nuxrCid = (int) $customer['id'];
            $nuxrGlobal = \Reseller\Security\PermissionService::scopeOf($nuxrActor, \Reseller\Security\Capability::CUSTOMER_READ) === 'G';
            $nuxrCanRead = \Reseller\Security\PermissionService::can($nuxrActor, \Reseller\Security\Capability::ACTIVATION_READ);
            $ui->assign('nuxr_flags', \Reseller\Activation\ActivationReadService::customerFlags($nuxrActor, $nuxrCid));
            if ($nuxrCanRead && $v === 'activations') {
                $ui->assign('nuxr_activations', \Reseller\Activation\ActivationReadService::list($nuxrActor, ['customer' => $nuxrCid], (int) _get('page', 1)));
            } elseif ($nuxrCanRead) {
                $ui->assign('nuxr_recent', array_slice(\Reseller\Activation\ActivationReadService::list($nuxrActor, ['customer' => $nuxrCid])['rows'], 0, 3));
            }
            $ui->assign('nuxr_can_activations', $nuxrCanRead);
            $nuxrActivate = false;
            if (\Reseller\Activation\ActivationService::eligible($nuxrActor) && $customer['manager_id'] !== null) {
                try {
                    \Reseller\Activation\ActivationService::payerOf($nuxrActor, $customer->as_array());
                    $nuxrActivate = true;
                } catch (\Reseller\Domain\DomainError $e) {
                    $nuxrActivate = false;
                }
            }
            $ui->assign('nuxr_can_activate', $nuxrActivate);
            $ui->assign('nuxr_session', \Reseller\Ui\Subscriber::session($customer->as_array(), $nuxrGlobal));
            $nuxrQuota = \Reseller\Ui\Subscriber::quota($customer->as_array());
            $ui->assign('nuxr_quota', $nuxrQuota);
            $ui->assign('nuxr_quota_over', in_array(true, array_column($nuxrQuota, 'over'), true));
            $ui->assign('nuxr_global', $nuxrGlobal);
            if ($nuxrGlobal && $customer['created_by']) {
                $nuxrCreator = ORM::for_table('tbl_users')->select_many('id', 'username')->find_one((int) $customer['created_by']);
                $ui->assign('nuxr_creator', $nuxrCreator ? $nuxrCreator['username'] : null);
            }
            $ui->assign('nuxr_created', _get('created') === '1');
            $ui->assign('_title', $customer['fullname']);
            $ui->assign('v', $v);
            $ui->assign('d', $customer);
            $ui->assign('customFields', $customFields);
            $ui->assign('csrf_token',  Csrf::generateAndStoreToken());
            $ui->display('admin/customers/view.tpl');
        } else {
            r2(getUrl('customers/list'), 'e', Lang::T('Account Not Found'));
        }
        break;
    case 'edit':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id = $routes['2'];
        run_hook('edit_customer'); #HOOK
        $d = ORM::for_table('tbl_customers')->find_one($id);
        // Fetch the Customers Attributes values from the tbl_customers_fields table
        $customFields = ORM::for_table('tbl_customers_fields')
            ->where('customer_id', $id)
            ->find_many();
        if ($d) {
            if (isset($routes['3']) && $routes['3'] == 'deletePhoto') {
                if ($d['photo'] != '' && strpos($d['photo'], 'default') === false) {
                    if (file_exists($UPLOAD_PATH . $d['photo']) && strpos($d['photo'], 'default') === false) {
                        unlink($UPLOAD_PATH . $d['photo']);
                        if (file_exists($UPLOAD_PATH . $d['photo'] . '.thumb.jpg')) {
                            unlink($UPLOAD_PATH . $d['photo'] . '.thumb.jpg');
                        }
                    }
                    $d->photo = '/user.default.jpg';
                    $d->save();
                    $ui->assign('notify_t', 's');
                    $ui->assign('notify', 'You have successfully deleted the photo');
                } else {
                    $ui->assign('notify_t', 'e');
                    $ui->assign('notify', 'No photo found to delete');
                }
            }
            $ui->assign('d', $d);
            $ui->assign('statuses', ORM::for_table('tbl_customers')->getEnum("status"));
            $ui->assign('customFields', $customFields);
            // WP6A (OD-H): no eager map stylesheet; the map loads when opened
            $ui->assign('csrf_token',  Csrf::generateAndStoreToken());
            $ui->display('admin/customers/edit.tpl');
        } else {
            r2(getUrl('customers/list'), 'e', Lang::T('Account Not Found'));
        }
        break;

    case 'delete':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $id = $routes['2'];
        $csrf_token = _req('token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('customers/view/') . $id, 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        // WP2: reference pre-check BEFORE any side effect (no device removal, no partial delete)
        try {
            \Reseller\Domain\CustomerService::assertDeletable($nuxrActor, (int) $id);
        } catch (\Reseller\Domain\DomainError $e) {
            r2(getUrl('customers/view/') . (int) $id, 'e', Lang::T($e->getMessage()));
        }
        run_hook('delete_customer'); #HOOK
        $c = ORM::for_table('tbl_customers')->find_one($id);
        if ($c) {
            //Delete active package (device side)
            $turs = ORM::for_table('tbl_user_recharges')->where('customer_id', $c['id'])->find_many();
            foreach ($turs as $tur) {
                $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
                if ($p) {
                    $dvc = Package::getDevice($p);
                    if ($_app_stage != 'demo') {
                        if (file_exists($dvc)) {
                            require_once $dvc;
                            $p['plan_expired'] = 0;
                            (new $p['device'])->remove_customer($c, $p);
                        } else {
                            new Exception(Lang::T("Devices Not Found"));
                        }
                    }
                }
            }
            // custom fields, recharges and the customer row in one audited transaction; a failure is
            // reported, never swallowed (the legacy flow reported success on a failed delete)
            try {
                \Reseller\Domain\CustomerService::deleteRecords($nuxrActor, (int) $c['id']);
            } catch (\Reseller\Domain\DomainError $e) {
                r2(getUrl('customers/view/') . (int) $id, 'e', Lang::T($e->getMessage()));
            }
            r2(getUrl('customers/list'), 's', Lang::T('User deleted Successfully'));
        }
        break;

    case 'add-post':
        // UX-12: a refill left by an earlier refused add never outlives the next add attempt
        unset($_SESSION['nuxr_customer_add_refill']);

        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('customers/add'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        $username = alphanumeric(_post('username'), ":+_.@-");
        $fullname = _post('fullname');
        $password = trim(_post('password'));
        $pppoe_username = trim(_post('pppoe_username'));
        $pppoe_password = trim(_post('pppoe_password'));
        $pppoe_ip = trim(_post('pppoe_ip'));
        $email = _post('email');
        $address = _post('address');
        $phonenumber = _post('phonenumber');
        $service_type = _post('service_type');
        $account_type = _post('account_type');
        $coordinates = _post('coordinates');
        //post Customers Attributes
        $custom_field_names = (array) $_POST['custom_field_name'];
        $custom_field_values = (array) $_POST['custom_field_value'];
        //additional information
        $city = _post('city');
        $district = _post('district');
        $state = _post('state');
        $zip = _post('zip');

        run_hook('add_customer'); #HOOK
        // UX-12: after a refused add the form is offered again with the NON-SECRET fields (one request, then cleared
        // by customers/add); passwords (login and PPPoE) are never kept
        $nuxrRefill = [];
        foreach (['fullname' => $fullname, 'phonenumber' => $phonenumber, 'email' => $email, 'address' => $address,
                     'service_type' => $service_type, 'account_type' => $account_type, 'username' => $username,
                     'pppoe_username' => $pppoe_username, 'pppoe_ip' => $pppoe_ip] as $k => $v) {
            $nuxrRefill[$k] = is_scalar($v) ? (string) $v : '';
        }
        $msg = '';
        if (Validator::Length($username, 55, 2) == false) {
            $msg .= 'Username should be between 3 to 54 characters' . '<br>';
        }
        // WP6A (Arabic-first): characters, not bytes, within the accepted CustomerService rule and the column (1-45)
        if (mb_strlen((string) $fullname) < 1 || mb_strlen((string) $fullname) > 45) {
            $msg .= Lang::T('Full Name should be between 1 to 45 characters') . '<br>';
        }
        if (!Validator::Length($password, 36, 2)) {
            $msg .= 'Password should be between 3 to 35 characters' . '<br>';
        }

        $d = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
        if ($d) {
            $msg .= Lang::T('Account already exists') . '<br>';
        }
        if ($msg == '') {
            // WP2 / SEC-06: ownership resolved server-side by role (CustomerService); posted
            // manager_id / assigned_sales_id are only honoured within the actor's authority
            $nuxrFields = [];
            if (!empty($custom_field_names) && !empty($custom_field_values)) {
                $totalFields = min(count($custom_field_names), count($custom_field_values));
                for ($i = 0; $i < $totalFields; $i++) {
                    if (!empty($custom_field_names[$i]) && is_string($custom_field_names[$i])) {
                        $nuxrFields[$custom_field_names[$i]] = is_scalar($custom_field_values[$i]) ? (string) $custom_field_values[$i] : '';
                    }
                }
            }
            try {
                $customerId = \Reseller\Domain\CustomerService::create($nuxrActor, [
                    'username' => $username, 'password' => $password, 'fullname' => $fullname,
                    'pppoe_username' => $pppoe_username, 'pppoe_password' => $pppoe_password, 'pppoe_ip' => $pppoe_ip,
                    'email' => $email, 'account_type' => $account_type, 'address' => $address,
                    'phonenumber' => Lang::phoneFormat($phonenumber), 'service_type' => $service_type, 'coordinates' => $coordinates,
                    'city' => $city, 'district' => $district, 'state' => $state, 'zip' => $zip,
                    'manager_id' => _post('manager_id'), 'assigned_sales_id' => _post('assigned_sales_id'),
                ], $nuxrFields);
            } catch (\Reseller\Domain\DomainError $e) {
                $_SESSION['nuxr_customer_add_refill'] = ['at' => time(), 'fields' => $nuxrRefill];
                r2(getUrl('customers/add'), 'e', Lang::T($e->getMessage()));
            }
            $d = ORM::for_table('tbl_customers')->find_one($customerId);

            // Send welcome message
            if (isset($_POST['send_welcome_message']) && $_POST['send_welcome_message'] == true) {
                $welcomeMessage = Lang::getNotifText('welcome_message');
                $welcomeMessage = str_replace('[[company]]', $config['CompanyName'], $welcomeMessage);
                $welcomeMessage = str_replace('[[name]]', $d['fullname'], $welcomeMessage);
                $welcomeMessage = str_replace('[[username]]', $d['username'], $welcomeMessage);
                $welcomeMessage = str_replace('[[password]]', $d['password'], $welcomeMessage);
                $welcomeMessage = str_replace('[[url]]', APP_URL . '/?_route=login', $welcomeMessage);

                $emailSubject = "Welcome to " . $config['CompanyName'];

                $channels = [
                    'sms' => [
                        'enabled' => isset($_POST['sms']),
                        'method' => 'sendSMS',
                        'args' => [$d['phonenumber'], $welcomeMessage]
                    ],
                    'whatsapp' => [
                        'enabled' => isset($_POST['wa']),
                        'method' => 'sendWhatsapp',
                        'args' => [$d['phonenumber'], $welcomeMessage]
                    ],
                    'email' => [
                        'enabled' => isset($_POST['mail']),
                        'method' => 'Message::sendEmail',
                        'args' => [$d['email'], $emailSubject, $welcomeMessage, $d['email']]
                    ]
                ];

                foreach ($channels as $channel => $message) {
                    if ($message['enabled']) {
                        try {
                            call_user_func_array($message['method'], $message['args']);
                        } catch (Exception $e) {
                            // Log the error and handle the failure
                            \Reseller\Security\ErrorReporter::report($e, 'welcome-message-' . $channel);
                        }
                    }
                }
            }
            // WP6A: continue on the new subscriber's 360 (Activate now / Later)
            r2(getUrl('customers/view/') . (int) $customerId . '&created=1', 's', Lang::T('Account Created Successfully'));
        } else {
            $_SESSION['nuxr_customer_add_refill'] = ['at' => time(), 'fields' => $nuxrRefill];
            r2(getUrl('customers/add'), 'e', $msg);
        }
        break;

    case 'edit-post':
        $id = _post('id');
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('customers/edit/') . $id, 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        $username = alphanumeric(_post('username'), ":+_.@-");
        $fullname = _post('fullname');
        $account_type = _post('account_type');
        $password = trim(_post('password'));
        $pppoe_username = trim(_post('pppoe_username'));
        $pppoe_password = trim(_post('pppoe_password'));
        $pppoe_ip = trim(_post('pppoe_ip'));
        $email = _post('email');
        $address = _post('address');
        $phonenumber = Lang::phoneFormat(_post('phonenumber'));
        $service_type = _post('service_type');
        $coordinates = _post('coordinates');
        $status = _post('status');
        //additional information
        $city = _post('city');
        $district = _post('district');
        $state = _post('state');
        $zip = _post('zip');
        run_hook('edit_customer'); #HOOK
        $msg = '';
        if (Validator::Length($username, 55, 2) == false) {
            $msg .= 'Username should be between 3 to 54 characters' . '<br>';
        }
        // WP6A (Arabic-first): characters, not bytes, within the accepted CustomerService rule and the column (1-45)
        if (mb_strlen((string) $fullname) < 1 || mb_strlen((string) $fullname) > 45) {
            $msg .= Lang::T('Full Name should be between 1 to 45 characters') . '<br>';
        }

        $c = ORM::for_table('tbl_customers')->find_one($id);

        if (!$c) {
            $msg .= Lang::T('Data Not Found') . '<br>';
        }

        //lets find user Customers Attributes using id
        $customFields = ORM::for_table('tbl_customers_fields')
            ->where('customer_id', $id)
            ->find_many();

        $oldusername = $c['username'];
        $oldPppoeUsername = $c['pppoe_username'];
        $oldPppoePassword = $c['pppoe_password'];
        $oldPppoeIp = $c['pppoe_ip'];
        $oldPassPassword = $c['password'];
        $userDiff = false;
        $pppoeDiff = false;
        $passDiff = false;
        $pppoeIpDiff = false;
        if ($oldusername != $username) {
            // WP9 U0 (I-4): the customer itself is excluded (a case-only rename of its own name is not a collision)
            if (ORM::for_table('tbl_customers')->where('username', $username)->where_not_equal('id', $id)->find_one()) {
                $msg .= Lang::T('Username already used by another customer') . '<br>';
            }
            if (ORM::for_table('tbl_customers')->where('pppoe_username', $username)->where_not_equal('id', $id)->find_one()) {
                $msg .= Lang::T('Username already used by another pppoe username customer') . '<br>';
            }
            $userDiff = true;
        }
        // WP8 A3 (owner D-3): strict comparison; the PPPoE username is always saved, so a loose "equal" (numeric
        // strings, e.g. 100 and 1e2) would skip every collision check and the RADIUS rename
        if ((string) $oldPppoeUsername !== (string) $pppoe_username) {
            // if(!empty($pppoe_username)){
            //     if(ORM::for_table('tbl_customers')->where('pppoe_username', $pppoe_username)->find_one()){
            //         $msg.= Lang::T('PPPoE Username already used by another customer') . '<br>';
            //     }
            //     if(ORM::for_table('tbl_customers')->where('username', $pppoe_username)->find_one()){
            //         $msg.= Lang::T('PPPoE Username already used by another customer') . '<br>';
            //     }
            // }
            $pppoeDiff = true;
        }

        // WP9 U0 (I-5): a changed name fits its column (username 45, PPPoE username 32, no control character), never truncated
        if ($userDiff && mb_strlen((string) $username) > 45) {
            $msg .= 'Username should be at most 45 characters<br>';
        }
        if ($pppoeDiff && (mb_strlen((string) $pppoe_username) > 32 || preg_match('/[\x00-\x1F\x7F]/', (string) $pppoe_username))) {
            $msg .= 'PPPoE username should be at most 32 characters, without control characters<br>';
        }
        if ($oldPppoeIp != $pppoe_ip) {
            $pppoeIpDiff = true;
        }
        if ($password != '' && $oldPassPassword != $password) {
            $passDiff = true;
        }
        // WP5B (OD-10, E21): a reseller-owned customer's identity (username, PPPoE username / password / IP,
        // password) is changed only by LegacyReprovisionService: serialized with activations, device-verified,
        // reverted on a confirmed device failure. The controller never writes these fields for such a customer.
        // Re-activation (status -> Active) stays refused; while ownership cannot be evaluated everything is refused.
        $resellerOwned = $c && \Reseller\Domain\LegacyReprovisionService::owned($c['id']);
        if ($c && ($resellerOwned ? ($status === 'Active' && $c['status'] !== 'Active')
                : ($userDiff || $pppoeDiff || $pppoeIpDiff || $passDiff || ($status === 'Active' && $c['status'] !== 'Active')))
            && \Reseller\Domain\ServiceGate::isBlockedCustomerId($c['id'])) {
            \Reseller\Domain\ServiceGate::refused('customerEditPost', $c['id']);
            r2(getUrl('customers/edit/') . (int) $id, 'e', \Reseller\Domain\ServiceGate::message());
        }
        if ($resellerOwned && $msg == '' && ($userDiff || $pppoeDiff || $pppoeIpDiff || $passDiff)) {
            try {
                $nuxrReprovision = \Reseller\Domain\LegacyReprovisionService::run(\Reseller\Security\ActorContext::current(), (int) $c['id'], \Reseller\Domain\LegacyReprovisionService::IDENTITY_EDIT, [
                    'username' => $username, 'password' => $password, 'pppoe_username' => $pppoe_username, 'pppoe_password' => $pppoe_password, 'pppoe_ip' => $pppoe_ip,
                ]);
            } catch (\Reseller\Domain\DomainError $e) {
                r2(getUrl('customers/edit/') . (int) $id, 'e', $e->getMessage());
            }
            $c = ORM::for_table('tbl_customers')->find_one($id);
        }
        if ($resellerOwned) {
            $userDiff = $pppoeDiff = $pppoeIpDiff = $passDiff = false;
        }
        // WP8 A3 (OD-A3R-9, owner D-3): a PPPoE username never takes another customer's name, and a RADIUS rename that
        // would meet another identity (equivalent key, a policy that is not this customer's, RADIUS unreadable) is
        // refused before the customer row is saved (nothing is written, nothing to revert)
        $nuxrOld = ['username' => $oldusername, 'pppoe_username' => $oldPppoeUsername];
        $nuxrNew = ['username' => $userDiff ? $username : $oldusername, 'pppoe_username' => $pppoeDiff ? $pppoe_username : $oldPppoeUsername];
        if ($c && $msg == '' && $pppoeDiff && (string) $pppoe_username !== ''
            && \Reseller\Domain\LegacyReprovisionService::nameTakenByOther((string) $pppoe_username, (int) $c['id'])) {
            $msg .= Lang::T('PPPoE username already used by another customer') . '<br>';
        }
        if ($c && $msg == '' && ($userDiff || $pppoeDiff)) {
            $nuxrCheck = \Reseller\Domain\LegacyReprovisionService::renameCheck(ORM::for_table('tbl_user_recharges')->where('customer_id', $c['id'])->where('status', 'on')->order_by_asc('id')->find_array(), $nuxrOld, $nuxrNew);
            if ($nuxrCheck !== \Reseller\Domain\LegacyReprovisionService::RENAME_OK) {
                $msg .= Lang::T(\Reseller\Domain\LegacyReprovisionService::RENAME_REFUSALS[$nuxrCheck]) . '<br>';
            }
        }

        if ($msg == '') {
            if (!empty($_FILES['photo']['name']) && file_exists($_FILES['photo']['tmp_name'])) {
                if (function_exists('imagecreatetruecolor')) {
                    $hash = md5_file($_FILES['photo']['tmp_name']);
                    $subfolder = substr($hash, 0, 2);
                    $folder = $UPLOAD_PATH . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR;
                    if (!file_exists($folder)) {
                        mkdir($folder);
                    }
                    $folder = $UPLOAD_PATH . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . $subfolder . DIRECTORY_SEPARATOR;
                    if (!file_exists($folder)) {
                        mkdir($folder);
                    }
                    $imgPath = $folder . $hash . '.jpg';
                    if (!file_exists($imgPath)) {
                        File::resizeCropImage($_FILES['photo']['tmp_name'], $imgPath, 1600, 1600, 100);
                    }
                    if (!file_exists($imgPath . '.thumb.jpg')) {
                        if (_post('faceDetect') == 'yes') {
                            try {
                                $detector = new svay\FaceDetector();
                                $detector->setTimeout(5000);
                                $detector->faceDetect($imgPath);
                                $detector->cropFaceToJpeg($imgPath . '.thumb.jpg', false);
                            } catch (Exception $e) {
                                File::makeThumb($imgPath, $imgPath . '.thumb.jpg', 200);
                            } catch (Throwable $e) {
                                File::makeThumb($imgPath, $imgPath . '.thumb.jpg', 200);
                            }
                        } else {
                            File::makeThumb($imgPath, $imgPath . '.thumb.jpg', 200);
                        }
                    }
                    if (file_exists($imgPath)) {
                        if ($c['photo'] != '' && strpos($c['photo'], 'default') === false) {
                            if (file_exists($UPLOAD_PATH . $c['photo'])) {
                                unlink($UPLOAD_PATH . $c['photo']);
                                if (file_exists($UPLOAD_PATH . $c['photo'] . '.thumb.jpg')) {
                                    unlink($UPLOAD_PATH . $c['photo'] . '.thumb.jpg');
                                }
                            }
                        }
                        $c->photo = '/photos/' . $subfolder . '/' . $hash . '.jpg';
                    }
                    if (file_exists($_FILES['photo']['tmp_name'])) unlink($_FILES['photo']['tmp_name']);
                } else {
                    r2(getUrl('settings/app'), 'e', 'PHP GD is not installed');
                }
            }
            if (!$resellerOwned) {
                if ($userDiff) {
                    $c->username = $username;
                }
                if ($password != '') {
                    $c->password = $password;
                }
                $c->pppoe_username = $pppoe_username;
                $c->pppoe_password = $pppoe_password;
                $c->pppoe_ip = $pppoe_ip;
            }
            $c->fullname = $fullname;
            $c->email = $email;
            $c->account_type = $account_type;
            $c->address = $address;
            $c->status = $status;
            $c->phonenumber = $phonenumber;
            $c->service_type = $service_type;
            $c->coordinates = $coordinates;
            $c->city = $city;
            $c->district = $district;
            $c->state = $state;
            $c->zip = $zip;
            // WP9 U0 (P4): a name this edit acquires is checked and saved under the shared identity lock (customers, live
            // voucher identities, RADIUS keys; the customer itself and its own RADIUS keys excluded); the device steps
            // below run after the lock is released
            $nuxrAcquired = $resellerOwned ? [] : \Reseller\Domain\CustomerIdentity::acquired($nuxrOld, $nuxrNew);
            if ($nuxrAcquired === []) {
                $c->save();
            } else {
                try {
                    \Reseller\Domain\CustomerIdentity::hold(static function (\PDO $pdo) use ($c, $nuxrAcquired, $nuxrOld): void {
                        \Reseller\Domain\CustomerIdentity::assertAvailable($pdo, $nuxrAcquired, (int) $c['id'],
                            \Reseller\Domain\CustomerIdentity::keysOf((string) $nuxrOld['username'], (string) $nuxrOld['pppoe_username']));
                        $c->save();
                    });
                } catch (\Reseller\Domain\DomainError $e) {
                    r2(getUrl('customers/edit/') . (int) $id, 'e', Lang::T($e->getMessage()));
                }
            }


            // Update Customers Attributes values in tbl_customers_fields table
            foreach ($customFields as $customField) {
                $fieldName = $customField['field_name'];
                if (isset($_POST['custom_fields'][$fieldName])) {
                    $customFieldValue = $_POST['custom_fields'][$fieldName];
                    $customField->set('field_value', $customFieldValue);
                    $customField->save();
                }
            }

            // Add new Customers Attributess
            if (isset($_POST['custom_field_name']) && isset($_POST['custom_field_value'])) {
                $newCustomFieldNames = $_POST['custom_field_name'];
                $newCustomFieldValues = $_POST['custom_field_value'];

                // Check if the number of field names and values match
                if (count($newCustomFieldNames) == count($newCustomFieldValues)) {
                    $numNewFields = count($newCustomFieldNames);

                    for ($i = 0; $i < $numNewFields; $i++) {
                        $fieldName = $newCustomFieldNames[$i];
                        $fieldValue = $newCustomFieldValues[$i];

                        // Insert the new Customers Attributes
                        $newCustomField = ORM::for_table('tbl_customers_fields')->create();
                        $newCustomField->set('customer_id', $id);
                        $newCustomField->set('field_name', $fieldName);
                        $newCustomField->set('field_value', $fieldValue);
                        $newCustomField->save();
                    }
                }
            }

            // Delete Customers Attributess
            if (isset($_POST['delete_custom_fields'])) {
                $fieldsToDelete = $_POST['delete_custom_fields'];
                foreach ($fieldsToDelete as $fieldName) {
                    // Delete the Customers Attributes with the given field name
                    ORM::for_table('tbl_customers_fields')
                        ->where('field_name', $fieldName)
                        ->where('customer_id', $id)
                        ->delete_many();
                }
            }

            if ($userDiff || $pppoeDiff || $pppoeIpDiff || $passDiff) {
                // WP8 A3: in id order, as the rename pre-check saw them
                $turs = ORM::for_table('tbl_user_recharges')->where('customer_id', $c['id'])->order_by_asc('id')->findMany();
                // WP8 A3 (owner D-3): the Radius device may replace an existing exact target only when it is this
                // customer's own key (its old keys, or a key this edit renamed into first)
                \Reseller\Domain\LegacyReprovisionService::renameScope($nuxrOld);
                // WP9 / W8-17: the old-name sessions of this rename are confirmed and audited after the device calls
                \Reseller\Domain\LegacyReprovisionService::legacyRenameBegin();
                try {
                foreach ($turs as $tur) {
                    $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
                    $dvc = Package::getDevice($p);
                    if ($_app_stage != 'demo') {
                        // if has active package
                        if ($tur['status'] == 'on') {
                            if (file_exists($dvc)) {
                                require_once $dvc;
                                // username, then the PPPoE key (added: new username -> PPPoE username; cleared: back to
                                // the username; changed: old -> new). WP8 A3 (F-4, FIX-4): the shared RADIUS database
                                // renames each old key once; a second plan on the same key is only re-provisioned
                                foreach (\Reseller\Domain\LegacyReprovisionService::renamePairs($nuxrOld, $nuxrNew, (string) $tur['type']) as [$nuxrFrom, $nuxrTo]) {
                                    if ($p['device'] === 'Radius') {
                                        if (isset($nuxrRenamed[$nuxrFrom])) {
                                            continue;
                                        }
                                        $nuxrRenamed[$nuxrFrom] = true;
                                    }
                                    (new $p['device'])->change_username($p, $nuxrFrom, $nuxrTo);
                                }
                                (new $p['device'])->add_customer($c, $p);
                            } else {
                                new Exception(Lang::T("Devices Not Found"));
                            }
                        }
                    }
                    $tur->username = $username;
                    $tur->save();
                }
                } finally {
                    \Reseller\Domain\LegacyReprovisionService::renameScope(null);
                    $nuxrLegacyOldSession = \Reseller\Domain\LegacyReprovisionService::legacyRenameFinish(\Reseller\Security\ActorContext::current(), $c);
                }
            }
            // WP8 A3 (FIX-5): the old-identity session state of a reseller-owned rename; UNCONFIRMED and
            // NO_ACCOUNTING_EVIDENCE are shown as warnings, never as success
            $nuxrOldSession = isset($nuxrReprovision) ? ($nuxrReprovision['old_session'] ?? null) : ($nuxrLegacyOldSession ?? null);
            r2(getUrl('customers/view/') . $id, \Reseller\Domain\LegacyReprovisionService::oldSessionNoticeType($nuxrOldSession), 'User Updated Successfully' . \Reseller\Domain\LegacyReprovisionService::oldSessionNote($nuxrOldSession));
        } else {
            r2(getUrl('customers/edit/') . $id, 'e', $msg);
        }
        break;

    default:
        run_hook('list_customers'); #HOOK
        $search = _req('search');
        $order = _req('order', 'username');
        $filter = _req('filter', 'Active');
        $orderby = _req('orderby', 'asc');
        // WP2 / SEC-12: sort column and direction are allowlisted (no raw ORDER BY from the request)
        if (!in_array($order, ['username', 'created_at', 'balance', 'status', 'lastname', 'fullname'], true)) {
            $order = 'username';
        }
        $orderby = $orderby === 'desc' ? 'desc' : 'asc';
        $order_pos = [
            'username' => 0,
            'created_at' => 8,
            'balance' => 3,
            'status' => 7
        ];

        // WP6A: status filter gains "All"; service-state filter (active / expiring within 7 days / expired / none)
        $nuxrStatuses = ORM::for_table('tbl_customers')->getEnum("status");
        if ($filter !== 'All' && !in_array($filter, $nuxrStatuses, true)) {
            $filter = 'Active';
        }
        $nuxrService = _req('service', '');
        if (!in_array($nuxrService, ['', 'active', 'expiring', 'expired', 'none'], true)) {
            $nuxrService = '';
        }
        // WP6B: reseller workspace links (customers of one owner / assigned to one Sales); applied after the scope
        // predicate below, so they only narrow what the actor may already see
        $nuxrOwner = \Reseller\Security\DataScope::validId(_req('owner', '')) ? (int) _req('owner') : null;
        $nuxrSales = \Reseller\Security\DataScope::validId(_req('sales', '')) ? (int) _req('sales') : null;
        $append_url = "&order=" . urlencode($order) . "&filter=" . urlencode($filter) . "&orderby=" . urlencode($orderby) . "&service=" . urlencode($nuxrService)
            . ($nuxrOwner !== null ? '&owner=' . $nuxrOwner : '') . ($nuxrSales !== null ? '&sales=' . $nuxrSales : '');

        // WP2 / WP6A: the scope predicate first, then the parenthesized search group (it can never widen the scope)
        $query = ORM::for_table('tbl_customers');
        \Reseller\Security\DataScope::scopeCustomers($query, $nuxrActor, \Reseller\Security\Capability::CUSTOMER_READ);
        $nuxrTerm = \Reseller\Domain\CustomerSearch::term($search);
        if ($nuxrTerm !== null) {
            [$nuxrPred, $nuxrParams] = \Reseller\Domain\CustomerSearch::predicate($nuxrTerm, 'tbl_customers', true, \Reseller\Ui\Subscriber::sessionUsernames($nuxrTerm));
            $query->where_raw($nuxrPred, $nuxrParams);
        }
        if ($filter !== 'All') {
            $query->where('tbl_customers.status', $filter);
        }
        if ($nuxrOwner !== null) {
            $query->where('tbl_customers.manager_id', $nuxrOwner);
        }
        if ($nuxrSales !== null) {
            $query->where('tbl_customers.assigned_sales_id', $nuxrSales);
        }
        $nuxrToday = date('Y-m-d');
        $nuxrSoon = date('Y-m-d', strtotime('+7 days'));
        $nuxrOn = "EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = tbl_customers.id AND sr.status = 'on' AND sr.expiration >= ?)";
        if ($nuxrService === 'active') {
            $query->where_raw($nuxrOn, [$nuxrToday]);
        } elseif ($nuxrService === 'expiring') {
            $query->where_raw("EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = tbl_customers.id AND sr.status = 'on' AND sr.expiration BETWEEN ? AND ?)", [$nuxrToday, $nuxrSoon]);
        } elseif ($nuxrService === 'expired') {
            $query->where_raw("NOT {$nuxrOn} AND EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = tbl_customers.id)", [$nuxrToday]);
        } elseif ($nuxrService === 'none') {
            $query->where_raw('NOT EXISTS (SELECT 1 FROM tbl_user_recharges sr WHERE sr.customer_id = tbl_customers.id)');
        }
        if ($order == 'lastname') {
            $query->order_by_expr("SUBSTR(fullname, INSTR(fullname, ' ')) $orderby");
        } else {
            if ($orderby == 'asc') {
                $query->order_by_asc($order);
            } else {
                $query->order_by_desc($order);
            }
        }
        if (_post('export', '') == 'csv') {
            \Reseller\Security\PermissionService::require($nuxrActor, \Reseller\Security\Capability::CUSTOMER_EXPORT);
            $csrf_token = _post('csrf_token');
            if (!Csrf::check($csrf_token)) {
                r2(getUrl('customers'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
            }
            $d = $query->findMany();
            $h = false;
            set_time_limit(-1);
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header("Content-type: text/csv");
            header('Content-Disposition: attachment;filename="phpnuxbill_customers_' . $filter . '_' . date('Y-m-d_H_i') . '.csv"');
            header('Content-Transfer-Encoding: binary');

            $headers = [
                'id',
                'username',
                'fullname',
                'address',
                'phonenumber',
                'email',
                'balance',
                'service_type',
            ];
            $fp = fopen('php://output', 'wb');
            if (!$h) {
                fputcsv($fp, $headers, ";");
                $h = true;
            }
            foreach ($d as $c) {
                $row = [
                    $c['id'],
                    $c['username'],
                    $c['fullname'],
                    str_replace("\n", " ", $c['address']),
                    $c['phonenumber'],
                    $c['email'],
                    $c['balance'],
                    $c['service_type'],
                ];
                fputcsv($fp, array_map([\Reseller\Ui\Format::class, 'csvCell'], $row), ";");
            }
            fclose($fp);
            die();
        }
        $nuxrTotal = (clone $query)->count();
        $d = Paginator::findMany($query, ['search' => $search], 30, $append_url);
        $ui->assign('d', $d);
        $ui->assign('statuses', $nuxrStatuses);
        // WP6A: one query for the page's service summaries (replaces the per-row sequential AJAX); action flags
        $ui->assign('nuxr_plans', \Reseller\Ui\Subscriber::plans(array_map(static fn($c) => (int) $c['id'], is_array($d) ? $d : ($d instanceof \Traversable ? iterator_to_array($d) : []))));
        $ui->assign('nuxr_total', $nuxrTotal);
        $ui->assign('nuxr_service', $nuxrService);
        $ui->assign('nuxr_owner', $nuxrOwner);
        $ui->assign('nuxr_sales', $nuxrSales);
        $ui->assign('nuxr_can_activate', \Reseller\Activation\ActivationService::eligible($nuxrActor));
        $ui->assign('nuxr_global', \Reseller\Security\PermissionService::scopeOf($nuxrActor, \Reseller\Security\Capability::CUSTOMER_READ) === 'G');
        $ui->assign('filter', $filter);
        $ui->assign('search', $search);
        $ui->assign('order', $order);
        $ui->assign('order_pos', $order_pos[$order]);
        $ui->assign('orderby', $orderby);
        $ui->assign('csrf_token',  Csrf::generateAndStoreToken());
        $ui->display('admin/customers/list.tpl');
        break;
}
