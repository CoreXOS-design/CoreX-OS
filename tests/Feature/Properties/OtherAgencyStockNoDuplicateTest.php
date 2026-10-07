<?php

namespace Tests\Feature\Properties;

use App\Http\Controllers\CoreX\PropertyController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §5b — 2026-10-07 (Johan, QA1 property 21174): the
 * Duplicate action was live on an Other Agency Stock property. It can never be
 * duplicated: the button is disabled with a reason, AND every server path that
 * clones a property refuses it (hiding the button alone is not the fix).
 */
class OtherAgencyStockNoDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_properties', 'agency_id' => $this->agency->id]);
    }

    private function property(string $status): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->agent->id,
            'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Listing ' . Str::random(4),
            'suburb' => 'Uvongo',
            'property_type' => 'house',
            'status' => $status,
            'price' => 1500000,
            'beds' => 2, 'baths' => 1, 'garages' => 0,
            'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]);
    }

    private function propertyRows(): int
    {
        return Property::withoutGlobalScopes()->withTrashed()->count();
    }

    public function test_duplicate_is_refused_on_other_agency_stock_and_nothing_is_created(): void
    {
        $p = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $before = $this->propertyRows();

        $this->actingAs($this->agent)
            ->from(route('corex.properties.show', $p))
            ->post(route('corex.properties.duplicate', $p), ['target_type' => 'sale'])
            ->assertRedirect(route('corex.properties.show', $p))
            ->assertSessionHas('error', Property::OTHER_AGENCY_STOCK_NO_DUPLICATE_REASON);

        $this->assertSame($before, $this->propertyRows(), 'no clone may exist');
    }

    public function test_cross_type_duplicate_is_refused_too(): void
    {
        $p = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $before = $this->propertyRows();

        $this->actingAs($this->agent)->post(route('corex.properties.duplicate', $p), ['target_type' => 'rental'])
            ->assertSessionHas('error');

        $this->assertSame($before, $this->propertyRows());
    }

    public function test_api_style_caller_gets_a_403_with_the_reason(): void
    {
        $p = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $before = $this->propertyRows();

        $this->actingAs($this->agent)
            ->postJson(route('corex.properties.duplicate', $p), ['target_type' => 'sale'])
            ->assertStatus(403)
            ->assertJson(['message' => Property::OTHER_AGENCY_STOCK_NO_DUPLICATE_REASON]);

        $this->assertSame($before, $this->propertyRows());
    }

    public function test_change_type_is_refused_and_the_original_is_not_archived(): void
    {
        $p = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $before = $this->propertyRows();

        $this->actingAs($this->agent)->post(route('corex.properties.change-type', $p))
            ->assertSessionHas('error', Property::OTHER_AGENCY_STOCK_NO_DUPLICATE_REASON);

        $this->assertSame($before, $this->propertyRows());
        $this->assertNull(Property::withoutGlobalScopes()->withTrashed()->find($p->id)->deleted_at, 'the original stays');
        $this->assertSame(Property::STATUS_OTHER_AGENCY_STOCK, Property::withoutGlobalScopes()->find($p->id)->status);
    }

    public function test_the_clone_builder_itself_refuses_so_no_future_caller_can_copy_it(): void
    {
        $p = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $m = new \ReflectionMethod(PropertyController::class, 'makeClone');
        $m->setAccessible(true);

        $this->expectException(\DomainException::class);
        $m->invoke(app(PropertyController::class), $p, 'sale');
    }

    public function test_there_is_no_other_route_that_duplicates_a_property(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => (string) $r->getName())
            ->filter(fn ($n) => str_contains($n, 'propert') && (str_contains($n, 'duplicate') || str_contains($n, 'clone') || str_contains($n, 'copy')))
            ->values()->all();

        $this->assertSame(['corex.properties.duplicate'], $names, 'a new property-cloning route must also refuse Other Agency Stock — extend this guard');
    }

    public function test_a_normal_property_still_duplicates(): void
    {
        $p = $this->property('active');
        $before = $this->propertyRows();

        $this->actingAs($this->agent)->post(route('corex.properties.duplicate', $p), ['target_type' => 'sale'])
            ->assertSessionHas('success');

        $this->assertSame($before + 1, $this->propertyRows());
    }

    public function test_the_button_is_disabled_with_a_reason_on_other_agency_stock_and_live_on_a_normal_property(): void
    {
        $oas = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $resp = $this->actingAs($this->agent)->get(route('corex.properties.show', $oas));
        $resp->assertOk();
        $resp->assertSee('can&#039;t be duplicated', false);
        $resp->assertDontSee('Duplicate as');

        $normal = $this->property('active');
        $resp2 = $this->actingAs($this->agent)->get(route('corex.properties.show', $normal));
        $resp2->assertOk();
        $resp2->assertSee('Duplicate as');
        $resp2->assertDontSee('can&#039;t be duplicated', false);
    }
}
