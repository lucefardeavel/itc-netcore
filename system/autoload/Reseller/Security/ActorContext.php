<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\SchemaReadiness;

/**
 * Server-resolved identity of the current principal (WP2 §4). Built once per request from the
 * authoritative database rows — never from cached session role values. Fails closed: an error while
 * building the context yields a "denied" context with no capabilities for every role.
 */
final class ActorContext
{
    public const STAFF = 'STAFF';
    public const CUSTOMER = 'CUSTOMER';
    public const SYSTEM = 'SYSTEM';
    public const ANONYMOUS = 'ANONYMOUS';

    private static ?self $current = null;

    /** @param list<array{id:int,depth:int,role:string,status:string}> $ancestors */
    private function __construct(
        public readonly string $type,
        public readonly string $channel,
        public readonly ?int $userId = null,
        public readonly ?string $role = null,
        public readonly ?string $status = null,
        public readonly ?string $username = null,
        public readonly int $rootId = 0,
        public readonly ?int $customerId = null,
        public readonly bool $denied = false,
        public readonly bool $enrolled = false,
        public readonly bool $frozen = false,
        public readonly bool $inconsistent = false,
        public readonly ?int $managerId = null,
        public readonly array $ancestors = [],
        public readonly bool $schemaReady = false,
        public readonly ?int $customerManagerId = null,
    ) {
    }

    /**
     * Construction from already verified values (CLI tools and tests). Web/API requests always use
     * forStaff()/forCustomer() with the authoritative database rows.
     *
     * @param array<string,mixed> $v
     */
    public static function make(array $v): self
    {
        return new self(
            (string) ($v['type'] ?? self::ANONYMOUS),
            (string) ($v['channel'] ?? 'CLI'),
            userId: isset($v['userId']) ? (int) $v['userId'] : null,
            role: $v['role'] ?? null,
            status: $v['status'] ?? null,
            username: $v['username'] ?? null,
            rootId: (int) ($v['rootId'] ?? 0),
            customerId: isset($v['customerId']) ? (int) $v['customerId'] : null,
            denied: (bool) ($v['denied'] ?? false),
            enrolled: (bool) ($v['enrolled'] ?? false),
            frozen: (bool) ($v['frozen'] ?? false),
            inconsistent: (bool) ($v['inconsistent'] ?? false),
            managerId: isset($v['managerId']) ? (int) $v['managerId'] : null,
            ancestors: (array) ($v['ancestors'] ?? []),
            schemaReady: (bool) ($v['schemaReady'] ?? false),
        );
    }

    public static function current(): self
    {
        return self::$current ??= self::anonymous(self::defaultChannel());
    }

    public static function setCurrent(self $ctx): void
    {
        self::$current = $ctx;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    public static function anonymous(string $channel): self
    {
        return new self(self::ANONYMOUS, $channel);
    }

    public static function system(string $channel = 'CLI'): self
    {
        return new self(self::SYSTEM, $channel);
    }

    public static function denied(string $channel): self
    {
        return new self(self::ANONYMOUS, $channel, denied: true);
    }

    /**
     * Builds the staff context from the authoritative tbl_users row (already re-read this request).
     * Inactive or missing users never get here (Admin::getID() rejects them); a non-Active status is
     * nevertheless treated as frozen (defense in depth).
     */
    public static function forStaff(array $row, string $channel): self
    {
        try {
            $id = (int) $row['id'];
            $role = (string) $row['user_type'];
            $status = (string) $row['status'];
            $root = (int) ($row['root'] ?? 0);
            $ready = SchemaReadiness::isReady();
            $ancestors = [];
            $enrolled = false;
            $inconsistent = false;
            $managerId = null;
            if ($ready && in_array($role, ['Agent', 'Sales'], true)) {
                $st = \ORM::get_db()->prepare(
                    'SELECT t.ancestor_id, t.depth, u.user_type, u.status FROM tbl_manager_tree t '
                    . 'JOIN tbl_users u ON u.id = t.ancestor_id WHERE t.descendant_id = ? ORDER BY t.depth'
                );
                $st->execute([$id]);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $a) {
                    $ancestors[] = ['id' => (int) $a['ancestor_id'], 'depth' => (int) $a['depth'], 'role' => (string) $a['user_type'], 'status' => (string) $a['status']];
                }
                $enrolled = $ancestors !== [] && $ancestors[0]['depth'] === 0 && $ancestors[0]['id'] === $id;
                $parent = null;
                foreach ($ancestors as $a) {
                    if ($a['depth'] === 1) {
                        $parent = $a;
                    }
                }
                if ($enrolled) {
                    // closure must agree with the root compatibility field
                    if (($parent === null && $root !== 0) || ($parent !== null && $parent['id'] !== $root)) {
                        $inconsistent = true;
                    }
                    if ($role === 'Agent') {
                        $managerId = $id;
                        if ($parent !== null && $parent['role'] !== 'Agent') {
                            $inconsistent = true;
                        }
                    } else {
                        if ($parent === null || $parent['role'] !== 'Agent') {
                            $inconsistent = true;
                        } else {
                            $managerId = $parent['id'];
                        }
                    }
                }
            }
            $frozen = $status !== 'Active';
            foreach ($ancestors as $a) {
                if ($a['depth'] > 0 && $a['status'] !== 'Active') {
                    $frozen = true;
                }
            }
            return new self(
                self::STAFF,
                $channel,
                userId: $id,
                role: $role,
                status: $status,
                username: (string) $row['username'],
                rootId: $root,
                enrolled: $enrolled,
                frozen: $frozen,
                inconsistent: $inconsistent,
                managerId: $managerId,
                ancestors: $ancestors,
                schemaReady: $ready,
            );
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'actor-context');
            return self::denied($channel);
        }
    }

    public static function forCustomer(array $row, string $channel): self
    {
        try {
            return new self(
                self::CUSTOMER,
                $channel,
                username: (string) $row['username'],
                status: (string) ($row['status'] ?? ''),
                customerId: (int) $row['id'],
                schemaReady: SchemaReadiness::isReady(),
                customerManagerId: isset($row['manager_id']) ? (int) $row['manager_id'] : null,
            );
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'actor-context');
            return self::denied($channel);
        }
    }

    public function isStaff(): bool
    {
        return $this->type === self::STAFF && !$this->denied;
    }

    public function isCustomer(): bool
    {
        return $this->type === self::CUSTOMER && !$this->denied;
    }

    public function isRole(string ...$roles): bool
    {
        return $this->isStaff() && in_array($this->role, $roles, true);
    }

    public function isGlobalAdmin(): bool
    {
        return $this->isRole('SuperAdmin', 'Admin');
    }

    /** Mutations require an active, non-frozen, consistent staff principal. */
    public function canMutate(): bool
    {
        return $this->isStaff() && $this->status === 'Active' && !$this->frozen && !$this->inconsistent;
    }

    public function auditActorType(): string
    {
        return match ($this->type) {
            self::STAFF => 'STAFF',
            self::CUSTOMER => 'CUSTOMER',
            default => $this->channel === 'CLI' ? 'CRON' : 'SYSTEM',
        };
    }

    /** Identity snapshot for audit evidence (no secrets). */
    public function snapshot(): array
    {
        return array_filter([
            'type' => $this->type,
            'channel' => $this->channel,
            'id' => $this->userId ?? $this->customerId,
            'username' => $this->username,
            'role' => $this->role,
            'status' => $this->status,
            'root' => $this->type === self::STAFF ? $this->rootId : null,
            'manager_id' => $this->managerId,
            'enrolled' => $this->type === self::STAFF ? $this->enrolled : null,
            'frozen' => $this->type === self::STAFF ? $this->frozen : null,
        ], static fn($v) => $v !== null);
    }

    private static function defaultChannel(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'CLI';
        }
        return !empty($GLOBALS['isApi']) ? 'API' : 'WEB';
    }
}
