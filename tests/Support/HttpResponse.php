<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class HttpResponse
{
    /** @param array<string,list<string>> $headers lower-cased names */
    public function __construct(public readonly int $status, public readonly array $headers, public readonly string $body)
    {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function location(): ?string
    {
        return $this->header('location');
    }

    /** @return array<string,mixed>|null */
    public function json(): ?array
    {
        $j = json_decode($this->body, true);
        return is_array($j) ? $j : null;
    }

    /** Markers of leaked internals (the legacy crash page rendered these with HTTP 200). */
    public function leaksInternals(): bool
    {
        return (bool) preg_match('/SQLSTATE|Stack trace|#0 \/|\.php:\d+|\/home\/|\/repo\/|\/tmp\/wp|PDOException|TypeError:|Fatal error/i', $this->body);
    }

    public function excerpt(int $n = 400): string
    {
        return $this->status . ' ' . ($this->location() ?? '') . ' ' . substr(preg_replace('/\s+/', ' ', strip_tags($this->body)) ?? '', 0, $n);
    }
}
