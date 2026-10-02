<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Structured outcome of every RADIUS / NAS mutation (WP4; 02_ARCHITECTURE "All network mutations return a
 * structured result"; D017). The status values map 1:1 onto the R0003 activation-event column
 * `provisioning_result` ENUM('SUCCESS','FAILED','UNKNOWN').
 *
 *  - SUCCESS: the change is applied and verified.
 *  - FAILED:  the change was NOT applied (nothing written, or rolled back); retrying is safe when retryable.
 *  - UNKNOWN: the outcome cannot be known (lost commit acknowledgement, no NAS answer). It is never a clean
 *             failure and is never retryable here: the caller (WP5A) must reconcile, not blindly retry.
 *
 * The message is a fixed, sanitized text: no SQL, secrets, paths or exception text.
 */
final class ProvisionResult
{
    public const SUCCESS = 'SUCCESS';
    public const FAILED = 'FAILED';
    public const UNKNOWN = 'UNKNOWN';

    /** @param array<string,scalar|null> $metadata */
    private function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly string $message,
        public readonly bool $retryable,
        public readonly ?string $nasId,
        public readonly array $metadata,
    ) {
    }

    /** @param array<string,scalar|null> $metadata */
    public static function success(string $code = 'OK', string $message = 'Applied.', ?string $nasId = null, array $metadata = []): self
    {
        return new self(self::SUCCESS, $code, $message, false, $nasId, $metadata);
    }

    /** @param array<string,scalar|null> $metadata */
    public static function failed(string $code, string $message, bool $retryable, ?string $nasId = null, array $metadata = []): self
    {
        return new self(self::FAILED, $code, $message, $retryable, $nasId, $metadata);
    }

    /** UNKNOWN is never retryable: a blind retry of an uncertain network mutation is forbidden. */
    public static function unknown(string $code, string $message, ?string $nasId = null, array $metadata = []): self
    {
        return new self(self::UNKNOWN, $code, $message, false, $nasId, $metadata);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    /** @return array{status:string,code:string,message:string,retryable:bool,nas_id:?string,metadata:array<string,scalar|null>} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'code' => $this->code, 'message' => $this->message, 'retryable' => $this->retryable,
            'nas_id' => $this->nasId, 'metadata' => $this->metadata];
    }
}
