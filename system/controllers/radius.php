<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/
_admin();
$ui->assign('_title', Lang::T('Plugin Manager'));
$ui->assign('_system_menu', 'settings');

$action = $routes['1'];
$ui->assign('_admin', $admin);


if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
    _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
}

switch ($action) {

    case 'nas-add':
        $ui->assign('_system_menu', 'radius');
        $ui->assign('_title', Lang::T('Network Access Server'));
        $ui->assign('routers', ORM::for_table('tbl_routers')->find_many());
        $ui->display('admin/radius/nas-add.tpl');
        break;
    case 'nas-add-post':
        $shortname = _post('shortname');
        $nasname = _post('nasname');
        $secret = _post('secret');
        $ports = _post('ports', null);
        $type = _post('type', 'other');
        $server = _post('server', null);
        $community = _post('community', null);
        $description = _post('description');
        $routers = _post('routers');
        $msg = '';

        if (Validator::Length($shortname, 30, 2) == false) {
            $msg .= 'Name should be between 3 to 30 characters' . '<br>';
        }
        // WP4 (SEC-17): validated NAS data; the secret is required when adding
        $msg .= nuxr_nas_validation($nasname, $ports, $secret, true);
        if (empty($ports)) {
            $ports = null;
        }
        if (empty($server)) {
            $server = null;
        }
        if (empty($community)) {
            $community = null;
        }
        if (empty($type)) {
            $type = null;
        }
        $d = ORM::for_table('nas', 'radius')->where('nasname', $nasname)->find_one();
        if ($d) {
            $msg .= 'NAS IP Exists<br>';
        }
        if ($msg == '') {
            require_once $DEVICE_PATH . DIRECTORY_SEPARATOR . "Radius.php";
            if ((new Radius())->nasAdd($shortname, $nasname, $ports, $secret, $routers, $description, $type, $server, $community) > 0) {
                r2(getUrl('radius/nas-list/'), 's', "NAS Added");
            } else {
                r2(getUrl('radius/nas-add/'), 'e', "NAS Added Failed");
            }
        } else {
            r2(getUrl('radius/nas-add'), 'e', $msg);
        }
        break;
    case 'nas-edit':
        $ui->assign('_system_menu', 'radius');
        $ui->assign('_title', Lang::T('Network Access Server'));

        $id  = $routes['2'];
        $d = ORM::for_table('nas', 'radius')->find_one($id);
        if (!$d) {
            $d = ORM::for_table('nas', 'radius')->where_equal('shortname', _get('name'))->find_one();
        }
        if ($d) {
            $ui->assign('routers', ORM::for_table('tbl_routers')->find_many());
            $ui->assign('d', $d);
            $ui->display('admin/radius/nas-edit.tpl');
        } else {
            r2(getUrl('radius/list'), 'e', Lang::T('Account Not Found'));
        }

        break;
    case 'nas-edit-post':
        $id  = $routes['2'];
        $shortname = _post('shortname');
        $nasname = _post('nasname');
        $secret = _post('secret');
        $ports = _post('ports', null);
        $type = _post('type', 'other');
        $server = _post('server', null);
        $community = _post('community', null);
        $description = _post('description');
        $routers = _post('routers');
        $msg = '';

        if (Validator::Length($shortname, 30, 2) == false) {
            $msg .= 'Name should be between 3 to 30 characters' . '<br>';
        }
        // WP4 (SEC-17): validated NAS data; an empty secret keeps the stored one (it is never rendered)
        $msg .= nuxr_nas_validation($nasname, $ports, $secret, false);
        if (empty($ports)) {
            $ports = null;
        }
        if (empty($server)) {
            $server = null;
        }
        if (empty($community)) {
            $community = null;
        }
        if (empty($type)) {
            $type = null;
        }
        if ($msg == '') {
            require_once $DEVICE_PATH . DIRECTORY_SEPARATOR . "Radius.php";
            if ((new Radius())->nasUpdate($id, $shortname, $nasname, $ports, $secret, $routers, $description, $type, $server, $community)) {
                r2(getUrl('radius/list/'), 's', "NAS Saved");
            } else {
                r2(getUrl('radius/nas-add'), 'e', 'NAS NOT Exists');
            }
        } else {
            r2(getUrl('radius/nas-add'), 'e', $msg);
        }
        break;
    case 'nas-delete':
        $id  = $routes['2'];
        $d = ORM::for_table('nas', 'radius')->find_one($id);
        if ($d) {
            $d->delete();
        } else {
            r2(getUrl('radius/nas-list'), 'e', 'NAS Not found');
        }
    default:
        $ui->assign('_system_menu', 'radius');
        $ui->assign('_title', Lang::T('Network Access Server'));
        $name = _post('name');
        if (empty($name)) {
            $query = ORM::for_table('nas', 'radius');
            $nas = Paginator::findMany($query);
        } else {
            $query = ORM::for_table('nas', 'radius')
                ->where_like('nasname', $search)
                ->where_like('shortname', $search)
                ->where_like('description', $search);
            $nas = Paginator::findMany($query, ['name' => $name]);
        }
        $ui->assign('name', $name);
        $ui->assign('nas', $nas);
        $ui->display('admin/radius/nas.tpl');
}

/** NAS form validation (WP4): address, CoA port and secret; returns the error text ('' when valid). */
function nuxr_nas_validation($nasname, $ports, $secret, $secretRequired)
{
    $msg = '';
    $name = trim((string) $nasname);
    $ip = explode('/', $name, 2);
    $isIp = filter_var($ip[0], FILTER_VALIDATE_IP) !== false && (count($ip) === 1 || (ctype_digit($ip[1]) && (int) $ip[1] <= (strpos($ip[0], ':') !== false ? 128 : 32)));
    $isHost = (bool) preg_match('/^(?=.{1,128}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $name);
    if (!$isIp && !$isHost) {
        $msg .= 'NAS address must be an IP address, a CIDR range or a host name<br>';
    }
    if (!empty($ports) && (!ctype_digit((string) $ports) || (int) $ports < 1 || (int) $ports > 65535)) {
        $msg .= 'Port must be a number between 1 and 65535<br>';
    }
    $secret = (string) $secret;
    if (($secretRequired || $secret !== '') && ($secret === '' || strlen($secret) > 60 || preg_match('/[\x00-\x1f\x7f]/', $secret))) {
        $msg .= 'Secret must be 1 to 60 printable characters<br>';
    }
    return $msg;
}
