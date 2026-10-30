<?php
// CLI ONLY: refused before anything else (this file must never run through a web server).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * ITC NetCore V1.0 fresh install: render config.php from install/fresh/templates/config.php.tmpl.
 *
 *   php render-config.php <template> <output>
 *
 * Values come from the environment (never from argv, never printed):
 *   NUXR_CFG_APP_URL, NUXR_CFG_DB_HOST, NUXR_CFG_DB_NAME, NUXR_CFG_DB_USER, NUXR_CFG_DB_PASS (required);
 *   NUXR_CFG_APP_SECRET, NUXR_CFG_API_SECRET (optional; generated here with random_bytes(32) when absent).
 * Every value is written as a PHP string literal (var_export), so no value can break out of its quotes.
 * The output is created with mode 0600 next to its final name and renamed into place; it must not exist yet.
 * Exit: 0 written; 3 refused (output exists); 5 usage / missing value.
 */
[$tpl, $out] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($tpl === '' || $out === '' || !is_file($tpl)) {
    fwrite(STDERR, "usage: php render-config.php <template> <output>\n");
    exit(5);
}
if (file_exists($out) || is_link($out)) {
    fwrite(STDERR, "refused: {$out} already exists\n");
    exit(3);
}
$v = [];
foreach (['APP_URL', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
    $x = getenv('NUXR_CFG_' . $k);
    if ($x === false || $x === '') {
        fwrite(STDERR, "missing value NUXR_CFG_{$k}\n");
        exit(5);
    }
    $v[$k] = $x;
}
foreach (['APP_SECRET', 'API_SECRET'] as $k) {
    $x = getenv('NUXR_CFG_' . $k);
    $v[$k] = ($x === false || $x === '') ? bin2hex(random_bytes(32)) : $x;
}
if ($v['APP_SECRET'] === $v['API_SECRET'] || in_array($v['DB_PASS'], [$v['APP_SECRET'], $v['API_SECRET']], true)) {
    fwrite(STDERR, "refused: the application key, the API secret and the database password must all differ\n");
    exit(5);
}
$s = (string) file_get_contents($tpl);
$s = str_replace('@@GENERATED_AT@@', gmdate('Y-m-d H:i:s'), $s);
foreach ($v as $k => $x) {
    $s = str_replace('@@' . $k . '@@', var_export($x, true), $s);
}
if (preg_match('/@@[A-Z_]+@@/', $s)) {
    fwrite(STDERR, "template placeholder left unresolved\n");
    exit(5);
}
$tmp = $out . '.tmp-' . bin2hex(random_bytes(4));
$old = umask(0077);
$ok = file_put_contents($tmp, $s, LOCK_EX) === strlen($s) && chmod($tmp, 0600) && rename($tmp, $out);
umask($old);
if (!$ok) {
    @unlink($tmp);
    fwrite(STDERR, "could not write {$out}\n");
    exit(1);
}
echo "config written: {$out} (values not shown)\n";
exit(0);
