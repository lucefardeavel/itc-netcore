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
}
