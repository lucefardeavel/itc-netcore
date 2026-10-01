<?php
namespace Reseller\Tests\Unit\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\DomainError;
use Reseller\Finance\FinanceActor;
use Reseller\Security\ActorContext;

/**
 * Owner correction: the ledger actor is constructed explicitly. CLI and API are channels, not
 * identities; WP3A never derives CRON from PHP_SAPI and never emits actor_type API.
 */
final class FinanceActorTest extends TestCase
{
    private static function staff(string $channel): ActorContext
    {
        return ActorContext::make(['type' => ActorContext::STAFF, 'channel' => $channel, 'userId' => 7, 'role' => 'Admin', 'status' => 'Active', 'schemaReady' => true]);
    }

    private static function customer(string $channel): ActorContext
    {
        return ActorContext::make(['type' => ActorContext::CUSTOMER, 'channel' => $channel, 'customerId' => 42, 'schemaReady' => true]);
    }

    public function testStaffStaysStaffOnEveryChannel(): void
    {
        foreach (['WEB', 'API', 'CLI'] as $channel) {
            $a = FinanceActor::staff(self::staff($channel));
            $this->assertSame('STAFF', $a->type, "staff via {$channel}");
            $this->assertSame(7, $a->operatorId);
            $this->assertNull($a->customerId);
        }
        $this->assertSame('cli', PHP_SAPI, 'this very test runs on the CLI and still gets STAFF');
    }

    public function testCustomerStaysCustomerOnEveryChannel(): void
    {
        foreach (['WEB', 'API', 'CLI'] as $channel) {
            $a = FinanceActor::customer(self::customer($channel));
            $this->assertSame('CUSTOMER', $a->type);
            $this->assertSame(42, $a->customerId);
            $this->assertNull($a->operatorId);
        }
    }

    public function testSystemAndCronAreExplicit(): void
    {
        $this->assertSame('SYSTEM', FinanceActor::system('activation-service')->type);
        $this->assertSame('CRON', FinanceActor::cron('autorenew')->type);
        foreach (['', 'X', 'bad name', str_repeat('a', 80)] as $label) {
            try {
                FinanceActor::system($label);
                $this->fail('label accepted');
            } catch (DomainError $e) {
                $this->assertSame('INVALID_ACTOR', $e->reason);
            }
        }
    }

    public function testWrongPrincipalsAreRefused(): void
    {
        $cases = [
            [fn() => FinanceActor::staff(ActorContext::system('CLI')), 'a CLI system context is not a staff principal'],
            [fn() => FinanceActor::staff(ActorContext::anonymous('API')), 'anonymous'],
            [fn() => FinanceActor::staff(ActorContext::denied('WEB')), 'denied'],
            [fn() => FinanceActor::staff(self::customer('API')), 'customer as staff'],
            [fn() => FinanceActor::customer(self::staff('API')), 'staff as customer'],
            [fn() => FinanceActor::customer(ActorContext::system('CLI')), 'system as customer'],
        ];
        foreach ($cases as [$fn, $label]) {
            try {
                $fn();
                $this->fail($label);
            } catch (DomainError $e) {
                $this->assertSame('INVALID_ACTOR', $e->reason, $label);
            }
        }
    }

    public function testThereIsNoApiOrInferredFactory(): void
    {
        $methods = array_map(static fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(FinanceActor::class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC));
        $statics = array_values(array_filter($methods, static fn(string $m) => (new \ReflectionMethod(FinanceActor::class, $m))->isStatic()));
        sort($statics);
        $this->assertSame(['cron', 'customer', 'staff', 'system'], $statics);
        $this->assertFalse((new \ReflectionClass(FinanceActor::class))->getConstructor()->isPublic());
        $this->assertArrayNotHasKey('API', (new \ReflectionClass(FinanceActor::class))->getConstants());
    }
}
