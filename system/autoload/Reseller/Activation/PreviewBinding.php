<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP6A (owner decision OD-B): the fingerprint of the activation quote an operator confirmed, bound to a server key
 * (the staff session's preview key). It carries no money: the server re-derives the quote and only compares.
 * The fingerprint is an HMAC, so it reveals nothing about values the actor may not see (Sales and wholesale).
 */
final class PreviewBinding
{
    private function __construct(private readonly string $fingerprint, private readonly string $key)
    {
    }

    /** A binding from request input; a malformed or missing fingerprint never matches (fail closed). */
    public static function of(mixed $fingerprint, string $key): self
    {
        return new self(is_string($fingerprint) && preg_match('/^[0-9a-f]{64}$/D', $fingerprint) ? $fingerprint : '', $key);
    }

    public static function fingerprint(string $material, string $key): string
    {
        return hash_hmac('sha256', $material, $key);
    }

    public function matches(string $material): bool
    {
        return $this->fingerprint !== '' && strlen($this->key) >= 32 && hash_equals(self::fingerprint($material, $this->key), $this->fingerprint);
    }
}
