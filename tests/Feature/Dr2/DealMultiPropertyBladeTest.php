<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-398 — Blade-level proof for the on-screen "Properties on this deal" list
 * (resources/views/dr2/create.blade.php, edit mode). Renders the real edit
 * page through the full HTTP/middleware/Blade stack (no interactive browser
 * available in this environment — see the AT-398 report for what a real
 * browser pass would still need to confirm: client-side search/sort/filter
 * JS actually firing). This proves: the list renders, the empty state shows
 * for a properties-less deal, the multi-property sum + read-only main-form
 * fields appear once a second property exists, the archived/restore section
 * renders for a removed property, and a same-owner refusal flashed into the
 * session surfaces in plain language on reload.
 */
final class DealMultiPropertyBladeTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $bm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Blade Co', 'slug' => 'blade-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        foreach (['access_deal_register', 'view_deals', 'create_deals', 'deals.view', 'deals.create', 'deals.edit'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        PermissionService::clearCache();

        $this->bm = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function makeProperty(string $address): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'BL-' . Str::random(8), 'title' => $address, 'address' => $address,
            'agent_id' => $agent->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'listing_type' => 'sale', 'status' => 'for_sale',
        ]));
    }

    private function makeContact(string $first): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'first_name' => $first, 'last_name' => 'Owner', 'phone' => '0' . random_int(600000000, 699999999),
        ]);
    }

    private function linkOwner(Property $property, Contact $contact): void
    {
        DB::table('contact_property')->insert([
            'property_id' => $property->id, 'contact_id' => $contact->id, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeDeal(Property $property): Deal
    {
        return Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(5000, 5999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $property->id, 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);
    }

    public function test_a_single_property_deal_shows_the_empty_multi_property_add_prompt_and_editable_price(): void
    {
        $property = $this->makeProperty('1 Blade Solo Rd');
        $this->linkOwner($property, $this->makeContact('Steve'));
        $deal = $this->makeDeal($property);

        $response = $this->actingAs($this->bm)->get(route('deals-dr2.edit', $deal));

        $response->assertOk();
        $response->assertSee('Properties on this deal (1)');
        $response->assertSee('Primary', false);
        $response->assertSee('Add another property');
        // Only one property — the main Selling Price field must stay editable.
        $response->assertDontSee('This deal has more than one property');
    }

    public function test_a_multi_property_deal_shows_the_sum_and_read_only_main_price_fields(): void
    {
        $propA = $this->makeProperty('2 Blade Multi A Rd');
        $propB = $this->makeProperty('2 Blade Multi B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA);
        DealProperty::create(['deal_id' => $deal->id, 'property_id' => $propB->id, 'is_primary' => false, 'allocated_price' => 500000, 'allocated_commission' => 28750]);
        app(\App\Services\Deal\DealPropertyPricingService::class)->recalculateTotals($deal->fresh());

        $response = $this->actingAs($this->bm)->get(route('deals-dr2.edit', $deal));

        $response->assertOk();
        $response->assertSee('Properties on this deal (2)');
        $response->assertSee('This deal has more than one property');
        $response->assertSee('2 Blade Multi B Rd');
        // Sum rendered into the (now read-only) Selling Price field.
        $response->assertSee('value="1500000.00"', false);
        $response->assertSee('readonly', false);
        // Sort/filter controls only appear once there is more than one row.
        $response->assertSee('dr2mp_filter', false);
    }

    public function test_a_removed_property_shows_in_the_archived_restore_section(): void
    {
        $propA = $this->makeProperty('3 Blade Archive A Rd');
        $propB = $this->makeProperty('3 Blade Archive B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA);
        $row = DealProperty::create(['deal_id' => $deal->id, 'property_id' => $propB->id, 'is_primary' => false, 'allocated_price' => 500000, 'allocated_commission' => 28750]);
        $row->delete(); // soft

        $response = $this->actingAs($this->bm)->get(route('deals-dr2.edit', $deal));

        $response->assertOk();
        $response->assertSee('Removed properties (1)');
        $response->assertSee('Restore');
    }

    public function test_same_owner_refusal_flashed_from_the_add_action_surfaces_in_plain_language_on_reload(): void
    {
        $propA = $this->makeProperty('4 Blade Refusal A Rd');
        $propB = $this->makeProperty('4 Blade Refusal B Rd');
        $steve = $this->makeContact('Steve');
        $dave = $this->makeContact('Dave');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $this->linkOwner($propB, $dave);
        $deal = $this->makeDeal($propA);

        // Trigger the real refusal via the real action (back()->withErrors() flashes
        // to session), then load the edit page — the SAME test-client session picks
        // up that one-time flash exactly as a real browser would on the redirect.
        $this->actingAs($this->bm)->post(route('deals-dr2.properties.add', $deal), [
            'property_id' => $propB->id, 'allocated_price' => 100, 'allocated_commission' => 10,
        ]);
        $response = $this->get(route('deals-dr2.edit', $deal));

        $response->assertOk();
        $response->assertSee('Dave');
        $response->assertSee('separate deal');
        $this->assertDatabaseMissing('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id]);
    }
}
