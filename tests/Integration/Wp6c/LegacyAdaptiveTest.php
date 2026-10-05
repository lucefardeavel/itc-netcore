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
            if (!str_contains($form->getAttribute('action'), $actionNeedle)) {
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

    public function testAConvertedFormStillPostsThroughItsController(): void
    {
        $s = static::$seed;
        $c = $this->staff('ad');
        $page = $c->get('settings/change-password');
        $this->assertSame(200, $page->status);
        // wrong current password: the unchanged controller refuses and redirects back (no write)
        $r = $c->postWithToken('settings/change-password-post', ['password' => 'not-the-password', 'npass' => 'x1-Newpass!', 'cnpass' => 'x1-Newpass!']);
        $this->assertSame(302, $r->status);
        $this->assertSame(sha1(\Reseller\Tests\Support\Wp2Seed::password('ad')), (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$s->staff['ad']]),
            'the controller (not the template) still decides');
    }
}
