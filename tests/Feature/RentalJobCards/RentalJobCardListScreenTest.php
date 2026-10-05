<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two items against the Job Cards list screen (/corex/rental-job-cards):
 *
 * Item 1 — the list had no "New Job Card" button at all (every sibling
 * list — Fault Reports, Work Orders — has one); added, permission-gated
 * like the existing create route.
 *
 * Item 3 — the list's own property-filter picker (new — the list
 * previously had no property_id filter of any kind): only properties with
 * a job card visible to this user, never every rental property (the
 * unscoped picker stays on the create screen, unaffected).
 */
final class RentalJobCardListScreenTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RJC List Screen Agency', 'slug' => 'rjcls-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
    }

    private function property(User $agent, string $title = 'Property'): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    // ── Item 1 — the "New Job Card" button ──────────────────────────────

    public function test_list_screen_shows_new_job_card_button_when_permitted(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('corex.rental-job-cards.index'));

        $response->assertOk();
        $response->assertSee('New Job Card');
        $response->assertSee(route('corex.rental-job-cards.create'), false);
    }

    public function test_list_screen_hides_new_job_card_button_without_create_permission(): void
    {
        Role::create(['name' => 'viewer_only', 'label' => 'Viewer only', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'viewer_only', 'permission_key' => 'rental_job_cards.view', 'agency_id' => $this->agency->id],
            ['scope' => 'all'],
        );
        PermissionService::clearCache();
        $viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer_only']);

        $response = $this->actingAs($viewer)->get(route('corex.rental-job-cards.index'));

        $response->assertOk();
        $response->assertDontSee('New Job Card');
    }

    // ── Item 3 — the list screen's own property-filter picker ──────────

    public function test_search_properties_only_returns_properties_with_a_visible_job_card(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $withJobCard = $this->property($admin, 'Has a job card');
        app(RentalJobCardService::class)->createForProperty($withJobCard, ['title' => 'Fix the geyser'], $admin);
        $withoutJobCard = $this->property($admin, 'No job card at all');

        $response = $this->actingAs($admin)->getJson(route('corex.rental-job-cards.search-properties', ['q' => 'job card']));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($withJobCard->id, $ids);
        $this->assertNotContains($withoutJobCard->id, $ids);
    }

    public function test_search_properties_never_returns_another_agencys_property(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency job-carded property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        app(RentalJobCardService::class)->createForProperty($otherProperty, ['title' => 'Fix something'], $otherAdmin);

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $response = $this->actingAs($admin)->getJson(route('corex.rental-job-cards.search-properties', ['q' => 'job-carded']));

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_property_id_filter_narrows_the_list_to_that_property(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $propertyA = $this->property($admin, 'A');
        $jobCardA = app(RentalJobCardService::class)->createForProperty($propertyA, ['title' => 'Fix A'], $admin);
        $propertyB = $this->property($admin, 'B');
        app(RentalJobCardService::class)->createForProperty($propertyB, ['title' => 'Fix B'], $admin);

        $response = $this->actingAs($admin)->get(route('corex.rental-job-cards.index', ['property_id' => $propertyA->id]));

        $response->assertOk();
        $this->assertSame([$jobCardA->id], $response->viewData('jobCards')->pluck('id')->all());
    }
}
