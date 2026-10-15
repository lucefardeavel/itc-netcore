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

    public function testGrandfatheredCollidingOwnedCustomerActivationIsCharacterised(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', 'PPPOE');
        $x = 'w9rg' . bin2hex(random_bytes(3));
        [, $victimPw] = $this->victim($x);
        // pre-existing (grandfathered) collision: inserted as data, not through a writer
        $b = $this->customer($leaf, null, ['pppoe_username' => $x]);
        $before = $this->radcheck($x);
        $this->assertSame($victimPw, $before['Cleartext-Password'] ?? null, 'control: the victim policy');
        $out = $this->activate($leaf, $b, $plan, 'NEW');
        $after = $this->radcheck($x);
        $overwritten = ($after['Cleartext-Password'] ?? null) !== $victimPw;
        self::record('U0 reverse direction: grandfathered collider activation', $overwritten ? 'KNOWN_RESIDUAL_REPRODUCED' : 'OBSERVED', [
            'activation_status' => $out['status'], 'error_code' => $out['error_code'], 'victim_policy_overwritten' => $overwritten,
            'detail' => 'pre-existing duplicate identities are outside U0 (acquirers only); needs the read-only survey + operator plan or a uniqueness decision (U1-U4)']);
        $this->assertNotSame('', $out['reference'], 'control: the activation ran');
    }
}
