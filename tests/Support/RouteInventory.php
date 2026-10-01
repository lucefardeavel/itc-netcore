<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Static inventory of controller actions (token_get_all): the case labels of each controller's
 * top-level dispatch switch on $action / $do / $routes[1]. Used by the RouteMap completeness test so
 * that a new or renamed legacy action cannot be reachable without an explicit authorization entry.
 */
final class RouteInventory
{
    private const DISPATCH_VARS = ['$action', '$do'];

    /** @return array<string,list<string>> controller => sorted action labels */
    public static function scan(string $controllersDir): array
    {
        $out = [];
        foreach (glob(rtrim($controllersDir, '/') . '/*.php') as $file) {
            $out[basename($file, '.php')] = self::actions((string) file_get_contents($file));
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> */
    public static function actions(string $code): array
    {
        $tokens = array_values(array_filter(token_get_all($code), static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $labels = [];
        $depth = 0;
        $dispatchDepths = [];
        $pendingDispatch = false;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_SWITCH) {
                // switch ( <var> ) or switch ( $routes['1'] ) / ( $routes[1] )
                $expr = '';
                $j = $i + 2;
                $paren = 1;
                while ($j < $n && $paren > 0) {
                    $tk = $tokens[$j];
                    $s = is_array($tk) ? $tk[1] : $tk;
                    if ($s === '(') { $paren++; }
                    if ($s === ')') { $paren--; if ($paren === 0) { break; } }
                    $expr .= $s;
                    $j++;
                }
                $expr = str_replace(['"', "'"], '', $expr);
                if (in_array($expr, self::DISPATCH_VARS, true) || $expr === '$routes[1]') {
                    $pendingDispatch = true;
                }
                continue;
            }
            if (!is_array($t) && ($t === '{')) {
                $depth++;
                if ($pendingDispatch) {
                    $dispatchDepths[] = $depth;
                    $pendingDispatch = false;
                }
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
                continue;
            }
            if (!is_array($t) && $t === '}') {
                if ($dispatchDepths !== [] && end($dispatchDepths) === $depth) {
                    array_pop($dispatchDepths);
                }
                $depth--;
                continue;
            }
            if (is_array($t) && $t[0] === T_CASE && $dispatchDepths !== [] && end($dispatchDepths) === $depth) {
                $next = $tokens[$i + 1] ?? null;
                if (is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $labels[] = trim($next[1], "'\"");
                }
            }
            // if/else dispatch: $action == 'x' / 'x' == $action / $routes['1'] == 'x'
            if (is_array($t) && $t[0] === T_VARIABLE && in_array($t[1], self::DISPATCH_VARS, true)) {
                $op = $tokens[$i + 1] ?? null;
                $val = $tokens[$i + 2] ?? null;
                if (is_array($op) && in_array($op[0], [T_IS_EQUAL, T_IS_IDENTICAL], true) && is_array($val) && $val[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $labels[] = trim($val[1], "'\"");
                }
            }
        }
        $labels = array_values(array_unique($labels));
        sort($labels);
        return $labels;
    }
}
