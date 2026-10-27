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
    private static int $dear;
    private static string $expiry;

    private static function plan(string $name, int $validity, string $price = '20'): int
    {
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => $name . '_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        return static::$db->insertRow('tbl_plans', [
            'name_plan' => $name, 'id_bw' => $bw, 'price' => $price, 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
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
        // UX-16: a plan whose wholesale cost is above every wallet in the fixture (credit insufficient)
        self::$dear = self::plan('w10_dear', 1, '5000');
        self::$expiry = date('Y-m-d', strtotime('+20 days'));
        $long = self::$long;
        $short = self::$short;
        $dear = self::$dear;
        $a = static::$seed->staff['A'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
foreach ([{$long}, {$short}] as \$p) {
    \\Reseller\\Finance\\PricingService::setBaseCost(\$ad, \$p, '10');
    \\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, \$p, true);
}
\\Reseller\\Finance\\PricingService::setBaseCost(\$ad, {$dear}, '1000');
\\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, {$dear}, true);
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
        // the number is display only: the form posts only a required acknowledgement (UX-15, server-checked), never the days
        preg_match('#<form method="post"[^>]*id="nuxr-confirm-form".*?</form>#s', $html, $form);
        preg_match_all('#name="([a-z_]+)"#', $form[0] ?? '', $names);
        $this->assertSame(['csrf_token', 'customer_id', 'plan_id', 'expected_action', 'nonce', 'preview', 'ack_loss'], $names[1], 'no lost-days value is ever posted');
        $this->assertMatchesRegularExpression('#<input type="checkbox" name="ack_loss" value="1" required#', $form[0]);
        // the plan validity reads in words
        $this->assertMatchesRegularExpression('#w10_short \(Hotspot\)[^<]*· 1 day</option>#', $html);
        $this->assertMatchesRegularExpression('#w10_long \(Hotspot\)[^<]*· 30 days</option>#', $html);
    }

    /**
     * UX-16 (independent review 2026-10-27): with insufficient credit the acknowledgement must not re-enable Confirm. The
     * button is rendered disabled and is not the target of the acknowledgement script (the server refuses either way).
     * Runs before the plan change below, while cA0 still has 20 paid days on the 30-day plan; it only reads pages.
     */
    public function testTheAcknowledgementDoesNotEnableConfirmWithoutCredit(): void
    {
        $html = $this->confirmation(static::$seed->customers['cA0'], self::$dear);
        $this->assertStringContainsString('name="expected_action" value="CHANGE_PLAN"', $html);
        $this->assertStringContainsString('<strong>Credit sufficient: no</strong>', $html, 'discriminating: credit is short');
        $this->assertMatchesRegularExpression('#<input type="checkbox" name="ack_loss" value="1" required#', $html, 'the loss is still acknowledged');
        $b = $this->confirmButton($html);
        $this->assertStringContainsString('disabled', $b);
        $this->assertStringNotContainsString('data-nuxr-needs-ack', $b, 'the acknowledgement cannot enable a button that credit disables');
        // with credit the acknowledgement still drives the button (UX-15 unchanged)
        $ok = $this->confirmation(static::$seed->customers['cA0'], self::$short);
        $this->assertStringContainsString('Credit sufficient: yes', $ok);
        $this->assertStringContainsString('data-nuxr-needs-ack', $this->confirmButton($ok));
    }

    /** the attributes of the Confirm button of the confirm form (the page has other submit buttons) */
    private function confirmButton(string $html): string
    {
        $this->assertSame(1, preg_match('#<form method="post"[^>]*id="nuxr-confirm-form".*?</form>#s', $html, $form), 'the confirm form');
        $this->assertSame(1, preg_match('#<button class="nuxr-btn nuxr-btn-primary" type="submit"([^>]*)>#', $form[0], $b), 'the Confirm button');
        return $b[1];
    }

    /** UX-15: the server refuses the plan change without the acknowledgement (nothing reserved), and runs it with it */
    public function testThePlanChangeNeedsTheAcknowledgementOnTheServer(): void
    {
        $cid = static::$seed->customers['cA0'];
        $html = $this->confirmation($cid, self::$short);
        preg_match('#name="nonce" value="([^"]+)"#', $html, $n);
        preg_match('#name="preview" value="([^"]+)"#', $html, $f);
        $before = (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = ?', [$cid]);
        $post = ['customer_id' => $cid, 'plan_id' => self::$short, 'expected_action' => 'CHANGE_PLAN', 'nonce' => $n[1], 'preview' => $f[1]];
        $c = $this->staff('A');
        $r = $c->postWithToken('activation/create-post', $post);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertStringContainsString('activation/create', (string) $r->location(), 'back to the confirmation');
        $this->assertSame($before, (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = ?', [$cid]), 'no activation without the acknowledgement');
        $again = $c->get('activation/create', ['customer' => $cid, 'plan' => self::$short])->body;
        $this->assertStringContainsString('Please confirm that the remaining paid time will be lost.', $again);
        // the operator confirms the (fresh) confirmation with the box ticked
        preg_match('#name="nonce" value="([^"]+)"#', $again, $n);
        preg_match('#name="preview" value="([^"]+)"#', $again, $f);
        $r = $c->postWithToken('activation/create-post', ['nonce' => $n[1], 'preview' => $f[1], 'ack_loss' => '1'] + $post);
        $this->assertSame(302, $r->status, $r->excerpt());
        $why = '';
        if (!str_contains((string) $r->location(), 'activation/view/')) {
            preg_match("#title: '([^']*)'#", $c->get('activation/create', ['customer' => $cid, 'plan' => self::$short])->body, $t);
            $why = $t[1] ?? '?';
        }
        $this->assertStringContainsString('activation/view/', (string) $r->location(), 'acknowledged: the activation runs ' . $why);
        $this->assertSame($before + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = ?', [$cid]));
        // expert review 2026-10-26: a replay of the same request (same nonce) without the box is answered by the
        // idempotency path (the existing activation), never by a new refusal, and creates nothing
        $view = (string) $r->location();
        $r = $c->postWithToken('activation/create-post', ['nonce' => $n[1], 'preview' => $f[1]] + $post);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertStringContainsString('activation/view/', (string) $r->location(), 'the replay reports the existing activation');
        $this->assertSame(substr($view, strpos($view, 'activation/view/')), substr((string) $r->location(), strpos((string) $r->location(), 'activation/view/')));
        $this->assertSame($before + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = ?', [$cid]));
    }

    public function testARenewalShowsNoLossWarning(): void
    {
        $html = $this->confirmation(static::$seed->customers['cA2'], self::$short);
        $this->assertStringContainsString('name="expected_action" value="RENEW"', $html);
        $this->assertStringContainsString('Same plan: the new period continues from the current expiry.', $html);
        $this->assertStringNotContainsString('nuxr-change-loss', $html);
        $this->assertStringNotContainsString('will be lost', $html);
        $this->assertStringNotContainsString('ack_loss', $html, 'a renewal needs no acknowledgement');
    }

    public function testANewActivationShowsNoLossWarning(): void
    {
        $html = $this->confirmation(static::$seed->customers['cA1'], self::$short);
        $this->assertStringNotContainsString('name="expected_action" value="CHANGE_PLAN"', $html, 'cA1 has no current RADIUS-plan period');
        $this->assertStringNotContainsString('nuxr-change-loss', $html);
    }
}
