<?php
namespace Reseller\Tests\Unit\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reseller\Domain\LegacyReprovisionService as R;

/**
 * WP8 A3 (R-WP8-CARRY-02): the old-identity session classification (pure) and the static guards over the runtime sources
 * (comments stripped): the rename captures before it renames and sends nothing inside the device when the re-provision
 * service defers; the service contacts the NAS only after the tree lock is released; the drift kind exists.
 */
final class RenameOldSessionUnitTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:bool,3:string}> */
    public static function sessions(): array
    {
        return [
            'ACK + Stop' => ['SUCCESS', 'COA_ACK', true, 'CONFIRMED'],
            'ACK, no Stop' => ['SUCCESS', 'COA_ACK', false, 'ack_without_stop'],
            'NAK 503' => ['SUCCESS', 'SESSION_NOT_FOUND', false, 'CONFIRMED'],
            'NAK 406 / other' => ['FAILED', 'COA_NAK', false, 'COA_NAK'],
            'no answer' => ['UNKNOWN', 'COA_NO_RESPONSE', false, 'COA_NO_RESPONSE'],
            'NAS unknown' => ['FAILED', 'NAS_UNKNOWN', false, 'NAS_UNKNOWN'],
            'unexpected reply' => ['UNKNOWN', 'COA_UNEXPECTED_REPLY', false, 'COA_UNEXPECTED_REPLY'],
            'exception' => ['UNKNOWN', 'COA_EXCEPTION', false, 'COA_EXCEPTION'],
            'a Stop never turns a NAK into proof' => ['FAILED', 'COA_NAK', true, 'COA_NAK'],
        ];
    }

    #[DataProvider('sessions')]
    public function testSessionState(string $status, string $code, bool $stop, string $expected): void
    {
        $this->assertSame($expected, R::sessionState($status, $code, $stop));
    }

    public function testIdentityStateAndWorstOf(): void
    {
        $this->assertSame(['NO_ACCOUNTING_EVIDENCE', null], R::identityState('TARGETED', []));
        $this->assertSame(['CONFIRMED', null], R::identityState('TARGETED', ['CONFIRMED', 'CONFIRMED']));
        $this->assertSame(['UNCONFIRMED', 'ack_without_stop'], R::identityState('TARGETED', ['CONFIRMED', 'ack_without_stop']));
        $this->assertSame(['UNCONFIRMED', 'ambiguous_identity'], R::identityState('AMBIGUOUS', []));
        $this->assertSame(['UNCONFIRMED', 'capture_failed'], R::identityState('CAPTURE_FAILED', []));
        $this->assertSame(['UNCONFIRMED', 'rename_not_confirmed'], R::identityState('RENAME_NOT_CONFIRMED', []));
        $this->assertSame('NOT_APPLICABLE', R::worstOldSession([]));
        $this->assertSame('CONFIRMED', R::worstOldSession(['CONFIRMED', 'CONFIRMED']));
        $this->assertSame('NO_ACCOUNTING_EVIDENCE', R::worstOldSession(['CONFIRMED', 'NO_ACCOUNTING_EVIDENCE']));
        $this->assertSame('UNCONFIRMED', R::worstOldSession(['NO_ACCOUNTING_EVIDENCE', 'UNCONFIRMED', 'CONFIRMED']));
    }

    // ------------------------------------------------------------------ source guards

    private static function code(string $rel): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    private static function method(string $code, string $name): string
    {
        $start = strpos($code, 'function ' . $name . '(');
        self::assertNotFalse($start, $name);
        $next = strpos($code, "\n    function ", $start + 10);
        $nextPublic = strpos($code, "\n    public ", $start + 10);
        $nextPrivate = strpos($code, "\n    private ", $start + 10);
        $ends = array_filter([$next, $nextPublic, $nextPrivate], static fn($p): bool => $p !== false);
        return substr($code, $start, ($ends === [] ? strlen($code) : min($ends)) - $start);
    }

    public function testTheRadiusRenameCapturesBeforeAndAfterAndDefersTheDisconnect(): void
    {
        $m = self::method(self::code('system/devices/Radius.php'), 'change_username');
        $before = strpos($m, 'self::oldIdentityBefore(');
        $rename = strpos($m, '->rename(');
        $after = strpos($m, 'self::oldIdentityAfter(');
        $defer = strpos($m, 'LegacyReprovisionService::deferOldSessions(');
        $now = strpos($m, 'LegacyReprovisionService::disconnectCaptured(');
        foreach (['before' => $before, 'rename' => $rename, 'after' => $after, 'defer' => $defer, 'legacy send' => $now] as $k => $p) {
            $this->assertNotFalse($p, $k);
        }
        $this->assertTrue($before < $rename && $rename < $after && $after < $defer && $defer < $now, 'snapshot -> rename -> capture -> defer, else send now (legacy)');
        $this->assertStringNotContainsString('disconnectCustomer(', $m, 'never by name: only the captured rows');
        $this->assertStringNotContainsString('throw', $m, 'a NAS problem never fails the rename step');
    }

    public function testTheServiceContactsTheNasOnlyAfterTheTreeLockIsReleased(): void
    {
        $run = self::method(self::code('system/autoload/Reseller/Domain/LegacyReprovisionService.php'), 'run');
        $hold = strpos($run, 'TreeSerialization::hold(');
        $confirm = strpos($run, 'self::confirmOldSessions(');
        $this->assertNotFalse($hold);
        $this->assertNotFalse($confirm);
        $this->assertGreaterThan($hold, $confirm);
        $closure = substr($run, $hold, $confirm - $hold);
        $this->assertStringNotContainsString('confirmOldSessions', $closure);
        $this->assertStringNotContainsString('disconnectCaptured', $closure, 'no Disconnect inside the tree lock');
        $confirmBody = self::method(self::code('system/autoload/Reseller/Domain/LegacyReprovisionService.php'), 'confirmOldSessions');
        $this->assertStringContainsString("'rename.old_session'", $confirmBody, 'its own event name, outside reprovision.% (the REPROVISION_UNCONFIRMED drift)');
        $this->assertStringNotContainsString('Tx::run(', $confirmBody);
        $this->assertStringNotContainsString('TreeSerialization::', $confirmBody);
    }

    public function testTheDisconnectTargetsACapturedRowAndTheDriftKindExists(): void
    {
        $coa = self::method(self::code('system/autoload/Reseller/Radius/CoAClient.php'), 'disconnectSession');
        $this->assertStringContainsString('$this->toSessionNas($session, RadiusPacket::DISCONNECT_REQUEST, [])', $coa);
        $this->assertStringNotContainsString('openSessions', $coa, 'never re-read by name');
        $drift = self::code('system/autoload/Reseller/Activation/DriftReport.php');
        $this->assertStringContainsString("'OLD_SESSION_UNCONFIRMED'", $drift);
        $this->assertStringContainsString("'CONFIRMATION_MISSING'", $drift);
    }

    // ------------------------------------------------------------------ WP8 A3 final remediation (owner D-1 / D-3)

    /** F6 (FIX-5, plan §4.4): UNCONFIRMED and NO_ACCOUNTING_EVIDENCE are warnings, never a success notice */
    public function testOldSessionNoticeType(): void
    {
        $this->assertSame(['w', 'w', 's', 's', 's'], array_map([R::class, 'oldSessionNoticeType'], ['UNCONFIRMED', 'NO_ACCOUNTING_EVIDENCE', 'CONFIRMED', 'NOT_APPLICABLE', null]));
        $controller = self::code('system/controllers/customers.php');
        $this->assertStringContainsString('LegacyReprovisionService::oldSessionNoticeType($nuxrOldSession)', $controller);
        $this->assertStringNotContainsString("\$nuxrOldSession === 'UNCONFIRMED' ? 'e' : 's'", $controller);
    }

    /** F4 (FIX-3): a pair that keeps the key is not a rename; real renames keep their order */
    public function testRenamePairsDropPairsThatKeepTheKey(): void
    {
        $id = static fn(string $u, string $p): array => ['username' => $u, 'pppoe_username' => $p];
        $this->assertSame([], R::renamePairs($id('a', 'a'), $id('a', ''), 'PPPOE'), 'cleared: [a -> a]');
        $this->assertSame([], R::renamePairs($id('a', ''), $id('a', 'a'), 'PPPOE'), 'set equal to the username: [a -> a]');
        $this->assertSame([['a', 'b'], ['b', 'p']], R::renamePairs($id('a', ''), $id('b', 'p'), 'PPPOE'));
        $this->assertSame([['a', 'b']], R::renamePairs($id('a', 'p'), $id('b', 'q'), 'Hotspot'));
    }

    /** D-3: the existing-target check runs in the rename's own write transaction, after the locking read */
    public function testTheTargetCheckIsInsideTheRenameTransaction(): void
    {
        $rename = self::method(self::code('system/autoload/Reseller/Radius/RadiusRepository.php'), 'renameUser');
        $tx = strpos($rename, '$this->tx(');
        $lock = strpos($rename, '$this->equivalentKeys($from, $to, true)');
        $taken = strpos($rename, '!$replaceTarget && $this->exactRows($to) > 0');
        $delete = strpos($rename, 'DELETE FROM');
        foreach ([$tx, $lock, $taken, $delete] as $at) {
            $this->assertNotFalse($at);
        }
        $this->assertTrue($tx < $lock && $lock < $taken && $taken < $delete, 'transaction -> locking read -> target check -> write');
        // R-3: whatever the isolation level, a target row deleted for a caller that does not own it aborts the rename
        $this->assertStringContainsString('!$replaceTarget && $deleted > 0', $rename);
        $device = self::method(self::code('system/devices/Radius.php'), 'change_username');
        $this->assertStringContainsString('LegacyReprovisionService::targetReplaceable((string) $to)', $device);
        $this->assertStringContainsString('->rename((string) $from, (string) $to, $replace)', $device);
        // both paths open the customer's rename scope and close it on every path
        $devices = self::method(self::code('system/autoload/Reseller/Domain/LegacyReprovisionService.php'), 'devices');
        $this->assertStringContainsString('self::renameScope($old);', $devices);
        $this->assertStringContainsString('self::renameScope(null);', substr($devices, (int) strpos($devices, 'finally')));
        $controller = self::code('system/controllers/customers.php');
        $open = strpos($controller, 'LegacyReprovisionService::renameScope($nuxrOld);');
        $close = strpos($controller, 'LegacyReprovisionService::renameScope(null);');
        $this->assertNotFalse($open);
        $this->assertNotFalse($close);
        $this->assertStringContainsString('finally', substr($controller, $open, $close - $open));
    }

    /** F1 (FIX-1): a refusal never supersedes an earlier re-provision outcome in the drift report */
    public function testTheDriftIgnoresRefusals(): void
    {
        $drift = self::code('system/autoload/Reseller/Activation/DriftReport.php');
        $this->assertStringContainsString("str_starts_with((string) (\$after['outcome'] ?? ''), 'REFUSED_')", $drift);
    }
}
