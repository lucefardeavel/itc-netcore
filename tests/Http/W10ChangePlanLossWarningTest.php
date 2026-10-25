<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * Lab finding UX-15 (ITC NetCore lab 2026-10-25): a plan change starts now and replaces the current period (approved policy,
 * docs/WP5A_ACTIVATION_MODEL.md: CHANGE_PLAN starts now, no proration), but the confirmation only listed the two dates, so
 * the operator was not told that the remaining paid days are lost. The confirm step now shows a warning with the number of
 * paid days and the date they ran to, computed for display from the quote's own dates (never posted, never read by the
 * server). A renewal continues from the current expiry and shows no such warning. The plan validity reads in words
 * ("1 day", "30 days"; Arabic "يوم واحد", "30 يوماً").
 */
final class W10ChangePlanLossWarningTest extends RadiusHttpTestCase
{
    private static int $long;
    private static int $short;
    private static string $expiry;

    private static function plan(string $name, int $validity): int
    {
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => $name . '_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        return static::$db->insertRow('tbl_plans', [
            'name_plan' => $name, 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => $validity, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
    }

    private static function current(string $customer, int $plan, string $name): void
    {
        static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => static::$seed->customers[$customer], 'username' => $customer, 'plan_id' => $plan, 'namebp' => $name,
            'recharged_on' => date('Y-m-d', strtotime('-10 days')), 'recharged_time' => '00:00:00', 'expiration' => self::$expiry, 'time' => '23:59:59',
            'status' => 'on', 'method' => 'Admin - seed', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => static::$seed->staff['ad'],
        ]);
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$long = self::plan('w10_long', 30);
        self::$short = self::plan('w10_short', 1);
        self::$expiry = date('Y-m-d', strtotime('+20 days'));
        $long = self::$long;
        $short = self::$short;
        $a = static::$seed->staff['A'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
foreach ([{$long}, {$short}] as \$p) {
    \\Reseller\\Finance\\PricingService::setBaseCost(\$ad, \$p, '10');
    \\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, \$p, true);
}
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '100', 'initial provider funding', 'F-w10-change-loss');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
        // cA0: 20 paid days left on the 30-day plan (a change to the 1-day plan replaces them); cA2: the same plan again (renewal)
        self::current('cA0', self::$long, 'w10_long');
        self::current('cA2', self::$short, 'w10_short');
    }

    private function confirmation(int $customer, int $plan): string
    {
        $page = $this->staff('A')->get('activation/create', ['customer' => $customer, 'plan' => $plan]);
        $this->assertSame(200, $page->status, $page->excerpt());
        $this->assertStringContainsString('id="nuxr-confirm-form"', $page->body, 'the confirm step is shown');
        return $page->body;
    }

    public function testAChangeToAShorterPlanWarnsAboutTheLostPaidDays(): void
    {
        $html = $this->confirmation(static::$seed->customers['cA0'], self::$short);
        $this->assertStringContainsString('name="expected_action" value="CHANGE_PLAN"', $html);
        $this->assertMatchesRegularExpression('#<div class="nuxr-alert is-warning" role="alert" id="nuxr-change-loss"#', $html, 'a visible warning box');
        // the box title is a plain "Warning": the sentence itself says what is lost (no "will be lost ... will be lost")
        $this->assertStringContainsString('<strong>Warning</strong>', $html);
        $this->assertSame(1, preg_match('#(\d+) days of paid service \(until ' . preg_quote(self::$expiry, '#') . '\) will be lost#', $html, $m), 'N paid days until the current expiry');
        $this->assertGreaterThanOrEqual(19, (int) $m[1]);
        $this->assertLessThanOrEqual(21, (int) $m[1]);
        // the warning is display only: the confirmation posts exactly the fields it posted before
        preg_match('#<form method="post"[^>]*id="nuxr-confirm-form".*?</form>#s', $html, $form);
        preg_match_all('#name="([a-z_]+)"#', $form[0] ?? '', $names);
        $this->assertSame(['csrf_token', 'customer_id', 'plan_id', 'expected_action', 'nonce', 'preview'], $names[1], 'no lost-days value is ever posted');
        // the plan validity reads in words
        $this->assertMatchesRegularExpression('#w10_short \(Hotspot\)[^<]*· 1 day</option>#', $html);
        $this->assertMatchesRegularExpression('#w10_long \(Hotspot\)[^<]*· 30 days</option>#', $html);
    }

    public function testARenewalShowsNoLossWarning(): void
    {
        $html = $this->confirmation(static::$seed->customers['cA2'], self::$short);
        $this->assertStringContainsString('name="expected_action" value="RENEW"', $html);
        $this->assertStringContainsString('Same plan: the new period continues from the current expiry.', $html);
        $this->assertStringNotContainsString('nuxr-change-loss', $html);
        $this->assertStringNotContainsString('will be lost', $html);
    }

    public function testANewActivationShowsNoLossWarning(): void
    {
        $html = $this->confirmation(static::$seed->customers['cA1'], self::$short);
        $this->assertStringNotContainsString('name="expected_action" value="CHANGE_PLAN"', $html, 'cA1 has no current RADIUS-plan period');
        $this->assertStringNotContainsString('nuxr-change-loss', $html);
    }
}
