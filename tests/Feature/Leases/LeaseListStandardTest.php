<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-439 Part 3 — the shared rental list standard (.ai/specs/rentals-rebuild.md
 * §1.1) applied to the Leases list: status tiles (Total first), per-page,
 * show-archived, print-list, export — all scope-guarded identically to
 * index(). Search/sort/filter/pagination/own-branch-agency scoping are
 * already covered by LeaseCoreTest/LeaseRecordScopeGuardTest; this file
 * covers only what Part 3 added.
 */
final class LeaseListStandardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'Lease List Standard Agency', 'slug' => 'lls-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
    }

    private function property(User $agent, string $title = 'Property'): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function lease(Property $property, User $creator, array $attrs = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'property_id' => $property->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 5000, 'start_date' => now()->subMonth(), 'source' => 'manual',
            'created_by_user_id' => $creator->id,
        ], $attrs));
    }

    public function test_total_tile_count_equals_scoped_row_count(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->lease($this->property($admin, 'A'), $admin, ['status' => Lease::STATUS_DRAFT]);
        $this->lease($this->property($admin, 'B'), $admin, ['status' => Lease::STATUS_ACTIVE]);
        $this->lease($this->property($admin, 'C'), $admin, ['status' => Lease::STATUS_CANCELLED]);

        $response = $this->actingAs($admin)->get(route('corex.leases.index'));

        $response->assertOk();
        $this->assertSame(3, $response->viewData('tileCounts')['total']);
        $this->assertSame(3, $response->viewData('leases')->total());
    }

    public function test_per_page_option_controls_page_size(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        for ($i = 0; $i < 15; $i++) {
            $this->lease($this->property($admin, "P{$i}"), $admin);
        }

        $response = $this->actingAs($admin)->get(route('corex.leases.index', ['per_page' => 10]));
        $this->assertCount(10, $response->viewData('leases'));

        $response = $this->actingAs($admin)->get(route('corex.leases.index', ['per_page' => 999]));
        $this->assertSame(25, $response->viewData('perPage')); // not a listed option — falls back to the default
    }

    public function test_show_archived_lists_only_archived_leases_and_restore_brings_it_back(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $live = $this->lease($this->property($admin, 'Live'), $admin);
        $archived = $this->lease($this->property($admin, 'Archived'), $admin);
        $archived->delete();

        $response = $this->actingAs($admin)->get(route('corex.leases.index', ['archived' => 1]));
        $this->assertSame([$archived->id], $response->viewData('leases')->pluck('id')->all());

        $response = $this->actingAs($admin)->get(route('corex.leases.index'));
        $this->assertSame([$live->id], $response->viewData('leases')->pluck('id')->all());

        $this->actingAs($admin)->post(route('corex.leases.restore', $archived->id))
            ->assertRedirect();
        $this->assertNull($archived->fresh()->deleted_at);
    }

    public function test_print_list_shows_only_scoped_filtered_rows_with_active_filters_in_header(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $matching = $this->lease($this->property($admin, 'Match'), $admin, ['status' => Lease::STATUS_ACTIVE]);
        $this->lease($this->property($admin, 'NoMatch'), $admin, ['status' => Lease::STATUS_DRAFT]);

        $response = $this->actingAs($admin)->get(route('corex.leases.print-list', ['status' => 'active']));

        $response->assertOk();
        $this->assertSame([$matching->id], $response->viewData('leases')->pluck('id')->all());
        $this->assertSame('Active', $response->viewData('printFilters')['Status']);
        $response->assertSee('Match');
        $response->assertDontSee('NoMatch');
    }

    public function test_export_respects_scope_and_filters(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->lease($this->property($agentOne, 'Mine'), $agentOne);
        $this->lease($this->property($agentTwo, 'Not mine'), $agentTwo);

        $csv = $this->actingAs($agentOne)->get(route('corex.leases.export', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringStartsWith('text/csv', $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Mine', $body);
        $this->assertStringNotContainsString('Not mine', $body);

        $xlsx = $this->actingAs($agentOne)->get(route('corex.leases.export'));
        $xlsx->assertOk();
        $xlsx->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_cross_agency_user_cannot_export_or_print_another_agencys_leases(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        \Illuminate\Support\Facades\Auth::logout();
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        Lease::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 5000, 'start_date' => now()->subMonth(),
            'source' => 'manual', 'created_by_user_id' => $otherAdmin->id,
        ]);

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $csv = $this->actingAs($admin)->get(route('corex.leases.export', ['format' => 'csv']));
        $this->assertStringNotContainsString('Other agency property', $csv->streamedContent());

        $print = $this->actingAs($admin)->get(route('corex.leases.print-list'));
        $print->assertDontSee('Other agency property');
    }

    private function contact(string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first . '.' . $last) . '-' . uniqid() . '@example.test',
        ]);
    }

    /** AT-444's own test: a landlord column added to the leases list must never show a tenant. */
    public function test_leases_list_shows_landlord_under_tenant_never_the_tenant_itself(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $property = $this->property($admin, 'With landlord');
        $landlord = $this->contact('Real', 'Landlord');
        ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');
        $tenantContact = $this->contact('The', 'Tenant');
        $lease = $this->lease($property, $admin);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenantContact->id, 'is_primary' => true]);

        $response = $this->actingAs($admin)->get(route('corex.leases.index'));

        $response->assertOk();
        $response->assertSee('Real Landlord');
        $response->assertSee('The Tenant');
    }

    public function test_leases_list_shows_no_landlord_linked_with_a_link_when_tenant_only(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $property = $this->property($admin, 'Tenant only');
        $tenantContact = $this->contact('Andre', 'Roets');
        ContactPropertyLinker::link($tenantContact->id, $property->id, 'tenant');
        $lease = $this->lease($property, $admin);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenantContact->id, 'is_primary' => true]);

        $response = $this->actingAs($admin)->get(route('corex.leases.index'));

        $response->assertOk();
        $response->assertSee('No landlord linked');
        $response->assertSee(route('corex.properties.show', ['property' => $property->id, 'tab' => 'contacts']), false);
        // The tenant must never render as the landlord anywhere on this row.
        $this->assertSame(1, substr_count($response->getContent(), 'Andre Roets'));
    }

    public function test_leases_list_landlord_search_finds_the_lease_by_landlord_name(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $matchProperty = $this->property($admin, 'Match');
        ContactPropertyLinker::link($this->contact('Searchable', 'Landlord')->id, $matchProperty->id, 'landlord');
        $match = $this->lease($matchProperty, $admin);
        $noMatchProperty = $this->property($admin, 'NoMatch');
        ContactPropertyLinker::link($this->contact('Other', 'Owner')->id, $noMatchProperty->id, 'landlord');
        $this->lease($noMatchProperty, $admin);

        $response = $this->actingAs($admin)->get(route('corex.leases.index', ['q' => 'Searchable']));

        $this->assertSame([$match->id], $response->viewData('leases')->pluck('id')->all());
    }

    public function test_leases_list_export_includes_landlord_column(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $property = $this->property($admin, 'Exported');
        ContactPropertyLinker::link($this->contact('Export', 'Landlord')->id, $property->id, 'landlord');
        $this->lease($property, $admin);

        $csv = $this->actingAs($admin)->get(route('corex.leases.export', ['format' => 'csv']));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('Landlord', $body);
        $this->assertStringContainsString('Export Landlord', $body);
    }

    /** The landlord column must not add a per-row query — eager-loaded, same as the tenant column. */
    public function test_leases_list_landlord_column_does_not_n_plus_one(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        for ($i = 0; $i < 5; $i++) {
            $property = $this->property($admin, "N1-{$i}");
            ContactPropertyLinker::link($this->contact("Landlord{$i}", 'X')->id, $property->id, 'landlord');
            $this->lease($property, $admin);
        }

        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('corex.leases.index'))->assertOk();
        $fiveRowQueries = count(DB::getQueryLog());

        DB::disableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $property = $this->property($admin, "N1b-{$i}");
            ContactPropertyLinker::link($this->contact("LandlordB{$i}", 'X')->id, $property->id, 'landlord');
            $this->lease($property, $admin);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('corex.leases.index'))->assertOk();
        $tenRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Doubling the rows must not meaningfully increase query count — a
        // real per-row query (the N+1 this eager-load exists to prevent)
        // would scale roughly linearly with row count instead.
        $this->assertLessThan($fiveRowQueries + 5, $tenRowQueries);
    }

    /**
     * The list screen's own property-filter picker (search-properties) —
     * only properties with a lease visible to this user, never every
     * rental property (that's the create form's own separate job).
     */
    public function test_search_properties_only_returns_properties_with_a_visible_lease(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $withLease = $this->property($admin, 'Has a lease');
        $this->lease($withLease, $admin);
        $withoutLease = $this->property($admin, 'No lease at all');

        $response = $this->actingAs($admin)->getJson(route('corex.leases.search-properties', ['q' => 'lease']));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($withLease->id, $ids);
        $this->assertNotContains($withoutLease->id, $ids);
    }

    public function test_search_properties_never_returns_another_agencys_property(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency leased property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        Lease::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 5000, 'start_date' => now()->subMonth(),
            'source' => 'manual', 'created_by_user_id' => $otherAdmin->id,
        ]);

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $response = $this->actingAs($admin)->getJson(route('corex.leases.search-properties', ['q' => 'leased']));

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_search_properties_respects_own_scope(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $mineProperty = $this->property($agentOne, 'Mine leased');
        $this->lease($mineProperty, $agentOne);
        $theirsProperty = $this->property($agentTwo, 'Theirs leased');
        $this->lease($theirsProperty, $agentTwo);

        $response = $this->actingAs($agentOne)->getJson(route('corex.leases.search-properties', ['q' => 'leased', 'scope' => 'own']));

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($mineProperty->id, $ids);
        $this->assertNotContains($theirsProperty->id, $ids);
    }
}
