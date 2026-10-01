<?php
/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

// WP2 / OD-9 / SEC-02: RouteGuard has already verified that $routes[1] is a REGISTERED plugin menu
// function the current principal is authorized for (PluginRegistry); function_exists() is not enough.
if (isset($routes[1]) && \Reseller\Security\PluginRegistry::isAllowed(\Reseller\Security\ActorContext::current(), (string) $routes[1]) && function_exists($routes[1])) {
    call_user_func($routes[1]);
} else {
    \Reseller\Security\RouteGuard::deny(404, 'plugin function not found');
}
