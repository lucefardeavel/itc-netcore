<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * N2 / TA-02 (lab stand-in, RouterOS CHR 7.24.5): the RouterOS clock against the host (the application / database / FreeRADIUS clock of the
 * lab). Records the NAS clock, its zone and NTP client state and asserts the operational limit of docs/TIME_CONTRACT.md: |offset| <= 60 s
 * (the 5 s limit between application, database and FreeRADIUS is the preflight's). It does NOT prove that a production router runs NTP:
 * that is TA-02 on the target.
 */
final class V1ClockLabTest extends TestCase
{
    use Wp8LabHelpers;

    protected function setUp(): void
    {
        $this->requireLab();
    }

    public function testTheNasClockIsWithinTheOperationalLimit(): void
    {
        $before = microtime(true);
        $clock = Wp8Lab::ros('/system/clock/print')[0] ?? [];
        $after = microtime(true);
        $this->assertArrayHasKey('date', $clock, json_encode($clock));
        $zone = (string) ($clock['time-zone-name'] ?? $clock['time-zone-autodetect'] ?? '');
        // RouterOS prints local time in its own zone: convert with the zone it reports (UTC when "UTC" / "manual" offset 0)
        $tz = $zone !== '' && in_array($zone, timezone_identifiers_list(), true) ? new \DateTimeZone($zone) : new \DateTimeZone('UTC');
        $nas = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->isoDate((string) $clock['date']) . ' ' . $clock['time'], $tz);
        $this->assertNotFalse($nas, json_encode($clock));
        $offset = $nas->getTimestamp() - (int) round(($before + $after) / 2);
        $ntp = [];
        try {
            $ntp = Wp8Lab::ros('/system/ntp/client/print')[0] ?? [];
        } catch (\Throwable $e) {
            $ntp = ['error' => 'ntp client not readable'];
        }
        Wp8Lab::evidence('V1.clock-discipline', ['nas_clock' => $clock['date'] . ' ' . $clock['time'], 'nas_zone' => $zone, 'offset_s' => $offset, 'ntp_client' => $ntp]);
        $this->assertLessThanOrEqual(60, abs($offset), 'the NAS clock is within the 60 s operational limit of the time contract');
    }

    /** RouterOS prints "oct/18/2026" (or "2026-10-18"): the ISO date */
    private function isoDate(string $d): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $d;
        }
        $x = \DateTimeImmutable::createFromFormat('M/d/Y', ucfirst(strtolower($d)), new \DateTimeZone('UTC'));
        return $x ? $x->format('Y-m-d') : $d;
    }
}
