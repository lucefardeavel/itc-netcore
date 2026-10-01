<?php
namespace Reseller\Audit;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\Tx;
use Reseller\Security\ActorContext;
use Reseller\Security\ErrorReporter;

/**
 * Append-only writer for tbl_reseller_audit_events (R0006, owner decision OD-1).
 * There is deliberately no update/delete method. Every payload passes the redactor: audit records
 * never contain passwords, secrets, tokens, OTPs or credential hashes.
 */
final class AuditLog
{
    public const MAX_PAYLOAD_BYTES = 65536;
    private const SECRET_KEY = '/pass|secret|token|key|otp|hash|signature|cookie|salt/i';

    /**
     * @param array{target_type:string,target_user_id?:int|null,target_customer_id?:int|null,target_snapshot?:array|null,
     *              before?:array|null,after?:array|null,reason?:string|null} $event
     */
    public static function record(string $eventType, ActorContext $actor, array $event, ?\PDO $pdo = null): void
    {
        $pdo ??= \ORM::get_db();
        $actorSnapshot = self::encode(self::redact($actor->snapshot()));
        $row = [
            'event_type' => substr($eventType, 0, 64),
            'actor_type' => $actor->auditActorType(),
            'actor_user_id' => $actor->isStaff() ? $actor->userId : null,
            'actor_customer_id' => $actor->isCustomer() ? $actor->customerId : null,
            'actor_snapshot' => $actorSnapshot ?? '{}',
            'target_type' => substr($event['target_type'], 0, 32),
            'target_user_id' => $event['target_user_id'] ?? null,
            'target_customer_id' => $event['target_customer_id'] ?? null,
            'target_snapshot' => isset($event['target_snapshot']) ? self::encode(self::redact($event['target_snapshot'])) : null,
            'before_json' => isset($event['before']) ? self::encode(self::redact($event['before'])) : null,
            'after_json' => isset($event['after']) ? self::encode(self::redact($event['after'])) : null,
            'reason' => isset($event['reason']) ? mb_substr(self::scrubText((string) $event['reason']), 0, 255) : null,
            'request_id' => ErrorReporter::requestId(),
            'channel' => $actor->channel,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
            'created_at' => Tx::utcNow(),
        ];
        $cols = array_keys($row);
        $sql = 'INSERT INTO tbl_reseller_audit_events (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $st = $pdo->prepare($sql);
        $st->execute(array_values($row));
    }

    /** Recursively removes secret-like keys; scrubs string values that look like secrets. */
    public static function redact(mixed $payload): mixed
    {
        if (is_array($payload)) {
            $out = [];
            foreach ($payload as $k => $v) {
                if (is_string($k) && preg_match(self::SECRET_KEY, $k)) {
                    continue;
                }
                $out[$k] = self::redact($v);
            }
            return $out;
        }
        if (is_string($payload)) {
            return self::scrubText($payload);
        }
        if (is_scalar($payload) || $payload === null) {
            return $payload;
        }
        return null;
    }

    private static function scrubText(string $s): string
    {
        return ErrorReporter::sanitize($s);
    }

    private static function encode(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new \LengthException('audit payload too large or not encodable');
        }
        return $json;
    }
}
