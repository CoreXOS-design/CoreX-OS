<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 5 verification for .ai/specs/rental-work-orders.md §3a.5/§6a —
 * Johan's own reason for the whole feature: "geyser in month 7... an agent
 * can see what damages there were... and what was not repaired." This is
 * the piece that makes an out-inspection judgeable against real history,
 * not a tidy work-order screen for its own sake.
 *
 * The two facts this suite exists to prove:
 * 1. The attached block is scoped by LEASE, not by property (§3a.4) — a
 *    PREVIOUS tenancy's fault report must NOT appear, in deliberate
 *    contrast to rental_inspection_items' own property-wide carry-forward.
 * 2. It is attached, not merged — RentalInspection::tabPayloadFor()'s
 *    existing keys (items, in_inspection, out_inspection) are completely
 *    unaffected; this is purely an additional key alongside them.
 */
final class OutInspectionFaultHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'Out-Inspection History Agency', 'slug' => 'oi-history-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function lease(array $attrs = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function faultReport(Lease $lease, array $attrs = []): RentalFaultReport
    {
        return RentalFaultReport::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Geyser burst', 'description' => 'x', 'status' => RentalFaultReport::STATUS_RESOLVED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'outcome' => RentalFaultReport::OUTCOME_REPAIRED,
            'repaired_at' => now()->subMonths(2), 'reported_at' => now()->subMonths(3), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    public function test_attached_history_shows_only_this_tenancys_fault_reports_not_a_previous_tenants(): void
    {
        $previousLease = $this->lease(['status' => Lease::STATUS_CANCELLED, 'start_date' => now()->subYear()]);
        $previousFault = $this->faultReport($previousLease, ['title' => 'Previous tenant\'s fault']);

        $currentLease = $this->lease();
        $currentFault = $this->faultReport($currentLease, ['title' => 'Current tenant\'s fault']);

        $outInspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $currentLease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'created_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $response->assertOk();
        $history = collect($response->json('out_inspection_fault_history'));

        $this->assertTrue($history->contains('id', $currentFault->id), 'Current tenancy\'s fault report must appear');
        $this->assertFalse($history->contains('id', $previousFault->id), 'A PREVIOUS tenancy\'s fault report must NOT appear — §3a.4');
    }

    public function test_attached_history_carries_outcome_and_repaired_at_the_spine_fields(): void
    {
        $lease = $this->lease();
        $faultReport = $this->faultReport($lease);
        RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'created_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $entry = collect($response->json('out_inspection_fault_history'))->firstWhere('id', $faultReport->id);
        $this->assertNotNull($entry);
        $this->assertSame('repaired', $entry['outcome']);
        $this->assertNotNull($entry['repaired_at']);
    }

    public function test_history_is_empty_without_an_open_out_inspection_not_an_error(): void
    {
        $lease = $this->lease();
        $this->faultReport($lease);
        // No RentalInspection created at all.

        $response = $this->actingAs($this->admin)->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $response->assertOk();
        $this->assertSame([], $response->json('out_inspection_fault_history'));
    }

    public function test_attaching_history_does_not_disturb_the_existing_tab_payload_keys(): void
    {
        $lease = $this->lease();
        RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'created_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $response->assertOk()->assertJsonStructure(['items', 'in_inspection', 'out_inspection', 'out_inspection_fault_history']);
        $this->assertNull($response->json('in_inspection'));
        $this->assertNotNull($response->json('out_inspection'));
    }
}
