<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\Tx;
use Reseller\Finance\FinanceTx;

/**
 * The exact RADIUS subscriber rows that restore an activation's captured prior state, resolved WITHOUT writing
 * anything (extracted unchanged from the WP5A ReconciliationService rollback; reused by the WP5B reversal).
 *
 * The captured prior state stores the password only as a digest (a verifier): it proves whether a known
 * candidate matches, it cannot reconstruct an unknown value. Each prior password row is therefore restored only
 * when it equals, exactly, one of the customer's own passwords (customer.password, customer.pppoe_password);
 * otherwise the restore is refused (PRIOR_SECRET_UNRESTORABLE). A prior state without a password row restores
 * that absence. No prior state captured: PRIOR_STATE_NOT_CAPTURED.
 */
final class RestorePlan
{
    /**
     * @return array{refused:string}|array{username:string,check:list<array{0:string,1:string,2:string}>,reply:list<array{0:string,1:string,2:string}>,groups:list<array{0:string,1:int}>,prior:array,password:string}
     */
    public static function build(array $act): array
    {
        $pdo = FinanceTx::pdo();
        $prior = ActivationStore::radius($pdo, (int) $act['id'])['prior'];
        if (!is_array($prior) || !is_string($prior['username'] ?? null) || !is_array($prior['subscriber'] ?? null)) {
            return ['refused' => 'PRIOR_STATE_NOT_CAPTURED'];
        }
        $customer = Tx::row($pdo, 'SELECT password, pppoe_password FROM tbl_customers WHERE id = ?', [(int) $act['customer_id']]) ?? [];
        $candidates = array_values(array_filter([(string) ($customer['password'] ?? ''), (string) ($customer['pppoe_password'] ?? '')], static fn(string $c): bool => $c !== ''));
        $salt = (string) $act['reference'];
        $password = 'none';
        $check = [];
        foreach ($prior['subscriber']['check'] ?? [] as $row) {
            [$attribute, $op, $value] = [(string) $row[0], (string) $row[1], (string) $row[2]];
            if ($attribute === 'Cleartext-Password') {
                $known = null;
                foreach ($candidates as $candidate) {
                    if (hash_equals($value, RadiusState::digest($salt, $candidate))) {
                        $known = $candidate;
                        break;
                    }
                }
                if ($known === null) {
                    return ['refused' => 'PRIOR_SECRET_UNRESTORABLE'];
                }
                $value = $known;
                $password = 'restored';
            }
            $check[] = [$attribute, $op, $value];
        }
        return ['username' => (string) $prior['username'], 'check' => $check,
            'reply' => array_map(static fn(array $r): array => [(string) $r[0], (string) $r[1], (string) $r[2]], $prior['subscriber']['reply'] ?? []),
            'groups' => array_map(static fn(array $g): array => [(string) $g[0], (int) $g[1]], $prior['subscriber']['groups'] ?? []),
            'prior' => $prior, 'password' => $password];
    }
}
