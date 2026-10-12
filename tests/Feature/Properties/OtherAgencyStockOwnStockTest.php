<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CommandTask;
use App\Models\Property;
use App\Models\PropertyExternalSource;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Compliance\PpraMandateRegisterService;
use App\Services\Properties\OtherAgencyStockImportService;
use App\Services\Prospecting\OnMarketStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-453 — .ai/specs/other-agency-stock.md §12a. Other Agency Stock is another agency's listing:
 * it is allowed ONLY in Core Matches and viewing packs / share links, so it is never counted as,
 * or worked on as, the agency's own stock. One definition: Property::scopeOwnStock().
 */
class OtherAgencyStockOwnStockTest extends TestCase
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

        // Same grants as OtherAgencyStockTest: once any grant exists the permission service really enforces.
        foreach (['agent', 'branch_manager'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'access_properties', 'agency_id' => $this->agency->id]);
        }
    }

    private function make(string $status, array $extra = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->agent->id,
            'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Listing ' . Str::random(4),
            'suburb' => 'Uvongo',
            'property_type' => 'house',
            'listing_type' => 'sale',
            'status' => $status,
            'price' => 1500000,
            'beds' => 2, 'baths' => 1, 'garages' => 0,
            'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ], $extra));
    }

    private function oasWithPortalLink(): Property
    {
        $p = $this->make(Property::STATUS_OTHER_AGENCY_STOCK);
        PropertyExternalSource::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id,
            'portal' => 'p24', 'listing_ref' => '117580701',
            'listing_url' => 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6217/117580701',
            'imported_by_user_id' => $this->agent->id, 'imported_at' => now(),
        ]);

        return $p;
    }

    // ── the one definition ────────────────────────────────────────────────

    public function test_own_stock_scope_leaves_out_only_other_agency_stock(): void
    {
        $active = $this->make('active');
        $draft = $this->make('draft');
        $oas = $this->make(Property::STATUS_OTHER_AGENCY_STOCK);

        $ids = Property::withoutGlobalScopes()->ownStock()->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertContains($draft->id, $ids);
        $this->assertNotContains($oas->id, $ids);
        $this->assertTrue($active->isOwnStock());
        $this->assertFalse($oas->fresh()->isOwnStock());
    }

    // ── the Properties list: tiles, filter, option ────────────────────────

    public function test_total_and_on_market_tiles_never_count_other_agency_stock_and_it_has_its_own_tile(): void
    {
        $this->make('active');
        $this->make(Property::STATUS_OTHER_AGENCY_STOCK);
        $this->make(Property::STATUS_OTHER_AGENCY_STOCK);

        $r = $this->actingAs($this->agent)->get(route('corex.properties.index'))->assertOk();
        $stats = $r->viewData('stats');

        $this->assertSame(1, $stats['total'], 'Total is our own stock only');
        $this->assertSame(1, $stats['active'], 'On Market is our own stock only');
        $this->assertSame(2, $stats['otherAgency']);
        $r->assertSee('Other agency');
    }

    public function test_on_market_filter_leaves_other_agency_stock_out(): void
    {
        $active = $this->make('active');
        $oas = $this->make(Property::STATUS_OTHER_AGENCY_STOCK);

        $r = $this->actingAs($this->agent)->get(route('corex.properties.index', ['status' => 'on_market']))->assertOk();
        $ids = $r->viewData('properties')->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($oas->id, $ids);
    }

    public function test_status_option_filters_to_other_agency_stock_and_shows_a_chip_and_clear_all(): void
    {
        $active = $this->make('active');
        $oas = $this->make(Property::STATUS_OTHER_AGENCY_STOCK);

        $r = $this->actingAs($this->agent)
            ->get(route('corex.properties.index', ['status' => Property::STATUS_OTHER_AGENCY_STOCK]))->assertOk();
        $ids = $r->viewData('properties')->pluck('id')->all();

        $this->assertSame([$oas->id], $ids);
        $r->assertSee('<option value="other_agency_stock" selected>Other Agency Stock</option>', false);
        $r->assertSee('Status: Other Agency Stock');
        $r->assertSee('clear=1', false);   // "Clear all"

        // And the dropdown offers it (unselected) when another status is chosen.
        $this->actingAs($this->agent)->get(route('corex.properties.index', ['status' => 'on_market']))
            ->assertSee('<option value="other_agency_stock" >Other Agency Stock</option>', false);
    }

    public function test_option_and_tile_are_absent_where_the_agency_hides_other_agency_stock_from_the_role(): void
    {
        $this->agency->update(['other_agency_stock_visible_roles' => ['branch_manager']]);
        $this->make(Property::STATUS_OTHER_AGENCY_STOCK);

        $r = $this->actingAs($this->agent)->get(route('corex.properties.index'))->assertOk();

        $this->assertFalse($r->viewData('showOtherAgencyStock'));
        $r->assertDontSee('value="other_agency_stock"', false);
        $r->assertDontSee('Other agency');
    }

    // ── the card: one photo box ───────────────────────────────────────────

    public function test_the_portal_link_is_not_nested_inside_the_photo_link(): void
    {
        $this->oasWithPortalLink();

        $html = $this->actingAs($this->agent)->get(route('corex.properties.index'))->assertOk()->getContent();

        // The photo link: from its opening tag to the FIRST closing </a>. A second <a inside it is the
        // invalid nesting that made the browser paint a second, pale photo box.
        $this->assertSame(1, preg_match('/<a href="[^"]*"[^>]*class="relative block h-40[^>]*>(.*?)<\/a>/s', $html, $m));
        $this->assertStringNotContainsString('<a ', $m[1], 'no link inside the photo link');

        // The portal link is still drawn — once, as a sibling beside the syndication button.
        $this->assertSame(1, substr_count($html, 'data-oas-portal-link'));
        $this->assertStringContainsString('property24.com/for-sale/uvongo', $html);
    }

    // ── the import: no mandate chase tasks ───────────────────────────────

    public function test_an_import_creates_no_mandate_chase_tasks_but_an_ordinary_property_still_does(): void
    {
        $imported = app(OtherAgencyStockImportService::class)->import([
            'portal' => 'pp', 'listing_ref' => 'T88888',
            'listing_url' => 'https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate/T88888',
            'consent' => true, 'price' => 1500000, 'property_type' => 'house', 'beds' => 3, 'baths' => 2,
            'suburb' => 'Uvongo', 'description' => 'A lovely home',
            'source_agency_name' => 'Seeff Hibiscus Coast', 'source_agent_name' => 'Jane Agent',
        ], $this->agent);

        $this->assertSame(0, CommandTask::withoutGlobalScopes()->where('property_id', $imported->id)->where('source_type', 'automation_rule')->count());

        // Control: the same observer still chases an ordinary new listing, so the zero above means something.
        $own = $this->make('draft');
        $this->assertGreaterThan(0, CommandTask::withoutGlobalScopes()->where('property_id', $own->id)->where('source_type', 'automation_rule')->count());
    }

    // ── registers and counts ──────────────────────────────────────────────

    public function test_the_ppra_mandate_register_does_not_list_other_agency_stock(): void
    {
        $own = $this->make('active', ['address' => '1 Own Street']);
        $oas = $this->make(Property::STATUS_OTHER_AGENCY_STOCK, ['address' => '2 Other Street']);

        $rows = app(PpraMandateRegisterService::class)->search($this->agency, ['advertised_only' => '0']);
        $ids = collect($rows->items())->map(fn ($row) => $row->property->id)->all();

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($oas->id, $ids);
    }

    public function test_the_mic_in_stock_count_is_our_own_stock_only(): void
    {
        $this->make('active');
        $this->make(Property::STATUS_OTHER_AGENCY_STOCK);

        $svc = app(OnMarketStockService::class);

        $this->assertSame(1, $svc->totalCount($this->agency->id));
        $this->assertSame(['Uvongo' => 1], $svc->countBySuburb($this->agency->id));
    }

    public function test_core_matches_and_viewing_packs_still_see_other_agency_stock(): void
    {
        // Untouched on purpose (spec §2): on-market, and in the Core Matches allow-list.
        $oas = $this->make(Property::STATUS_OTHER_AGENCY_STOCK);

        $this->assertContains(Property::STATUS_OTHER_AGENCY_STOCK, Property::coreMatchAllowedStatuses($this->agency->id));
        $this->assertTrue(Property::withoutGlobalScopes()->onMarket()->whereKey($oas->id)->exists());
    }
}
