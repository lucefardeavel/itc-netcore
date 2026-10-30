<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\HttpResponse;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * R-HF-CARRY-01 round 2, review finding F-1 (O-1): customers/add-post ran an UNSCOPED login-username existence check
 * before anything else and appended "Account already exists" to any other validation message. An Agent or Sales user
 * (web or a2 API token) could post a probe username with an invalid field (1-character password, overlong PPPoE
 * username or zip, a foreign Sales account, a PPPoE username already in use) and learn whether the username exists
 * ANYWHERE in the instance while nothing was created and nothing was logged.
 *
 * Required: for tree-scoped staff the uniqueness result is not disclosed unless every other check of the request
 * passes, i.e. a refused add answers byte-identically for a taken and a free username, and an identity collision on an
 * otherwise valid add answers with one generic refusal whichever identity collides. Positive controls: a free name is
 * created; a taken name is still refused on save (server-side uniqueness); Admin keeps "Account already exists".
 */
final class W9CustomerAddExistenceOracleTest extends Wp2HttpTestCase
{
    /** out-of-scope login usernames for Agent A and Sales A1 (tree C, unassigned) */
    private const TAKEN = ['cC1', 'cNULL'];

    /** @return array<string,mixed> a complete, valid add form */
    private static function form(string $username, string $tag): array
    {
        return [
            'username' => $username, 'fullname' => 'Probe ' . $tag, 'password' => 'Pw' . $tag . 'Secret',
            'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '',
            'email' => 'probe' . $tag . '@example.invalid', 'address' => '', 'phonenumber' => '0790123456',
            'service_type' => 'PPPoE', 'account_type' => 'Personal', 'coordinates' => '', 'zip' => '',
        ];
    }

    /** @return array<string,array<string,mixed>> invalid-field variants that never create a customer (actor-specific) */
    private function invalid(string $who): array
    {
        $v = [
            'short password' => ['password' => 'x'],
            'overlong PPPoE username' => ['pppoe_username' => str_repeat('p', 33)],
            'overlong zip' => ['zip' => str_repeat('9', 11)],
            'PPPoE username already in use (in scope)' => ['pppoe_username' => 'cA1'],
        ];
        if ($who === 'A') {
            $v['Sales account of another tree'] = ['assigned_sales_id' => (string) $this->sid('C1')];
        }
        return $v;
    }

    /** what a web add-post tells the actor: status, redirect target and the flash message shown on the next page */
    private function webOutcome(HttpClient $c, array $form): string
    {
        $r = $c->postWithToken('customers/add-post', $form, [], 'customers/add');
        $flash = '';
        if ($r->status === 302 && str_contains((string) $r->location(), 'customers/add')) {
            $page = $c->get('customers/add');
            if (preg_match("/icon: '(success|warning|error)',\\s*title: '((?:[^'\\\\]|\\\\.)*)'/", $page->body, $m)) {
                $flash = $m[1] . ': ' . $m[2];
            }
        }
        return $r->status . ' ' . self::normalize((string) $r->location()) . ' | ' . $flash . ($r->status === 302 ? '' : ' | ' . self::normalize($r->body));
    }

    private static function normalize(string $s): string
    {
        // per-request values (request ids, error references "<12 hex>-<6 hex>", tokens) are not part of the answer
        return (string) preg_replace('/[0-9a-f]{12,}(?:-[0-9a-f]{4,})*/i', '#', $s);
    }

    private function customerCount(): int
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers');
    }

    public function testTreeScopedStaffLearnNothingFromAnAddRefusedForAnotherReason(): void
    {
        foreach (['A', 'A1'] as $who) {
            $c = $this->staff($who);
            foreach ($this->invalid($who) as $case => $bad) {
                $tag = bin2hex(random_bytes(3));
                $before = $this->customerCount();
                $free = $this->webOutcome($c, $bad + self::form('w9or_' . $tag, $tag));
                $this->assertSame($before, $this->customerCount(), "{$who} / {$case}: the control (free name) creates nothing");
                foreach (self::TAKEN as $owner) {
                    $taken = $this->webOutcome($c, $bad + self::form($owner, $tag));
                    $this->assertSame($free, $taken, "{$who} / {$case}: the out-of-scope username {$owner} must answer like a free one");
                    $this->assertSame($before, $this->customerCount(), "{$who} / {$case} / {$owner}: nothing is created");
                }
            }
        }
    }

    public function testTheApiAnswersTheSameWayForTreeScopedStaff(): void
    {
        $token = $this->apiToken('A');
        foreach ($this->invalid('A') as $case => $bad) {
            $tag = bin2hex(random_bytes(3));
            $before = $this->customerCount();
            $ask = fn(string $u): HttpResponse => $this->client()->api('POST', 'customers/add-post', $bad + self::form($u, $tag), $token);
            $free = $ask('w9or_' . $tag);
            foreach (self::TAKEN as $owner) {
                $taken = $ask($owner);
                $this->assertSame([$free->status, self::normalize($free->body)], [$taken->status, self::normalize($taken->body)], "API / {$case}: {$owner} must answer like a free name");
            }
            $this->assertSame($before, $this->customerCount(), "API / {$case}: nothing is created");
        }
    }

    /**
     * An otherwise valid add that meets an existing identity: refused (uniqueness is still enforced on save) with ONE
     * generic answer, whichever identity collides, so a known-taken name cannot be combined with a probe name.
     */
    public function testAnIdentityCollisionIsRefusedWithOneGenericAnswer(): void
    {
        foreach (['A', 'A1'] as $who) {
            $c = $this->staff($who);
            $tag = bin2hex(random_bytes(3));
            $before = $this->customerCount();
            $answers = [
                'login username of another tree' => $this->webOutcome($c, self::form('cC1', $tag)),
                'login username, unassigned customer' => $this->webOutcome($c, self::form('cNULL', $tag)),
                'PPPoE username = another tree\'s login username' => $this->webOutcome($c, ['pppoe_username' => 'cC1'] + self::form('w9or_p' . $tag, $tag)),
                'PPPoE username = an in-scope login username' => $this->webOutcome($c, ['pppoe_username' => 'cA1'] + self::form('w9or_q' . $tag, $tag)),
            ];
            $this->assertSame($before, $this->customerCount(), "{$who}: every collision is refused on save, nothing is created");
            $this->assertCount(1, array_unique($answers), "{$who}: one answer for every identity collision: " . json_encode($answers, JSON_UNESCAPED_SLASHES));
            $one = (string) reset($answers);
            $this->assertStringContainsString('error: ', $one, "{$who}: a refusal");
            $this->assertStringNotContainsString('Account already exists', $one, "{$who}: not the login-username-specific text");
            $this->assertStringNotContainsString('cC1', $one, "{$who}: no name");

            // positive control: the same form with free names is created, owned server-side
            $new = 'w9or_ok' . $tag;
            $ok = $this->webOutcome($c, self::form($new, $tag));
            $this->assertStringContainsString('customers/view/', $ok, "{$who}: positive control, a free name is created");
            $this->assertSame($before + 1, $this->customerCount());
            $this->assertSame($this->sid('A'), (int) static::$db->value('SELECT manager_id FROM tbl_customers WHERE username = ?', [$new]));
        }
    }

    /** Global staff keep the legacy answer (owner: Admin / SuperAdmin behaviour unchanged). */
    public function testAdminKeepsTheAccountAlreadyExistsAnswer(): void
    {
        $c = $this->staff('ad');
        $tag = bin2hex(random_bytes(3));
        $before = $this->customerCount();
        $this->assertStringContainsString('Account already exists', $this->webOutcome($c, ['password' => 'x'] + self::form('cC1', $tag)));
        $this->assertStringContainsString('Account already exists', $this->webOutcome($c, self::form('cC1', $tag)));
        $this->assertSame($before, $this->customerCount());
    }
}
