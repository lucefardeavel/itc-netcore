<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

_auth();
$action = $routes['1'];
$user = User::_info();
$ui->assign('_user', $user);

// WP5B §7a.2 (E8): a buy with the balance "gateway" is the balance purchase itself, in the same POST
if ($action === 'buy' && _post('gateway') === 'balance') {
    unset($_SESSION['gateway']);
    $action = 'pay';
}

switch ($action) {
    case 'voucher':
        $ui->assign('_system_menu', 'voucher');
        $ui->assign('_title', Lang::T('Order Voucher'));
        run_hook('customer_view_order'); #HOOK
        $ui->display('customer/order.tpl');
        break;
    case 'history':
        $ui->assign('_system_menu', 'history');
        $query = ORM::for_table('tbl_payment_gateway')->where('user_id', $user['id'])->order_by_desc('id');
        $d = Paginator::findMany($query);

        if (empty($order) || $order < 5) {
            $query = ORM::for_table('tbl_payment_gateway')->where('username', $user['username'])->order_by_desc('id');
            $d = Paginator::findMany($query);
        }

        $ui->assign('d', $d);
        $ui->assign('_title', Lang::T('Order History'));
        run_hook('customer_view_order_history'); #HOOK
        $ui->display('customer/orderHistory.tpl');
        break;
    case 'balance':
        if (strpos($user['email'], '@') === false) {
            r2(getUrl('accounts/profile'), 'e', Lang::T("Please enter your email address"));
        }
        $ui->assign('_title', 'Top Up');
        $ui->assign('_system_menu', 'balance');
        $plans_balance = ORM::for_table('tbl_plans')->where('enabled', '1')->where('type', 'Balance')->where('prepaid', 'yes')->find_many();
        $ui->assign('plans_balance', $plans_balance);
        $ui->display('customer/orderBalance.tpl');
        break;
    case 'package':
        if (strpos($user['email'], '@') === false) {
            r2(getUrl('accounts/profile'), 'e', Lang::T("Please enter your email address"));
        }
        $ui->assign('_title', 'Order Plan');
        $ui->assign('_system_menu', 'package');
        $account_type = $user['account_type'];
        if (empty($account_type)) {
            $account_type = 'Personal';
        }
        if (!empty($_SESSION['nux-router'])) {
            if ($_SESSION['nux-router'] == 'radius') {
                $radius_pppoe = ORM::for_table('tbl_plans')
                    ->where('plan_type', $account_type)
                    ->where('enabled', '1')
                    ->where('is_radius', 1)
                    ->where('type', 'PPPOE')
                    ->where('prepaid', 'yes')->find_many();
                $radius_hotspot = ORM::for_table('tbl_plans')
                    ->where('plan_type', $account_type)
                    ->where('enabled', '1')
                    ->where('is_radius', 1)
                    ->where('type', 'Hotspot')
                    ->where('prepaid', 'yes')->find_many();
            } else {
                $routers = ORM::for_table('tbl_routers')->where('id', $_SESSION['nux-router'])->find_many();
                $rs = [];
                foreach ($routers as $r) {
                    $rs[] = $r['name'];
                }
                $plans_pppoe = ORM::for_table('tbl_plans')
                    ->where('plan_type', $account_type)
                    ->where('enabled', '1')
                    ->where_in('routers', $rs)
                    ->where('is_radius', 0)
                    ->where('type', 'PPPOE')
                    ->where('prepaid', 'yes')
                    ->find_many();
                $plans_hotspot = ORM::for_table('tbl_plans')
                    ->where('plan_type', $account_type)
                    ->where('enabled', '1')
                    ->where_in('routers', $rs)
                    ->where('is_radius', 0)
                    ->where('type', 'Hotspot')
                    ->where('prepaid', 'yes')
                    ->find_many();
            }
        } else {
            $radius_pppoe = ORM::for_table('tbl_plans')
                ->where('plan_type', $account_type)
                ->where('enabled', '1')
                ->where('is_radius', 1)
                ->where('type', 'PPPOE')
                ->where('prepaid', 'yes')
                ->find_many();
            $radius_hotspot = ORM::for_table('tbl_plans')
                ->where('plan_type', $account_type)
                ->where('enabled', '1')
                ->where('is_radius', 1)
                ->where('type', 'Hotspot')
                ->where('prepaid', 'yes')
                ->find_many();

            $routers = ORM::for_table('tbl_routers')->find_many();
            $plans_pppoe = ORM::for_table('tbl_plans')
                ->where('plan_type', $account_type)
                ->where('enabled', '1')
                ->where('is_radius', 0)
                ->where('type', 'PPPOE')
                ->where('prepaid', 'yes')
                ->find_many();
            $plans_hotspot = ORM::for_table('tbl_plans')
                ->where('plan_type', $account_type)
                ->where('enabled', '1')->where('is_radius', 0)
                ->where('type', 'Hotspot')
                ->where('prepaid', 'yes')
                ->find_many();
            $plans_vpn = ORM::for_table('tbl_plans')
                ->where('plan_type', $account_type)
                ->where('enabled', '1')->where('is_radius', 0)
                ->where('type', 'VPN')
                ->where('prepaid', 'yes')
                ->find_many();
        }
        $ui->assign('routers', $routers);
        $ui->assign('radius_pppoe', $radius_pppoe);
        $ui->assign('radius_hotspot', $radius_hotspot);
        $ui->assign('plans_pppoe', $plans_pppoe);
        $ui->assign('plans_hotspot', $plans_hotspot);
        $ui->assign('plans_vpn', $plans_vpn);
        run_hook('customer_view_order_plan'); #HOOK
        $ui->display('customer/orderPlan.tpl');
        break;
    case 'unpaid':
        $d = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->where('status', 1)
            ->find_one();
        run_hook('custome
        r_find_unpaid'); #HOOK
        if ($d) {
            if (empty($d['pg_url_payment'])) {
                r2(getUrl('order/buy/') . $trx['routers_id'] . '/' . $trx['plan_id'], 'w', Lang::T("Checking payment"));
            } else {
                r2(getUrl('order/view/') . $d['id'] . '/check/', 's', Lang::T("You have unpaid transaction"));
            }
        } else {
            r2(getUrl('order/package/'), 's', Lang::T("You have no unpaid transaction"));
        }
        break;
    case 'view':
        $trxid = $routes['2'];
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->find_one($trxid);
        run_hook('customer_view_payment'); #HOOK
        // jika tidak ditemukan, berarti punya orang lain
        if (empty($trx)) {
            r2(getUrl('order/package'), 'w', Lang::T("Payment not found"));
        }
        // jika url kosong, balikin ke buy, kecuali cancel
        if ($trx['status'] == 1 && empty($trx['pg_url_payment']) && $routes['3'] != 'cancel') {
            r2(getUrl('order/buy/') . (($trx['routers_id'] == 0) ? $trx['routers'] : $trx['routers_id']) . '/' . $trx['plan_id'], 'w', Lang::T("Checking payment"));
        }
        if ($routes['3'] == 'check') {
            // WP5B §7 (SEC-13): only a configured gateway whose file resolves inside the gateway directory
            $pgFile = \Reseller\Domain\PaymentGatewayResolver::resolve($trx['gateway']);
            if ($pgFile === null) {
                r2(getUrl('order/view/') . $trxid, 'e', Lang::T("No Payment Gateway Available"));
            }
            // WP2 / OD-11 (E10): a gateway status check can complete a payment and grant service
            \Reseller\Domain\ServiceGate::guardCustomer($user['id'], 'orderCheck', 'order/view/' . (int) $trxid);
            // WP5B §7: the plugin runs only while the row is unpaid (a paid / cancelled / held row is final)
            if ((int) $trx['status'] === 1) {
                run_hook('customer_check_payment_status'); #HOOK
                include $pgFile;
                call_user_func($trx['gateway'] . '_validate_config');
                call_user_func($trx['gateway'] . '_get_status', $trx, $user);
            }
        } else if ($routes['3'] == 'cancel') {
            run_hook('customer_cancel_payment'); #HOOK
            // WP5B §7: conditional transition; a paid (or held) row can never be cancelled
            $st = ORM::get_db()->prepare("UPDATE tbl_payment_gateway SET pg_paid_response = '{}', status = 4, paid_date = ? WHERE id = ? AND username = ? AND status = 1");
            $st->execute([date('Y-m-d H:i:s'), (int) $trxid, $user['username']]);
            $trx = ORM::for_table('tbl_payment_gateway')
                ->where('username', $user['username'])
                ->find_one($trxid);
        }
        if (empty($trx)) {
            r2(getUrl('order/package'), 'e', Lang::T("Transaction Not found"));
        }

        $router = ORM::for_table('tbl_routers')->where('name', $trx['routers'])->find_one();
        $plan = ORM::for_table('tbl_plans')->find_one($trx['plan_id']);
        $bandw = ORM::for_table('tbl_bandwidth')->find_one($plan['id_bw']);
        $invoice = ORM::for_table('tbl_transactions')->where("invoice", $trx['trx_invoice'])->find_one();
        $ui->assign('invoice', $invoice);
        $ui->assign('trx', $trx);
        $ui->assign('router', $router);
        $ui->assign('plan', $plan);
        $ui->assign('bandw', $bandw);
        $ui->assign('_title', 'TRX #' . $trxid);
        $ui->display('customer/orderView.tpl');
        break;
    case 'pay':
        if ($config['enable_balance'] != 'yes') {
            r2(getUrl('order/package'), 'e', Lang::T("Balance not enabled"));
        }
        // WP2 / OD-11 (E8): no balance purchase for a reseller-owned customer
        \Reseller\Domain\ServiceGate::guardCustomer($user['id'], 'orderPay', 'order/package');
        if ($user['status'] != 'Active') {
            _alert(Lang::T('This account status') . ' : ' . Lang::T($user['status']), 'danger', "");
        }
        $plan = ORM::for_table('tbl_plans')->find_one($routes[3]);
        if (!$plan) {
            r2(getUrl('order/package'), 'e', Lang::T("Plan Not found"));
        }
        if ($plan['is_radius'] == '1') {
            $router_name = 'radius';
            $router = 'radius';
        } else {
            $router_name = $plan['routers'];
        }

        list($bills, $add_cost) = User::getBills($id_customer);

        // Tax calculation start
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
        // Tax calculation stop
        $total_cost = $plan['price'] + $add_cost + $tax;
        // WP5B §7a.2 (E8): the legacy amount, debited exactly once per request nonce under the customer row lock,
        // HELD before the grant; a replay never debits or grants again (POST + CSRF; no Balance::*)
        try {
            $nuxrBuy = \Reseller\Domain\LegacyCustomerBalanceService::purchase([
                'kind' => 'buy', 'key' => \Reseller\Domain\LegacyCustomerBalanceService::key('buy', (int) $user['id'], (string) _post('nuxr_nonce')),
                'payer_id' => (int) $user['id'], 'target_id' => (int) $user['id'], 'plan_id' => (int) $plan['id'], 'routers' => (string) $router_name,
                'amount' => $total_cost, 'grant' => ['Customer', 'Balance'],
            ]);
        } catch (\Reseller\Domain\DomainError $e) {
            r2(getUrl('order/gateway/' . $routes[2] . '/' . $routes[3]), 'e', $e->reason === 'INSUFFICIENT_BALANCE' ? Lang::T("Insufficient balance") : Lang::T($e->getMessage()));
        }
        if ($nuxrBuy['status'] === 'COMPLETED') {
            r2(getUrl('voucher/invoice/'), 's', Lang::T("Success to buy package"));
        } elseif ($nuxrBuy['status'] === 'HELD') {
            r2(getUrl('order/view/' . $nuxrBuy['journal_id']), 'w', Lang::T("Your purchase is being reviewed; the amount is held"));
        }
        Message::sendTelegram("Buy Package with Balance Failed\n\n#u$user[username] #buy \n" . $plan['name_plan'] .
            "\nRouter: " . $router_name . "\nPrice: " . $total_cost);
        r2(getUrl('order/package'), 'e', Lang::T("Failed to buy package"));
        break;

    case 'send':
        if ($config['enable_balance'] != 'yes') {
            r2(getUrl('order/package'), 'e', Lang::T("Balance not enabled"));
        }
        if ($user['status'] != 'Active') {
            _alert(Lang::T('This account status') . ' : ' . Lang::T($user['status']), 'danger', "");
        }
        $ui->assign('_title', Lang::T('Buy for friend'));
        $ui->assign('_system_menu', 'package');
        $plan = ORM::for_table('tbl_plans')->find_one($routes['3']);
        if (empty($plan)) {
            r2(getUrl('order/package'), 'e', Lang::T("Plan Not found"));
        }
        if (!$plan['enabled']) {
            r2(getUrl('home'), 'e', 'Plan is not exists');
        }
        if ($plan['is_radius'] == '1') {
            $routes['2'] = 0;
            $router_name = 'radius';
        } else {
            $router_name = $plan['routers'];
        }
        $tax_rate_setting = isset($config['tax_rate']) ? $config['tax_rate'] : null;
        $custom_tax_rate = isset($config['custom_tax_rate']) ? (float)$config['custom_tax_rate'] : null;

        if ($tax_rate_setting === 'custom') {
            $tax_rate = $custom_tax_rate;
        } else {
            $tax_rate = $tax_rate_setting;
        }

        $tax_enable = isset($config['enable_tax']) ? $config['enable_tax'] : 'no';

        if ($tax_enable === 'yes') {
            $tax = Package::tax($plan['price'], $tax_rate);
            $ui->assign('tax', $tax);
        } else {
            $tax = 0;
        }

        // Add tax to plan price
        $plan['price'] += $tax;

        if (isset($_POST['send']) && $_POST['send'] == 'plan') {
            // WP2 / OD-11 (E9): no plan gift from or to a reseller-owned customer
            \Reseller\Domain\ServiceGate::guardCustomer($user['id'], 'orderSend', 'order/package');
            \Reseller\Domain\ServiceGate::guardUsername(_post('username'), 'orderSend', 'order/package');
            $target = ORM::for_table('tbl_customers')->where('username', _post('username'))->find_one();
            list($bills, $add_cost) = User::getBills($target['id']);
            if (!empty($add_cost)) {
                $ui->assign('bills', $bills);
                $ui->assign('add_cost', $add_cost);
                $plan['price'] += $add_cost;
            }

            if (!$target) {
                r2(getUrl('home'), 'd', Lang::T('Username not found'));
            }
            if ($user['balance'] < $plan['price']) {
                r2(getUrl('home'), 'd', Lang::T('insufficient balance'));
            }
            if ($user['username'] == $target['username']) {
                r2(getUrl('order/pay/$routes[2]/$routes[3]'), 's', '^_^ v');
            }
            $active = ORM::for_table('tbl_user_recharges')
                ->where('username', _post('username'))
                ->where('status', 'on')
                ->find_one();

            if ($active && $active['plan_id'] != $plan['id']) {
                r2(getUrl('order/package'), 'e', Lang::T("Target has active plan, different with current plant.") . " [ <b>$active[namebp]</b> ]");
            }
            // WP5B §7a.2 (E9): sender debited exactly once per request nonce (journal = the sender's "Send Plan"
            // row), target granted; replays never debit or grant again (no Balance::*)
            try {
                $nuxrGift = \Reseller\Domain\LegacyCustomerBalanceService::purchase([
                    'kind' => 'gift', 'key' => \Reseller\Domain\LegacyCustomerBalanceService::key('gift', (int) $user['id'], (string) _post('nuxr_nonce')),
                    'payer_id' => (int) $user['id'], 'target_id' => (int) $target['id'], 'plan_id' => (int) $plan['id'], 'routers' => (string) $router_name,
                    'routers_id' => (int) $routes['2'], 'amount' => $plan['price'], 'grant' => [(string) $user['username'], 'Balance'],
                ]);
            } catch (\Reseller\Domain\DomainError $e) {
                r2(getUrl('order/package'), 'e', $e->reason === 'INSUFFICIENT_BALANCE' ? Lang::T('insufficient balance') : Lang::T($e->getMessage()));
            }
            $result = $nuxrGift['status'] === 'COMPLETED' && !$nuxrGift['replayed'] ? $nuxrGift['invoice'] : null;
            if ($nuxrGift['replayed'] || $nuxrGift['status'] === 'HELD') {
                r2(getUrl('order/view/' . $nuxrGift['journal_id']), $nuxrGift['status'] === 'COMPLETED' ? 's' : 'w', $nuxrGift['status'] === 'COMPLETED'
                    ? Lang::T("Success to send package") : Lang::T("Your purchase is being reviewed; the amount is held"));
            }
            if (!empty($result)) {
                $trx_id = $nuxrGift['journal_id'];
                //receiver
                $d = ORM::for_table('tbl_payment_gateway')->create();
                $d->username = $target['username'];
                $d->user_id = $target['id'];
                $d->gateway = $user['username'];
                $d->plan_id = $plan['id'];
                $d->plan_name = $plan['name_plan'];
                $d->routers_id = $routes['2'];
                $d->routers = $router_name;
                $d->price = $plan['price'];
                $d->payment_method = "Balance";
                $d->payment_channel = "Received Plan";
                $d->created_date = date('Y-m-d H:i:s');
                $d->paid_date = date('Y-m-d H:i:s');
                $d->expired_date = date('Y-m-d H:i:s');
                $d->pg_url_payment = 'balance';
                $d->trx_invoice = $result;
                $d->status = 2;
                $d->save();
                r2(getUrl("order/view/$trx_id"), 's', Lang::T("Success to send package"));
            } else {
                $errorMessage = "Send Package with Balance Failed\n\n#u$user[username] #send \n" . $plan['name_plan'] .
                    "\nRouter: " . $router_name .
                    "\nPrice: " . $plan['price'];

                if ($tax_enable === 'yes') {
                    $errorMessage .= "\nTax: " . $tax;
                }

                r2(getUrl('order/package'), 'e', Lang::T("Failed to Send package"));
                Message::sendTelegram($errorMessage);
            }
        }
        $ui->assign('username', $_GET['u']);
        $ui->assign('router', $router_name);
        $ui->assign('plan', $plan);
        $ui->assign('tax', $tax);
        $ui->display('customer/sendPlan.tpl');
        break;
    case 'gateway':
        // WP2 / OD-11 (E10): refused before any payment is started
        \Reseller\Domain\ServiceGate::guardCustomer($user['id'], 'orderGateway', 'order/package');
        $ui->assign('_title', Lang::T('Select Payment Gateway'));
        $ui->assign('_system_menu', 'package');
        if (strpos($user['email'], '@') === false) {
            r2(getUrl('accounts/profile'), 'e', Lang::T("Please enter your email address"));
        }
        $tax_enable = isset($config['enable_tax']) ? $config['enable_tax'] : 'no';
        $tax_rate_setting = isset($config['tax_rate']) ? $config['tax_rate'] : null;
        $custom_tax_rate = isset($config['custom_tax_rate']) ? (float)$config['custom_tax_rate'] : null;
        if ($tax_rate_setting === 'custom') {
            $tax_rate = $custom_tax_rate;
        } else {
            $tax_rate = $tax_rate_setting;
        }
        $plan = ORM::for_table('tbl_plans')->find_one($routes['3']);
        $add_cost = 0;
        if ($router['name'] != 'balance') {
            list($bills, $add_cost) = User::getBills($id_customer);
        }
		$add_inv = User::getAttribute("Invoice", $id_customer);
		if (!empty($add_inv)) {
			$plan['price'] = $add_inv;
		}

        if($config['enable_coupons']){
            if (!isset($_SESSION['coupon_attempts'])) {
                $_SESSION['coupon_attempts'] = 0;
                $_SESSION['last_attempt_time'] = time();
            }

            if ($_SESSION['coupon_attempts'] >= 5) {
                $timeout = 10 * 60; // 10 minutes in seconds
                $time_diff = time() - $_SESSION['last_attempt_time'];

                if ($time_diff >= $timeout) {
                    $_SESSION['coupon_attempts'] = 0;
                    $_SESSION['last_attempt_time'] = time();
                } else {
                    $remaining_time = ceil(($timeout - $time_diff) / 60);
                    r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Too many invalid attempts. Please try again after $remaining_time minutes."));
                }
            }

            if (_post('coupon')) {
                if ($plan['routers'] === 'balance') {
                    r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Coupon not available for Balance"));
                }

                $coupon = ORM::for_table('tbl_coupons')->where('code', _post('coupon'))->find_one();

                if (!$coupon) {
                    $_SESSION['coupon_attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                    r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Coupon not found"));
                }

                if ($coupon['status'] != 'active') {
                    $_SESSION['coupon_attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                    r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Coupon is not active"));
                }

                // Reset attempts after a successful coupon validation
                $_SESSION['coupon_attempts'] = 0;
                $_SESSION['last_attempt_time'] = time();

                $today = date('Y-m-d');
                if ($today < $coupon['start_date'] || $today > $coupon['end_date']) {
                    $_SESSION['coupon_attempts']++;
                    r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Coupon is not valid for today"));
                }

                if ($coupon['max_usage'] > 0 && $coupon['usage_count'] >= $coupon['max_usage']) {
                    $_SESSION['coupon_attempts']++;
                    r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Coupon usage limit reached"));
                }

                if ($plan['price'] < $coupon['min_order_amount']) {
                    $_SESSION['coupon_attempts']++;
                    r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("The order amount does not meet the minimum requirement for this coupon"));
                }

                // Calculate discount value
                $discount = 0;
                switch ($coupon['type']) {
                    case 'percent':
                        $discount = ($coupon['value'] / 100) * $plan['price'];
                        if ($discount > $coupon['max_discount_amount']) {
                            $discount = $coupon['max_discount_amount'];
                        }
                        break;
                    case 'fixed':
                        $discount = $coupon['value'];
                        break;
                }

                // Ensure discount does not exceed the plan price
                if ($discount >= $plan['price']) {
                    r2($_SERVER['HTTP_REFERER'], 'e', Lang::T("Discount value exceeds the plan price"));
                }

                $plan['price'] -= $discount;
                $coupon->usage_count = $coupon['usage_count'] + 1;
                $coupon->save();
                // WP5B §7 (SEC-14): the buy step uses only this server-validated discount, never a posted one
                $_SESSION['wp5b_coupon_discount'][(int) $plan['id']] = $discount;

                $ui->assign('discount', $discount);
                $ui->assign('notify', Lang::T("Coupon applied successfully. You saved " . Lang::moneyFormat($discount)));
                $ui->assign('notify_t', 's');
            }
        }

        $tax = Package::tax($plan['price'] + $add_cost, $tax_rate);
        $pgs = array_values(explode(',', $config['payment_gateway']));
        if (count($pgs) == 0) {
            sendTelegram("Payment Gateway not set, please set it in Settings");
            _log(Lang::T("Payment Gateway not set, please set it in Settings"));
            r2(getUrl('home'), 'e', Lang::T("Failed to create Transaction.."));
        }
        if (count($pgs) > 0) {
            $ui->assign('pgs', $pgs);
            if ($tax_enable === 'yes') {
                $ui->assign('tax', $tax);
            }

            if (_post('custom') == '1') {
                if (_post('amount') > 0) {
                    $ui->assign('custom', '1');
                    $ui->assign('amount', _post('amount'));
                } else {
                    r2(getUrl('order/balance'), 'e', Lang::T("Please enter amount"));
                }
            }

            $ui->assign('route2', $routes[2]);
            $ui->assign('route3', $routes[3]);
            $ui->assign('add_cost', $add_cost);
            $ui->assign('bills', $bills);
            $ui->assign('plan', $plan);
            $ui->display('customer/selectGateway.tpl');
            break;
        } else {
            sendTelegram("Payment Gateway not set, please set it in Settings");
            _log(Lang::T("Payment Gateway not set, please set it in Settings"));
            r2(getUrl('home'), 'e', Lang::T("Failed to create Transaction.."));
        }
    case 'buy':
        // WP2 / OD-11 (E10): refused before any payment is started
        \Reseller\Domain\ServiceGate::guardCustomer($user['id'], 'orderBuy', 'order/package');
        $gateway = _post('gateway');
        // WP5B §7 (SEC-14): a posted discount is ignored; only the coupon validated on the gateway page counts
        $discount = (float) ($_SESSION['wp5b_coupon_discount'][(int) $routes['3']] ?? 0);
        unset($_SESSION['wp5b_coupon_discount'][(int) $routes['3']]);
        if ($gateway == 'balance') {
            // unreachable since WP5B: a buy with gateway=balance is dispatched to the order/pay code below the
            // switch header (order/pay is POST-only and never reached by a GET redirect)
            unset($_SESSION['gateway']);
            r2(getUrl('order/package'), 'e', Lang::T("Failed to buy package"));
        }
        if (empty($gateway) && !empty($_SESSION['gateway'])) {
            $gateway = $_SESSION['gateway'];
        } else if (!empty($gateway)) {
            $_SESSION['gateway'] = $gateway;
        }
        if ($user['status'] != 'Active') {
            _alert(Lang::T('This account status') . ' : ' . Lang::T($user['status']), 'danger', "");
        }
        if (empty($gateway)) {
            r2(getUrl('order/gateway/') . $routes[2] . '/' . $routes[3], 'w', Lang::T("Please select Payment Gateway"));
        }
        // WP5B §7 (SEC-13): only a configured gateway whose file resolves inside the gateway directory
        $pgFile = \Reseller\Domain\PaymentGatewayResolver::resolve($gateway);
        if ($pgFile === null) {
            unset($_SESSION['gateway']);
            r2(getUrl('order/gateway/') . $routes[2] . '/' . $routes[3], 'e', Lang::T("No Payment Gateway Available"));
        }
        run_hook('customer_buy_plan'); #HOOK
        include $pgFile;
        call_user_func($gateway . '_validate_config');

        switch (_post('custom')) {
            case '1':
                $amount = _post('amount');
                $amount = (float) $amount;

                if ($amount <= 0) {
                    r2(getUrl('order/gateway/') . $routes[2] . '/' . $routes[3], 'w', Lang::T("Please enter amount"));
                }

                $d = ORM::for_table('tbl_payment_gateway')
                    ->where('username', $user['username'])
                    ->where('status', 1)
                    ->find_one();
                if ($d) {
                    if ($d['pg_url_payment']) {
                        r2(getUrl('order/view/') . $d['id'], 'w', Lang::T("You already have unpaid transaction, cancel it or pay it."));
                    } else {
                        if ($gateway == $d['gateway']) {
                            $id = $d['id'];
                        } else {
                            // WP5B §7: conditional switch; only a still unpaid row is cancelled
                            ORM::get_db()->prepare('UPDATE tbl_payment_gateway SET status = 4 WHERE id = ? AND username = ? AND status = 1')->execute([(int) $d['id'], $user['username']]);
                        }
                    }
                }
                $d = ORM::for_table('tbl_payment_gateway')->create();
                $d->username = $user['username'];
                $d->user_id = $user['id'];
                $d->gateway = $gateway;
                $d->plan_id = 0;
                $d->plan_name = 'Custom';
                $d->routers_id = '0';
                $d->routers = 'Custom Balance';
                $d->price = $amount;
                $d->created_date = date('Y-m-d H:i:s');
                $d->status = 1;
                $d->save();
                $id = $d->id;
                break;

            default:
                $plan = ORM::for_table('tbl_plans')->where('enabled', '1')->find_one($routes['3']);
                if ($plan['is_radius'] == '1') {
                    $router['id'] = 0;
                    $router['name'] = 'radius';
                } else if ($routes['2'] > 0) {
                    $router = ORM::for_table('tbl_routers')->where('enabled', '1')->find_one($routes['2']);
                } else {
                    $router['id'] = 0;
                    $router['name'] = 'balance';
                }
                if (empty($router) || empty($plan)) {
                    r2(getUrl('order/package'), 'e', Lang::T("Plan Not found"));
                }
                $d = ORM::for_table('tbl_payment_gateway')
                    ->where('username', $user['username'])
                    ->where('status', 1)
                    ->find_one();
                if ($d) {
                    if ($d['pg_url_payment']) {
                        r2(getUrl('order/view/') . $d['id'], 'w', Lang::T("You already have unpaid transaction, cancel it or pay it."));
                    } else {
                        if ($gateway == $d['gateway']) {
                            $id = $d['id'];
                        } else {
                            // WP5B §7: conditional switch; only a still unpaid row is cancelled
                            ORM::get_db()->prepare('UPDATE tbl_payment_gateway SET status = 4 WHERE id = ? AND username = ? AND status = 1')->execute([(int) $d['id'], $user['username']]);
                        }
                    }
                }
                $add_cost = 0;
                $tax = 0;
                if ($router['name'] != 'balance') {
                    list($bills, $add_cost) = User::getBills($id_customer);
                }
                // Tax calculation start
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
                }
                // Tax calculation stop
                if (empty($id)) {
                    $d = ORM::for_table('tbl_payment_gateway')->create();
                    $d->username = $user['username'];
                    $d->user_id = $user['id'];
                    $d->gateway = $gateway;
                    $d->plan_id = $plan['id'];
                    $d->plan_name = $plan['name_plan'];
                    $d->routers_id = $router['id'];
                    $d->routers = $router['name'];
                    if ($plan['validity_unit'] == 'Period') {
                        // Postpaid price from field
                        $add_inv = User::getAttribute("Invoice", $id_customer);
                        if (empty($add_inv) or $add_inv == 0) {
                            $d->price = $plan['price'] + $add_cost + $tax - $discount;
                        } else {
                            $d->price = $add_inv + $add_cost + $tax - $discount;
                        }
                    } else {
                        $d->price = $plan['price'] + $add_cost + $tax - $discount;
                    }
                    $d->created_date = date('Y-m-d H:i:s');
                    $d->status = 1;
                    $d->save();
                    $id = $d->id();
                } else {
                    $d->username = $user['username'];
                    $d->user_id = $user['id'];
                    $d->gateway = $gateway;
                    $d->plan_id = $plan['id'];
                    $d->plan_name = $plan['name_plan'];
                    $d->routers_id = $router['id'];
                    $d->routers = $router['name'];
                    if ($plan['validity_unit'] == 'Period') {
                        // Postpaid price from field
                        $add_inv = User::getAttribute("Invoice", $id_customer);
                        if (empty($add_inv) or $add_inv == 0) {
                            $d->price = ($plan['price'] + $add_cost + $tax - $discount);
                        } else {
                            $d->price = ($add_inv + $add_cost + $tax - $discount);
                        }
                    } else {
                        $d->price = ($plan['price'] + $add_cost + $tax - $discount);
                    }
                    //$d->price = ($plan['price'] + $add_cost);
                    $d->created_date = date('Y-m-d H:i:s');
                    $d->status = 1;
                    $d->save();
                }
                break;
        }
        if (!$id) {
            r2(getUrl('order/package/') . $d['id'], 'e', Lang::T("Failed to create Transaction.."));
        } else {
            call_user_func($gateway . '_create_transaction', $d, $user);
        }
        break;
    default:
        r2(getUrl('order/package/'), 's', '');
}
