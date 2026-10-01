<?php

/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *
 * WP2 / OD-2 / SEC-03: the legacy web updater is disabled completely. It downloaded and extracted
 * code from a request-supplied URL and ran SQL from updates.json with errors swallowed, which would
 * overwrite this fork's reviewed code and bypass the strict migration runner. Updates are delivered
 * through the repository release process; schema changes through system/reseller/migrate.php.
 * No session, no download, no filesystem or database access happens here.
 **/
http_response_code(403);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><html><head><meta charset="utf-8"><title>403 Forbidden</title></head><body>'
    . '<h1>403 Forbidden</h1><p>The web updater is disabled. Updates are installed through the reviewed release process.</p>'
    . '</body></html>';
exit;
