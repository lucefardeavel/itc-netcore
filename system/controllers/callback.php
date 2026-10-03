<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/


$action = $routes['1'];


// WP5B §7 (SEC-13): only a configured gateway whose file resolves inside the gateway directory; the
// notification's authenticity is the gateway plugin's contract
$pgFile = \Reseller\Domain\PaymentGatewayResolver::resolve($action);
if ($pgFile !== null) {
    include $pgFile;
    if (function_exists($action . '_payment_notification')) {
        run_hook('callback_payment_notification'); #HOOK
        call_user_func($action . '_payment_notification');
        die();
    }
}

header('HTTP/1.1 404 Not Found');
echo 'Not Found';
