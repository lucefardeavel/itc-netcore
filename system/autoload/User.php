<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/


class User
{
    /*
     * WP9-D / NEW-7 + N-03 + NEW-6 (owner decision O7-A): customer tokens are signed with purpose subkeys
     * of the dedicated application key ($app_secret, Reseller\Security\AppKey), never with $db_pass.
     *   remember cookie  c3.{uid}.{iat}.{exp}.{mac}   purpose customer-cookie, at most 30 days
     *   login link       l3.{uid}.{iat}.{exp}.{mac}   purpose customer-link, at most 30 days (?uid= only)
     *   mac = HMAC(subkey, "<kind>|uid|iat|exp|<stored password>"): a password change revokes every token
     * Verification is constant-time; a future issue time (60 s skew), an expired token, an unknown or
     * malformed token and a token of the other kind are refused. Without a valid application key no
     * token is issued or accepted (fail closed). The cookie is HttpOnly + SameSite=Lax (+ Secure on HTTPS).
     */
    public const TOKEN_MAX_AGE = 2592000;
    public const TOKEN_FUTURE_SKEW = 60;

    public static function getID()
    {
        if (isset($_SESSION['uid']) && !empty($_SESSION['uid'])) {
            return $_SESSION['uid'];
        } else if (isset($_COOKIE['uid'])) {
            $uid = self::verifyToken($_COOKIE['uid'], 'c3');
            if ($uid > 0) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $_SESSION['uid'] = $uid;
                return $uid;
            }
            self::removeCookie();
        }
        return 0;
    }

    /** ?uid= login link (boot.php): only an l3 link token is accepted, never a cookie token. */
    public static function loginWithLink($token)
    {
        $uid = self::verifyToken($token, 'l3');
        if ($uid <= 0) {
            return 0;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['uid'] = $uid;
        return $uid;
    }

    /** @return int customer id of a valid token of $kind, or 0 */
    public static function verifyToken($token, $kind, $now = null)
    {
        if (!is_string($token) || strlen($token) > 200) {
            return 0;
        }
        $p = explode('.', $token);
        if (count($p) !== 5 || $p[0] !== $kind || !in_array($kind, ['c3', 'l3'], true)
            || !preg_match('/^[1-9][0-9]{0,9}$/', $p[1]) || !preg_match('/^[1-9][0-9]{0,11}$/', $p[2]) || !preg_match('/^[1-9][0-9]{0,11}$/', $p[3])) {
            return 0;
        }
        $now = $now ?? time();
        $iat = (int) $p[2];
        $exp = (int) $p[3];
        if ($iat > $now + self::TOKEN_FUTURE_SKEW || $exp <= $now || $exp - $iat > self::TOKEN_MAX_AGE || $exp <= $iat) {
            return 0;
        }
        $row = ORM::for_table('tbl_customers')->select('id')->select('password')->find_one((int) $p[1]);
        if (!$row) {
            return 0;
        }
        $purpose = $kind === 'c3' ? \Reseller\Security\AppKey::CUSTOMER_COOKIE : \Reseller\Security\AppKey::CUSTOMER_LINK;
        $msg = $kind . '|' . (int) $row['id'] . '|' . $iat . '|' . $exp . '|' . (string) $row['password'];
        return \Reseller\Security\AppKey::verify($purpose, $msg, $p[4]) ? (int) $row['id'] : 0;
    }

    /** @return string|null signed token of $kind valid for $seconds, or null (no key / unknown customer) */
    public static function issueToken($uid, $kind, $seconds, $now = null)
    {
        $now = $now ?? time();
        $seconds = max(60, min((int) $seconds, self::TOKEN_MAX_AGE));
        if (!in_array($kind, ['c3', 'l3'], true)) {
            return null;
        }
        $row = ORM::for_table('tbl_customers')->select('id')->select('password')->find_one((int) $uid);
        if (!$row) {
            return null;
        }
        $exp = $now + $seconds;
        $purpose = $kind === 'c3' ? \Reseller\Security\AppKey::CUSTOMER_COOKIE : \Reseller\Security\AppKey::CUSTOMER_LINK;
        $mac = \Reseller\Security\AppKey::mac($purpose, $kind . '|' . (int) $row['id'] . '|' . $now . '|' . $exp . '|' . (string) $row['password']);
        return $mac === null ? null : $kind . '.' . (int) $row['id'] . '.' . $now . '.' . $exp . '.' . $mac;
    }

    public static function getTawkToHash($email)
    {
        global $config;
        if (!empty($config['tawkto_api_key']) && !empty($email)) {
            return hash_hmac('sha256', $email, $config['tawkto_api_key']);
        }
        return '';
    }

    public static function getBills($id = 0)
    {
        if (!$id) {
            $id = User::getID();
            if (!$id) {
                return [];
            }
        }
        $addcost = 0;
        $bills = [];
        $attrs = User::getAttributes('Bill', $id);
        foreach ($attrs as $k => $v) {
            // if has : then its an installment
            if (strpos($v, ":") === false) {
                // Not installment
                $bills[$k] = $v;
                $addcost += $v;
            } else {
                // installment
                list($cost, $rem) = explode(":", $v);
                // :0 installment is done
                if (!empty($rem)) {
                    $bills[$k] = $cost;
                    $addcost += $cost;
                }
            }
        }
        return [$bills, $addcost];
    }

    public static function getBillNames($id = 0)
    {
        if (!$id) {
            $id = User::getID();
            if (!$id) {
                return [];
            }
        }
        $bills = [];
        $attrs = User::getAttributes('Bill', $id);
        foreach ($attrs as $k => $v) {
            $bills[] = str_replace(' Bill', '', $k);
        }
        return $bills;
    }

    public static function billsPaid($bills, $id = 0)
    {
        if (!$id) {
            $id = User::getID();
            if (!$id) {
                return [];
            }
        }
        foreach ($bills as $k => $v) {
            // if has : then its an installment
            $v = User::getAttribute($k, $id);
            if (strpos($v, ":") === false) {
                // Not installment, no need decrement
            } else {
                // installment
                list($cost, $rem) = explode(":", $v);
                // :0 installment is done
                if ($rem != 0) {
                    User::setAttribute($k, "$cost:" . ($rem - 1), $id);
                }
            }
        }
    }

    public static function setAttribute($name, $value, $id = 0)
    {
        if (!$id) {
            $id = User::getID();
            if (!$id) {
                return '';
            }
        }
        $f = ORM::for_table('tbl_customers_fields')->where('field_name', $name)->where('customer_id', $id)->find_one();
        if (!$f) {
            $f = ORM::for_table('tbl_customers_fields')->create();
            $f->customer_id = $id;
            $f->field_name = $name;
            $f->field_value = $value;
            $f->save();
            $result = $f->id();
            if ($result) {
                return $result;
            }
        } else {
            $f->field_value = $value;
            $f->save();
            return $f['id'];
        }
        return 0;
    }

    public static function getAttribute($name, $id = 0, $default = '')
    {
        if (!$id) {
            $id = User::getID();
            if (!$id) {
                return [];
            }
        }
        $f = ORM::for_table('tbl_customers_fields')->where('field_name', $name)->where('customer_id', $id)->find_one();
        if ($f) {
            return $f['field_value'];
        }
        return $default;
    }

    public static function getAttributes($endWith, $id = 0)
    {
        if (!$id) {
            $id = User::getID();
            if (!$id) {
                return [];
            }
        }
        $attrs = [];
        $f = ORM::for_table('tbl_customers_fields')->where_like('field_name', "%$endWith")->where('customer_id', $id)->find_many();
        if ($f) {
            foreach ($f as $k) {
                $attrs[$k['field_name']] = $k['field_value'];
            }
            return $attrs;
        }
        return [];
    }

    /**
     * Login-link token for messages / invoices (?uid=), valid for $validDays days (1..30).
     * WP9-D / NEW-6: the legacy token stayed valid for up to 59 days; it is now exactly $validDays.
     * @return array{time:int,token:string}
     */
    public static function generateToken($uid, $validDays = 30)
    {
        $days = max(1, min(30, (int) $validDays));
        $time = time();
        return [
            'time' => $time,
            'token' => (string) self::issueToken($uid, 'l3', $days * 86400, $time),
        ];
    }

    public static function setCookie($uid)
    {
        if (isset($uid)) {
            $token = self::issueToken($uid, 'c3', self::TOKEN_MAX_AGE);
            if ($token === null) {
                return false;
            }
            setcookie('uid', $token, self::cookieOptions(time() + self::TOKEN_MAX_AGE));
            return ['time' => time(), 'token' => $token];
        } else {
            return false;
        }
    }

    public static function removeCookie()
    {
        if (isset($_COOKIE['uid'])) {
            setcookie('uid', '', self::cookieOptions(time() - 86400));
        }
    }

    private static function cookieOptions($expires)
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public static function _info($id = 0)
    {
        global $config;
        if ($config['maintenance_mode'] == true) {
            if ($config['maintenance_mode_logout'] == true) {
                r2(getUrl('logout'), 'd', '');
            } else {
                displayMaintenanceMessage();
            }
        }
        if (!$id) {
            $id = User::getID();
        }
        $d = ORM::for_table('tbl_customers')->find_one($id);
        if ($d['status'] == 'Banned') {
            _alert(Lang::T('This account status') . ' : ' . Lang::T($d['status']), 'danger', "logout");
        }
        return $d;
    }

    public static function _infoByName($username)
    {
        global $config;
        if ($config['maintenance_mode'] == true) {
            if ($config['maintenance_mode_logout'] == true) {
                r2(getUrl('logout'), 'd', '');
            } else {
                displayMaintenanceMessage();
            }
        }
        $d = ORM::for_table('tbl_customers')->where("username", $username)->find_one();
        if ($d['status'] == 'Banned') {
            _alert(Lang::T('This account status') . ' : ' . Lang::T($d['status']), 'danger', "logout");
        }
        return $d;
    }

    public static function isUserVoucher($kode)
    {
        $regex = '/^GC\d+C.{10}$/';
        return preg_match($regex, $kode);
    }

    public static function _billing($id = 0)
    {
        if (!$id) {
            $id = User::getID();
        }
        $d = ORM::for_table('tbl_user_recharges')
            ->select('tbl_user_recharges.id', 'id')
            ->selects([
                'customer_id',
                'username',
                'plan_id',
                'namebp',
                'recharged_on',
                'recharged_time',
                'expiration',
                'time',
                'status',
                'method',
                'plan_type',
                ['tbl_user_recharges.routers', 'routers'],
                ['tbl_user_recharges.type', 'type'],
                'admin_id',
                'prepaid'
            ])
            ->left_outer_join('tbl_plans', ['tbl_plans.id', '=', 'tbl_user_recharges.plan_id'])
            ->left_outer_join('tbl_bandwidth', ['tbl_bandwidth.id', '=', 'tbl_plans.id_bw'])
            ->select('tbl_bandwidth.name_bw', 'name_bw')
            ->select('tbl_plans.price', 'price')
            ->where('customer_id', $id)
            ->find_many();
        return $d;
    }

    public static function setFormCustomField($uid = 0)
    {
        global $UPLOAD_PATH;
        $fieldPath = $UPLOAD_PATH . DIRECTORY_SEPARATOR . "customer_field.json";
        if (!file_exists($fieldPath)) {
            return '';
        }
        $fields = json_decode(file_get_contents($fieldPath), true);
        foreach ($fields as $field) {
            if (!empty(_post($field['name']))) {
                self::setAttribute($field['name'], _post($field['name']), $uid);
            }
        }
    }

    public static function getFormCustomField($ui, $register = false, $uid = 0)
    {
        global $UPLOAD_PATH;
        $fieldPath = $UPLOAD_PATH . DIRECTORY_SEPARATOR . "customer_field.json";
        if (!file_exists($fieldPath)) {
            return '';
        }
        $fields = json_decode(file_get_contents($fieldPath), true);
        $attrs = [];
        if (!$register) {
            $attrs = self::getAttributes('', $uid);
            $ui->assign('attrs', $attrs);
        }
        $html = '';
        $ui->assign('register', $register);
        foreach ($fields as $field) {
            if ($register) {
                if ($field['register']) {
                    $ui->assign('field', $field);
                    $html .= $ui->fetch('customer/custom_field.tpl');
                }
            } else {
                $ui->assign('field', $field);
                $html .= $ui->fetch('customer/custom_field.tpl');
            }
        }
        return $html;
    }
    public static function find($id)
    {
        return ORM::for_table('tbl_customers')->find_one($id);
    }
}


