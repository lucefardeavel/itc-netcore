<?php
// CLI-only test bootstrap: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';
foreach (['Outcome', 'Db', 'SchemaInspector', 'Grants', 'MigrationDefinition', 'MigrationSet', 'VariantRegistry', 'Registry', 'Runner'] as $c) {
    require_once $root . "/system/reseller/Migration/{$c}.php";
}
// WP2/WP3A runtime classes (system/autoload/Reseller/{Security,Domain,Audit,Finance}) refuse to load without this marker
if (!defined('NUXR_RUNTIME')) {
    define('NUXR_RUNTIME', true);
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (preg_match('/^Reseller\\\\(Security|Domain|Audit|Finance)\\\\[A-Za-z0-9]+$/', $class)) {
        $file = $root . '/system/autoload/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
require_once __DIR__ . '/Support/FixtureBuilder.php';
require_once __DIR__ . '/Support/TestDatabase.php';
require_once __DIR__ . '/Support/RunnerProcess.php';
require_once __DIR__ . '/Support/IntegrationTestCase.php';
foreach (['RouteInventory', 'DbProbe', 'HttpResponse', 'HttpClient', 'AppServer', 'Wp2Seed', 'Wp2HttpTestCase', 'FinanceSeed', 'FinanceInvariants', 'FinanceTestCase', 'FinanceWorkers', 'PricingTestCase', 'SnapshotFactory'] as $c) {
    require_once __DIR__ . "/Support/{$c}.php";
}
