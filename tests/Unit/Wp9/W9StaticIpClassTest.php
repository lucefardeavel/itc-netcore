<?php
namespace Reseller\Tests\Unit\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\CustomerIdentity;

/**
 * R-HF-CARRY-01 round 3, review N-1: CustomerIdentity::validStaticIp accepted every well-formed IPv4 address except
 * 0.0.0.0, so a static IP of 255.255.255.255 was saved and handed out as Framed-IP-Address. RFC 2865 §5.8 defines
 * 0xFFFFFFFF as "the NAS should allow the user to select an address" (and 0xFFFFFFFE as "the NAS should select one"): the
 * subscriber could then take any address, also another customer's static IP. 0/8, loopback, link-local, multicast and
 * reserved (240/4, incl. the broadcast address) also break the session or the NAS route table.
 *
 * Required: these classes are refused; private RFC 1918 and CGNAT 100.64/10 (normal ISP subscriber addressing) and public
 * unicast addresses stay accepted, as do '' (none). The format rules of round 2 are unchanged.
 */
final class W9StaticIpClassTest extends TestCase
{
    /** @return array<string,list<string>> refused class => addresses (both ends of each range and an inside value) */
    private static function refused(): array
    {
        return [
            '0.0.0.0/8 (this network)' => ['0.0.0.0', '0.0.0.1', '0.1.2.3', '0.255.255.255'],
            '127.0.0.0/8 (loopback)' => ['127.0.0.0', '127.0.0.1', '127.10.20.30', '127.255.255.255'],
            '169.254.0.0/16 (link-local)' => ['169.254.0.0', '169.254.0.1', '169.254.169.254', '169.254.255.255'],
            '224.0.0.0/4 (multicast)' => ['224.0.0.0', '224.0.0.1', '232.1.2.3', '239.255.255.255'],
            '240.0.0.0/4 (reserved)' => ['240.0.0.0', '240.0.0.1', '250.1.2.3', '255.255.255.254'],
            '255.255.255.255 (broadcast; RFC 2865 "user selects")' => ['255.255.255.255'],
        ];
    }

    /** @return array<string,list<string>> accepted class => addresses */
    private static function accepted(): array
    {
        return [
            'none' => [''],
            '10.0.0.0/8 (RFC 1918)' => ['10.0.0.1', '10.66.0.3', '10.255.255.254'],
            '172.16.0.0/12 (RFC 1918)' => ['172.16.0.1', '172.20.30.40', '172.31.255.254'],
            '192.168.0.0/16 (RFC 1918)' => ['192.168.0.1', '192.168.1.100', '192.168.255.254'],
            '100.64.0.0/10 (CGNAT, RFC 6598)' => ['100.64.0.1', '100.100.100.100', '100.127.255.254'],
            'public unicast' => ['1.0.0.1', '8.8.8.8', '185.10.20.30', '223.255.255.254'],
            'neighbours of the refused ranges' => ['1.0.0.0', '126.255.255.255', '128.0.0.1', '169.253.255.255', '169.255.0.1', '223.255.255.255'],
        ];
    }

    public function testSpecialAndReservedAddressesAreRefused(): void
    {
        $accepted = [];
        foreach (self::refused() as $class => $ips) {
            foreach ($ips as $ip) {
                if (CustomerIdentity::validStaticIp($ip)) {
                    $accepted[] = "{$class}: {$ip}";
                }
            }
        }
        $this->assertSame([], $accepted, 'special / reserved IPv4 addresses must not be valid static IPs');
    }

    public function testSubscriberRangesStayAccepted(): void
    {
        $refused = [];
        foreach (self::accepted() as $class => $ips) {
            foreach ($ips as $ip) {
                if (!CustomerIdentity::validStaticIp($ip)) {
                    $refused[] = "{$class}: {$ip}";
                }
            }
        }
        $this->assertSame([], $refused, 'private, CGNAT and public unicast addresses are normal static IPs');
    }

    public function testTheFormatRulesAreUnchanged(): void
    {
        foreach (['not-an-ip', '10.0.0.256', '10.0.0.1/24', '010.0.0.1', ' 10.0.0.1', '10.0.0.1 ', "10.0.0.1\t", '10.0.0', '::1', '::ffff:10.0.0.1', '10.0.0.1"><x-ip>'] as $ip) {
            $this->assertFalse(CustomerIdentity::validStaticIp($ip), 'refused: ' . json_encode($ip));
        }
    }
}
