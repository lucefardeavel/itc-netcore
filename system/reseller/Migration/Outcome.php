<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Exit codes of the project migration runner (documented in system/reseller/README.md). */
final class Outcome
{
    public const OK = 0;
    public const NOT_READY = 2;
    public const FAILED = 3;
    public const DRIFT = 4;
    public const USAGE = 5;

    public static function name(int $code): string
    {
        return match ($code) {
            self::OK => 'OK',
            self::NOT_READY => 'NOT_READY',
            self::FAILED => 'FAILED',
            self::DRIFT => 'DRIFT',
            self::USAGE => 'USAGE',
            default => 'UNKNOWN',
        };
    }
}

/** Raised to stop a command with a specific outcome. Never caught-and-ignored. */
final class StopException extends \RuntimeException
{
    /** @param list<string> $details */
    public function __construct(public readonly int $outcome, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
