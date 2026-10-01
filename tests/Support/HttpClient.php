<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Minimal HTTP client for the disposable app server: its own cookie jar (one browser session per
 * client), no automatic redirects, raw form posts. Every request is a real HTTP request — UI hiding
 * never counts as a control.
 */
final class HttpClient
{
    /** @var array<string,string> */
    public array $cookies = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function get(string $route, array $query = [], array $headers = []): HttpResponse
    {
        return $this->request('GET', $this->url($route, $query), null, $headers);
    }

    public function post(string $route, array $data = [], array $query = [], array $headers = []): HttpResponse
    {
        return $this->request('POST', $this->url($route, $query), $data, $headers);
    }

    /** POST with the session's CSRF token (taken from a rendered page of this session). */
    public function postWithToken(string $route, array $data = [], array $query = [], string $tokenPage = 'dashboard'): HttpResponse
    {
        $data['csrf_token'] ??= $this->token($tokenPage);
        return $this->post($route, $data, $query);
    }

    /** multipart/form-data POST with file uploads ($files: field => local path). */
    public function postMultipart(string $route, array $fields, array $files, array $query = []): HttpResponse
    {
        $data = $fields;
        foreach ($files as $field => $path) {
            $data[$field] = new \CURLFile($path, 'application/json', basename($path));
        }
        return $this->request('POST', $this->url($route, $query), $data, [], true, true);
    }

    public function token(string $route = 'dashboard', array $query = []): string
    {
        $r = $this->get($route, $query);
        $t = self::extractToken($r->body);
        if ($t === null) {
            throw new \RuntimeException('no CSRF token on ' . $route . ': ' . $r->excerpt());
        }
        return $t;
    }

    public static function extractToken(string $html): ?string
    {
        if (preg_match('/window\.nuxrCsrfToken\s*=\s*"([0-9a-f]{64})"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/name=["\']csrf_token["\'][^>]*value=["\']([0-9a-f]{64})["\']/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Staff login through the real form (token from the rendered login page). */
    public function loginStaff(string $username, string $password): HttpResponse
    {
        $token = $this->token('admin');
        return $this->post('admin/post', ['username' => $username, 'password' => $password, 'csrf_token' => $token]);
    }

    public function loginCustomer(string $username, string $password): HttpResponse
    {
        $token = $this->token('login');
        return $this->post('login/post', ['username' => $username, 'password' => $password, 'csrf_token' => $token]);
    }

    /** API request: never sends this client's cookies (the API must ignore them anyway). */
    public function api(string $method, string $r, array $data = [], ?string $token = null, array $query = [], bool $bearer = true, bool $withCookies = false): HttpResponse
    {
        $q = ['r' => $r] + $query;
        $headers = [];
        if ($token !== null) {
            if ($bearer) {
                $headers[] = 'Authorization: Bearer ' . $token;
            } else {
                $q['token'] = $token;
            }
        }
        $url = rtrim($this->baseUrl, '/') . '/system/api.php?' . http_build_query($q);
        return $this->request($method, $url, $method === 'GET' ? null : $data, $headers, $withCookies);
    }

    public function url(string $route, array $query = []): string
    {
        return rtrim($this->baseUrl, '/') . '/?' . http_build_query(['_route' => $route] + $query);
    }

    public function raw(string $method, string $path, ?array $data = null, array $headers = []): HttpResponse
    {
        return $this->request($method, rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'), $data, $headers);
    }

    private function request(string $method, string $url, ?array $data, array $headers, bool $withCookies = true, bool $multipart = false): HttpResponse
    {
        $ch = curl_init($url);
        $respHeaders = [];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))][] = trim($v);
                }
                return strlen($line);
            },
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        if ($data !== null && $multipart) {
            $opts[CURLOPT_POSTFIELDS] = $data;
        } elseif ($data !== null) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($withCookies && $this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($withCookies) {
            foreach ($respHeaders['set-cookie'] ?? [] as $c) {
                $first = explode(';', $c, 2)[0];
                if (!str_contains($first, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $first, 2);
                $k = trim($k);
                if ($v === '' || $v === 'deleted' || preg_match('/expires=[^;]*19[0-9]{2}|max-age=0/i', $c) || preg_match('/expires=([^;]+)/i', $c, $m) && strtotime($m[1]) !== false && strtotime($m[1]) < time()) {
                    unset($this->cookies[$k]);
                } else {
                    $this->cookies[$k] = $v;
                }
            }
        }
        return new HttpResponse($status, $respHeaders, (string) $body);
    }
}
