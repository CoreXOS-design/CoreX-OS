<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultReports;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 Part 3 — the shared rental list standard (.ai/specs/rentals-rebuild.md
 * §1.1) applied to Rental Fault Reports: Total tile, per-page, show-archived
 * (new — the list previously had no way to browse an archived fault
 * report), print-list, export. Search/sort/filter/pagination/scoping are
 * already covered by RentalFaultReportListScreenTest.
 */
final class RentalFaultReportListStandardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RFR List Standard Agency', 'slug' => 'rfrls-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
    }

    private function property(User $agent, string $title = 'Property'): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
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

    public function test_total_tile_count_equals_scoped_row_count(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->faultReport($this->property($admin, 'A'), $admin, ['status' => RentalFaultReport::STATUS_REPORTED]);
        $this->faultReport($this->property($admin, 'B'), $admin, ['status' => RentalFaultReport::STATUS_RESOLVED]);

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.index'));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('tileCounts')['total']);
        $this->assertSame(2, $response->viewData('faultReports')->total());
    }

    public function test_per_page_option_controls_page_size(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        for ($i = 0; $i < 15; $i++) {
            $this->faultReport($this->property($admin, "P{$i}"), $admin);
        }

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.index', ['per_page' => 10]));
        $this->assertCount(10, $response->viewData('faultReports'));
    }

    public function test_show_archived_lists_only_archived_fault_reports_and_restore_brings_it_back(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $live = $this->faultReport($this->property($admin, 'Live'), $admin);
        $archived = $this->faultReport($this->property($admin, 'Archived'), $admin);
        $archived->delete();

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.index', ['archived' => 1]));
        $this->assertSame([$archived->id], $response->viewData('faultReports')->pluck('id')->all());

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.index'));
        $this->assertSame([$live->id], $response->viewData('faultReports')->pluck('id')->all());

        $this->actingAs($admin)->post(route('corex.rental-fault-reports.restore', $archived->id))
            ->assertRedirect();
        $this->assertNull($archived->fresh()->deleted_at);
    }

    public function test_print_list_shows_only_scoped_filtered_rows_with_active_filters_in_header(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $matching = $this->faultReport($this->property($admin, 'Match'), $admin, ['status' => RentalFaultReport::STATUS_RESOLVED]);
        $this->faultReport($this->property($admin, 'NoMatch'), $admin, ['status' => RentalFaultReport::STATUS_REPORTED]);

        $response = $this->actingAs($admin)->get(route('corex.rental-fault-reports.print-list', ['status' => 'resolved']));

        $response->assertOk();
        $this->assertSame([$matching->id], $response->viewData('faultReports')->pluck('id')->all());
        $this->assertSame('Resolved', $response->viewData('printFilters')['Status']);
    }

    public function test_export_respects_scope_and_filters(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->faultReport($this->property($agentOne, 'Mine'), $agentOne);
        $this->faultReport($this->property($agentTwo, 'Not mine'), $agentTwo);

        $csv = $this->actingAs($agentOne)->get(route('corex.rental-fault-reports.export', ['format' => 'csv']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Mine', $body);
        $this->assertStringNotContainsString('Not mine', $body);
    }

    // ── Item 2 — context bar on the show page ───────────────────────────

    public function test_show_page_includes_the_context_bar_with_correct_faults_count(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $property = $this->property($admin, 'Context bar property');
        $first = $this->faultReport($property, $admin);
        $this->faultReport($property, $admin);

        $this->actingAs($admin)->get(route('corex.rental-fault-reports.show', $first))
            ->assertOk()
            ->assertSee('Faults (2)', false);
    }
}
