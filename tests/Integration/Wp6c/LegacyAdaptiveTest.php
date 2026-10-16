<?php
namespace Reseller\Tests\Integration\Wp6c;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6C slices 2 / 5 over real HTTP: the converted legacy forms keep their exact contract (POST route, field names, the
 * same submit) - presentation only - and gain the adaptive form layer: the action row is a direct child of the form
 * (sticky on phones), and every visible control has an accessible name (label for / wrapping label / aria-label).
 */
final class LegacyAdaptiveTest extends Wp2HttpTestCase
{
    /** @return array{action:string,names:list<string>,actionbar:bool,submitInBar:bool,unnamed:list<string>} */
    private static function form(string $html, string $actionNeedle): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);
        foreach ($xp->query('//form') as $form) {
            /** @var \DOMElement $form */
            // '' = the page's self-posting form (action="")
            if ($actionNeedle === '' ? $form->getAttribute('action') !== '' : !str_contains($form->getAttribute('action'), $actionNeedle)) {
                continue;
            }
            $names = [];
            $unnamed = [];
            foreach ($xp->query('.//input|.//select|.//textarea', $form) as $c) {
                /** @var \DOMElement $c */
                $type = strtolower($c->getAttribute('type'));
                if ($c->getAttribute('name') !== '') {
                    $names[] = $c->getAttribute('name');
                }
                if (in_array($type, ['hidden', 'submit', 'button'], true)) {
                    continue;
                }
                $id = $c->getAttribute('id');
                $labelled = $c->getAttribute('aria-label') !== '' || $c->getAttribute('aria-labelledby') !== ''
                    || ($id !== '' && $xp->query('//label[@for="' . $id . '"]')->length > 0) || $xp->query('ancestor::label', $c)->length > 0;
                if (!$labelled) {
                    $unnamed[] = $c->getAttribute('name') ?: $c->nodeName;
                }
            }
            $bar = $xp->query('./div[contains(@class, "nuxr-step-nav")]', $form);
            return ['action' => $form->getAttribute('action'), 'names' => array_values(array_unique($names)), 'actionbar' => $bar->length === 1,
                'submitInBar' => $bar->length === 1 && $xp->query('.//button[@type="submit"]', $bar->item(0))->length === 1, 'unnamed' => $unnamed];
        }
        throw new \RuntimeException('form not found: ' . $actionNeedle);
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>}> role, page, form action, required field names */
    private function cases(): array
    {
        $s = static::$seed;
        return [
            ['ad', 'customers/edit/' . $s->customers['cNULL'], 'customers/edit-post', ['id', 'username', 'fullname', 'email', 'phonenumber', 'password', 'address', 'service_type', 'account_type', 'status']],
            ['ad', 'plan/recharge', 'plan/recharge-confirm', ['id_customer', 'type', 'server', 'plan', 'using']],
            ['ad', 'plan/refill', 'plan/refill-post', ['id_customer', 'code']],
            ['ad', 'message/send', 'message/send-post', ['id_customer', 'via', 'message']],
            ['ad', 'settings/users-add', 'settings/users-post', ['username', 'fullname', 'password', 'user_type']],
            ['ad', 'settings/users-edit/' . $s->staff['A'], 'settings/users-edit-post', ['username', 'fullname', 'id']],
            ['ad', 'settings/change-password', 'settings/change-password-post', ['password', 'npass', 'cnpass']],
            ['ad', 'plan/add-voucher', 'plan/voucher-post', ['type', 'server', 'plan', 'numbervoucher']],
            // slice 5: admin / config Adaptive Layer
            ['ad', 'services/add', 'services/add-post', ['name', 'id_bw', 'price', 'validity', 'validity_unit', 'enabled', 'prepaid']],
            ['ad', 'services/pppoe-add', 'services/pppoe-add-post', ['name_plan', 'id_bw', 'price', 'validity', 'validity_unit']],
            ['ad', 'bandwidth/add', 'bandwidth/add-post', ['name', 'rate_down', 'rate_down_unit', 'rate_up', 'rate_up_unit']],
            ['ad', 'routers/add', 'routers/add-post', ['name', 'ip_address', 'username', 'password', 'description']],
            ['ad', 'radius/nas-add', 'radius/nas-add-post', ['shortname', 'nasname', 'secret', 'ports', 'type']],
            ['ad', 'coupons/add', 'coupons/add-post', ['code', 'type', 'value', 'max_usage', 'start_date', 'end_date']],
            ['ad', 'pool/add', 'pool/add-post', ['name', 'ip_address', 'routers']],
            ['ad', 'settings/localisation', 'settings/localisation-post', ['tzone', 'date_format', 'lan', 'currency_code']],
            ['ad', 'settings/miscellaneous', '', ['router_check', 'extend_expiry']],
            ['ad', 'settings/notifications', 'settings/notifications-post', ['expired', 'reminder_7_day', 'invoice_paid']],
        ];
    }

    public function testConvertedFormsKeepTheirContractAndGainTheAdaptiveLayer(): void
    {
        foreach ($this->cases() as [$role, $page, $action, $fields]) {
            $r = $this->staff($role)->get($page);
            $this->assertSame(200, $r->status, "{$page}: " . $r->excerpt());
            $f = self::form($r->body, $action);
            foreach ($fields as $field) {
                $this->assertContains($field, $f['names'], "{$page}: field {$field} unchanged");
            }
            $this->assertTrue($f['actionbar'], "{$page}: the action row is a direct child of the form (sticky on phones)");
            $this->assertTrue($f['submitInBar'], "{$page}: the submit button lives in the action row");
            $this->assertSame([], $f['unnamed'], "{$page}: every visible control has an accessible name");
        }
    }

    public function testDailyListsRenderTheAdaptivePairWithRealLinks(): void
    {
        foreach (['plan/list', 'plan/voucher', 'settings/users', 'logs/list', 'reports', 'reports/activation'] as $page) {
            $r = $this->staff('ad')->get($page);
            $this->assertSame(200, $r->status, "{$page}: " . $r->excerpt());
            $this->assertMatchesRegularExpression('/class="nuxr-adaptive[^"]*"/', $r->body, "{$page}: table + card pair");
            $this->assertStringContainsString('class="nuxr-mlist"', $r->body, "{$page}: phone cards");
            $this->assertDoesNotMatchRegularExpression('/<(td|div)[^>]*\sonclick="(window\.location|showFilter)/', $r->body, "{$page}: no click-only cells or headers");
        }
        // touch-safe reveal: the secret stays in the markup the role could already see, masked until revealed
        $v = $this->staff('ad')->get('plan/voucher');
        $this->assertStringContainsString('data-nuxr-reveal', $v->body);
    }

    public function testAdminConfigListsStackWithLabelsAndNoHoverOnlyData(): void
    {
        // radius/* needs the RADIUS database the harness does not provide (as in the accepted RouteMatrixTest); verified in the lab
        foreach (['services/hotspot', 'services/pppoe', 'bandwidth/list', 'routers/list', 'pool/list', 'pool/port', 'odp', 'coupons'] as $page) {
            $r = $this->staff('ad')->get($page);
            $this->assertSame(200, $r->status, "{$page}: " . $r->excerpt());
            $this->assertStringContainsString('nuxr-stack', $r->body, "{$page}: stacks into cards when narrow");
            if (in_array($page, ['services/hotspot', 'bandwidth/list', 'routers/list'], true)) { // lists with seeded rows
                $this->assertMatchesRegularExpression('/<td data-label="[^"]+"/', $r->body, "{$page}: cells carry their column name");
            }
            $this->assertStringNotContainsString('onmouseenter="this.style.backgroundColor', $r->body, "{$page}: no hover-only cells");
        }
        // the self-posting settings form keeps its submit name / value inside the action row
        $this->assertMatchesRegularExpression('/<div class="nuxr-step-nav nuxr-actionbar-form">\s*<button class="nuxr-btn nuxr-btn-primary" name="save" value="save" type="submit">/',
            $this->staff('ad')->get('settings/miscellaneous')->body);
        // confirmations moved from window.confirm to the shell's sheet: same question, same submit
        $bw = $this->staff('ad')->get('bandwidth/add')->body;
        $this->assertMatchesRegularExpression('/<div class="nuxr-step-nav nuxr-actionbar-form">.*?<button class="nuxr-btn nuxr-btn-primary" data-nuxr-ask="Continue the Bandwidth addition process\?" type="submit">/s', $bw);
        $this->assertStringContainsString("askTitle: 'Please confirm'", $bw, 'the sheet is built on first use from the shell strings (no page markup)');
        $this->assertDoesNotMatchRegularExpression('/nuxr-actionbar-form">(?:(?!<\/div>).)*onclick="return ask\(/s', $bw);
        // touch / keyboard equivalent for the legacy hover-revealed secrets (Amendment G); nothing new is rendered
        $this->assertStringContainsString('data-nuxr-peek', $this->staff('ad')->get('routers/add')->body);
        $this->assertStringContainsString('data-nuxr-reveal', $this->staff('ad')->get('routers/list')->body);
    }

    public function testAuditDefectsAreFixed(): void
    {
        // Amendment H: the database status page works on the shipped image (it needed the absent mysqli extension)
        $db = $this->staff('ad')->get('settings/dbstatus');
        $this->assertSame(200, $db->status, $db->excerpt());
        $this->assertStringContainsString('name="tables[]"', $db->body);
        // no template comment leaks into the page as text
        foreach (['routers/add', 'odp/add', 'customers/edit/' . static::$seed->customers['cNULL']] as $page) {
            $r = $this->staff('ad')->get($page);
            $this->assertSame(200, $r->status, "{$page}: " . $r->excerpt());
            $this->assertStringNotContainsString('{*', $r->body, "{$page}: no raw Smarty comment");
        }
        // the ODP search box is well-formed (its value attribute was never closed)
        $odp = $this->staff('ad')->get('odp');
        $this->assertMatchesRegularExpression('/<input type="text" name="name" class="form-control"\s+placeholder="[^"]*" value="[^"]*" aria-label="[^"]+">/', $odp->body);
        // the database status page stays an Admin page
        $this->assertNotSame(200, $this->staff('A')->get('settings/dbstatus')->status);
    }

    public function testAConvertedFormStillPostsThroughItsController(): void
    {
        $s = static::$seed;
        $c = $this->staff('ad');
        $page = $c->get('settings/change-password');
        $this->assertSame(200, $page->status);
        // WP9 SEC-17: the login above may have upgraded the stored hash (SHA-1 -> password_hash); compare with it
        $stored = (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$s->staff['ad']]);
        // wrong current password: the unchanged controller refuses and redirects back (no write)
        $r = $c->postWithToken('settings/change-password-post', ['password' => 'not-the-password', 'npass' => 'x1-Newpass!', 'cnpass' => 'x1-Newpass!']);
        $this->assertSame(302, $r->status);
        $this->assertSame($stored, (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$s->staff['ad']]),
            'the controller (not the template) still decides');
    }
}
