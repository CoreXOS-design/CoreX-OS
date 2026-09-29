<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultReports;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1 verification for .ai/specs/rental-work-orders.md §3a/§6a. Same
 * BUILD_STANDARD §1a-§1d floor as RentalInspectionListScreenTest: search,
 * sort, filter, pagination, empty state, OWN/BRANCH/AGENCY scoping enforced
 * at the query layer. Same unseeded-role_permissions AT-265 fallback
 * ('admin' -> 'all', 'branch_manager' -> 'branch', 'agent' -> 'own') is
 * exercised here, without seeding real grants.
 */
final class RentalFaultReportListScreenTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RFR List Agency', 'slug' => 'rfr-list-' . uniqid()]);
        $this->branchA = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->branchB = Branch::forceCreate(['name' => 'Margate', 'agency_id' => $this->agency->id]);
    }

    private function property(Branch $branch, string $address, User $agent): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => $address, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function faultReport(Property $property, User $creator, array $attrs = []): RentalFaultReport
    {
        return RentalFaultReport::create(array_merge([
            'agency_id' => $this->agency->id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $creator->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'captured_by_user_id' => $creator->id,
            'title' => 'Fault',
            'description' => 'Something is wrong.',
            'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(),
            'created_by_user_id' => $creator->id,
        ], $attrs));
    }

    // ── Search ────────────────────────────────────────────────────────

    public function test_search_matches_property_address_and_title(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $this->faultReport($this->property($this->branchA, '19 Windsor Avenue', $admin), $admin, ['title' => 'Geyser burst']);
        $this->faultReport($this->property($this->branchA, 'Somewhere else', $admin), $admin, ['title' => 'Leaking tap']);

        $this->actingAs($admin)->get(route('corex.rental-fault-reports.index', ['q' => 'Windsor']))
            ->assertOk()->assertSee('Windsor Avenue');
        $this->actingAs($admin)->get(route('corex.rental-fault-reports.index', ['q' => 'Geyser']))
            ->assertOk()->assertSee('Windsor Avenue');
    }

    // ── Sort ──────────────────────────────────────────────────────────

    public function test_default_sort_is_reported_at_most_recent_first(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $older = $this->faultReport($this->property($this->branchA, 'Older', $admin), $admin, ['reported_at' => now()->subDays(10)]);
        $newer = $this->faultReport($this->property($this->branchA, 'Newer', $admin), $admin, ['reported_at' => now()->subDay()]);

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.index'));

        $response->assertOk();
        $ids = $response->viewData('faultReports')->pluck('id')->all();
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    // ── Filter ────────────────────────────────────────────────────────

    public function test_status_and_outcome_filters_narrow_the_list(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $resolved = $this->faultReport($this->property($this->branchA, 'A', $admin), $admin, [
            'status' => RentalFaultReport::STATUS_RESOLVED, 'outcome' => RentalFaultReport::OUTCOME_REPAIRED,
        ]);
        $this->faultReport($this->property($this->branchA, 'B', $admin), $admin, ['status' => RentalFaultReport::STATUS_REPORTED]);

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.index', ['status' => 'resolved']));
        $this->assertSame([$resolved->id], $response->viewData('faultReports')->pluck('id')->all());

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.index', ['outcome' => 'repaired']));
        $this->assertSame([$resolved->id], $response->viewData('faultReports')->pluck('id')->all());
    }

    // ── Pagination & empty state ─────────────────────────────────────

    public function test_paginates_at_twenty_five(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        for ($i = 0; $i < 30; $i++) {
            $this->faultReport($this->property($this->branchA, "Property {$i}", $admin), $admin);
        }

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.index'));
        $this->assertCount(25, $response->viewData('faultReports'));
        $this->assertSame(30, $response->viewData('faultReports')->total());
    }

    public function test_empty_state_distinguishes_none_yet_from_none_matching_filter(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);

        $this->actingAs($admin)->get(route('corex.rental-fault-reports.index'))
            ->assertOk()->assertSee('No fault reports yet on this agency');

        $this->faultReport($this->property($this->branchA, 'Only one', $admin), $admin, ['status' => RentalFaultReport::STATUS_RESOLVED]);

        $this->actingAs($admin)->get(route('corex.rental-fault-reports.index', ['status' => 'cancelled']))
            ->assertOk()->assertSee('No fault reports match this search or filter');
    }

    // ── Scoping ──────────────────────────────────────────────────────

    public function test_agent_sees_only_own_created_fault_reports(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $own = $this->faultReport($this->property($this->branchA, 'Mine', $agentOne), $agentOne);
        $this->faultReport($this->property($this->branchA, 'Not mine', $agentTwo), $agentTwo);

        $response = $this->actingAs($agentOne)->get(route('corex.rental-fault-reports.index'));
        $this->assertSame([$own->id], $response->viewData('faultReports')->pluck('id')->all());
    }

    public function test_branch_manager_sees_branch_but_not_other_branch(): void
    {
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'branch_manager']);
        $agentSameBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentOtherBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchB->id, 'role' => 'agent']);

        $inBranch = $this->faultReport($this->property($this->branchA, 'Branch A property', $agentSameBranch), $agentSameBranch);
        $this->faultReport($this->property($this->branchB, 'Branch B property', $agentOtherBranch), $agentOtherBranch);

        $response = $this->actingAs($manager)->get(route('corex.rental-fault-reports.index'));
        $this->assertSame([$inBranch->id], $response->viewData('faultReports')->pluck('id')->all());
    }

    // ── Show / cancel / archive / restore ──────────────────────────────

    public function test_show_renders_the_fault_report(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $property = $this->property($this->branchA, 'Detail property', $admin);
        $faultReport = $this->faultReport($property, $admin, ['title' => 'Cracked tile']);

        $this->actingAs($admin)->get(route('corex.rental-fault-reports.show', $faultReport))
            ->assertOk()->assertSee('Cracked tile');
    }

    public function test_cancel_requires_a_reason_and_stamps_the_cancelling_user(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $faultReport = $this->faultReport($this->property($this->branchA, 'Cancel me', $admin), $admin);

        $this->actingAs($admin)->post(route('corex.rental-fault-reports.cancel', $faultReport), [])->assertSessionHasErrors();

        $this->actingAs($admin)->post(route('corex.rental-fault-reports.cancel', $faultReport), ['cancel_reason' => 'Logged in error.'])
            ->assertRedirect(route('corex.rental-fault-reports.show', $faultReport));

        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_CANCELLED, $faultReport->status);
        $this->assertSame($admin->id, $faultReport->cancelled_by_user_id);
        $this->assertSame('Logged in error.', $faultReport->cancel_reason);
    }

    public function test_a_fault_report_with_a_photo_cannot_be_deleted_only_cancelled(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $faultReport = $this->faultReport($this->property($this->branchA, 'Has evidence', $admin), $admin);
        \App\Models\RentalFaultReportPhoto::create([
            'agency_id' => $this->agency->id, 'rental_fault_report_id' => $faultReport->id,
            'storage_path' => '/fake.jpg', 'uploaded_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->delete(route('corex.rental-fault-reports.destroy', $faultReport))->assertSessionHasErrors();
        $this->assertNull($faultReport->fresh()->deleted_at);
    }

    public function test_a_fault_report_with_no_evidence_can_be_archived_and_restored(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $faultReport = $this->faultReport($this->property($this->branchA, 'No evidence yet', $admin), $admin);

        $this->actingAs($admin)->delete(route('corex.rental-fault-reports.destroy', $faultReport))
            ->assertRedirect(route('corex.rental-fault-reports.index'));
        $this->assertNotNull($faultReport->fresh()->deleted_at);

        $this->actingAs($admin)->post(route('corex.rental-fault-reports.restore', $faultReport->id))
            ->assertRedirect(route('corex.rental-fault-reports.show', $faultReport));
        $this->assertNull($faultReport->fresh()->deleted_at);
    }

    public function test_cross_agency_fault_report_is_not_reachable_by_id(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        \Illuminate\Support\Facades\Auth::logout();
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherFaultReport = RentalFaultReport::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $otherAdmin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $otherAdmin->id,
            'title' => 'Other agency fault', 'description' => 'x', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $otherAdmin->id,
        ]);

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);

        $this->actingAs($admin)->get(route('corex.rental-fault-reports.show', $otherFaultReport))->assertNotFound();
    }
}
