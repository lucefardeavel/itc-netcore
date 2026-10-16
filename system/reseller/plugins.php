<?php
/**
 * WP9-B / N-02 (OD-W9-9 B2) plugin inventory: plugins are fully trusted, in-process PHP (there is no sandbox), so an
 * installation must know exactly which plugin files run. CLI only.
 *
 *   php system/reseller/plugins.php                      list every loaded plugin file with its SHA-256
 *   php system/reseller/plugins.php --expect=<file>      compare with an approved inventory ("<sha256>  <name>" lines)
 *   --dir=<path>                                         plugin directory (default: <repo>/system/plugin)
 *
 * Exit codes: 0 inventory printed / matches; 4 DRIFT (unknown, missing or modified plugin); 5 usage error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(dir|expect)=(.+)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "usage: plugins.php [--dir=<path>] [--expect=<inventory file>]\n");
        exit(5);
    }
}
$dir = $opts['dir'] ?? dirname(__DIR__) . '/plugin';
if (!is_dir($dir)) {
    fwrite(STDERR, "plugin directory not found: {$dir}\n");
    exit(5);
}
// exactly the files init.php includes (top-level *.php)
$actual = [];
foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $f) {
    $actual[basename($f)] = hash_file('sha256', $f);
}
ksort($actual);
if (!isset($opts['expect'])) {
    foreach ($actual as $name => $sha) {
        echo "{$sha}  {$name}\n";
    }
    exit(0);
}
if (!is_file($opts['expect'])) {
    fwrite(STDERR, "inventory file not found\n");
    exit(5);
}
$expected = [];
foreach (file($opts['expect'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^([0-9a-f]{64})\s+(\S.*)$/', trim($line), $m)) {
        $expected[$m[2]] = $m[1];
    }
}
$drift = [];
foreach ($actual as $name => $sha) {
    if (!isset($expected[$name])) {
        $drift[] = "UNKNOWN  {$name}";
    } elseif (!hash_equals($expected[$name], $sha)) {
        $drift[] = "MODIFIED {$name}";
    }
}
foreach ($expected as $name => $sha) {
    if (!isset($actual[$name])) {
        $drift[] = "MISSING  {$name}";
    }
}
if ($drift === []) {
    echo 'OK ' . count($actual) . " plugin file(s) match the approved inventory\n";
    exit(0);
}
echo "DRIFT\n" . implode("\n", $drift) . "\n";
exit(4);
