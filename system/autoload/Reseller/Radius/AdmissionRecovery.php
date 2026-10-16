<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME) or the operator CLI; never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * WP9-OPS / W8-4 / AV-1: safety-gated operator recovery of an unresolved PPPoE admission claim (nuxr_admission PENDING:
 * logins of that subscriber are refused until it ends). The SQL procedure install/freeradius/nuxr_admission_release.sql
 * stays the manual equivalent; this adds the gates an operator should not have to remember:
 *  - the claim is still PENDING with exactly that token (a newer claim is never touched);
 *  - it is older than the establishment horizon (MIN_AGE_SECONDS): the Access-Accept of that attempt, if one was sent,
 *    can no longer turn into a session (RouterOS RADIUS timeout and PPP negotiation complete within seconds);
 *  - no accounting proof of a session exists for it (state would be ACTIVE) and no open radacct row of its session
 *    name exists (conservative);
 *  - the operator states a reason and the NAS-side evidence (README "Operator recovery"); the audit row records the
 *    database-attested account and connection.
 * It never deletes a row and never releases an ACTIVE claim (the A1 verification ends those when proven gone).
 */
final class AdmissionRecovery
{
    public const MIN_AGE_SECONDS = 600;

    private static function pdo(): \PDO
    {
        try {
            $pdo = \ORM::get_db('radius');
        } catch (\Throwable) {
            $pdo = \ORM::get_db();
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** @return list<array{key_hex:string,token:string,nas:string,age_seconds:int,attach_count:int,releasable:bool}> */
    public static function pending(): array
    {
        $out = [];
        $rows = self::pdo()->query("SELECT HEX(username) AS k, token, nas, attach_count, TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP(6)) AS age, session_user, created_at
            FROM nuxr_admission WHERE state = 'PENDING' ORDER BY created_at")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out[] = ['key_hex' => (string) $r['k'], 'token' => (string) $r['token'], 'nas' => (string) $r['nas'], 'age_seconds' => (int) $r['age'],
                'attach_count' => (int) $r['attach_count'], 'releasable' => (int) $r['age'] >= self::MIN_AGE_SECONDS && !self::sessionSince((string) $r['session_user'], (string) $r['created_at'])];
        }
        return $out;
    }

    /** @return array{outcome:string} RELEASED (the claim ended) */
    public static function release(string $keyHex, string $token, string $operator, string $reason, string $evidence): array
    {
        if (!preg_match('/^[0-9A-Fa-f]{2,128}$/', $keyHex) || !preg_match('/^[0-9a-f]{32}$/', $token)
            || strlen(trim($operator)) < 2 || strlen(trim($reason)) < 10 || strlen(trim($evidence)) < 10) {
            throw new DomainError('INVALID_INPUT', 'key (hex), token (32 hex), operator (>= 2), reason and NAS-side evidence (>= 10 characters) are required');
        }
        $pdo = self::pdo();
        $claim = $pdo->prepare("SELECT session_user, created_at, TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP(6)) AS age FROM nuxr_admission WHERE username = UNHEX(?) AND token = ? AND state = 'PENDING'");
        $claim->execute([strtoupper($keyHex), $token]);
        $c = $claim->fetch(\PDO::FETCH_ASSOC);
        $refuse = null;
        if ($c === false) {
            $refuse = 'not a PENDING claim with that token';
        } elseif ((int) $c['age'] < self::MIN_AGE_SECONDS) {
            $refuse = 'younger than ' . self::MIN_AGE_SECONDS . ' s (the attempt may still complete)';
        } elseif (self::sessionSince((string) $c['session_user'], (string) $c['created_at'])) {
            $refuse = 'an open accounting session started after the claim';
        }
        $pdo->beginTransaction();
        try {
            $released = 0;
            if ($refuse === null) {
                $up = $pdo->prepare("UPDATE nuxr_admission SET state = 'ENDED', ended_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), end_reason = 'operator_recovery' WHERE username = UNHEX(?) AND token = ? AND state = 'PENDING'");
                $up->execute([strtoupper($keyHex), $token]);
                $released = $up->rowCount();
            }
            $au = $pdo->prepare("INSERT INTO nuxr_admission_audit (at, action, outcome, username, token, operator, reason, evidence)
                VALUES (UTC_TIMESTAMP(6), 'release_pending', ?, UNHEX(?), ?, ?, ?, LEFT(CONCAT('[db_account=', CONVERT(CURRENT_USER() USING utf8mb4), ' connection=', CONNECTION_ID(), ' tool=reconcile.php] ', ?), 1024))");
            $au->execute([$released === 1 ? 'RELEASED' : 'REFUSED', strtoupper($keyHex), $token, mb_substr(trim($operator), 0, 64),
                mb_substr(trim($reason) . ($refuse !== null ? ' [refused: ' . $refuse . ']' : ''), 0, 255), trim($evidence)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($released !== 1) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT', 'Not released: ' . ($refuse ?? 'the claim changed meanwhile') . '. The refusal is audited.');
        }
        return ['outcome' => 'RELEASED'];
    }

    /**
     * Any open radacct row of this session name (conservative: a possibly live session of the subscriber, whatever its
     * start time zone - P-A4 - refuses the release; the operator then follows the A1 verification instead).
     */
    private static function sessionSince(string $sessionUser, string $createdAtUtc): bool
    {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM radacct WHERE username = ? AND acctstoptime IS NULL');
        $st->execute([$sessionUser]);
        return (int) $st->fetchColumn() > 0;
    }
}
