<?php
// Controller: only reachable through the dispatcher (index.php); never API-allowed (WP6B owner decision OD-1).
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP6B — reseller finance web surface (owner decisions OD-1 .. OD-6). Thin: every action is authorized centrally
 * (RouteGuard / RouteMap) and again by the authoritative services behind Reseller\Backoffice; reads are scoped there
 * (wallet.read / ledger.read: G for Admin / SuperAdmin, T for an Agent). No amount, balance or price is taken from the
 * browser as authority. CSV exports (&format=csv) use the same route, capability, scope and query as the screen.
 *
 *   overview   GET  Agent: own wallet; Admin / SuperAdmin: wallets
 *   wallets    GET  scoped wallet list (+csv)
 *   wallet     GET  one wallet (balance, debt line, reserved, available; latest movements; offered actions)
 *   ledger     GET  one wallet's immutable ledger, newest first, filters (+csv, date range required)
 *   entry      GET  one ledger entry with its links
 *   transfers  GET  transfers in scope (+csv, date range required)
 *   transfer   GET  one transfer with its in-scope legs
 *   funding    GET  Admin: provider funding / withdrawal form (op=fund|withdraw)
 *   funding-review POST  review (no write): normalised amount, before / after, reference, confirm token
 *   funding-post   POST  execute the reviewed operation (TransferService::fund / withdraw)
 *   send       GET  parent -> direct child transfer form (Agent: own wallet; Admin: the child's parent)
 *   send-review    POST  review (no write)
 *   send-post      POST  execute the reviewed transfer (TransferService::transferToChild)
 */

_admin();
$ui->assign('_system_menu', 'finance');
$ui->assign('_admin', $admin);

$action = $routes['1'] ?? '';
$nuxrActor = \Reseller\Security\ActorContext::current();
$nuxrFail = static function (\Reseller\Domain\DomainError $e, string $back): void {
    r2(getUrl($back), 'e', Lang::T($e->getMessage()));
};
// review -> execute binding key (per session, never rendered; Backoffice\ConfirmToken)
if (!isset($_SESSION['nuxr_finance_key']) || !is_string($_SESSION['nuxr_finance_key']) || strlen($_SESSION['nuxr_finance_key']) !== 64) {
    $_SESSION['nuxr_finance_key'] = bin2hex(random_bytes(32));
}
$nuxrKey = $_SESSION['nuxr_finance_key'];
/** @return array<string,?string> the named POST fields (strings only) */
$nuxrPost = static function (array $keys): array {
    $out = [];
    foreach ($keys as $k) {
        $v = _post($k);
        $out[$k] = is_string($v) ? $v : null;
    }
    return $out;
};
/** @return array<string,string> the accepted filter keys of this request (trimmed strings only) */
$nuxrFilters = static function (array $keys): array {
    $out = [];
    foreach ($keys as $k) {
        $v = _get($k);
        if (is_string($v) && trim($v) !== '') {
            $out[$k] = mb_substr(trim($v), 0, 64);
        }
    }
    return $out;
};
$nuxrPage = static fn(): int => max(1, (int) (is_string(_get('page')) && ctype_digit(_get('page')) ? _get('page') : 1));
$nuxrCsv = _get('format') === 'csv';
$nuxrId = $routes['2'] ?? '';
/** List URLs with the current filters inside Text::url (canonical-URL safe); CSV = same route + format=csv. */
$nuxrUrls = static function (string $route, array $f) use ($ui): void {
    $qs = http_build_query($f);
    $ui->assign('nuxr_base', Text::url($route . '&' . $qs));
    $ui->assign('nuxr_csv', Text::url($route . '&' . ($qs !== '' ? $qs . '&' : '') . 'format=csv'));
};

switch ($action) {
    case 'wallets':
        $f = $nuxrFilters(['q', 'status', 'wallet']);
        try {
            if ($nuxrCsv) {
                \Reseller\Backoffice\WalletDesk::export($nuxrActor, 'wallets', null, $f);
                exit;
            }
            $list = \Reseller\Backoffice\WalletDesk::wallets($nuxrActor, $f, $nuxrPage());
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'dashboard');
        }
        $ui->assign('_title', Lang::T('Reseller wallets'));
        $ui->assign('nuxr_list', $list);
        $ui->assign('nuxr_filters', $f);
        $nuxrUrls('finance/wallets', $f);
        $ui->display('admin/finance/wallets.tpl');
        break;

    case 'wallet':
        try {
            $page = \Reseller\Backoffice\WalletDesk::wallet($nuxrActor, $nuxrId);
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'finance');
        }
        $ui->assign('_title', Lang::T('Wallet') . ' ' . $page['wallet']['username']);
        $ui->assign('nuxr_page', $page);
        $ui->display('admin/finance/wallet.tpl');
        break;

    case 'ledger':
        $f = $nuxrFilters(['from', 'to', 'type', 'direction']);
        try {
            if ($nuxrCsv) {
                \Reseller\Backoffice\WalletDesk::export($nuxrActor, 'ledger', $nuxrId, $f);
                exit;
            }
            $ledger = \Reseller\Backoffice\WalletDesk::ledger($nuxrActor, $nuxrId, $f, $nuxrPage());
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, is_string($nuxrId) && ctype_digit($nuxrId) && $e->reason !== 'NOT_FOUND' ? 'finance/ledger/' . $nuxrId : 'finance');
        }
        $ui->assign('_title', Lang::T('Ledger') . ' ' . $ledger['owner']['username']);
        $ui->assign('nuxr_ledger', $ledger);
        $ui->assign('nuxr_filters', $f);
        $nuxrUrls('finance/ledger/' . $ledger['owner']['id'], $f);
        $ui->display('admin/finance/ledger.tpl');
        break;

    case 'entry':
        try {
            $entry = \Reseller\Backoffice\WalletDesk::entry($nuxrActor, $nuxrId);
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'finance');
        }
        $ui->assign('_title', Lang::T('Ledger entry') . ' #' . $entry['id']);
        $ui->assign('nuxr_entry', $entry);
        $ui->display('admin/finance/entry.tpl');
        break;

    case 'transfers':
        $f = $nuxrFilters(['from', 'to', 'type', 'status', 'manager', 'reference']);
        try {
            if ($nuxrCsv) {
                \Reseller\Backoffice\WalletDesk::export($nuxrActor, 'transfers', null, $f);
                exit;
            }
            $list = \Reseller\Backoffice\WalletDesk::transfers($nuxrActor, $f, $nuxrPage());
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, $e->reason === 'NOT_FOUND' || $e->reason === 'FORBIDDEN' ? 'finance' : 'finance/transfers');
        }
        $ui->assign('_title', Lang::T('Transfers'));
        $ui->assign('nuxr_list', $list);
        $ui->assign('nuxr_filters', $f);
        $nuxrUrls('finance/transfers', $f);
        $ui->display('admin/finance/transfers.tpl');
        break;

    case 'transfer':
        try {
            $transfer = \Reseller\Backoffice\WalletDesk::transfer($nuxrActor, $nuxrId);
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'finance/transfers');
        }
        $ui->assign('_title', Lang::T('Transfer') . ' ' . $transfer['reference']);
        $ui->assign('nuxr_transfer', $transfer);
        $ui->display('admin/finance/transfer.tpl');
        break;

    case 'funding':
        try {
            $form = \Reseller\Backoffice\WalletDesk::fundingForm($nuxrActor, $nuxrId, _get('op'));
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'finance');
        }
        $ui->assign('_title', $form['op'] === 'fund' ? Lang::T('Provider funding') : Lang::T('Provider withdrawal'));
        $ui->assign('nuxr_form', $form);
        $ui->assign('nuxr_values', []);
        $ui->display('admin/finance/funding.tpl');
        break;

    case 'funding-review':
    case 'funding-post':
        $in = $action === 'funding-review'
            ? $nuxrPost(['manager_id', 'op', 'amount', 'reason', 'external_reference'])
            : $nuxrPost(['op', 'target', 'amount', 'reason', 'external', 'reference', 'token', 'confirm']);
        try {
            if ($action === 'funding-review') {
                $review = \Reseller\Backoffice\WalletDesk::reviewFunding($nuxrActor, $nuxrKey, $in);
                $ui->assign('_title', Lang::T('Review'));
                $ui->assign('nuxr_review', $review);
                $ui->display('admin/finance/review.tpl');
                break;
            }
            $done = \Reseller\Backoffice\WalletDesk::executeFunding($nuxrActor, $nuxrKey, $in);
            r2(getUrl('finance/transfer/' . $done['transfer_id']), 's', $done['replayed'] ? Lang::T('Already recorded: this confirmation was processed once.') : Lang::T('Recorded.'));
        } catch (\Reseller\Domain\DomainError $e) {
            // re-show the form with the operator's values and the fixed message (nothing was written)
            try {
                $form = \Reseller\Backoffice\WalletDesk::fundingForm($nuxrActor, $in['manager_id'] ?? $in['target'] ?? null, $in['op'] ?? null);
            } catch (\Reseller\Domain\DomainError $e2) {
                $nuxrFail($e, 'finance');
            }
            $ui->assign('_title', Lang::T('Provider funding'));
            $ui->assign('nuxr_form', $form);
            $ui->assign('nuxr_values', ['amount' => $in['amount'] ?? '', 'reason' => $in['reason'] ?? '', 'external_reference' => $in['external_reference'] ?? $in['external'] ?? '']);
            $ui->assign('nuxr_error', Lang::T($e->getMessage()));
            $ui->display('admin/finance/funding.tpl');
        }
        break;

    case 'send':
        try {
            $form = \Reseller\Backoffice\WalletDesk::sendForm($nuxrActor, _get('to'));
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'finance');
        }
        $ui->assign('_title', Lang::T('Send to a child reseller'));
        $ui->assign('nuxr_form', $form);
        $ui->assign('nuxr_values', []);
        $ui->display('admin/finance/send.tpl');
        break;

    case 'send-review':
    case 'send-post':
        $in = $action === 'send-review'
            ? $nuxrPost(['to_id', 'amount', 'reason'])
            : $nuxrPost(['op', 'from', 'to', 'amount', 'reason', 'reference', 'token']);
        try {
            if ($action === 'send-review') {
                $review = \Reseller\Backoffice\WalletDesk::reviewSend($nuxrActor, $nuxrKey, $in);
                $ui->assign('_title', Lang::T('Review'));
                $ui->assign('nuxr_review', $review);
                $ui->display('admin/finance/review.tpl');
                break;
            }
            $done = \Reseller\Backoffice\WalletDesk::executeSend($nuxrActor, $nuxrKey, $in);
            r2(getUrl('finance/transfer/' . $done['transfer_id']), 's', $done['replayed'] ? Lang::T('Already recorded: this confirmation was processed once.') : Lang::T('Recorded.'));
        } catch (\Reseller\Domain\DomainError $e) {
            try {
                $form = \Reseller\Backoffice\WalletDesk::sendForm($nuxrActor, $in['to_id'] ?? $in['to'] ?? null);
            } catch (\Reseller\Domain\DomainError $e2) {
                $nuxrFail($e, 'finance');
            }
            $ui->assign('_title', Lang::T('Send to a child reseller'));
            $ui->assign('nuxr_form', $form);
            $ui->assign('nuxr_values', ['amount' => $in['amount'] ?? '', 'reason' => $in['reason'] ?? '']);
            $ui->assign('nuxr_error', Lang::T($e->getMessage()));
            $ui->display('admin/finance/send.tpl');
        }
        break;

    case 'overview':
    default:
        // the bare controller and unknown actions are the read home (RouteMap '' / '*' => overview, wallet.read)
        try {
            $home = \Reseller\Backoffice\WalletDesk::home($nuxrActor);
        } catch (\Reseller\Domain\DomainError $e) {
            $nuxrFail($e, 'dashboard');
        }
        $ui->assign('_title', $home['mode'] === 'own' ? Lang::T('My wallet') : Lang::T('Finance'));
        $ui->assign('nuxr_home', $home);
        $ui->display('admin/finance/overview.tpl');
        break;
}
