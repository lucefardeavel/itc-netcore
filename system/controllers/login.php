<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

$maintenance_mode = $config['maintenance_mode'];
if ($maintenance_mode == true) {
    displayMaintenanceMessage();
}

if (User::getID()) {
    r2(getUrl('home'));
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
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            _msglog('e', Lang::T('Invalid or Expired CSRF Token'));
            r2(getUrl('login'));
        }
        run_hook('customer_login'); #HOOK
        if ($username != '' and $password != '') {
            $d = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
            if ($d) {
                $d_pass = $d['password'];
                if ($d['status'] == 'Banned') {
                    _alert(Lang::T('This account status') . ' : ' . Lang::T($d['status']), 'danger', "");
                }
                if (Password::_uverify($password, $d_pass) == true) {
                    // WP2: fresh session id and CSRF token on login (session fixation)
                    if (!$isApi && session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                        \Reseller\Security\CsrfPolicy::rotate();
                    }
                    $_SESSION['uid'] = $d['id'];
                    $token = User::setCookie($d['id']);
                    $d->last_login = date('Y-m-d H:i:s');
                    $d->save();
                    _log($username . ' ' . Lang::T('Login Successful'), 'User', $d['id']);
                    if ($isApi) {
                        if ($token) {
                            showResult(true, Lang::T('Login Successful'), ['token' => "u." . $token]);
                        } else {
                            showResult(false, Lang::T('Invalid Username or Password'));
                        }
                    }
                    _alert(Lang::T('Login Successful'), 'success', "home");
                } else {
                    _msglog('e', Lang::T('Invalid Username or Password'));
                    _log($username . ' ' . Lang::T('Failed Login'), 'User');
                    r2(getUrl('login'));
                }
            } else {
                _msglog('e', Lang::T('Invalid Username or Password'));
                r2(getUrl('login'));
            }
        } else {
            _msglog('e', Lang::T('Invalid Username or Password'));
            r2(getUrl('login'));
        }

        break;

    case 'activation':
        if (!empty(_post('voucher_only'))) {
            $csrf_token = _post('csrf_token');
            if (!Csrf::check($csrf_token)) {
                _msglog('e', Lang::T('Invalid or Expired CSRF Token'));
                r2(getUrl('login'));
            }
            $voucher = Text::alphanumeric(_post('voucher_only'), "-_.,");
            // WP2 / OD-11 (E14): a voucher-only session must not collide with a reseller-owned customer
            // (also refuses everything while the reseller schema is not ready). WP9 U0 N-07: the typed name is the voucher
            // code, a credential - the refusal is recorded without it (ServiceGate::guardUsername would record the name)
            if (\Reseller\Domain\ServiceGate::isBlockedUsername($voucher)) { \Reseller\Domain\ServiceGate::refused('loginVoucherOnly'); r2(getUrl('login'), 'e', \Reseller\Domain\ServiceGate::message()); }
            $tur = ORM::for_table('tbl_user_recharges')
                ->where('username', $voucher)
                ->where('customer_id', '0') // Voucher Only will make customer ID as 0
                ->find_one();
            if ($tur) {
                if ($tur['status'] == 'off') {
                    _alert(Lang::T('Internet Voucher Expired'), 'danger', "login");
                }
                // WP9 U0 R-FC-4: re-activation serves only a voucher the state machine granted (never one under review or unredeemed)
                if (\Reseller\Domain\VoucherRedemptionService::voucherOnlyServiceRefusal($voucher) !== null) {
                    _alert(Lang::T('Voucher Not Valid'), 'danger', "login");
                }
                $p = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
                if ($p) {
                    $dvc = Package::getDevice($p);
                    if ($_app_stage != 'demo') {
                        if (file_exists($dvc)) {
                            if (file_exists($dvc)) {
                                require_once $dvc;
                                $c = [
                                    'fullname' => "Voucher",
                                    'email' => '',
                                    'username' => $voucher,
                                    'password' => $voucher,
                                ];
                                (new $p['device'])->add_customer($c, $p);
                            } else {
                                new Exception(Lang::T("Devices Not Found"));
                            }
                            if (!empty($config['voucher_redirect'])) {
                                r2($config['voucher_redirect'], 's', Lang::T("Voucher activation success, now you can login"));
                            } else {
                                r2(getUrl('login'), 's', Lang::T("Voucher activation success, now you can login"));
                            }
                        } else {
                            new Exception(Lang::T("Devices Not Found"));
                        }
                    }
                    if (!empty($config['voucher_redirect'])) {
                        _alert(Lang::T("Voucher activation success, now you can login"), 'danger', $config['voucher_redirect']);
                    } else {
                        r2(getUrl('login'), 's', Lang::T("Voucher activation success, you are connected to internet"));
                    }
                } else {
                    _alert(Lang::T('Internet Plan Expired'), 'danger', "login");
                }
            } else {
                $v = ORM::for_table('tbl_voucher')->whereRaw("BINARY code = '$voucher'")->find_one();
                if (!$v) {
                    _alert(Lang::T('Voucher invalid'), 'danger', "login");
                }
                if ((string) $v['status'] === '0') {
                    // WP5B (E12; SEC-23): the single atomic voucher redemption path (claim -> grant -> finalize)
                    $out = \Reseller\Domain\VoucherRedemptionService::redeem($voucher, \Reseller\Domain\RedemptionTarget::voucherOnly($voucher), \Reseller\Domain\RedemptionSource::public());
                    if ($out['status'] === \Reseller\Domain\VoucherRedemptionService::GRANTED) {
                        $tur = ORM::for_table('tbl_user_recharges')->where('username', $voucher)->find_one();
                        if ($tur) {
                            $p = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
                            if ($p) {
                                $dvc = Package::getDevice($p);
                                if ($_app_stage != 'demo') {
                                    if (file_exists($dvc)) {
                                        if (file_exists($dvc)) {
                                            require_once $dvc;
                                            $c = [
                                                'fullname' => "Voucher",
                                                'email' => '',
                                                'username' => $voucher,
                                                'password' => $voucher,
                                            ];
                                            (new $p['device'])->add_customer($c, $p);
                                        } else {
                                            new Exception(Lang::T("Devices Not Found"));
                                        }
                                        if (!empty($config['voucher_redirect'])) {
                                            r2($config['voucher_redirect'], 's', Lang::T("Voucher activation success, now you can login"));
                                        } else {
                                            r2(getUrl('login'), 's', Lang::T("Voucher activation success, now you can login"));
                                        }
                                    } else {
                                        new Exception(Lang::T("Devices Not Found"));
                                    }
                                }
                                if (!empty($config['voucher_redirect'])) {
                                    _alert(Lang::T("Voucher activation success, now you can login"), 'danger', $config['voucher_redirect']);
                                } else {
                                    r2(getUrl('login'), 's', Lang::T("Voucher activation success, you are connected to internet"));
                                }
                            } else {
                                _alert(Lang::T('Internet Plan Expired'), 'danger', "login");
                            }
                        } else {
                            _alert(Lang::T('Voucher activation failed'), 'danger', "login");
                        }
                    } else {
                        _alert(Lang::T('Voucher activation failed'), 'danger', "login");
                    }
                } else {
                    _alert(Lang::T('Internet Voucher Expired'), 'danger', "login");
                }
            }
        } else {
            $voucher = Text::alphanumeric(_post('voucher'), "-_.,");
            $username = _post('username');
            // WP2 / OD-11 (E13): refused before any customer row is created or a password is changed
            \Reseller\Domain\ServiceGate::guardUsername($username, 'loginVoucherActivation', 'login');
            $v1 = ORM::for_table('tbl_voucher')->whereRaw("BINARY code = '$voucher'")->find_one();
            if ($v1) {
                // the session is connected to the hotspot (when the portal knows the device) and redirected
                $connected = static function ($user, $v1) use ($config, $_app_stage): void {
                    $to = !empty($config['voucher_redirect']) ? $config['voucher_redirect'] : getUrl('login');
                    if (!empty($_SESSION['nux-mac']) && !empty($_SESSION['nux-ip'])) {
                        try {
                            $p = ORM::for_table('tbl_plans')->where('id', $v1['id_plan'])->find_one();
                            $dvc = Package::getDevice($p);
                            if ($_app_stage != 'demo') {
                                if (file_exists($dvc)) {
                                    require_once $dvc;
                                    (new $p['device'])->connect_customer($user, $_SESSION['nux-ip'], $_SESSION['nux-mac'], $v1['routers']);
                                    r2($to, 's', Lang::T("Voucher activation success, now you can login"));
                                } else {
                                    new Exception(Lang::T("Devices Not Found"));
                                }
                            }
                            r2($to, 's', !empty($config['voucher_redirect']) ? Lang::T("Voucher activation success, you are connected to internet") : Lang::T("Voucher activation success, now you can login"));
                        } catch (Exception $e) {
                            r2($to, 's', Lang::T("Voucher activation success, now you can login"));
                        }
                    }
                    r2($to, 's', !empty($config['voucher_redirect']) ? Lang::T("Voucher activation success, you are connected to internet") : Lang::T("Voucher activation success, now you can login"));
                };
                $user = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
                if ((string) $v1['status'] === '0') {
                    // WP5B (OD-9, E13; SEC-16 / SEC-23): a voucher with a username creates a NEW customer exactly once,
                    // serialized per username, through the single redemption path; an existing customer is never
                    // touched (no password overwrite, no recharge)
                    if ($user) {
                        r2(getUrl('login'), 'e', Lang::T('Username already used by another customer'));
                    }
                    $out = \Reseller\Domain\VoucherRedemptionService::redeemForNewUsername($voucher, (string) $username, \Reseller\Domain\RedemptionSource::public());
                    if ($out['status'] !== \Reseller\Domain\VoucherRedemptionService::GRANTED) {
                        r2(getUrl('login'), 'e', match ($out['status']) {
                            \Reseller\Domain\VoucherRedemptionService::USERNAME_TAKEN => Lang::T('Username already used by another customer'),
                            \Reseller\Domain\VoucherRedemptionService::NOT_VALID, \Reseller\Domain\VoucherRedemptionService::REFUSED => Lang::T('Voucher Not Valid'),
                            \Reseller\Domain\VoucherRedemptionService::REVIEW => Lang::T('Voucher activation is pending review'),
                            default => Lang::T('Failed to activate voucher'),
                        });
                    }
                    $user = ORM::for_table('tbl_customers')->find_one($out['customer_id']);
                    $user->last_login = date('Y-m-d H:i:s');
                    $user->save();
                    $connected($user, $v1);
                } elseif ($user && (string) $v1['status'] === '1' && $v1['user'] == $user['username']) {
                    // a voucher this customer already redeemed: the session logs in again (no grant)
                    $user->last_login = date('Y-m-d H:i:s');
                    $user->save();
                    $connected($user, $v1);
                } else {
                    // used by another customer, or claimed and under review
                    r2(getUrl('login'), 'e', Lang::T('Voucher Not Valid'));
                }
            } else {
                _msglog('e', Lang::T('Invalid Username or Password'));
                r2(getUrl('login'));
            }
        }
    default:
        run_hook('customer_view_login'); #HOOK
        $csrf_token = Csrf::generateAndStoreToken();
        if ($config['disable_registration'] == 'yes') {
            $ui->assign('csrf_token', $csrf_token);
            $ui->assign('_title', Lang::T('Activation'));
            $ui->assign('code', alphanumeric(_get('code'), "-"));
            $ui->display('customer/login-noreg.tpl');
        } else {
            $UPLOAD_URL_PATH = str_replace($root_path, '',  $UPLOAD_PATH);
            if (!empty($config['login_page_logo']) && file_exists($UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . $config['login_page_logo'])) {
                $login_logo = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . $config['login_page_logo'];
            } elseif (file_exists($UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'login-logo.png')) {
                $login_logo = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'login-logo.png';
            } else {
                $login_logo = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'login-logo.default.png';
            }

            if (!empty($config['login_page_wallpaper']) && file_exists($UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . $config['login_page_wallpaper'])) {
                $wallpaper = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . $config['login_page_wallpaper'];
            } elseif (file_exists($UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'wallpaper.png')) {
                $wallpaper = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'wallpaper.png';
            } else {
                $wallpaper = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'wallpaper.default.png';
            }

            if (!empty($config['login_page_favicon']) && file_exists($UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . $config['login_page_favicon'])) {
                $favicon = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . $config['login_page_favicon'];
            } elseif (file_exists($UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'favicon.png')) {
                $favicon = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'favicon.png';
            } else {
                $favicon = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'favicon.default.png';
            }

            $ui->assign('login_logo', $login_logo);
            $ui->assign('wallpaper', $wallpaper);
            $ui->assign('favicon', $favicon);
            $ui->assign('csrf_token', $csrf_token);
            $ui->assign('_title', Lang::T('Login'));
            switch ($config['login_page_type']) {
                case 'custom':
                    $ui->display('customer/login-custom-' . $config['login_Page_template'] . '.tpl');
                    break;
                default:
                    $ui->display('customer/login.tpl');
                    break;
            }
        }

        break;
}
