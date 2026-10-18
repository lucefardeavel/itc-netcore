<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

_admin();
$ui->assign('_title', \Reseller\Ui\Brand::NAME . ' ' . Lang::T('Logs'));
$ui->assign('_system_menu', 'logs');

$action = $routes['1'];
$ui->assign('_admin', $admin);

if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
    _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
}


switch ($action) {
    case 'list-csv':
        $logs = ORM::for_table('tbl_logs')
            ->select('id')
            ->select('date')
            ->select('type')
            ->select('description')
            ->select('userid')
            ->select('ip')
            ->order_by_asc('id')->find_array();
        $h = false;
        set_time_limit(-1);
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header("Content-type: text/csv");
        header('Content-Disposition: attachment;filename="activity-logs_' . date('Y-m-d_H_i') . '.csv"');
        header('Content-Transfer-Encoding: binary');
        foreach ($logs as $log) {
            $ks = [];
            $vs = [];
            foreach ($log as $k => $v) {
                $ks[] = $k;
                $vs[] = $v;
            }
            if (!$h) {
                echo \Reseller\Ui\Format::csvLine($ks, ';');
                $h = true;
            }
            echo \Reseller\Ui\Format::csvLine($vs, ';');
        }
        break;
    case 'radius-csv':
        $logs = ORM::for_table('radpostauth')
            ->select('id')
            ->select('username')
            ->select_expr("'[hidden]'", 'pass') // WP9-D / N-11: post-auth passwords are never exported
            ->select('reply')
            ->select('authdate')
            ->order_by_asc('id')->find_array();
        $h = false;
        set_time_limit(-1);
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header("Content-type: text/csv");
        header('Content-Disposition: attachment;filename="radius-logs_' . date('Y-m-d_H_i') . '.csv"');
        header('Content-Transfer-Encoding: binary');
        foreach ($logs as $log) {
            $ks = [];
            $vs = [];
            foreach ($log as $k => $v) {
                $ks[] = $k;
                $vs[] = $v;
            }
            if (!$h) {
                echo \Reseller\Ui\Format::csvLine($ks, ';');
                $h = true;
            }
            echo \Reseller\Ui\Format::csvLine($vs, ';');
        }
        break;

    case 'message-csv':
        $logs = ORM::for_table('tbl_message_logs')
            ->select('id')
            ->select('message_type')
            ->select('recipient')
            ->select('message_content')
            ->select('status')
            ->select('error_message')
            ->select('sent_at')
            ->order_by_asc('id')->find_array();
        $h = false;
        set_time_limit(-1);
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header("Content-type: text/csv");
        header('Content-Disposition: attachment;filename="message-logs_' . date('Y-m-d_H_i') . '.csv"');
        header('Content-Transfer-Encoding: binary');
        foreach ($logs as $log) {
            $ks = [];
            $vs = [];
            foreach ($log as $k => $v) {
                $ks[] = $k;
                $vs[] = $v;
            }
            if (!$h) {
                echo \Reseller\Ui\Format::csvLine($ks, ';');
                $h = true;
            }
            echo \Reseller\Ui\Format::csvLine($vs, ';');
        }
        break;

    case 'list':
        $q = (_post('q') ? _post('q') : _get('q'));
        // WP2 / SEC-12: bounded integer, bound parameter
        $keep = _post('keep');
        if ($keep !== '') {
            if (!preg_match('/^[1-9][0-9]{0,4}$/', (string) $keep) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
                r2(getUrl('logs/list/'), 'e', Lang::T('Invalid input'));
            }
            ORM::raw_execute("DELETE FROM tbl_logs WHERE UNIX_TIMESTAMP(date) < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? DAY))", [(int) $keep]);
            r2(getUrl('logs/list/'), 's', "Delete logs older than " . (int) $keep . " days");
        }
        if ($q != '') {
            $query = ORM::for_table('tbl_logs')->where_like('description', '%' . $q . '%')->order_by_desc('id');
            $d = Paginator::findMany($query, ['q' => $q]);
        } else {
            $query = ORM::for_table('tbl_logs')->order_by_desc('id');
            $d = Paginator::findMany($query);
        }

        $ui->assign('d', $d);
        $ui->assign('q', $q);
        $ui->display('admin/logs/system.tpl');
        break;
    case 'radius':
        $q = (_post('q') ? _post('q') : _get('q'));
        $keep = _post('keep');
        if ($keep !== '') {
            if (!preg_match('/^[1-9][0-9]{0,4}$/', (string) $keep) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
                r2(getUrl('logs/radius/'), 'e', Lang::T('Invalid input'));
            }
            ORM::raw_execute("DELETE FROM radpostauth WHERE UNIX_TIMESTAMP(authdate) < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? DAY))", [(int) $keep], 'radius');
            r2(getUrl('logs/radius/'), 's', "Delete logs older than " . (int) $keep . " days");
        }
        if ($q != '') {
            $query = ORM::for_table('radpostauth', 'radius')->where_like('username', '%' . $q . '%')->order_by_desc('id');
            $d = Paginator::findMany($query, ['q' => $q]);
        } else {
            $query = ORM::for_table('radpostauth', 'radius')->order_by_desc('id');
            $d = Paginator::findMany($query);
        }

        $ui->assign('d', $d);
        $ui->assign('q', $q);
        $ui->display('admin/logs/radius.tpl');
        break;

    case 'message':
        $q = _post('q') ?: _get('q');
        $keep = (int) _post('keep');
        if ($keep > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
            ORM::raw_execute("DELETE FROM tbl_message_logs WHERE UNIX_TIMESTAMP(sent_at) < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? DAY))", [$keep]);
            r2(getUrl('logs/message/'), 's', "Deleted logs older than $keep days");
        }

        if ($q !== null && $q !== '') {
            // WP2 / SEC-12: bound parameters, parenthesized OR group (was string-interpolated SQL)
            $like = '%' . $q . '%';
            $query = ORM::for_table('tbl_message_logs')
            ->whereRaw("(message_type LIKE ? OR recipient LIKE ? OR message_content LIKE ? OR status LIKE ? OR error_message LIKE ?)", [$like, $like, $like, $like, $like])
                ->order_by_desc('sent_at');
            $d = Paginator::findMany($query, ['q' => $q]);
        } else {
            $query = ORM::for_table('tbl_message_logs')->order_by_desc('sent_at');
            $d = Paginator::findMany($query);
        }

        if ($d) {
            $ui->assign('d', $d);
        } else {
            $ui->assign('d', []);
        }

        $ui->assign('q', $q);
        $ui->display('admin/logs/message.tpl');
        break;

    default:
        r2(getUrl('logs/list/'), 's', '');
}
