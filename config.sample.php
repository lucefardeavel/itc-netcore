<?php

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || 
             (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";

// Check if HTTP_HOST is set, otherwise use a default value or SERVER_NAME
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');

$baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('APP_URL', $protocol . $host . $baseDir);


$_app_stage = 'Live'; # Do not change this

$db_host    = "localhost"; # Database Host
$db_port    = "";   # Database Port. Keep it blank if you are un sure.
$db_user    = "root"; # Database Username
$db_pass    = ""; # Database Password
$db_name    = "phpnuxbill"; # Database Name

// Dedicated application key (WP9-D / NEW-7): signs staff and customer sessions, login links, public invoice
// links and OTP file names. At least 32 random characters, never the database password, the API secret or the
// FreeRADIUS secret. Without it staff login, remember cookies, login links, invoice links and OTP fail closed.
// Generate once per installation: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
$app_secret = "";
// Dedicated API signing secret (WP2 / OD-3): at least 32 characters; without it the API fails closed.
// $api_secret = "";

// FreeRADIUS rlm_rest endpoint (radius.php) - OFF unless a secret of at least 32 characters is set.
// See install/freeradius/README.md. The secret is the HTTP Basic password of the FreeRADIUS rest module.
// $radius_rest_secret = "";
// $radius_rest_allow  = "127.0.0.1, ::1"; # IPs / CIDRs of the FreeRADIUS servers calling radius.php




//error reporting
if($_app_stage!='Live'){
    error_reporting(E_ERROR);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}else{
    error_reporting(E_ERROR);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}
