<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill)
 *  by https://t.me/ibnux
 *
 * Authorize
 *    - Voucher activation
 * Authenticate
 *    - is it allow to login
 * Accounting
 *    - log
 *
 * WP4 (SEC-01, E16): this FreeRADIUS rlm_rest endpoint is OFF unless config.php sets $radius_rest_secret
 * (and optionally $radius_rest_allow); every request must come from an allowed FreeRADIUS server address and
 * present the secret (Reseller\Radius\RestGate). Every query is parameterized; a voucher authenticates only
 * while it is unused or its recharge is active; accounting counters are per session (Reseller\Radius\RestCounter).
 **/

header("Content-Type: application/json");

include "init.php";

$nuxrRefusal = \Reseller\Radius\RestGate::refusal($radius_rest_secret ?? null, $radius_rest_allow ?? null, $_SERVER);
if ($nuxrRefusal !== null) {
    \Reseller\Security\ErrorReporter::log('radius.rest_refused', 'reason=' . $nuxrRefusal);
    show_radius_result(["control:Auth-Type" => "Reject", "reply:Reply-Message" => 'Unauthorized'], 401);
}

$action = $_SERVER['HTTP_X_FREERADIUS_SECTION'] ?? '';
if (empty($action)) {
    $action = _get('action');
}

$code = 200;

try {
    switch ($action) {
        case 'authenticate':
            $username = _req('username');
            $password = _req('password');
            $CHAPassword = _req('CHAPassword');
            $CHAPchallenge = _req('CHAPchallenge');
            $isCHAP = false;
            if (!empty($CHAPassword)) {
                $c = radius_active_customer('username', $username) ?: radius_active_customer('pppoe_username', $username);
                if ($c) {
                    if (Password::chap_verify($c['password'], $CHAPassword, $CHAPchallenge)) {
                        $password = $c['password'];
                        $isVoucher = false;
                        $isCHAP = true;
                    } else if (!empty($c['pppoe_password']) && Password::chap_verify($c['pppoe_password'], $CHAPassword, $CHAPchallenge)) {
                        $password = $c['pppoe_password'];
                        $isVoucher = false;
                        $isCHAP = true;
                    } else if (Password::chap_verify($username, $CHAPassword, $CHAPchallenge) || Password::chap_verify('', $CHAPassword, $CHAPchallenge)) {
                        // voucher (password = code, or no password)
                        $isVoucher = true;
                        $password = $username;
                    } else {
                        show_radius_result(['Reply-Message' => 'Username or Password is wrong'], 401);
                    }
                }
            } else {
                if (!empty($username) && empty($password)) {
                    // Voucher with empty password
                    $isVoucher = true;
                    $password = $username;
                } else if (empty($username) || empty($password)) {
                    show_radius_result([
                        "control:Auth-Type" => "Reject",
                        "reply:Reply-Message" => 'Login invalid......'
                    ], 401);
                }
            }
            if ($username == $password) {
                // E16: a voucher authenticates only while unused (authorize redeems it) or while its recharge is active
                $username = Text::alphanumeric($username, "-_.,");
                $v = ORM::for_table('tbl_voucher')->where_raw('BINARY code = ?', [$username])->find_one();
                $d = $v && ($v['status'] == 0 || radius_active_recharge($username));
            } else {
                $c = ORM::for_table('tbl_customers')->where_raw("BINARY username = ? AND status = 'Active'", [$username])->find_one();
                $d = $c && ($c['password'] == $password || ($c['pppoe_password'] != '' && $c['pppoe_password'] == $password));
            }
            if ($d) {
                header("HTTP/1.1 204 No Content");
                die();
            } else {
                show_radius_result([
                    "control:Auth-Type" => "Reject",
                    "reply:Reply-Message" => 'Login invalid......'
                ], 401);
            }
            break;
        case 'authorize':
            $username = _req('username');
            $password = _req('password');
            $isVoucher = ($username == $password);
            $CHAPassword = _req('CHAPassword');
            $CHAPchallenge = _req('CHAPchallenge');
            $isCHAP = false;
            if (!empty($CHAPassword)) {
                $byPppoe = false;
                $c = radius_active_customer('username', $username);
                if (!$c) {
                    $c = radius_active_customer('pppoe_username', $username);
                    $byPppoe = (bool) $c;
                }
                if ($c) {
                    if (Password::chap_verify($c['password'], $CHAPassword, $CHAPchallenge)) {
                        $password = $c['password'];
                        $username = $byPppoe ? $c['username'] : $username;
                        $isVoucher = false;
                        $isCHAP = true;
                    } else if (!empty($c['pppoe_password']) && Password::chap_verify($c['pppoe_password'], $CHAPassword, $CHAPchallenge)) {
                        $password = $c['pppoe_password'];
                        $username = $byPppoe ? $c['username'] : $username;
                        $isVoucher = false;
                        $isCHAP = true;
                    } else if (Password::chap_verify($username, $CHAPassword, $CHAPchallenge) || Password::chap_verify('', $CHAPassword, $CHAPchallenge)) {
                        $isVoucher = true;
                        $password = $username;
                    } else {
                        show_radius_result(['Reply-Message' => 'Username or Password is wrong'], 401);
                    }
                }
            } else {
                if (!empty($username) && empty($password)) {
                    // Voucher with empty password
                    $isVoucher = true;
                    $password = $username;
                } else if (empty($username) || empty($password)) {
                    show_radius_result([
                        "control:Auth-Type" => "Reject",
                        "reply:Reply-Message" => 'Login invalid......'
                    ], 401);
                }
            }
            $tur = radius_recharge($username);
            if (!$tur) {
                // if check if pppoe_username
                $c = ORM::for_table('tbl_customers')->select('username')->where_raw('BINARY pppoe_username = ?', [$username])->find_one();
                if ($c) {
                    $username = $c['username'];
                    $tur = radius_recharge($username);
                }
            }
            if ($tur) {
                if (!$isVoucher && !$isCHAP) {
                    $d = radius_active_customer('username', $username) ?: radius_active_customer('pppoe_username', $username);
                    if ($d && $d['password'] != $password && $d['pppoe_password'] != $password) {
                        show_radius_result(['Reply-Message' => 'Username or Password is wrong'], 401);
                    }
                }
                process_radiust_rest($tur, $code);
            } else {
                if ($isVoucher) {
                    $username = Text::alphanumeric($username, "-_.,");
                    $v = ORM::for_table('tbl_voucher')->where_raw("BINARY code = ? AND routers = 'radius'", [$username])->find_one();
                    if ($v) {
                        if ($v['status'] == 0) {
                            if (Package::rechargeUser(0, $v['routers'], $v['id_plan'], "Voucher", $username)) {
                                $v->status = "1";
                                $v->used_date = date('Y-m-d H:i:s');
                                $v->save();
                                $tur = radius_recharge($username);
                                if ($tur) {
                                    process_radiust_rest($tur, $code);
                                } else {
                                    show_radius_result(['Reply-Message' => 'Voucher activation failed'], 401);
                                }
                            } else {
                                show_radius_result(['Reply-Message' => 'Voucher activation failed.'], 401);
                            }
                        } else {
                            show_radius_result(['Reply-Message' => 'Voucher Expired...'], 401);
                        }
                    } else {
                        show_radius_result(['Reply-Message' => 'Invalid Voucher..'], 401);
                    }
                } else {
                    show_radius_result(['Reply-Message' => 'Internet Plan Expired..'], 401);
                }
            }
            break;
        case 'accounting':
            $username = _req('username');
            if (empty($username)) {
                show_radius_result([
                    "control:Auth-Type" => "Reject",
                    "reply:Reply-Message" => 'Username empty'
                ], 200);
                die();
            }
            header("HTTP/1.1 200 ok");
            // only the accounting of an active PHPNuxBill RADIUS customer is kept
            $tur = ORM::for_table('tbl_user_recharges')->where_raw("BINARY username = ? AND `status` = 'on' AND `routers` = 'radius'", [$username])->find_one();
            if (!$tur) {
                // check if pppoe_username
                $c = ORM::for_table('tbl_customers')->select('username')->where_raw('BINARY pppoe_username = ?', [$username])->find_one();
                if ($c) {
                    $username = $c['username'];
                    $tur = ORM::for_table('tbl_user_recharges')->where_raw("BINARY username = ? AND `status` = 'on'", [$username])->find_one();
                }
            }
            if ($tur) {
                $counter = \Reseller\Radius\RestCounter::connection();
                $counter->record($username, [
                    'nasid' => _post('nasid'), 'macaddr' => _post('macAddr'), 'acctsessionid' => _post('acctSessionId'),
                    'acctstatustype' => _post('acctStatusType'), 'realm' => _post('realm'), 'nasipaddress' => _post('nasIpAddress'),
                    'nasportid' => _post('nasPortId'), 'nasporttype' => _post('nasPortType'), 'framedipaddress' => _post('framedIPAddress'),
                    'acctsessiontime' => _post('acctSessionTime'), 'acctinputoctets' => _post('acctInputOctets', 0), 'acctoutputoctets' => _post('acctOutputOctets', 0),
                ], date('Y-m-d H:i:s'));
                if (_post('acctStatusType') == 'Start') {
                    $plan = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
                    if ($plan['limit_type'] == "Data_Limit" || $plan['limit_type'] == "Both_Limit") {
                        if (Text::convertDataUnit($plan['data_limit'], $plan['data_unit']) - $counter->usage($username) < 0) {
                            show_radius_result(["control:Auth-Type" => "Accept", 'Reply-Message' => 'You have exceeded your data limit.'], 401);
                        }
                    }
                }
                process_radiust_rest($tur, 200);
            }
            show_radius_result([
                "control:Auth-Type" => "Accept",
                "reply:Reply-Message" => 'Saved'
            ], 200);
            break;
    }
    die();
} catch (Throwable $e) {
    // WP2 / OD-14: sanitized server log + summary-only notification; generic RADIUS reply
    \Reseller\Security\ErrorReporter::report($e, 'radius');
    show_radius_result(['Reply-Message' => 'Command Failed'], 401);
}
show_radius_result(['Reply-Message' => 'Invalid Command'], 401);

/** Active customer by username or pppoe_username (parameterized; two fixed statements). */
function radius_active_customer($column, $username)
{
    $sql = $column === 'pppoe_username' ? "BINARY pppoe_username = ? AND status = 'Active'" : "BINARY username = ? AND status = 'Active'";
    return ORM::for_table('tbl_customers')->select('username')->select('password')->select('pppoe_password')
        ->where_raw($sql, [(string) $username])->find_one();
}

/** The subscriber's recharge, the active one first. */
function radius_recharge($username)
{
    return ORM::for_table('tbl_user_recharges')->where_raw('BINARY username = ?', [(string) $username])
        ->order_by_desc('status')->order_by_desc('id')->find_one();
}

/** True while the voucher subscriber's recharge is active and not expired. */
function radius_active_recharge($username)
{
    $t = ORM::for_table('tbl_user_recharges')->where_raw("BINARY username = ? AND status = 'on'", [(string) $username])->order_by_desc('id')->find_one();
    return $t && strtotime($t['expiration'] . ' ' . $t['time']) > time();
}

function process_radiust_rest($tur, $code)
{
    global $config;
    $plan = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
    $bw = ORM::for_table("tbl_bandwidth")->find_one($plan['id_bw']);
    $counter = \Reseller\Radius\RestCounter::connection();
    // Count User Onlines (open sessions of the per-session counter rows)
    $ips = $counter->onlineAddresses($tur['username']);
    // check if user reach shared_users limit but IP is not in the list active
    if (count($ips) >= $plan['shared_users'] && $plan['type'] == 'Hotspot' && !in_array(_post('framedIPAddress'), $ips)) {
        show_radius_result(["control:Auth-Type" => "Accept", 'Reply-Message' => 'You are already logged in - access denied (' . count($ips) . ')'], 401);
    }
    if ($bw['rate_down_unit'] == 'Kbps') {
        $unitdown = 'K';
    } else {
        $unitdown = 'M';
    }
    if ($bw['rate_up_unit'] == 'Kbps') {
        $unitup = 'K';
    } else {
        $unitup = 'M';
    }
    $rate = $bw['rate_up'] . $unitup . "/" . $bw['rate_down'] . $unitdown;
    $rates = explode('/', $rate);

    if (!empty(trim($bw['burst']))) {
        $ratos = $rate . ' ' . $bw['burst'];
    } else {
        $ratos = $rates[0] . '/' . $rates[1];
    }

    $attrs = [];
    $timeexp = strtotime($tur['expiration'] . ' ' . $tur['time']);
    if ($tur['status'] != 'on' || $timeexp < time()) {
        show_radius_result([
            "control:Auth-Type" => "Reject",
            "reply:Reply-Message" => "Sorry, your account's active period has expired (" . $tur['expiration'] . ")"
        ], 401);
        exit;
    }

    $attrs['reply:Reply-Message'] = 'success';
    $attrs['Simultaneous-Use'] = $plan['shared_users'];
    $attrs['reply:Mikrotik-Wireless-Comment'] = $plan['name_plan'] . ' | ' . $tur['expiration'] . ' ' . $tur['time'];

    $attrs['reply:Ascend-Data-Rate'] = str_replace('M', '000000', str_replace('K', '000', $rates[1]));
    $attrs['reply:Ascend-Xmit-Rate'] = str_replace('M', '000000', str_replace('K', '000', $rates[0]));
    $attrs['reply:Mikrotik-Rate-Limit'] = $ratos;
    $attrs['reply:WISPr-Bandwidth-Max-Up'] = str_replace('M', '000000', str_replace('K', '000', $rates[0]));
    $attrs['reply:WISPr-Bandwidth-Max-Down'] = str_replace('M', '000000', str_replace('K', '000', $rates[1]));
    $attrs['reply:expiration'] = date('d M Y H:i:s', $timeexp);
    $attrs['reply:WISPr-Session-Terminate-Time'] = date('Y-m-d', $timeexp) . 'T' . date('H:i:sP', $timeexp);

    if ($plan['type'] == 'PPPOE') {
        $attrs['reply:Framed-Pool'] = $plan['pool'];
    }

    if ($plan['typebp'] == "Limited") {
        if ($plan['limit_type'] == "Data_Limit" || $plan['limit_type'] == "Both_Limit") {
            $attrs['reply:Mikrotik-Total-Limit'] = Text::convertDataUnit($plan['data_limit'], $plan['data_unit']) - $counter->usage($tur['username']);
            if ($attrs['reply:Mikrotik-Total-Limit'] < 0) {
                $attrs['reply:Mikrotik-Total-Limit'] = 0;
                show_radius_result(["control:Auth-Type" => "Accept", 'Reply-Message' => 'You have exceeded your data limit.'], 401);
            }
        }
        if ($plan['limit_type'] == "Time_Limit") {
            if ($plan['time_unit'] == 'Hrs')
                $timelimit = $plan['time_limit'] * 60 * 60;
            else
                $timelimit = $plan['time_limit'] * 60;
            $attrs['reply:Max-All-Session'] = $timelimit;
            $attrs['reply:Expire-After'] = $timelimit;
        } else if ($plan['limit_type'] == "Data_Limit") {
            if ($plan['data_unit'] == 'GB')
                $datalimit = $plan['data_limit'] . "000000000";
            else
                $datalimit = $plan['data_limit'] . "000000";
            $attrs['reply:Max-Data'] = $datalimit;
            $attrs['reply:Mikrotik-Recv-Limit-Gigawords'] = $datalimit;
            $attrs['reply:Mikrotik-Xmit-Limit-Gigawords'] = $datalimit;
        } else if ($plan['limit_type'] == "Both_Limit") {
            if ($plan['time_unit'] == 'Hrs')
                $timelimit = $plan['time_limit'] * 60 * 60;
            else
                $timelimit = $plan['time_limit'] * 60;
            if ($plan['data_unit'] == 'GB')
                $datalimit = $plan['data_limit'] . "000000000";
            else
                $datalimit = $plan['data_limit'] . "000000";
            $attrs['reply:Max-All-Session'] = $timelimit;
            $attrs['reply:Max-Data'] = $datalimit;
            $attrs['reply:Mikrotik-Recv-Limit-Gigawords'] = $datalimit;
            $attrs['reply:Mikrotik-Xmit-Limit-Gigawords'] = $datalimit;
        }
    }
    $result = array_merge([
        "control:Auth-Type" => "Accept",
        "reply" =>  ["Reply-Message" => ['value' => 'success']]
    ], $attrs);
    show_radius_result($result, $code);
}

function show_radius_result($array, $code = 200)
{
    if ($code == 401) {
        header("HTTP/1.1 401 Unauthorized");
    } else if ($code == 200) {
        header("HTTP/1.1 200 OK");
    } else if ($code == 204) {
        header("HTTP/1.1 204 No Content");
        die();
    }
    die(json_encode($array));
}
