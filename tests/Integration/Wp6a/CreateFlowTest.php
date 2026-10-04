<?php
namespace Reseller\Tests\Integration\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6A create subscriber: one form and one POST (customers/add-post -> CustomerService). Owner / Sales choices follow
 * the actor's create scope (Admin: owner + that owner's Sales; Agent: its Sales; Sales: none, server-assigned);
 * forged ownership is refused or ignored by the accepted server rules; Arabic names are counted in characters; the flow
 * continues on the new subscriber's 360 with "Activate now" only when the actor may activate it.
 */
final class CreateFlowTest extends Wp2HttpTestCase
{
    private function create(string $actor, array $extra = []): array
    {
        $u = 'wp6a' . bin2hex(random_bytes(3));
        $r = $this->staff($actor)->postWithToken('customers/add-post', $extra + [
            'username' => $u, 'fullname' => 'Create Flow ' . $u, 'password' => 'Pw-' . $u, 'service_type' => 'Hotspot', 'account_type' => 'Personal',
        ]);
        $row = static::$db->rows('SELECT * FROM tbl_customers WHERE username = ?', [$u])[0] ?? null;
        return [$r, $row];
    }

    public function testFormOffersOnlyTheActorsOwnershipChoices(): void
    {
        $sid = fn(string $u): int => $this->sid($u);
        $admin = $this->staff('ad')->get('customers/add')->body;
        $this->assertStringContainsString('name="manager_id"', $admin);
        $this->assertMatchesRegularExpression('#<optgroup label="A" data-owner="' . $sid('A') . '">.*value="' . $sid('A1') . '".*value="' . $sid('A2') . '".*</optgroup>#s', $admin, 'Sales grouped by owner');
        $agent = $this->staff('A')->get('customers/add')->body;
        $this->assertStringNotContainsString('name="manager_id"', $agent);
        $this->assertStringContainsString('name="assigned_sales_id"', $agent);
        $this->assertStringNotContainsString('value="' . $sid('C1') . '"', $agent, "no other tree's Sales");
        $sales = $this->staff('A1')->get('customers/add')->body;
        $this->assertStringNotContainsString('name="manager_id"', $sales);
        $this->assertStringNotContainsString('name="assigned_sales_id"', $sales);
        $this->assertStringContainsString('assigned to you', $sales);
        foreach ([$admin, $agent, $sales] as $html) {
            $this->assertStringContainsString('data-nuxr-steps', $html, 'phone stepper');
            $this->assertSame(4, substr_count($html, 'data-nuxr-step '), 'four steps');
            $this->assertStringNotContainsStringIgnoringCase('unpkg.com/leaflet@1.9.3/dist/leaflet.js', $html, 'no eager map library');
            $this->assertStringNotContainsString('google.com/vt', preg_replace('#function nuxrInitCreateMap.*#s', '', $html), 'no eager tiles');
        }
    }

    public function testOwnershipIsResolvedByTheServerAndForgeriesFail(): void
    {
        [$r, $row] = $this->create('ad', ['manager_id' => $this->sid('A'), 'assigned_sales_id' => $this->sid('A1')]);
        $this->assertSame([$this->sid('A'), $this->sid('A1')], [(int) $row['manager_id'], (int) $row['assigned_sales_id']]);
        $this->assertMatchesRegularExpression('#customers/view/' . $row['id'] . '&created=1#', (string) $r->location(), 'continues on the new 360');
        [, $row] = $this->create('ad', ['manager_id' => '']);
        $this->assertNull($row['manager_id'], 'Admin may create an unassigned legacy customer');
        [, $row] = $this->create('ad', ['manager_id' => $this->sid('A'), 'assigned_sales_id' => $this->sid('C1')]);
        $this->assertNull($row, "another owner's Sales is refused");
        [, $row] = $this->create('A', ['manager_id' => $this->sid('C'), 'assigned_sales_id' => $this->sid('A2')]);
        $this->assertSame([$this->sid('A'), $this->sid('A2')], [(int) $row['manager_id'], (int) $row['assigned_sales_id']], 'Agent: the forged owner is ignored');
        [, $row] = $this->create('A', ['assigned_sales_id' => $this->sid('C1')]);
        $this->assertNull($row, "Agent: another tree's Sales is refused");
        [, $row] = $this->create('A1', ['manager_id' => $this->sid('C'), 'assigned_sales_id' => $this->sid('A2')]);
        $this->assertSame([$this->sid('A'), $this->sid('A1')], [(int) $row['manager_id'], (int) $row['assigned_sales_id']], 'Sales: parent owner, assigned to itself');
    }

    public function testArabicNamesAndTheActivateNowPrompt(): void
    {
        $name = 'فاطمة الزهراء علي حسن الموسوي'; // 29 characters, 54 bytes
        [, $row] = $this->create('A', ['fullname' => $name]);
        $this->assertNotNull($row, 'an Arabic name is counted in characters');
        $this->assertSame($name, $row['fullname']);
        [, $tooLong] = $this->create('A', ['fullname' => str_repeat('ب', 46)]);
        $this->assertNull($tooLong, 'over the 45-character column');

        $created = $this->staff('A')->get('customers/view/' . $row['id'], ['created' => 1])->body;
        $this->assertStringContainsString('Subscriber created', $created);
        $this->assertStringContainsString('activation/create&customer=' . $row['id'], $created, 'Activate now for an eligible owner');
        [, $legacy] = $this->create('ad', ['manager_id' => '']);
        $legacyPage = $this->staff('ad')->get('customers/view/' . $legacy['id'], ['created' => 1])->body;
        $this->assertStringContainsString('Subscriber created', $legacyPage);
        $this->assertStringNotContainsString('activation/create&customer=', $legacyPage, 'no reseller activation for a legacy customer');
        // an unenrolled Agent may not create (accepted WP2 rule): the route is denied and the shell offers no link
        $this->assertDenied($this->staff('E')->get('customers/add'), 'unenrolled Agent');
        $this->assertStringNotContainsString('_route=customers/add', $this->staff('E')->get('dashboard')->body);
    }
}
