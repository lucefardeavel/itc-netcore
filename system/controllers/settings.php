<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/
_admin();
$ui->assign('_title', Lang::T('Settings'));
$ui->assign('_system_menu', 'settings');

$action = $routes['1'];
$ui->assign('_admin', $admin);
// WP2 / SEC-04: staff management goes through StaffService (server-side target-role table, no
// user_type/root/status trust from forms, audited). Authoritative principal for this request:
$nuxrActor = \Reseller\Security\ActorContext::current();
$nuxrC = \Reseller\Security\Capability::class;

/** Staff row visible to the actor (self, or within the staff.read scope and role boundary)? */
function nuxr_staff_visible(\Reseller\Security\ActorContext $a, $row)
{
    if (!$row) {
        return false;
    }
    if ((int) $row['id'] === (int) $a->userId) {
        return true;
    }
    $scope = \Reseller\Security\PermissionService::scopeOf($a, \Reseller\Security\Capability::STAFF_READ);
    if ($scope === 'G') {
        return $a->role === 'SuperAdmin' || in_array($row['user_type'], ['Report', 'Agent', 'Sales'], true);
    }
    if ($scope === 'T') {
        return \Reseller\Security\DataScope::findStaff($a, \Reseller\Security\Capability::STAFF_READ, $row['id']) !== null;
    }
    return false;
}

switch ($action) {
    case 'docs':
        $d = ORM::for_table('tbl_appconfig')->where('setting', 'docs_clicked')->find_one();
        if ($d) {
            $d->value = 'yes';
            $d->save();
        } else {
            $d = ORM::for_table('tbl_appconfig')->create();
            $d->setting = 'docs_clicked';
            $d->value = 'yes';
            $d->save();
        }
        r2(APP_URL . '/docs');
        break;
    case 'devices':
        $files = scandir($DEVICE_PATH);
        $devices = [];
        foreach ($files as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if ($ext == 'php') {
                $dev = pathinfo($file, PATHINFO_FILENAME);
                require_once $DEVICE_PATH . DIRECTORY_SEPARATOR . $file;
                // WP9 release profile: a disabled device is listed (description only, never constructed)
                $nuxrEnabled = \Reseller\Domain\ReleaseProfile::deviceEnabled($dev);
                $dvc = $nuxrEnabled ? new $dev : (new \ReflectionClass($dev))->newInstanceWithoutConstructor();
                if (method_exists($dvc, 'description')) {
                    $arr = $dvc->description();
                    if (!$nuxrEnabled) {
                        $arr['title'] = ($arr['title'] ?? $dev) . ' - ' . Lang::T('disabled in the release profile');
                    }
                    $arr['file'] = $dev;
                    $devices[] = $arr;
                } else {
                    $devices[] = [
                        'title' => $dev,
                        'description' => '',
                        'author' => 'unknown',
                        'url' => [],
                        'file' => $dev
                    ];
                }
            }
        }
        $ui->assign('devices', $devices);
        $ui->display('admin/settings/devices.tpl');
        break;
    case 'app':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }

        // WP2 / OD-8: test sends are mutations (outbound messages) and arrive as POST (RouteMap app#test)
        if (!empty(_get('testWa'))) {
            if ($_app_stage == 'Demo') {
                r2(getUrl('settings/app'), 'e', 'You cannot perform this action in Demo mode');
            }
            $result = Message::sendWhatsapp(_get('testWa'), 'ITC NetCore Test Whatsapp');
            r2(getUrl('settings/app'), 's', 'Test Whatsapp has been send<br>Result: ' . $result);
        }
        if (!empty(_get('testSms'))) {
            if ($_app_stage == 'Demo') {
                r2(getUrl('settings/app'), 'e', 'You cannot perform this action in Demo mode');
            }
            $result = Message::sendSMS(_get('testSms'), 'ITC NetCore Test SMS');
            r2(getUrl('settings/app'), 's', 'Test SMS has been send<br>Result: ' . $result);
        }
        if (!empty(_get('testEmail'))) {
            if ($_app_stage == 'Demo') {
                r2(getUrl('settings/app'), 'e', 'You cannot perform this action in Demo mode');
            }
            Message::sendEmail(_get('testEmail'), 'ITC NetCore Test Email', 'ITC NetCore Test Email Body');
            r2(getUrl('settings/app'), 's', 'Test Email has been send');
        }
        if (!empty(_get('testTg'))) {
            if ($_app_stage == 'Demo') {
                r2(getUrl('settings/app'), 'e', 'You cannot perform this action in Demo mode');
            }
            $result = Message::sendTelegram('ITC NetCore Test Telegram');
            r2(getUrl('settings/app'), 's', 'Test Telegram has been send<br>Result: ' . $result);
        }

        $UPLOAD_URL_PATH = str_replace($root_path, '', $UPLOAD_PATH);
        if (file_exists($UPLOAD_PATH . DIRECTORY_SEPARATOR . 'logo.png')) {
            $logo = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'logo.png?' . time();
        } else {
            $logo = $UPLOAD_URL_PATH . DIRECTORY_SEPARATOR . 'logo.default.png';
        }
        $ui->assign('logo', $logo);

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

        $themes = [];
        $files = scandir('ui/themes/');
        foreach ($files as $file) {
            if (is_dir('ui/themes/' . $file) && !in_array($file, ['.', '..'])) {
                $themes[] = $file;
            }
        }

        $template_files = glob('ui/ui/customer/login-custom-*.tpl');
        $templates = [];

        foreach ($template_files as $file) {
            $parts = explode('-', basename($file, '.tpl'));
            $template_identifier = $parts[2] ?? 'unknown';
            $templates[] = [
                'filename' => basename($file),
                'value' => $template_identifier,
                'name' => str_replace('_', ' ', ucfirst($template_identifier))
            ];
        }

        $r = ORM::for_table('tbl_routers')->find_many();
        $ui->assign('r', $r);
        if (function_exists("shell_exec")) {
            $php = trim(shell_exec('which php'));
            if (empty($php)) {
                $php = 'php';
            }
        } else {
            $php = 'php';
        }
        // WP2 / OD-3: the global api_key is disabled; it is no longer generated or displayed
        unset($config['api_key']);
        if (empty($config['mikrotik_sms_command'])) {
            $config['mikrotik_sms_command'] = "/tool sms send";
        }
        $ui->assign('template_files', $templates);
        $ui->assign('_c', $config);
        $ui->assign('php', $php);
        $ui->assign('dir', str_replace('controllers', '', __DIR__));
        $ui->assign('themes', $themes);
        run_hook('view_app_settings'); #HOOK
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->display('admin/settings/app.tpl');
        break;

    case 'app-post':

        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/app'), 'e', 'You cannot perform this action in Demo mode');
        }

        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $csrf_token = _post('csrf_token');

        if (!Csrf::check($csrf_token)) {
            r2(getUrl('settings/app'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        $company = _post('CompanyName');
        $custom_tax_rate = filter_var(_post('custom_tax_rate'), FILTER_SANITIZE_SPECIAL_CHARS);
        if (preg_match('/[^0-9.]/', $custom_tax_rate)) {
            r2(getUrl('settings/app'), 'e', 'Special characters are not allowed in tax rate');
            die();
        }
        run_hook('save_settings'); #HOOK
        if (!empty($_FILES['logo']['name'])) {
            if (function_exists('imagecreatetruecolor')) {
                if (file_exists($UPLOAD_PATH . DIRECTORY_SEPARATOR . 'logo.png'))
                    unlink($UPLOAD_PATH . DIRECTORY_SEPARATOR . 'logo.png');
                File::resizeCropImage($_FILES['logo']['tmp_name'], $UPLOAD_PATH . DIRECTORY_SEPARATOR . 'logo.png', 1078, 200, 100);
                if (file_exists($_FILES['logo']['tmp_name']))
                    unlink($_FILES['logo']['tmp_name']);
            } else {
                r2(getUrl('settings/app'), 'e', 'PHP GD is not installed');
            }
        }
        if ($_POST['general'] && $company == '') {
            r2(getUrl('settings/app'), 'e', Lang::T('All field is required'));
        } else {
            if ($radius_enable) {
                try {
                    require_once $DEVICE_PATH . DIRECTORY_SEPARATOR . "Radius.php";
                    (new Radius())->getTableNas()->find_many();
                } catch (Throwable $e) {
                    // WP2 / OD-14: no driver message in the page; reference only
                    $ui->assign("error_title", "RADIUS Error");
                    $ui->assign("error_message", "Radius table not found. Import install/radius.sql and check the radius connection in config.php. " .
                        \Reseller\Security\ErrorReporter::publicMessage(\Reseller\Security\ErrorReporter::report($e, 'radius-settings')));
                    $ui->display('admin/error.tpl');
                    die();
                }
            }
            // Save all settings including tax system
            $_POST['man_fields_email'] = isset($_POST['man_fields_email']) ? 'yes' : 'no';
            $_POST['man_fields_fname'] = isset($_POST['man_fields_fname']) ? 'yes' : 'no';
            $_POST['man_fields_address'] = isset($_POST['man_fields_address']) ? 'yes' : 'no';
            $_POST['man_fields_custom'] = isset($_POST['man_fields_custom']) ? 'yes' : 'no';
            $enable_session_timeout = isset($_POST['enable_session_timeout']) ? 1 : 0;
            $_POST['enable_session_timeout'] = $enable_session_timeout;
            $_POST['notification_reminder_1day'] = isset($_POST['notification_reminder_1day']) ? 'yes' : 'no';
            $_POST['notification_reminder_3days'] = isset($_POST['notification_reminder_3days']) ? 'yes' : 'no';
            $_POST['notification_reminder_7days'] = isset($_POST['notification_reminder_7days']) ? 'yes' : 'no';

            // hide dashboard
            $_POST['hide_mrc'] = _post('hide_mrc', 'no');
            $_POST['hide_tms'] = _post('hide_tms', 'no');
            $_POST['hide_al'] = _post('hide_al', 'no');
            $_POST['hide_uet'] = _post('hide_uet', 'no');
            $_POST['hide_vs'] = _post('hide_vs', 'no');
            $_POST['hide_pg'] = _post('hide_pg', 'no');
            $_POST['hide_aui'] = _post('hide_aui', 'no');

            // Login page post
            $login_page_title = _post('login_page_head');
            $login_page_description = _post('login_page_description');
            $login_Page_template = _post('login_Page_template');
            $login_page_type = _post('login_page_type');
            $csrf_token = _post('csrf_token');

            if (!Csrf::check($csrf_token)) {
                r2(getUrl('settings/app'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
            }

            if ($login_page_type == 'custom' && (empty($login_Page_template) || empty($login_page_title) || empty($login_page_description))) {
                r2(getUrl('settings/app'), 'e', 'Please fill all required fields');
                return;
            }

            if (strlen($login_page_title) > 25) {
                r2(getUrl('settings/app'), 'e', 'Login page title must not exceed 25 characters');
                return;
            }
            if (strlen($login_page_description) > 100) {
                r2(getUrl('settings/app'), 'e', 'Login page description must not exceed 50 characters');
                return;
            }

            $image_paths = [];
            $allowed_types = ['image/jpeg', 'image/png'];

            if ($_FILES['login_page_favicon']['name'] != '') {
                $favicon_type = $_FILES['login_page_favicon']['type'];
                if (in_array($favicon_type, $allowed_types) && preg_match('/\.(jpg|jpeg|png)$/i', $_FILES['login_page_favicon']['name'])) {
                    $extension = pathinfo($_FILES['login_page_favicon']['name'], PATHINFO_EXTENSION);
                    $favicon_path = $UPLOAD_PATH . DIRECTORY_SEPARATOR . uniqid('favicon_') . '.' . $extension;
                    File::resizeCropImage($_FILES['login_page_favicon']['tmp_name'], $favicon_path, 16, 16, 100);
                    $_POST['login_page_favicon'] = basename($favicon_path); // Save dynamic file name
                    if (file_exists($_FILES['login_page_favicon']['tmp_name']))
                        unlink($_FILES['login_page_favicon']['tmp_name']);
                } else {
                    r2(getUrl('settings/app'), 'e', 'Favicon must be a JPG, JPEG, or PNG image.');
                }
            }

            if ($_FILES['login_page_wallpaper']['name'] != '') {
                $wallpaper_type = $_FILES['login_page_wallpaper']['type'];
                if (in_array($wallpaper_type, $allowed_types) && preg_match('/\.(jpg|jpeg|png)$/i', $_FILES['login_page_wallpaper']['name'])) {
                    $extension = pathinfo($_FILES['login_page_wallpaper']['name'], PATHINFO_EXTENSION);
                    $wallpaper_path = $UPLOAD_PATH . DIRECTORY_SEPARATOR . uniqid('wallpaper_') . '.' . $extension;
                    File::resizeCropImage($_FILES['login_page_wallpaper']['tmp_name'], $wallpaper_path, 1920, 1080, 100);
                    $_POST['login_page_wallpaper'] = basename($wallpaper_path); // Save dynamic file name
                    if (file_exists($_FILES['login_page_wallpaper']['tmp_name']))
                        unlink($_FILES['login_page_wallpaper']['tmp_name']);
                } else {
                    r2(getUrl('settings/app'), 'e', 'Wallpaper must be a JPG, JPEG, or PNG image.');
                }
            }

            if ($_FILES['login_page_logo']['name'] != '') {
                $logo_type = $_FILES['login_page_logo']['type'];
                if (in_array($logo_type, $allowed_types) && preg_match('/\.(jpg|jpeg|png)$/i', $_FILES['login_page_logo']['name'])) {
                    $extension = pathinfo($_FILES['login_page_logo']['name'], PATHINFO_EXTENSION);
                    $logo_path = $UPLOAD_PATH . DIRECTORY_SEPARATOR . uniqid('logo_') . '.' . $extension;
                    File::resizeCropImage($_FILES['login_page_logo']['tmp_name'], $logo_path, 300, 60, 100);
                    $_POST['login_page_logo'] = basename($logo_path); // Save dynamic file name
                    if (file_exists($_FILES['login_page_logo']['tmp_name']))
                        unlink($_FILES['login_page_logo']['tmp_name']);
                } else {
                    r2(getUrl('settings/app'), 'e', 'Logo must be a JPG, JPEG, or PNG image.');
                }
            }
            // WP2 / SEC-07 / OD-12: only the keys of the settings form are stored; security keys are
            // SuperAdmin-only; the global api_key and csrf_enabled can no longer be set at all.
            $nuxrAllowed = ['CompanyFooter', 'CompanyName', 'address', 'allow_balance_custom', 'allow_balance_transfer', 'custom_tax_rate',
                'dashboard_cr', 'disable_registration', 'disable_voucher', 'enable_balance', 'enable_tax', 'extend_confirmation', 'extend_days',
                'extend_expired', 'general', 'login_Page_template', 'login_page_description', 'login_page_favicon', 'login_page_head',
                'login_page_logo', 'login_page_type', 'login_page_wallpaper', 'mail_from', 'mail_reply_to', 'man_fields_address',
                'man_fields_custom', 'man_fields_email', 'man_fields_fname', 'mikrotik_sms_command', 'minimum_transfer', 'note',
                'notification_reminder_1day', 'notification_reminder_3days', 'notification_reminder_7days', 'payment_usings', 'phone',
                'phone_otp_type', 'photo_register', 'printer_cols', 'reg_nofify_admin', 'registration_username', 'reset_day', 'sms_otp_registration',
                'sms_url', 'smtp_host', 'smtp_pass', 'smtp_port', 'smtp_ssltls', 'smtp_user', 'tawkto', 'tawkto_api_key', 'tax_rate', 'telegram_bot',
                'telegram_target_id', 'theme', 'url_canonical', 'user_notification_expired', 'user_notification_payment',
                'user_notification_reminder', 'voucher_format', 'voucher_redirect', 'wa_url', 'hide_mrc', 'hide_tms', 'hide_al', 'hide_uet',
                'hide_vs', 'hide_pg', 'hide_aui', 'radius_enable', 'single_session', 'enable_session_timeout', 'session_timeout_duration',
                'http_proxy', 'http_proxyauth', 'github_token', 'github_username'];
            $nuxrSuperAdminOnly = ['radius_enable', 'single_session', 'enable_session_timeout', 'session_timeout_duration',
                'http_proxy', 'http_proxyauth', 'github_token', 'github_username'];
            $nuxrIsSA = \Reseller\Security\PermissionService::can($nuxrActor, $nuxrC::SYSTEM_CODE);
            // WP9-D / N-11 + O-T0-1: secret settings are write-only (never rendered back); an empty submission
            // keeps the stored value
            $nuxrWriteOnly = ['smtp_pass', 'telegram_bot', 'http_proxyauth', 'github_token', 'tawkto_api_key'];
            foreach ($_POST as $key => $value) {
                if (!is_string($key) || !in_array($key, $nuxrAllowed, true) || !is_scalar($value)
                    || (in_array($key, $nuxrSuperAdminOnly, true) && !$nuxrIsSA)
                    || (in_array($key, $nuxrWriteOnly, true) && (string) $value === '')) {
                    continue;
                }
                $d = ORM::for_table('tbl_appconfig')->where('setting', $key)->find_one();
                if ($d) {
                    $d->value = $value;
                    $d->save();
                } else {
                    $d = ORM::for_table('tbl_appconfig')->create();
                    $d->setting = $key;
                    $d->value = $value;
                    $d->save();
                }
            }
            _log('[' . $admin['username'] . ']: ' . Lang::T('Settings Saved Successfully'), $admin['user_type'], $admin['id']);

            r2(getUrl('settings/app'), 's', Lang::T('Settings Saved Successfully'));
        }
        break;

    case 'localisation':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $folders = [];
        $files = scandir('system/lan/');
        foreach ($files as $file) {
            if (is_file('system/lan/' . $file) && !in_array($file, ['index.html', 'country.json', '.DS_Store'])) {
                $file = str_replace(".json", "", $file);
                $folders[$file] = '';
            }
        }
        $ui->assign('lani', $folders);
        $lans = Lang::getIsoLang();
        foreach ($lans as $lan => $val) {
            if (isset($folders[$lan])) {
                unset($lans[$lan]);
            }
        }
        $ui->assign('lan', $lans);
        $timezonelist = Timezone::timezoneList();
        $ui->assign('tlist', $timezonelist);
        $ui->assign('xjq', ' $("#tzone").select2(); ');
        run_hook('view_localisation'); #HOOK
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->display('admin/settings/localisation.tpl');
        break;

    case 'localisation-post':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/localisation'), 'e', 'You cannot perform this action in Demo mode');
        }
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('settings/localisation'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        $tzone = _post('tzone');
        $date_format = _post('date_format');
        $country_code_phone = _post('country_code_phone');
        $lan = _post('lan');
        run_hook('save_localisation'); #HOOK
        if ($tzone == '' or $date_format == '' or $lan == '') {
            r2(getUrl('settings/localisation'), 'e', Lang::T('All field is required'));
        } else {
            $d = ORM::for_table('tbl_appconfig')->where('setting', 'timezone')->find_one();
            $d->value = $tzone;
            $d->save();

            $d = ORM::for_table('tbl_appconfig')->where('setting', 'date_format')->find_one();
            $d->value = $date_format;
            $d->save();

            $dec_point = $_POST['dec_point'];
            if (strlen($dec_point) == '1') {
                $d = ORM::for_table('tbl_appconfig')->where('setting', 'dec_point')->find_one();
                $d->value = $dec_point;
                $d->save();
            }

            $thousands_sep = $_POST['thousands_sep'];
            if (strlen($thousands_sep) == '1') {
                $d = ORM::for_table('tbl_appconfig')->where('setting', 'thousands_sep')->find_one();
                $d->value = $thousands_sep;
                $d->save();
            }

            $d = ORM::for_table('tbl_appconfig')->where('setting', 'country_code_phone')->find_one();
            if ($d) {
                $d->value = $country_code_phone;
                $d->save();
            } else {
                $d = ORM::for_table('tbl_appconfig')->create();
                $d->setting = 'country_code_phone';
                $d->value = $country_code_phone;
                $d->save();
            }

            $d = ORM::for_table('tbl_appconfig')->where('setting', 'radius_plan')->find_one();
            if ($d) {
                $d->value = _post('radius_plan');
                $d->save();
            } else {
                $d = ORM::for_table('tbl_appconfig')->create();
                $d->setting = 'radius_plan';
                $d->value = _post('radius_plan');
                $d->save();
            }
            $d = ORM::for_table('tbl_appconfig')->where('setting', 'hotspot_plan')->find_one();
            if ($d) {
                $d->value = _post('hotspot_plan');
                $d->save();
            } else {
                $d = ORM::for_table('tbl_appconfig')->create();
                $d->setting = 'hotspot_plan';
                $d->value = _post('hotspot_plan');
                $d->save();
            }
            $d = ORM::for_table('tbl_appconfig')->where('setting', 'pppoe_plan')->find_one();
            if ($d) {
                $d->value = _post('pppoe_plan');
                $d->save();
            } else {
                $d = ORM::for_table('tbl_appconfig')->create();
                $d->setting = 'pppoe_plan';
                $d->value = _post('pppoe_plan');
                $d->save();
            }
            $d = ORM::for_table('tbl_appconfig')->where('setting', 'vpn_plan')->find_one();
            if ($d) {
                $d->value = _post('vpn_plan');
                $d->save();
            } else {
                $d = ORM::for_table('tbl_appconfig')->create();
                $d->setting = 'vpn_plan';
                $d->value = _post('vpn_plan');
                $d->save();
            }

            $currency_code = $_POST['currency_code'];
            $d = ORM::for_table('tbl_appconfig')->where('setting', 'currency_code')->find_one();
            $d->value = $currency_code;
            $d->save();

            $d = ORM::for_table('tbl_appconfig')->where('setting', 'language')->find_one();
            $d->value = $lan;
            $d->save();
            _log('[' . $admin['username'] . ']: ' . 'Settings Saved Successfully', $admin['user_type'], $admin['id']);
            r2(getUrl('settings/localisation'), 's', 'Settings Saved Successfully');
        }
        break;

    case 'users':
        $search = _req('search');
        // WP2 / SEC-06: SuperAdmin all; Admin non-admin roles + self; Agent its subtree (closure);
        // Sales/Report self only
        $query = ORM::for_table('tbl_users')->order_by_asc('id');
        if ($search != '') {
            $query->where_like('username', '%' . addcslashes($search, '\\%_') . '%');
        }
        if ($nuxrActor->isRole('Admin')) {
            $query->where_raw("(tbl_users.user_type IN ('Report','Agent','Sales') OR tbl_users.id = ?)", [(int) $nuxrActor->userId]);
        } elseif (!$nuxrActor->isRole('SuperAdmin')) {
            \Reseller\Security\DataScope::scopeStaff($query, $nuxrActor, $nuxrC::STAFF_READ, 'tbl_users.id');
        }
        $d = $search != '' ? Paginator::findMany($query, ['search' => $search]) : Paginator::findMany($query);
        $admins = [];
        foreach ($d as $k) {
            if (!empty($k['root'])) {
                $admins[] = $k['root'];
            }
        }
        if (count($admins) > 0) {
            $adms = ORM::for_table('tbl_users')->where_in('id', $admins)->findArray();
            unset($admins);
            foreach ($adms as $adm) {
                $admins[$adm['id']] = $adm['fullname'];
            }
        }
        // WP6C (fix round 1, owner amendment A): per-row presentation from the accepted target policies, so a row only
        // offers what the authoritative writers would accept for that target (route rules alone are not enough):
        // view = nuxr_staff_visible (users-view), edit = self or StaffService::canTarget(STAFF_UPDATE_PROFILE)
        // (users-edit), delete = StaffService::canTarget(STAFF_DELETE) and not an Agent (StaffService::delete V1 rule).
        // A parent identity links only when that account is visible to the actor. Presentation metadata only.
        $nuxrRows = [];
        foreach ($d as $k) {
            $row = is_array($k) ? $k : $k->as_array();
            $self = (int) $row['id'] === (int) $nuxrActor->userId;
            $nuxrRows[(int) $row['id']] = [
                'view' => nuxr_staff_visible($nuxrActor, $row),
                'edit' => $self || \Reseller\Domain\StaffService::canTarget($nuxrActor, $row, $nuxrC::STAFF_UPDATE_PROFILE),
                'delete' => !$self && $row['user_type'] !== 'Agent' && \Reseller\Domain\StaffService::canTarget($nuxrActor, $row, $nuxrC::STAFF_DELETE),
            ];
        }
        $nuxrParentView = [];
        foreach ($adms ?? [] as $adm) {
            $nuxrParentView[(int) $adm['id']] = nuxr_staff_visible($nuxrActor, $adm);
        }
        $ui->assign('nuxr_rows', $nuxrRows);
        $ui->assign('nuxr_parent_view', $nuxrParentView);
        $ui->assign('admins', $admins);
        $ui->assign('d', $d);
        $ui->assign('search', $search);
        run_hook('view_list_admin'); #HOOK
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->display('admin/admin/list.tpl');
        break;

    case 'users-add':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->assign('_title', Lang::T('Add User'));
        // WP2: role and parent choices come from the server-side target-role table
        $ui->assign('nuxr_roles', \Reseller\Domain\StaffService::creatableRoles($nuxrActor));
        $ui->assign('nuxr_parent_fixed', \Reseller\Security\PermissionService::scopeOf($nuxrActor, $nuxrC::STAFF_CREATE) === 'C');
        $ui->assign('agents', \Reseller\Domain\CustomerService::ownerOptions());
        $ui->display('admin/admin/add.tpl');
        break;
    case 'users-view':
        $ui->assign('_title', Lang::T('Edit User'));
        $id = $routes['2'];
        if (empty($id)) {
            $id = $admin['id'];
        }
        // WP2 / SEC-06: self, or a target within the actor's staff.read scope (no IDOR)
        $d = \Reseller\Security\DataScope::validId($id) ? (ORM::for_table('tbl_users')->where('id', (int) $id)->find_array()[0] ?? null) : null;
        if (!nuxr_staff_visible($nuxrActor, $d)) {
            $d = null;
        }
        if ($d) {
            unset($d['password'], $d['login_token']);
            run_hook('view_edit_admin'); #HOOK
            if ($d['user_type'] == 'Sales' && $d['root']) {
                $nuxrAgent = ORM::for_table('tbl_users')->select_many('id', 'username', 'fullname', 'phone', 'email')->where('id', $d['root'])->find_array();
                $ui->assign('agent', $nuxrAgent[0] ?? null);
            }
            $ui->assign('d', $d);
            $ui->assign('_title', $d['username']);
            $csrf_token = Csrf::generateAndStoreToken();
            $ui->assign('csrf_token', $csrf_token);
            $ui->display('admin/admin/view.tpl');
        } else {
            r2(getUrl('settings/users'), 'e', Lang::T('Account Not Found'));
        }
        break;
    case 'users-edit':
        // WP2: every role may edit its own profile; other targets are checked below (StaffService::canTarget)
        $ui->assign('_title', Lang::T('Edit User'));
        $id = $routes['2'];
        if (empty($id)) {
            $id = $admin['id'];
        }
        // WP2 / SEC-04: self, or a target the actor may manage (StaffService::canTarget)
        $d = \Reseller\Security\DataScope::validId($id) ? ORM::for_table('tbl_users')->find_one((int) $id) : false;
        if ($d && (int) $d['id'] !== (int) $nuxrActor->userId && !\Reseller\Domain\StaffService::canTarget($nuxrActor, $d->as_array(), $nuxrC::STAFF_UPDATE_PROFILE)) {
            $d = false;
        }
        $ui->assign('nuxr_self', $d && (int) $d['id'] === (int) $nuxrActor->userId);
        $ui->assign('nuxr_can_status', $d && (int) $d['id'] !== (int) $nuxrActor->userId && \Reseller\Domain\StaffService::canTarget($nuxrActor, $d->as_array(), $nuxrC::STAFF_DISABLE));
        // an Agent's role is permanent in V1 (reseller identity); StaffService::changeRole() enforces it
        $ui->assign('nuxr_can_role', $d && (int) $d['id'] !== (int) $nuxrActor->userId && $d['user_type'] !== 'Agent' && \Reseller\Domain\StaffService::canTarget($nuxrActor, $d->as_array(), $nuxrC::STAFF_CHANGE_ROLE));
        $ui->assign('nuxr_roles', \Reseller\Domain\StaffService::manageableRoles($nuxrActor));
        if ($d) {
            if (isset($routes['3']) && $routes['3'] == 'deletePhoto') {
                if ($d['photo'] != '' && strpos($d['photo'], 'default') === false) {
                    if (file_exists($UPLOAD_PATH . $d['photo']) && strpos($d['photo'], 'default') === false) {
                        unlink($UPLOAD_PATH . $d['photo']);
                        if (file_exists($UPLOAD_PATH . $d['photo'] . '.thumb.jpg')) {
                            unlink($UPLOAD_PATH . $d['photo'] . '.thumb.jpg');
                        }
                    }
                    $d->photo = '/admin.default.png';
                    $d->save();
                    $ui->assign('notify_t', 's');
                    $ui->assign('notify', 'You have successfully deleted the photo');
                } else {
                    $ui->assign('notify_t', 'e');
                    $ui->assign('notify', 'No photo found to delete');
                }
            }
            $ui->assign('id', $id);
            $ui->assign('d', $d);
            run_hook('view_edit_admin'); #HOOK
            $csrf_token = Csrf::generateAndStoreToken();
            $ui->assign('csrf_token', $csrf_token);
            $ui->display('admin/admin/edit.tpl');
        } else {
            r2(getUrl('settings/users'), 'e', Lang::T('Account Not Found'));
        }
        break;

    case 'users-delete':
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/users'), 'e', 'You cannot perform this action in Demo mode');
        }
        $id = $routes['2'];
        // WP2 / SEC-04: POST + CSRF; target rules; reference pre-check (enrolled/referenced → disable instead)
        run_hook('delete_admin'); #HOOK
        try {
            \Reseller\Domain\StaffService::delete($nuxrActor, (int) $id);
        } catch (\Reseller\Domain\DomainError $e) {
            r2(getUrl('settings/users'), 'e', Lang::T($e->getMessage()));
        }
        r2(getUrl('settings/users'), 's', Lang::T('User deleted Successfully'));
        break;

    case 'users-post':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin', 'Agent'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/users-add'), 'e', 'You cannot perform this action in Demo mode');
        }
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('settings/users-add'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        $username = _post('username');
        $fullname = _post('fullname');
        $password = _post('password');
        $user_type = _post('user_type');
        $phone = _post('phone');
        $email = _post('email');
        $city = _post('city');
        $subdistrict = _post('subdistrict');
        $ward = _post('ward');
        $send_notif = _post('send_notif');
        $root = _post('root');
        $msg = '';
        if (Validator::Length($username, 45, 2) == false) {
            $msg .= Lang::T('Username should be between 3 to 45 characters') . '<br>';
        }
        if (Validator::Length($fullname, 45, 2) == false) {
            $msg .= Lang::T('Full Name should be between 3 to 45 characters') . '<br>';
        }
        if (!Validator::Length($password, 1000, 5)) {
            $msg .= Lang::T('Password should be minimum 6 characters') . '<br>';
        }

        run_hook('add_admin'); #HOOK
        if ($msg == '') {
            // WP2 / SEC-04 / C20: server-side role table; parent only through the hierarchy rules
            try {
                \Reseller\Domain\StaffService::create($nuxrActor, [
                    'username' => $username, 'fullname' => $fullname, 'password' => $password, 'user_type' => $user_type,
                    'parent_id' => _post('parent_id'), 'phone' => $phone, 'email' => $email,
                    'city' => $city, 'subdistrict' => $subdistrict, 'ward' => $ward,
                ]);
            } catch (\Reseller\Domain\DomainError $e) {
                r2(getUrl('settings/users-add'), 'e', Lang::T($e->getMessage()));
            }

            if ($send_notif == 'wa') {
                Message::sendWhatsapp(Lang::phoneFormat($phone), Lang::T('Hello, Your account has been created successfully.') . "\nUsername: $username\nPassword: $password\n\n" . $config['CompanyName']);
            } else if ($send_notif == 'sms') {
                Message::sendSMS($phone, Lang::T('Hello, Your account has been created successfully.') . "\nUsername: $username\nPassword: $password\n\n" . $config['CompanyName']);
            }

            _log('[' . $admin['username'] . ']: ' . "Created $user_type <b>$username</b>", $admin['user_type'], $admin['id']);
            r2(getUrl('settings/users'), 's', Lang::T('Account Created Successfully'));
        } else {
            r2(getUrl('settings/users-add'), 'e', $msg);
        }
        break;

    case 'users-edit-post':
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/users-edit/'), 'e', 'You cannot perform this action in Demo mode');
        }
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('settings/users-edit/'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        $username = _post('username');
        $fullname = _post('fullname');
        $password = _post('password');
        $cpassword = _post('cpassword');
        $user_type = _post('user_type');
        $phone = _post('phone');
        $email = _post('email');
        $city = _post('city');
        $subdistrict = _post('subdistrict');
        $ward = _post('ward');
        $status = _post('status');
        $root = _post('root');
        $msg = '';
        if (Validator::Length($username, 45, 2) == false) {
            $msg .= Lang::T('Username should be between 3 to 45 characters') . '<br>';
        }
        if (Validator::Length($fullname, 45, 2) == false) {
            $msg .= Lang::T('Full Name should be between 3 to 45 characters') . '<br>';
        }
        if ($password != '') {
            if (!Validator::Length($password, 1000, 5)) {
                $msg .= Lang::T('Password should be minimum 6 characters') . '<br>';
            }
            if ($password != $cpassword) {
                $msg .= Lang::T('Passwords does not match') . '<br>';
            }
        }

        $id = (int) _post('id');
        // WP2 / SEC-04: target must be self or manageable; user_type/status/root are never written
        // from the form directly (role and status go through audited, rule-checked services)
        $d = $id > 0 ? ORM::for_table('tbl_users')->find_one($id) : false;
        if ($d && $id !== (int) $nuxrActor->userId && !\Reseller\Domain\StaffService::canTarget($nuxrActor, $d->as_array(), $nuxrC::STAFF_UPDATE_PROFILE)) {
            $d = false;
        }
        if (!$d) {
            $msg .= Lang::T('Data Not Found') . '<br>';
        }
        run_hook('edit_admin'); #HOOK
        if ($msg == '') {
            $nuxrPhoto = null;
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
                        File::makeThumb($imgPath, $imgPath . '.thumb.jpg', 200);
                    }
                    if (file_exists($imgPath)) {
                        $nuxrPhoto = '/photos/' . $subfolder . '/' . $hash . '.jpg';
                    }
                    if (file_exists($_FILES['photo']['tmp_name']))
                        unlink($_FILES['photo']['tmp_name']);
                } else {
                    r2(getUrl('settings/app'), 'e', 'PHP GD is not installed');
                }
            }
            $nuxrProfile = ['username' => $username, 'fullname' => $fullname, 'phone' => $phone, 'email' => $email,
                'city' => $city, 'subdistrict' => $subdistrict, 'ward' => $ward];
            if ($nuxrPhoto !== null) {
                $nuxrProfile['photo'] = $nuxrPhoto;
            }
            try {
                \Reseller\Domain\StaffService::updateProfile($nuxrActor, $id, $nuxrProfile, $password !== '' ? $password : null);
                if ($id !== (int) $nuxrActor->userId) {
                    if (isset($_POST['status']) && in_array($status, ['Active', 'Inactive'], true) && $status !== $d['status']) {
                        \Reseller\Domain\StaffService::setStatus($nuxrActor, $id, $status);
                    }
                    if ($user_type !== '' && $user_type !== $d['user_type']) {
                        \Reseller\Domain\StaffService::changeRole($nuxrActor, $id, $user_type);
                    }
                }
            } catch (\Reseller\Domain\DomainError $e) {
                r2(getUrl('settings/users-edit/') . $id, 'e', Lang::T($e->getMessage()));
            }
            if ($nuxrPhoto !== null && $d['photo'] != '' && strpos($d['photo'], 'default') === false && file_exists($UPLOAD_PATH . $d['photo'])) {
                unlink($UPLOAD_PATH . $d['photo']);
                if (file_exists($UPLOAD_PATH . $d['photo'] . '.thumb.jpg')) {
                    unlink($UPLOAD_PATH . $d['photo'] . '.thumb.jpg');
                }
            }

            _log('[' . $admin['username'] . ']: $username ' . Lang::T('User Updated Successfully'), $admin['user_type'], $admin['id']);
            r2(getUrl('settings/users-view/') . $id, 's', 'User Updated Successfully');
        } else {
            r2(getUrl('settings/users-edit/') . $id, 'e', $msg);
        }
        break;

    case 'change-password':
        run_hook('view_change_password'); #HOOK
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->display('admin/change-password.tpl');
        break;

    case 'change-password-post':
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/change-password'), 'e', 'You cannot perform this action in Demo mode');
        }
        $password = _post('password');
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('settings/change-password'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        if ($password != '') {
            $d = ORM::for_table('tbl_users')->where('username', $admin['username'])->find_one();
            run_hook('change_password'); #HOOK
            if ($d) {
                $d_pass = $d['password'];
                if (Password::_verify($password, $d_pass) == true) {
                    $npass = _post('npass');
                    $cnpass = _post('cnpass');
                    if (!Validator::Length($npass, 15, 5)) {
                        r2(getUrl('settings/change-password'), 'e', 'New Password must be 6 to 14 character');
                    }
                    if ($npass != $cnpass) {
                        r2(getUrl('settings/change-password'), 'e', 'Both Password should be same');
                    }

                    $npass = Password::_crypt($npass);
                    $d->password = $npass;
                    $d->save();

                    _msglog('s', Lang::T('Password changed successfully, Please login again'));
                    _log('[' . $admin['username'] . ']: Password changed successfully', $admin['user_type'], $admin['id']);

                    r2(getUrl('admin'));
                } else {
                    r2(getUrl('settings/change-password'), 'e', Lang::T('Incorrect Current Password'));
                }
            } else {
                r2(getUrl('settings/change-password'), 'e', Lang::T('Incorrect Current Password'));
            }
        } else {
            r2(getUrl('settings/change-password'), 'e', Lang::T('Incorrect Current Password'));
        }
        break;

    case 'notifications':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        run_hook('view_notifications'); #HOOK
        if (file_exists($UPLOAD_PATH . DIRECTORY_SEPARATOR . "notifications.json")) {
            $ui->assign('_json', json_decode(file_get_contents($UPLOAD_PATH . DIRECTORY_SEPARATOR . 'notifications.json'), true));
        } else {
            $ui->assign('_json', json_decode(file_get_contents($UPLOAD_PATH . DIRECTORY_SEPARATOR . 'notifications.default.json'), true));
        }

        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->assign('_default', json_decode(file_get_contents($UPLOAD_PATH . DIRECTORY_SEPARATOR . 'notifications.default.json'), true));
        $ui->display('admin/settings/notifications.tpl');
        break;
    case 'notifications-post':
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/notifications'), 'e', 'You cannot perform this action in Demo mode');
        }
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('settings/notifications'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        $nuxrNotif = $_POST;
        unset($nuxrNotif['csrf_token']);
        file_put_contents($UPLOAD_PATH . "/notifications.json", json_encode($nuxrNotif));
        r2(getUrl('settings/notifications'), 's', Lang::T('Settings Saved Successfully'));
        break;
    case 'dbstatus':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }

        // WP6C (Amendment H): the application's own PDO connection - the mysqli extension is not part of the shipped
        // image, so `new mysqli` was a fatal 500 here; no second connection from raw credentials either
        if ($result = ORM::get_db()->query('SHOW TABLE STATUS')) {
            $tables = array();
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $tables[$row['Name']]['rows'] = ORM::for_table($row["Name"])->count();
                $tables[$row['Name']]['name'] = $row["Name"];
            }
            $ui->assign('tables', $tables);
            run_hook('view_database'); #HOOK
            $ui->display('admin/settings/dbstatus.tpl');
        }
        break;

    case 'dbbackup':
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/dbstatus'), 'e', 'You cannot perform this action in Demo mode');
        }
        if (!in_array($admin['user_type'], ['SuperAdmin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        // WP2: only existing tables can be exported (no arbitrary identifiers)
        $tables = \Reseller\Security\RestoreGuard::backupTables($_POST['tables'] ?? []);
        if ($tables === []) {
            r2(getUrl('settings/dbstatus'), 'e', Lang::T('Select at least one table'));
        }
        set_time_limit(-1);
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Type: application/force-download');
        header('Content-Type: application/octet-stream');
        header('Content-Type: application/download');
        header('Content-Disposition: attachment;filename="phpnuxbill_' . count($tables) . '_tables_' . date('Y-m-d_H_i') . '.json"');
        header('Content-Transfer-Encoding: binary');
        $array = [];
        foreach ($tables as $table) {
            $array[$table] = ORM::for_table($table)->find_array();
        }
        echo json_encode($array);
        break;
    case 'dbrestore':
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/dbstatus'), 'e', 'You cannot perform this action in Demo mode');
        }
        if (!in_array($admin['user_type'], ['SuperAdmin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        // WP2 / C24 / OD-5: fail closed BEFORE reading the upload when reseller schema/state exists
        $nuxrRefusal = \Reseller\Security\RestoreGuard::refusalReason();
        if ($nuxrRefusal !== null) {
            \Reseller\Security\ErrorReporter::log('restore.refused', $nuxrRefusal);
            if (\Reseller\Domain\SchemaReadiness::isReady()) {
                try {
                    \Reseller\Audit\AuditLog::record('restore.refused', $nuxrActor, ['target_type' => 'system', 'reason' => mb_substr($nuxrRefusal, 0, 200)]);
                } catch (Throwable $e) {
                    \Reseller\Security\ErrorReporter::report($e, 'restore-audit');
                }
            }
            if (!empty($_FILES['json']['tmp_name']) && file_exists($_FILES['json']['tmp_name'])) {
                unlink($_FILES['json']['tmp_name']);
            }
            http_response_code(403);
            r2(getUrl('settings/dbstatus'), 'e', Lang::T('Restore is disabled on reseller installations; use the database-level restore runbook.'));
        }
        if (!empty($_FILES['json']['tmp_name']) && file_exists($_FILES['json']['tmp_name'])) {
            $suc = 0;
            $fal = 0;
            $json = json_decode(file_get_contents($_FILES['json']['tmp_name']), true);
            $nuxrTables = \Reseller\Security\RestoreGuard::validTables($json);
            if ($nuxrTables === null) {
                unlink($_FILES['json']['tmp_name']);
                r2(getUrl('settings/dbstatus'), 'e', Lang::T('Invalid backup file'));
            }
            // session-level only (never GLOBAL); restored in finally
            $nuxrFk = (string) ORM::get_db()->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn();
            ORM::raw_execute("SET SESSION FOREIGN_KEY_CHECKS=0");
            try {
                foreach ($nuxrTables as $table) {
                    ORM::raw_execute('TRUNCATE `' . $table . '`');
                    foreach ($json[$table] as $rec) {
                        try {
                            $t = ORM::for_table($table)->create();
                            foreach ((array) $rec as $k => $v) {
                                $t->set($k, $v);
                            }
                            if ($t->save()) {
                                $suc++;
                            } else {
                                $fal++;
                            }
                        } catch (Throwable $e) {
                            $fal++;
                        }
                    }
                }
            } finally {
                ORM::raw_execute('SET SESSION FOREIGN_KEY_CHECKS=' . ($nuxrFk === '0' ? '0' : '1'));
            }
            if (file_exists($_FILES['json']['tmp_name']))
                unlink($_FILES['json']['tmp_name']);
            r2(getUrl('settings/dbstatus'), 's', "Restored $suc success $fal failed");
        } else {
            r2(getUrl('settings/dbstatus'), 'e', 'Upload failed');
        }
        break;
    case 'language':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }
        run_hook('view_add_language'); #HOOK
        if (file_exists($lan_file)) {
            $ui->assign('langs', json_decode(file_get_contents($lan_file), true));
        } else {
            $ui->assign('langs', []);
        }
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->display('admin/settings/language-add.tpl');
        break;

    case 'lang-post':
        if ($_app_stage == 'Demo') {
            r2(getUrl('settings/dbstatus'), 'e', 'You cannot perform this action in Demo mode');
        }
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(getUrl('settings/language'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
        }
        // WP2 / NEW-1: $lan_file is always a shipped system/lan/<name>.json (init.php allowlist);
        // only string translations are stored, never the CSRF token or nested data
        $nuxrLang = [];
        foreach ($_POST as $k => $v) {
            if (is_string($k) && is_string($v) && $k !== 'csrf_token') {
                // Lab finding F-12: translations are printed unescaped by the templates ({Lang::T(...)}), so markup in a
                // translation written by an Admin rendered in SuperAdmin's and customers' pages. Translations are plain
                // text: a value with markup characters is refused and nothing is written
                if (strpbrk($v, '<>') !== false) {
                    r2(getUrl('settings/language'), 'e', Lang::T('Translations cannot contain HTML markup'));
                }
                $nuxrLang[$k] = $v;
            }
        }
        if (\Reseller\Security\PathGuard::language($config['language'], $root_path) === null) {
            r2(getUrl('settings/language'), 'e', Lang::T('Invalid language'));
        }
        file_put_contents($lan_file, json_encode($nuxrLang, JSON_PRETTY_PRINT));
        r2(getUrl('settings/language'), 's', Lang::T('Translation saved Successfully'));
        break;

    case 'maintenance':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
            exit;
        }

        if (_post('save') == 'save') {
            if ($_app_stage == 'Demo') {
                r2(getUrl('settings/maintenance'), 'e', 'You cannot perform this action in Demo mode');
            }
            $csrf_token = _post('csrf_token');
            if (!Csrf::check($csrf_token)) {
                r2(getUrl('settings/maintenance'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
            }
            $status = isset($_POST['maintenance_mode']) ? 1 : 0; // Checkbox returns 1 if checked, otherwise 0
            $force_logout = isset($_POST['maintenance_mode_logout']) ? 1 : 0; // Checkbox returns 1 if checked, otherwise 0
            $date = isset($_POST['maintenance_date']) ? $_POST['maintenance_date'] : null;

            $settings = [
                'maintenance_mode' => $status,
                'maintenance_mode_logout' => $force_logout,
                'maintenance_date' => $date
            ];

            foreach ($settings as $key => $value) {
                $d = ORM::for_table('tbl_appconfig')->where('setting', $key)->find_one();
                if ($d) {
                    $d->value = $value;
                    $d->save();
                } else {
                    $d = ORM::for_table('tbl_appconfig')->create();
                    $d->setting = $key;
                    $d->value = $value;
                    $d->save();
                }
            }

            r2(getUrl('settings/maintenance'), 's', Lang::T('Settings Saved Successfully'));
        }
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->assign('_c', $config);
        $ui->assign('_title', Lang::T('Maintenance Mode Settings'));
        $ui->display('admin/settings/maintenance-mode.tpl');
        break;

    case 'miscellaneous':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
            exit;
        }
        if (_post('save') == 'save') {
            if ($_app_stage == 'Demo') {
                r2(getUrl('settings/miscellaneous'), 'e', 'You cannot perform this action in Demo mode');
            }
            $csrf_token = _post('csrf_token');
            if (!Csrf::check($csrf_token)) {
                r2(getUrl('settings/miscellaneous'), 'e', Lang::T('Invalid or Expired CSRF Token') . ".");
            }
            // Lab finding F-1: only the keys of this form are stored (as settings/app-post does since WP2 SEC-07); the
            // security keys stay SuperAdmin-only on settings/app, and the form controls (save, csrf_token) are not settings
            $nuxrMiscAllowed = ['new_version_notify', 'router_check', 'allow_phone_otp', 'phone_otp_type', 'allow_email_otp',
                'show_bandwidth_plan', 'hs_auth_method', 'frrest_interim_update', 'check_customer_online', 'extend_expiry'];
            foreach ($_POST as $key => $value) {
                if (!is_string($key) || !in_array($key, $nuxrMiscAllowed, true) || !is_scalar($value)) {
                    continue;
                }
                $d = ORM::for_table('tbl_appconfig')->where('setting', $key)->find_one();
                if ($d) {
                    $d->value = $value;
                    $d->save();
                } else {
                    $d = ORM::for_table('tbl_appconfig')->create();
                    $d->setting = $key;
                    $d->value = $value;
                    $d->save();
                }
            }

            r2(getUrl('settings/miscellaneous'), 's', Lang::T('Settings Saved Successfully'));
        }
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->assign('_c', $config);
        $ui->assign('_title', Lang::T('Miscellaneous Settings'));
        $ui->display('admin/settings/miscellaneous.tpl');
        break;

    default:
        $ui->display('admin/404.tpl');
}
