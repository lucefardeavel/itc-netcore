<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

$ui->assign('_title', Lang::T('Voucher'));
$ui->assign('_system_menu', 'voucher');

$action = $routes['1'];
if(!_auth(false)){
    if($action== 'invoice'){
        // WP2 / NEW-5: the public invoice link requires a positive integer id and a matching signature
        // (constant-time); an empty id never selects "the latest transaction of any customer"
        $id = $routes[2] ?? '';
        $sign = $routes[3] ?? '';
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/', $id) || !is_string($sign) || !hash_equals(md5($id . $db_pass), $sign)) {
            http_response_code(404);
            $ui->display('customer/404.tpl');
            die();
        }
        $in = ORM::for_table('tbl_transactions')->where('id', (int) $id)->find_one();
        if ($in) {
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
            $ui->assign('public_url', getUrl("voucher/invoice/$id/".md5($id. $db_pass)));
            $ui->assign('logo', $logo);
            $ui->display('customer/invoice-customer.tpl');
            die();
        } else {
            http_response_code(404);
            $ui->display('customer/404.tpl');
            die();
        }
    }else{
        r2(getUrl('login'));
    }
}

$user = User::_info();
$ui->assign('_user', $user);

switch ($action) {

    case 'activation':
        run_hook('view_activate_voucher'); #HOOK
        $ui->assign('code', alphanumeric(_get('code'), "-_.,"));
        $ui->display('customer/activation.tpl');
        break;

    case 'activation-post':
        $code = alphanumeric(_post('code'), "-_.,");
        // WP2 / OD-11 (E7): no voucher activation for a reseller-owned customer (pending WP5B)
        \Reseller\Domain\ServiceGate::guardCustomer($user['id'], 'voucherActivation', 'voucher/activation');
        $v1 = ORM::for_table('tbl_voucher')->whereRaw("BINARY code = '$code'")->where('status', 0)->find_one();
        run_hook('customer_activate_voucher'); #HOOK
        if ($v1) {
            if (Package::rechargeUser($user['id'], $v1['routers'], $v1['id_plan'], "Voucher", $code)) {
                $v1->status = "1";
                $v1->used_date = date('Y-m-d H:i:s');
                $v1->user = $user['username'];
                $v1->save();
                r2(getUrl('voucher/list-activated'), 's', Lang::T('Activation Vouchers Successfully'));
            } else {
                r2(getUrl('voucher/activation'), 'e', "Failed to refill account");
            }
        } else {
            r2(getUrl('voucher/activation'), 'e', Lang::T('Voucher Not Valid'));
        }
        break;

    case 'list-activated':
        $ui->assign('_system_menu', 'list-activated');
        $query = ORM::for_table('tbl_transactions')->where('user_id', $user['id'])->order_by_desc('id');
        $d = Paginator::findMany($query);

        if (empty($d) || $d < 5) {
            $query = ORM::for_table('tbl_transactions')->where('username', $user['username'])->order_by_desc('id');
            $d = Paginator::findMany($query);
        }

        $ui->assign('d', $d);
        $ui->assign('_title', Lang::T('Activation History'));
        run_hook('customer_view_activation_list'); #HOOK
        $ui->display('customer/activation-list.tpl');

        break;
    case 'invoice':
        $id = $routes[2];
        // WP2: the customer's own transactions by customer id; "latest" when no id is given
        if (empty($id)) {
            $in = ORM::for_table('tbl_transactions')->where('user_id', $user['id'])->order_by_desc('id')->find_one();
        } else if (preg_match('/^[1-9][0-9]{0,18}$/', (string) $id)) {
            $in = ORM::for_table('tbl_transactions')->where('user_id', $user['id'])->where('id', (int) $id)->find_one();
        } else {
            $in = false;
        }
        if ($in) {
            // NEW-5: the public link is always signed over the real transaction id, never an empty id
            $id = (int) $in['id'];
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
            $ui->assign('public_url', getUrl("voucher/invoice/$id/".md5($id. $db_pass)));
            $ui->assign('logo', $logo);
            $ui->display('customer/invoice-customer.tpl');
        } else {
            r2(getUrl('voucher/list-activated'), 'e', Lang::T('Not Found'));
        }
        break;
    default:
        $ui->display('admin/404.tpl');
}
