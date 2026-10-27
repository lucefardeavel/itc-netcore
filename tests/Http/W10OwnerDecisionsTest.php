<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab D-30 (owner delegation 2026-10-25: "decide what is appropriate"): the remediations of the open owner items that are
 * correctness / safety rules, not business policy.
 *  - UX-14: the customer portal language is Arabic or English only (the server refused unknown files, now also the others);
 *  - F-6 Q3: the legacy staff sync never re-provisions a customer that is not Active (as F6-3 for activations);
 *  - D-4: staff opening the portal as a customer is logged (tbl_logs) and audited (service audit table).
 */
final class W10OwnerDecisionsTest extends Wp2HttpTestCase
{
    public function testThePortalLanguageIsArabicOrEnglishOnly(): void
    {
        $c = $this->customer('cNULL');
        $cid = $this->cid('cNULL');
        $lang = fn(): ?string => static::$db->value("SELECT field_value FROM tbl_customers_fields WHERE customer_id = ? AND field_name = 'Language'", [$cid]);
        $c->postWithToken('accounts/language-update-post', ['lang' => 'arabic'], [], 'home');
        $this->assertSame('arabic', $lang(), 'Arabic is accepted');
        $c->postWithToken('accounts/language-update-post', ['lang' => 'spanish'], [], 'home');
        $this->assertSame('arabic', $lang(), 'a shipped catalogue other than Arabic / English is refused');
        $c->postWithToken('accounts/language-update-post', ['lang' => 'english'], [], 'home');
        $this->assertSame('english', $lang());
    }

    public function testTheLegacySyncRefusesACustomerThatIsNotActive(): void
    {
        $cid = $this->cid('cNULL');
        static::$db->exec("UPDATE tbl_customers SET status = 'Suspended' WHERE id = {$cid}");
        try {
            $before = static::$app->probe('device');
            $s = $this->staff('ad');
            $r = $s->postWithToken('customers/sync/' . $cid, ['token' => $s->token()]);
            $this->assertSame(302, $r->status, $r->excerpt());
            $this->assertSame($before, static::$app->probe('device'), 'no device call for a Suspended customer');
            $this->assertStringContainsString('The customer is not Active; the service is not re-provisioned.', $s->get('customers/view/' . $cid)->body);
        } finally {
            static::$db->exec("UPDATE tbl_customers SET status = 'Active' WHERE id = {$cid}");
        }
    }

    /** expert review 2026-10-26: the rule also holds for a reseller-owned customer (LegacyReprovisionService ADMIN_SYNC) */
    public function testTheResellerSyncRefusesACustomerThatIsNotActive(): void
    {
        $cid = $this->cid('cA1');
        static::$db->exec("UPDATE tbl_customers SET status = 'Suspended' WHERE id = {$cid}");
        try {
            $before = static::$app->probe('device');
            $s = $this->staff('ad');
            $r = $s->postWithToken('customers/sync/' . $cid, ['token' => $s->token()]);
            $this->assertContains($r->status, [200, 302], $r->excerpt());
            $this->assertSame($before, static::$app->probe('device'), 'no device call for a Suspended reseller-owned customer');
        } finally {
            static::$db->exec("UPDATE tbl_customers SET status = 'Active' WHERE id = {$cid}");
        }
    }

    /** expert review 2026-10-26 (pre-existing): the customer-chosen name is escaped in the impersonation notice */
    public function testTheImpersonationNoticeEscapesTheCustomerName(): void
    {
        $cid = $this->cid('cNULL');
        $old = (string) static::$db->value('SELECT fullname FROM tbl_customers WHERE id = ?', [$cid]);
        static::$db->exec("UPDATE tbl_customers SET fullname = '<x-nuxr-xss>' WHERE id = {$cid}");
        try {
            $s = $this->staff('sa');
            $r = $s->postWithToken('customers/login/' . $cid, ['token' => $s->token()]);
            $this->assertStringNotContainsString('<x-nuxr-xss>', $r->body, 'the marker element never reaches the page unescaped');
            $this->assertStringContainsString('&lt;x-nuxr-xss&gt;', $r->body);
        } finally {
            static::$db->pdo->prepare('UPDATE tbl_customers SET fullname = ? WHERE id = ?')->execute([$old, $cid]);
        }
    }

    public function testImpersonationIsLoggedAndAudited(): void
    {
        $cid = $this->cid('cNULL');
        $logs = (int) static::$db->value("SELECT COUNT(*) FROM tbl_logs WHERE description = ?", ['Staff opened the customer portal as customer #' . $cid]);
        $audit = (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'customer.impersonate' AND target_customer_id = ?", [$cid]);
        $s = $this->staff('sa');
        $r = $s->postWithToken('customers/login/' . $cid, ['token' => $s->token()]);
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertSame($logs + 1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_logs WHERE description = ?", ['Staff opened the customer portal as customer #' . $cid]), 'logged');
        $this->assertSame($audit + 1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'customer.impersonate' AND target_customer_id = ?", [$cid]), 'audited');
    }

    private function openedLogs(int|string $cid): int
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_logs WHERE description = ?', ['Staff opened the customer portal as customer #' . $cid]);
    }

    /**
     * Lab closure FIN-6 (independent review 2026-10-26): the impersonation audit failed OPEN - when the audit insert threw,
     * the error was only reported and the staff member still got the customer's portal session. Now: no audit, no portal.
     * The audit insert is made to fail by taking its table away for the one request (database fault, both engines).
     */
    public function testImpersonationIsRefusedWhenItCannotBeAudited(): void
    {
        $cid = $this->cid('cNULL');
        $opened = $this->openedLogs($cid);
        $s = $this->staff('sa');
        $token = $s->token();                     // both tokens taken before the fault, so only the request under test meets it
        static::$db->exec('RENAME TABLE tbl_reseller_audit_events TO tbl_reseller_audit_events_w10off');
        try {
            $r = $s->post('customers/login/' . $cid, ['token' => $token, 'csrf_token' => $token]);
        } finally {
            static::$db->exec('RENAME TABLE tbl_reseller_audit_events_w10off TO tbl_reseller_audit_events');
        }
        // the refusal itself: back to the customer's staff page with the error notice, no portal notice
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertStringContainsString('customers/view/' . $cid, (string) $r->location());
        $this->assertStringNotContainsString('You are logging in as', $r->body, 'no portal session without the audit record');
        $notice = $s->get('customers/view/' . $cid);
        $this->assertStringContainsString('could not be recorded', $notice->body, 'the refusal is shown to the staff member');
        // and no customer portal session was opened (the portal home sends a non-customer to its login)
        $this->assertNotSame(200, $s->get('home')->status, 'the staff session did not become a customer session');
        $this->assertSame($opened, $this->openedLogs($cid), 'a refused attempt is not logged as opened');
    }
}
