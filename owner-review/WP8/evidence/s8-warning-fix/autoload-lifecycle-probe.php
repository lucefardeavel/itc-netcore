<?php
// S8 D-S8-3 lifecycle probe (read-only repo; isolated container): old drill-8 pattern vs the scoped PEAR2 loader
$mode = $argv[1];
$root = '/repo';
$names = static fn(): array => array_map(static fn($f) => is_string($f) ? $f : (is_array($f) ? (is_object($f[0]) ? get_class($f[0]) : $f[0]) . '::' . $f[1] : 'Closure'), spl_autoload_functions());
// a test-process-like autoloader already present (as tests/bootstrap.php registers one)
spl_autoload_register(static function (string $c): void {});
$before = spl_autoload_functions();
echo "mode=$mode\nstack_before=" . json_encode($names()) . "\n";
$prev = error_reporting(E_ALL & ~E_DEPRECATED);
if ($mode === 'old') {
    require_once $root . '/system/autoload/PEAR2/Autoload.php';
    $loaded = class_exists('PEAR2\Net\RouterOS\Client');
    error_reporting($prev);
} else {
    $pear2 = static function (string $class) use ($root): void {
        if (str_starts_with($class, 'PEAR2\\')) {
            $file = $root . '/system/autoload/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    };
    try {
        spl_autoload_register($pear2);
        $loaded = class_exists('PEAR2\Net\RouterOS\Client') && class_exists('PEAR2\Net\RouterOS\SocketException');
    } finally {
        spl_autoload_unregister($pear2);
        error_reporting($prev);
    }
}
echo "pear2_client_loadable_during_drill=" . json_encode($loaded) . "\n";
echo "stack_after=" . json_encode($names()) . "\nstack_identical=" . json_encode($before === spl_autoload_functions()) . "\n";
echo "PEAR2_Autoload_class_loaded=" . json_encode(class_exists('PEAR2\Autoload', false)) . "\n";
if (class_exists('PEAR2\Autoload', false)) {
    $r = new ReflectionProperty('PEAR2\Autoload', 'registered'); $r->setAccessible(true);
    echo "PEAR2_Autoload_registered=" . json_encode($r->getValue()) . "\n";
}
echo "-- a later test touches the global Message class (as ErrorReporter::report does):\n";
var_dump(class_exists('Message', true));
