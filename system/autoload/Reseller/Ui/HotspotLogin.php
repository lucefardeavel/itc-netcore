<?php

namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * V1.0.1: the ITC hotspot sign-in. The router's login page (system/hotspot/login.html, downloaded from the settings)
 * sends the device here with the router's sign-in address and its CHAP values; after a card is activated, or a
 * subscriber gives an account, the page signs the device in on that router, so a card connects in one step.
 *
 * The router address is taken only when it is a private network address or a host the administrator listed
 * (setting hs_login_hosts): a link from elsewhere can never make the page send a card to another site.
 */
final class HotspotLogin
{
    private const KEY = 'nuxr-hs';
    private const PENDING = 'nuxr-hs-pending';
    private const MAX_FIELD = 512;

    /** Takes the router context from the query string the router's login page builds (no context: nothing changes). */
    public static function capture(array $get, string $allowedHosts): void
    {
        if (!isset($get['nux-hs']) || !is_string($get['nux-hs']) || $get['nux-hs'] === '') {
            return;
        }
        $login = $get['nux-hs'];
        if (!self::allowedLoginUrl($login, self::hostList($allowedHosts))) {
            unset($_SESSION[self::KEY], $_SESSION[self::PENDING]);
            return;
        }
        $_SESSION[self::KEY] = [
            'login' => $login,
            'dst' => self::safeDst(is_string($get['nux-dst'] ?? null) ? $get['nux-dst'] : ''),
            'chap_id' => self::hexValue($get['nux-chapid'] ?? ''),
            'chap_challenge' => self::hexValue($get['nux-chapch'] ?? ''),
            'error' => !empty($get['nux-err']),
        ];
    }

    public static function active(): bool
    {
        return isset($_SESSION[self::KEY]['login']);
    }

    /** True once after the router refused a sign-in (the router's own message is not shown: it is English text). */
    public static function takeRouterError(): bool
    {
        if (empty($_SESSION[self::KEY]['error'])) {
            return false;
        }
        $_SESSION[self::KEY]['error'] = false;
        return true;
    }

    /** Keeps the account to sign in with until the connect page sends it to the router. */
    public static function hold(string $username, string $password): void
    {
        $_SESSION[self::PENDING] = ['username' => $username, 'password' => $password];
    }

    /** After a card is activated from the hotspot page: continue to the router sign-in instead of the portal. */
    public static function continueAfterActivation(string $code): void
    {
        if (!self::active()) {
            return;
        }
        self::hold($code, \Reseller\Domain\VoucherLogin::networkPassword($code));
        r2(getUrl('login/hotspot'));
    }

    /** The sign-in form for the router, once (the held account is cleared); null without a router context or account. */
    public static function takeForm(): ?array
    {
        $pending = $_SESSION[self::PENDING] ?? null;
        unset($_SESSION[self::PENDING]);
        if (!self::active() || !is_array($pending)) {
            return null;
        }
        return self::formFor($_SESSION[self::KEY], (string) $pending['username'], (string) $pending['password']);
    }

    /**
     * The fields a MikroTik hotspot accepts on its login address: with CHAP values the password is
     * md5(chap-id . password . chap-challenge) (the router's own login page does the same) and the request is a GET,
     * so no plain password leaves the page; without them the password is posted.
     *
     * @param array{login:string,dst:string,chap_id:string,chap_challenge:string} $ctx
     * @return array{action:string,method:string,fields:array<string,string>}
     */
    public static function formFor(array $ctx, string $username, string $password): array
    {
        $chap = $ctx['chap_id'] !== '' && $ctx['chap_challenge'] !== '';
        $fields = [
            'username' => $username,
            'password' => $chap ? md5(hex2bin($ctx['chap_id']) . $password . hex2bin($ctx['chap_challenge'])) : $password,
        ];
        if ($ctx['dst'] !== '') {
            $fields['dst'] = $ctx['dst'];
        }
        $fields['popup'] = 'true';
        return ['action' => $ctx['login'], 'method' => $chap ? 'get' : 'post', 'fields' => $fields];
    }

    /** http(s), no credentials in the address, and a private / CGNAT address or a listed host. */
    public static function allowedLoginUrl(string $url, array $hosts): bool
    {
        if (strlen($url) > self::MAX_FIELD || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])
            || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $host = strtolower(trim($p['host'], '[]'));
        if (in_array($host, $hosts, true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $n = ip2long($host);
        return ($n & 0xFF000000) === 0x0A000000      // 10.0.0.0/8
            || ($n & 0xFFF00000) === 0xAC100000      // 172.16.0.0/12
            || ($n & 0xFFFF0000) === 0xC0A80000      // 192.168.0.0/16
            || ($n & 0xFFC00000) === 0x64400000;     // 100.64.0.0/10 (CGNAT)
    }

    /** @return list<string> the hosts of the hs_login_hosts setting (comma or space separated) */
    public static function hostList(string $setting): array
    {
        return array_values(array_filter(array_map('strtolower', preg_split('/[\s,]+/', trim($setting)) ?: [])));
    }

    private static function safeDst(string $dst): string
    {
        return strlen($dst) <= self::MAX_FIELD && preg_match('#^https?://[^\s\x00-\x1f\x7f]+$#i', $dst) ? $dst : '';
    }

    private static function hexValue($v): string
    {
        return is_string($v) && strlen($v) <= 64 && strlen($v) % 2 === 0 && ctype_xdigit($v) ? strtolower($v) : '';
    }
}
