<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp9Evidence.php';

use Reseller\Domain\CustomerService;
use Reseller\Domain\DomainError;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * WP9 related to U0 (owner §5): the REVERSE direction of OBS-1 / L-A5-2 - a reseller-owned customer whose PPPoE key equals
 * an unassigned customer's name, activated through the reseller path (ActivationService, in-process, PPPoE plan).
 *  (1) After U0 the collider can no longer be CREATED or RENAMED into existence by an application writer (P1 Agent,
 *      P5), including the canonical (stored leading-space) variant - contract.
 *  (2) A GRANDFATHERED collider (pre-existing data, inserted as a fixture) is characterised: what the activation does to
 *      the other customer's RADIUS policy. U0 covers acquirers, not existing duplicates (U0_DESIGN §1.3 / §4.4: the
 *      read-only survey and an operator plan, or a uniqueness option U1–U4, are separate owner decisions).
 */
final class U0ReverseDirectionTest extends ActivationTestCase
{
    use Wp9Evidence;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // as LegacyReprovisionTest (WP5B) / T1-A: the legacy classes the re-provision service uses in-process
        $root = dirname(__DIR__, 3);
        $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
        $GLOBALS['_app_stage'] = 'Live';
        foreach (['Package', 'Text'] as $c) {
            require_once $root . "/system/autoload/{$c}.php";
        }
        require_once $root . '/system/devices/Radius.php';
    }

    /** the unassigned victim with its own RADIUS password row (fixture: its legacy grant) */
    private function victim(string $username): array
    {
        $id = $this->customer(null, null, ['username' => $username]);
        $pw = (string) static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$id]);
        static::$db->insertRow('radcheck', ['username' => trim($username), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $pw]);
        return [$id, $pw];
    }

    public function testACollidingOwnedIdentityCannotBeCreatedOrRenamedAnyMore(): void
    {
        [, $leaf] = $this->pricedChain(['12', '15'], '10', '20', 'PPPOE')[1];
        $x = 'w9rv' . bin2hex(random_bytes(3));
        $this->victim($x);
        $lead = 'w9rl' . bin2hex(random_bytes(3));
        $this->victim(' ' . $lead);
        $out = [];
        foreach (['P1 Agent pppoe = victim username' => $x, 'P1 Agent pppoe = trimmed stored leading-space username' => $lead] as $case => $pppoe) {
            try {
                CustomerService::create($this->ctx($leaf), ['username' => 'w9rn' . bin2hex(random_bytes(3)), 'pppoe_username' => $pppoe, 'password' => 'Pw-rv', 'fullname' => 'W9 RV']);
                $out[$case] = 'CREATED';
            } catch (DomainError $e) {
                $out[$case] = 'REFUSED ' . $e->reason;
            }
        }
        $owned = $this->customer($leaf);
        foreach (['P5 rename pppoe = victim username' => $x, 'P5 rename pppoe = trimmed stored leading-space username' => $lead] as $case => $pppoe) {
            try {
                R::run($this->ctx('ad'), $owned, R::IDENTITY_EDIT, ['pppoe_username' => $pppoe]);
                $out[$case] = 'ACCEPTED';
                $this->customersChanged();
            } catch (DomainError $e) {
                $out[$case] = 'REFUSED ' . $e->reason;
            }
        }
        $met = !in_array('CREATED', $out, true) && !in_array('ACCEPTED', $out, true);
        self::record('U0 reverse direction: collider creation / rename', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['outcomes' => $out]);
        foreach ($out as $case => $o) {
            $this->assertStringStartsWith('REFUSED', $o, $case);
        }
    }

    /**
     * Final hardening (D-U0-R1): the grandfathered collider's activation is refused before any RADIUS write - FAILED
     * RADIUS_IDENTITY_AMBIGUOUS, the other customer's policy unchanged, reservation released and no settlement (the
     * ActivationTestCase post-conditions check the finance invariants and the activation / reservation consistency).
     * The U0-5 run recorded the overwrite (KNOWN_RESIDUAL_REPRODUCED, evidence/u0/related).
     */
    public function testGrandfatheredCollidingOwnedCustomerActivationIsRefusedBeforeAnyWrite(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', 'PPPOE');
        $x = 'w9rg' . bin2hex(random_bytes(3));
        [, $victimPw] = $this->victim($x);
        // pre-existing (grandfathered) collision: inserted as data, not through a writer
        $b = $this->customer($leaf, null, ['pppoe_username' => $x]);
        $before = $this->radcheck($x);
        $this->assertSame($victimPw, $before['Cleartext-Password'] ?? null, 'control: the victim policy');
        $wallet = static::$db->rows('SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [$leaf]);
        $ledger = (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger');
        $out = $this->activate($leaf, $b, $plan, 'NEW');
        $after = $this->radcheck($x);
        $overwritten = $after !== $before;
        $act = $this->act($out['reference']);
        $res = static::$db->rows('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [(int) $act['id']]);
        $obs = ['activation_status' => $out['status'], 'error_code' => $out['error_code'], 'victim_policy_overwritten' => $overwritten,
            'reservation' => array_column($res, 'status'), 'wallet_unchanged' => static::$db->rows('SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [$leaf]) === $wallet,
            'ledger_rows_added' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger') - $ledger, 'recharge_rows_for_b' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE customer_id = ?', [$b])];
        $met = $out['status'] === 'FAILED' && $out['error_code'] === 'RADIUS_IDENTITY_AMBIGUOUS' && !$overwritten && $obs['wallet_unchanged'] && $obs['ledger_rows_added'] === 0 && $obs['recharge_rows_for_b'] === 0;
        self::record('U0 reverse direction: grandfathered collider activation', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        $this->assertSame('FAILED', $out['status'], json_encode($obs));
        $this->assertSame('RADIUS_IDENTITY_AMBIGUOUS', $out['error_code']);
        $this->assertFalse($overwritten, "the other customer's RADIUS policy is unchanged");
        $this->assertTrue($obs['wallet_unchanged'], 'no debit');
        $this->assertSame(0, $obs['ledger_rows_added'], 'no settlement');
        $this->assertSame(0, $obs['recharge_rows_for_b'], 'no legacy recharge row');
        $this->assertSame(['RELEASED'], $obs['reservation'], 'the reservation is released (WP5A FAILED)');
    }

    public function testANonCollidingOwnedActivationStillCompletes(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', 'PPPOE');
        $b = $this->customer($leaf, null, ['pppoe_username' => 'w9rk' . bin2hex(random_bytes(3))]);
        $out = $this->activate($leaf, $b, $plan, 'NEW');
        self::record('U0 reverse direction control: single owner activation', $out['status'] === 'COMPLETED' ? 'CONTROL_PASS' : 'CONTROL_FAIL', ['status' => $out['status'], 'error_code' => $out['error_code']]);
        $this->assertSame('COMPLETED', $out['status'], json_encode($out));
    }

    /** the reseller-owned rename of a key another customer also holds (exact duplicate) is refused before any write */
    public function testOwnedRenameOfAnAmbiguousKeyIsRefused(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', 'PPPOE');
        $x = 'w9ra' . bin2hex(random_bytes(3));
        [, $victimPw] = $this->victim($x);
        $b = $this->customer($leaf, null, ['pppoe_username' => $x]);
        $this->recharge($b, $plan, date('Y-m-d', time() + 86400 * 10), '23:59:59', 'on', 'PPPOE');
        $before = $this->radcheck($x);
        $y = 'w9rb' . bin2hex(random_bytes(3));
        try {
            R::run($this->ctx('ad'), $b, R::IDENTITY_EDIT, ['pppoe_username' => $y]);
            $outcome = 'ACCEPTED';
            $this->customersChanged();
        } catch (DomainError $e) {
            $outcome = 'REFUSED ' . $e->reason;
        }
        $stored = (string) static::$db->value('SELECT pppoe_username FROM tbl_customers WHERE id = ?', [$b]);
        $met = $outcome === 'REFUSED RENAME_AMBIGUOUS_IDENTITY' && $stored === $x && $this->radcheck($x) === $before;
        self::record('U0 reverse direction: owned rename of an ambiguous key', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['outcome' => $outcome, 'pppoe_unchanged' => $stored === $x, 'victim_policy_unchanged' => $this->radcheck($x) === $before]);
        $this->assertSame('REFUSED RENAME_AMBIGUOUS_IDENTITY', $outcome);
        $this->assertSame($x, $stored);
        $this->assertSame($before, $this->radcheck($x), "the other customer's policy is not moved");
        $this->assertSame($victimPw, $this->radcheck($x)['Cleartext-Password'] ?? null);
    }
}
