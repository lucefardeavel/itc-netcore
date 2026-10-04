<?php
/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

// WP2 / SEC-05: staff only (RouteGuard: customer.read), results limited to the actor's DataScope,
// LIKE wildcards escaped, minimum length, bounded result size, escaped output.
// WP6A: the shell's subscriber search. Username, full name (Arabic included), phone, PPPoE username, static IP,
// open-session framed IP, customer id (#123) and e-mail; the scope predicate is applied before the search
// predicate, so the OR group can never widen it. Returns listbox options (HTML fragment) for nuxr-shell.js.
_admin();
$nuxrActor = \Reseller\Security\ActorContext::current();
$term = \Reseller\Domain\CustomerSearch::term($_GET['query'] ?? null);

if ($term === null) {
    echo '<p class="nuxr-search-msg">' . htmlspecialchars(Lang::T('Type at least 2 characters.'), ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}
[$nuxrPred, $nuxrParams] = \Reseller\Domain\CustomerSearch::predicate($term, 'tbl_customers', false, \Reseller\Ui\Subscriber::sessionUsernames($term));
$results = \Reseller\Security\DataScope::scopeCustomers(ORM::for_table('tbl_customers'), $nuxrActor, \Reseller\Security\Capability::CUSTOMER_READ)
    ->where_raw($nuxrPred, $nuxrParams)
    ->select_many('id', 'username', 'fullname', 'phonenumber', 'pppoe_username', 'pppoe_ip', 'service_type', 'status')
    ->order_by_asc('tbl_customers.username')
    ->limit(20)
    ->find_array();

if (!$results) {
    echo '<p class="nuxr-search-msg">' . htmlspecialchars(Lang::T('No subscribers found.'), ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}
$ui->assign('nuxr_results', $results);
$ui->assign('nuxr_plans', \Reseller\Ui\Subscriber::plans(array_column($results, 'id')));
$ui->assign('nuxr_more_url', getUrl('customers/list') . '&filter=All&search=' . urlencode($term));
$ui->display('admin/nuxr/search-results.tpl');
