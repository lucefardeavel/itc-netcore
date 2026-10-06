<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp8Lab.php';

use Reseller\Radius\AccountingService;
use Reseller\Radius\CoAClient;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;

/**
 * Shared WP8 real-NAS test helpers (lab runner only): the lab RADIUS database, policy provisioning for nuxr_wp8_*
 * identities, accounting reads, FreeRADIUS reply evidence and client cleanup. Development/test only.
 */
trait Wp8LabHelpers
{
    protected static ?\PDO $radius = null;

    protected static function labRadius(): ?\PDO
    {
        return Wp8Lab::enabled() ? (self::$radius ??= Wp8Lab::radiusPdo()) : null;
    }

    protected function requireLab(): void
    {
        if (static::labRadius() === null) {
            $this->markTestIncomplete('WP8 real-NAS lab not running (dev/wp8/lab.sh up; dev/wp8/lab.sh test)');
        }
    }

    protected function releaseClients(): void
    {
        if (static::labRadius() === null) {
            return;
        }
        foreach (['c1', 'c3'] as $c) {
            Wp8Lab::agent('pppoe_down', ['client' => $c]);
        }
        foreach (['c2', 'c4'] as $c) {
            Wp8Lab::agent('hs_logout', ['client' => $c]);
        }
    }

    protected static function ident(string $what): string
    {
        return 'nuxr_wp8_' . $what . '_' . bin2hex(random_bytes(3));
    }

    /** @param array<string,mixed> $plan */
    protected function provisionPolicy(array $customer, array $plan, ?array $bw, \DateTimeImmutable $expiry, ?\DateTimeImmutable $period = null): string
    {
        $plan += ['id' => random_int(100000, 999999), 'typebp' => 'Unlimited', 'shared_users' => 1, 'pool' => ''];
        $policy = RadiusPolicyBuilder::build($customer, $plan, $bw, $expiry, $period);
        $r = (new RadiusProvisioner(new RadiusRepository(static::labRadius())))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    /** @return list<array<string,mixed>> radacct rows of $user, oldest first */
    protected function acct(string $user): array
    {
        $st = static::labRadius()->prepare('SELECT radacctid, acctsessionid, acctuniqueid, username, nasipaddress, nasportid, nasporttype, framedipaddress, callingstationid, acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, acctterminatecause FROM radacct WHERE username = ? ORDER BY radacctid');
        $st->execute([$user]);
        return $st->fetchAll();
    }

    protected function coa(): CoAClient
    {
        return new CoAClient(new AccountingService(static::labRadius()), new RadiusRepository(static::labRadius()));
    }

    /**
     * The reply attributes FreeRADIUS sent in its LAST Access-Accept / Access-Reject for $user (debug log, redacted).
     * @return array{code:?string, attrs:array<string,string>}
     */
    protected static function lastReply(string $user): array
    {
        $lines = Wp8Lab::frLog('^\(\d+\) ');
        $access = [];
        $req = null;
        foreach ($lines as $l) {
            if (preg_match('/^\((\d+)\) Received Access-Request /', $l, $m)) {
                $access[$m[1]] = true;
            } elseif (preg_match('/^\((\d+)\)\s+User-Name = "' . preg_quote($user, '/') . '"$/', $l, $m) && isset($access[$m[1]])) {
                $req = $m[1];
            }
        }
        $code = null;
        $attrs = [];
        if ($req !== null) {
            $in = false;
            foreach ($lines as $l) {
                if (preg_match('/^\(' . $req . '\) Sent (Access-\w+) Id/', $l, $m)) {
                    $code = $m[1];
                    $in = true;
                    $attrs = [];
                    continue;
                }
                if ($in && preg_match('/^\(' . $req . '\)\s+([A-Za-z][\w-]+) = (.*)$/', $l, $m)) {
                    $attrs[$m[1]] = trim($m[2], '"');
                } elseif ($in && str_starts_with($l, '(' . $req . ')')) {
                    $in = false;
                }
            }
        }
        return ['code' => $code, 'attrs' => $attrs];
    }

    /** proves no local RouterOS subscriber can mask RADIUS */
    protected function assertNoLocalSubscribers(): void
    {
        $this->assertSame([], Wp8Lab::ros('/ppp/secret/print'), 'no local PPP secret');
        $this->assertSame(['default-trial'], array_column(Wp8Lab::ros('/ip/hotspot/user/print'), 'name'), 'only the fixed, inert trial entry');
    }
}
