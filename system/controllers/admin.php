<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Pragma: no-cache");

if (Admin::getID()) {
    r2(getUrl('dashboard'), "s", Lang::T("You are already logged in"));
}

if (isset($routes['1'])) {
    $do = $routes['1'];
} else {
    $do = 'login-display';
}

switch ($do) {
    case 'post':
        $username = _post('username');
        $password = _post('password');
        //csrf token (web: also enforced centrally by CsrfPolicy; the API uses bearer tokens)
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            _alert(Lang::T('Invalid or Expired CSRF Token') . ".", 'danger', "admin");
        }
        run_hook('admin_login'); #HOOK
        if ($username != '' and $password != '') {
            $d = ORM::for_table('tbl_users')->where('username', $username)->find_one();
            // WP2 / SEC-10: an Inactive account is refused with the same message as a wrong password
            if ($d && $d['status'] === 'Active' && Password::_verify($password, $d['password']) == true) {
                $d->last_login = date('Y-m-d H:i:s');
                $d->save();
                _log($username . ' ' . Lang::T('Login Successful'), $d['user_type'], $d['id']);
                if ($isApi) {
                    // WP2 / OD-3: actor-bound a2 token (no cookie, no session); fails closed without a dedicated secret
                    $token = \Reseller\Security\ApiToken::issue((int) $d['id'], (string) $d['password']);
                    if ($token) {
                        showResult(true, Lang::T('Login Successful'), ['token' => $token]);
                    }
                    showResult(false, Lang::T('Invalid Username or Password'));
                }
                Admin::setCookie($d['id']);
                _alert(Lang::T('Login Successful'), 'success', "dashboard");
            } else {
                if ($d) {
                    _log($username . ' ' . Lang::T('Failed Login'), $d['user_type']);
                }
                _alert(Lang::T('Invalid Username or Password') . ".", 'danger', "admin");
            }
        } else {
            _alert(Lang::T('Invalid Username or Password') . ".", 'danger', "admin");
        }

        break;
    default:
        run_hook('view_login'); #HOOK
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->display('admin/admin/login.tpl');
        break;
}
