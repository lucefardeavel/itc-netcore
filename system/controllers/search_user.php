<?php
/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

// WP2 / SEC-05: staff only (RouteGuard: customer.read), results limited to the actor's DataScope,
// LIKE wildcards escaped, minimum length, bounded result size, escaped output.
_admin();
$nuxrActor = \Reseller\Security\ActorContext::current();
$query = isset($_GET['query']) && is_string($_GET['query']) ? trim($_GET['query']) : '';

if (mb_strlen($query) >= 2) {
    $like = '%' . addcslashes($query, '\\%_') . '%';
    $results = \Reseller\Security\DataScope::scopeCustomers(ORM::for_table('tbl_customers'), $nuxrActor, \Reseller\Security\Capability::CUSTOMER_READ)
        ->where_raw('(tbl_customers.username LIKE ?)', [$like])
        ->order_by_asc('tbl_customers.username')
        ->limit(30)
        ->find_many();

    if ($results && count($results) > 0) {
        echo '<ul>';
        foreach ($results as $user) {
            echo '<li><a href="' . htmlspecialchars(getUrl('customers/view/' . (int) $user->id), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8') . '</a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>' . htmlspecialchars(Lang::T('No users found.'), ENT_QUOTES, 'UTF-8') . '</p>';
    }
} else {
    echo '<p>' . htmlspecialchars(Lang::T('Please enter a search term.'), ENT_QUOTES, 'UTF-8') . '</p>';
}
