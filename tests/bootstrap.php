<?php
// CLI-only test bootstrap: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';
foreach (['Outcome', 'Db', 'SchemaInspector', 'Grants', 'MigrationDefinition', 'MigrationSet', 'VariantRegistry', 'Registry', 'Runner'] as $c) {
    require_once $root . "/system/reseller/Migration/{$c}.php";
}
require_once __DIR__ . '/Support/FixtureBuilder.php';
require_once __DIR__ . '/Support/TestDatabase.php';
require_once __DIR__ . '/Support/RunnerProcess.php';
require_once __DIR__ . '/Support/IntegrationTestCase.php';
