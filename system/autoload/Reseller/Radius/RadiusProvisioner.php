<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ErrorReporter;

/**
 * Provisioning lifecycle through the repository and the policy builder (06_RADIUS_CONTRACT
 * "RadiusProvisioner"). Every operation returns a ProvisionResult:
 *  - SUCCESS: committed and verified by reading the rows back;
 *  - FAILED:  rolled back (retryable for transient database errors);
 *  - UNKNOWN: the COMMIT acknowledgement was lost, or the read-back does not match what was committed.
 * It never deletes or rewrites accounting history (radacct) and never retries by itself.
 */
final class RadiusProvisioner
{
    /** MySQL/MariaDB driver codes after which the rolled-back write may safely be repeated. */
    private const TRANSIENT = [1205, 1213, 2002, 2006, 2013];

    public function __construct(private readonly RadiusRepository $repo)
    {
    }

    public static function connection(): self
    {
        return new self(RadiusRepository::connection());
    }

    public function repository(): RadiusRepository
    {
        return $this->repo;
    }

    public function provision(RadiusPolicy $policy): ProvisionResult
    {
        return $this->run('provision', fn(): bool => $this->repo->applyUser($policy), function () use ($policy): bool {
            $state = $this->repo->readUser($policy->username);
            foreach ([[$policy->checkMap(), $state['check'], RadiusPolicy::MANAGED_CHECK], [$policy->replyMap(), $state['reply'], RadiusPolicy::MANAGED_REPLY]] as [$want, $have, $managed]) {
                if (array_intersect_key($have, array_flip($managed)) != $want) {
                    return false;
                }
            }
            return $policy->group === null || $state['groups'] === [$policy->group];
        });
    }

    /**
     * Restores a subscriber's managed rows exactly (WP5A rollback); SUCCESS only when the read-back equals the
     * requested rows.
     *
     * @param list<array{0:string,1:string,2:string}> $check
     * @param list<array{0:string,1:string,2:string}> $reply
     * @param list<array{0:string,1:int}>             $groups
     */
    public function restore(string $username, array $check, array $reply, array $groups): ProvisionResult
    {
        $sortRows = static function (array $rows): array {
            sort($rows);
            return $rows;
        };
        $sortGroups = static fn(array $rows): array => RadiusRepository::orderGroups($rows);
        return $this->run('restore', fn(): bool => $this->repo->restoreUser($username, $check, $reply, $groups), function () use ($username, $check, $reply, $groups, $sortRows, $sortGroups): bool {
            $s = $this->repo->snapshotUser($username);
            return $s['check'] === $sortRows($check) && $s['reply'] === $sortRows($reply) && $s['groups'] === $sortGroups($groups);
        });
    }

    public function suspend(string $username): ProvisionResult
    {
        return $this->run('suspend', fn(): int => $this->repo->suspendUser($username), null);
    }

    public function remove(string $username): ProvisionResult
    {
        return $this->run('remove', fn(): int => $this->repo->removeUser($username), function () use ($username): bool {
            $s = $this->repo->readUser($username);
            return $s['check'] === [] && $s['reply'] === [] && $s['groups'] === [];
        });
    }

    public function rename(string $from, string $to): ProvisionResult
    {
        return $this->run('rename', fn(): int => $this->repo->renameUser($from, $to), function () use ($from, $to): bool {
            $s = $this->repo->readUser($from);
            return $from === $to || ($s['check'] === [] && $s['reply'] === [] && $s['groups'] === []);
        });
    }

    /** Plan group rows (radgroupreply); a plan without a usable rate removes its managed rows. */
    public function applyPlan(int $planId, ?array $bandwidth): ProvisionResult
    {
        $rows = $bandwidth !== null ? RadiusPolicyBuilder::groupReply($bandwidth) : [];
        return $this->run('plan', fn(): bool => $this->repo->applyGroup($planId, $rows), null);
    }

    public function removePlan(int $planId): ProvisionResult
    {
        return $this->run('plan-remove', fn(): int => $this->repo->removeGroup($planId), null);
    }

    /**
     * @param callable():mixed      $write
     * @param (callable():bool)|null $verify
     */
    private function run(string $operation, callable $write, ?callable $verify): ProvisionResult
    {
        try {
            $changed = $write();
        } catch (RadiusCommitUncertain $e) {
            ErrorReporter::report($e, 'radius-provision');
            return ProvisionResult::unknown('RADIUS_COMMIT_UNKNOWN', 'The RADIUS database did not confirm the change.', null, ['operation' => $operation]);
        } catch (\PDOException $e) {
            ErrorReporter::report($e, 'radius-provision');
            $code = (int) ($e->errorInfo[1] ?? 0);
            return ProvisionResult::failed('RADIUS_DB_ERROR', 'The RADIUS database rejected the change; nothing was applied.', in_array($code, self::TRANSIENT, true), null, ['operation' => $operation]);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'radius-provision');
            return ProvisionResult::failed('RADIUS_ERROR', 'The RADIUS change could not be applied.', false, null, ['operation' => $operation]);
        }
        if ($verify !== null) {
            try {
                $ok = $verify();
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'radius-verify');
                return ProvisionResult::unknown('RADIUS_VERIFY_UNAVAILABLE', 'The RADIUS change was committed but could not be verified.', null, ['operation' => $operation]);
            }
            if (!$ok) {
                return ProvisionResult::unknown('RADIUS_VERIFY_MISMATCH', 'The RADIUS state differs from the committed change.', null, ['operation' => $operation]);
            }
        }
        return ProvisionResult::success('OK', 'Applied.', null, ['operation' => $operation, 'changed' => (bool) $changed]);
    }
}
