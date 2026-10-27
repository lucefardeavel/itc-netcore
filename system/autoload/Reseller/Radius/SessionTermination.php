<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME) or the operator CLI; never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ErrorReporter;

/**
 * Ends running sessions and CONFIRMS that they ended (V1: Option B renewal, the data-quota enforcer). A Disconnect-ACK
 * alone is not proof: a session is CONFIRMED only when its Accounting-Stop is recorded after the ACK (bounded wait), or
 * when the NAS answers NAK 503 "session not found" (the WP8 A3 classification). A NAK to a retransmission (COA_RETRY_NAK,
 * lab NET-3: the first request may have ended the session, its ACK lost) waits for the Stop like an ACK. Anything else - ACK without Stop,
 * other NAK, no answer, unknown NAS, no NAS address - is reported as the code that was observed, i.e. not confirmed.
 * One Disconnect-Request per accounting row, to that row's NAS (CoAClient: 1 + 2 identical sends); a NAS that stays silent
 * is not asked again for the other rows of the same call (COA_NAS_SKIPPED). Never throws.
 * Accounting rows are only read.
 */
final class SessionTermination
{
    /** total wait for the Accounting-Stop of the ACKed sessions (seconds) */
    public const STOP_BOUND = 6.0;

    /**
     * @param list<array<string,mixed>> $rows open radacct rows (radacctid, username, acctsessionid, nasipaddress, ...)
     * @return list<array{radacctid:int,acct_session_id:string,nas:string,result:string,state:string,confirmed:bool}>
     */
    public static function end(array $rows, ?CoAClient $coa = null, ?AccountingService $acct = null, float $bound = self::STOP_BOUND): array
    {
        if ($rows === []) {
            return [];
        }
        $sent = [];
        try {
            $coa ??= CoAClient::connection();
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'session-termination');
            $coa = null;
        }
        $silent = [];   // NAS address => it did not answer a Disconnect in this call
        foreach ($rows as $row) {
            $nas = (string) ($row['nasipaddress'] ?? '');
            if ($nas !== '' && isset($silent[$nas])) {
                // N1: each unanswered Disconnect costs the CoA client ~4 s (1 + 2 identical sends); asking a NAS that just stayed
                // silent for every further session would stretch a pass to minutes during an outage. Not sent, not claimed ended.
                $sent[] = [$row, ProvisionResult::unknown('COA_NAS_SKIPPED', 'The NAS did not answer an earlier Disconnect in this pass; not asked again.')];
                continue;
            }
            try {
                $r = $coa !== null ? $coa->disconnectSession($row) : ProvisionResult::unknown('COA_EXCEPTION', 'The Disconnect could not be sent.');
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'session-termination');
                $r = ProvisionResult::unknown('COA_EXCEPTION', 'The Disconnect could not be sent.');
            }
            if ($nas !== '' && $r->code === 'COA_NO_RESPONSE') {
                $silent[$nas] = true;
            }
            $sent[] = [$row, $r];
        }
        RadiusFaults::hit('session.after_disconnect');   // test seam (no-op at runtime): the NAS's Accounting-Stop may arrive here
        // the Accounting-Stop of the ACKed sessions, polled by radacctid, bounded in total
        $pending = [];
        foreach ($sent as [$row, $r]) {
            if (($r->isSuccess() && $r->code === 'COA_ACK') || ($r->status === ProvisionResult::UNKNOWN && $r->code === 'COA_RETRY_NAK')) {
                $pending[(int) $row['radacctid']] = (string) $row['username'];
            }
        }
        $deadline = microtime(true) + $bound;
        while ($pending !== []) {
            try {
                $acct ??= AccountingService::connection();
                foreach (array_unique($pending) as $username) {
                    $open = array_map(static fn(array $o): int => (int) $o['radacctid'], $acct->openSessions($username));
                    foreach ($pending as $id => $u) {
                        if ($u === $username && !in_array($id, $open, true)) {
                            unset($pending[$id]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'session-termination');
                break;
            }
            if ($pending === [] || microtime(true) >= $deadline) {
                break;
            }
            usleep(250000);
        }
        $out = [];
        foreach ($sent as [$row, $r]) {
            $id = (int) $row['radacctid'];
            $state = self::state($r->status, $r->code, !isset($pending[$id]));
            $out[] = ['radacctid' => $id, 'acct_session_id' => (string) ($row['acctsessionid'] ?? ''), 'nas' => (string) ($row['nasipaddress'] ?? ''),
                'result' => $r->status . ':' . $r->code, 'state' => $state, 'confirmed' => $state === 'CONFIRMED'];
        }
        return $out;
    }

    /** CONFIRMED only on NAS-side evidence of the end; otherwise the observed reason */
    public static function state(string $status, string $code, bool $stopSeen): string
    {
        if ($status === ProvisionResult::SUCCESS && $code === 'SESSION_NOT_FOUND') {
            return 'CONFIRMED';
        }
        if ($status === ProvisionResult::SUCCESS && $code === 'COA_ACK') {
            return $stopSeen ? 'CONFIRMED' : 'ACK_WITHOUT_STOP';
        }
        if ($status === ProvisionResult::UNKNOWN && $code === 'COA_RETRY_NAK' && $stopSeen) {
            return 'CONFIRMED';
        }
        return $code !== '' ? $code : 'UNKNOWN';
    }
}
