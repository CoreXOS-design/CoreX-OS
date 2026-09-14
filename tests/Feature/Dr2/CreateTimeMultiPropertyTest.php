<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Contact;
use App\Models\Deal;
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
 * Create-time multi-property, Johan 2026-09-14/16 — his own finding, testing
 * DR2 live: "how do I add the next property?" There was no way to, on
 * create — "Add another property" only ever existed on the edit screen, so
 * building a two-property deal meant save first (with figures that don't
 * balance, or are knowingly wrong), then add, then go back and correct
 * them. His words: "2 properties sold together makes up 1 selling price...
 * that was never the spec." This is the fix: prices/commissions for every
 * property, primary included, held client-side (see dr2/create.blade.php's
 * create-mode JS) until the ONE save, persisted together in one
 * transaction, with the exact same DealPropertyOwnerGate/
 * DealPropertyStatusService checks addProperty() already runs for edit
 * mode — never a second, looser gate for this path.
 *
 * "The 7-day grace window" and "a revive link in the decline email" — two
 * earlier designs for an UNRELATED finding that night (withdrawn/declined
 * rental-application links) — are irrelevant here; not to be confused.
 */
final class CreateTimeMultiPropertyTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $bm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'CTMP Co', 'slug' => 'ctmp-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        foreach (['access_deal_register', 'view_deals', 'create_deals', 'deals.view', 'deals.create', 'deals.edit'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        // Deliberately NOT granting create_deals to 'agent' — proves the
        // permission gap Johan asked about does NOT exist (§3 of the
        // conductor's brief), rather than assuming the config defaults hold.
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        foreach (['view_deals', 'deals.view', 'deals.create'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        PermissionService::clearCache();

        $this->bm = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);
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
            'external_id' => 'CTMP-' . Str::random(8), 'title' => $address, 'address' => $address,
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

    private function linkOwner(Property $property, Contact $contact, string $role = 'seller'): void
    {
        DB::table('contact_property')->insert([
            'property_id' => $property->id, 'contact_id' => $contact->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function basePayload(): array
    {
        return [
            'period' => '2026-09', 'deal_date' => '2026-09-14', 'branch_id' => $this->branchId,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            // persistDeal()'s own "{side} side requires at least one agent"
            // guard (not related to this feature) — every side needs a real
            // agent unless marked external.
            'listing_agents' => [$this->bm->id], 'selling_agents' => [$this->bm->id],
        ];
    }

    public function test_create_screen_renders_the_properties_section_for_a_brand_new_deal(): void
    {
        $response = $this->actingAs($this->bm)->get(route('deals-dr2.create'));

        $response->assertOk();
        $response->assertSee('Properties on this deal', false);
        $response->assertSee('Add another property', false);
        // Johan, 2026-09-16 — "why offer a search, it can be a plain
        // dropdown" — a <select>, never a text search input, on either screen.
        $response->assertSee('id="dr2cp_picker"', false);
        $response->assertDontSee('id="dr2cp_search"', false);
        // AT-flow-fix, 2026-09-19 — picking a property IS adding it; there is
        // no separate "Add to deal" confirm form to render anymore.
        $response->assertDontSee('id="dr2cp_add_form"', false);
        $response->assertDontSee('id="dr2cp_add_confirm"', false);
    }

    public function test_an_ordinary_agent_cannot_reach_the_create_screen(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true]);

        $this->actingAs($agent)->get(route('deals-dr2.create'))->assertForbidden();
    }

    public function test_a_balanced_two_property_submission_creates_both_in_one_transaction(): void
    {
        $propA = $this->makeProperty('7 Create Multi A Rd');
        $propB = $this->makeProperty('7 Create Multi B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 1_500_000, 'total_commission' => 86_250,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => 1_000_000, 'allocated_commission' => 57_500],
                ['property_id' => $propB->id, 'allocated_price' => 500_000, 'allocated_commission' => 28_750],
            ],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $deal = Deal::where('property_id', $propA->id)->latest('id')->first();
        $this->assertNotNull($deal, 'The deal must have been created.');
        $this->assertSame('1500000.00', $deal->property_value);
        $this->assertSame('86250.00', $deal->total_commission);

        $this->assertDatabaseHas('deal_properties', [
            'deal_id' => $deal->id, 'property_id' => $propA->id, 'is_primary' => 1,
            'allocated_price' => 1_000_000, 'allocated_commission' => 57_500,
        ]);
        $this->assertDatabaseHas('deal_properties', [
            'deal_id' => $deal->id, 'property_id' => $propB->id, 'is_primary' => 0,
            'allocated_price' => 500_000, 'allocated_commission' => 28_750,
        ]);
    }

    /**
     * AT-flow-fix, 2026-09-19 — SUPERSEDES the earlier design this test
     * used to guard (an independently-typed total, rejected when it
     * disagreed with the sum). Johan's correction: the master is now
     * DERIVED, never independently typed, so there is no longer a
     * competing number that COULD disagree — a submitted top-level
     * property_value/total_commission that doesn't match the properties[]
     * sum is simply overridden by DealPropertyPricingService::recalculateTotals(),
     * the same unconditional mechanism the edit screen has relied on since
     * the original split-pricing build. This proves that override, not a
     * rejection: a deliberately wrong top-level total still saves
     * successfully, and the STORED figures are the true sum, not the
     * submitted (wrong) one.
     */
    public function test_a_mismatched_submitted_total_is_overridden_by_the_true_sum_not_rejected(): void
    {
        $propA = $this->makeProperty('7b Mismatch A Rd');
        $propB = $this->makeProperty('7b Mismatch B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            // Deliberately wrong top-level totals — the parts actually sum
            // to 1,400,000 / 86,250, not the 1,500,000 / 999 submitted here.
            'property_value' => 1_500_000, 'total_commission' => 999,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => 900_000, 'allocated_commission' => 57_500],
                ['property_id' => $propB->id, 'allocated_price' => 500_000, 'allocated_commission' => 28_750],
            ],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $deal = Deal::where('property_id', $propA->id)->latest('id')->first();
        $this->assertNotNull($deal, 'The deal must save — a mismatched top-level total is no longer a save-blocking error.');
        $this->assertSame('1400000.00', $deal->property_value, 'stored figure must be the TRUE sum of the properties, not the wrong 1,500,000 submitted');
        $this->assertSame('86250.00', $deal->total_commission, 'stored figure must be the TRUE sum of the properties, not the wrong 999 submitted');
    }

    /**
     * Johan's exact real-world numbers (AT-focus-fix, 2026-09-18) — the
     * scenario that surfaced the false "commission off by R17,800" banner
     * bug in the now-superseded reconciliation design. Under the new
     * additive-master design there is nothing to reconcile: the master IS
     * the sum, so this simply saves, and the stored totals are exactly the
     * sum of the two properties.
     */
    public function test_johans_real_numbers_now_simply_save_with_the_correct_derived_totals(): void
    {
        $propA = $this->makeProperty('Unit 5694, Serenity Hills Eco Estate');
        $propB = $this->makeProperty('Unit 2 door 11 + 11A, Natspat Door, 60 Lilliecrona Boulevard');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 220_000, 'total_commission' => 20_000,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => 100_000, 'allocated_commission' => 10_000],
                ['property_id' => $propB->id, 'allocated_price' => 100_000, 'allocated_commission' => 10_000],
            ],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $deal = Deal::where('property_id', $propA->id)->latest('id')->first();
        $this->assertNotNull($deal);
        $this->assertSame('200000.00', $deal->property_value);
        $this->assertSame('20000.00', $deal->total_commission);
    }

    public function test_a_second_property_with_a_different_owner_is_refused_and_creates_nothing_at_all(): void
    {
        $propA = $this->makeProperty('7c Owner Mismatch A Rd');
        $propB = $this->makeProperty('7c Owner Mismatch B Rd');
        $this->linkOwner($propA, $this->makeContact('Steve'));
        $this->linkOwner($propB, $this->makeContact('Dave')); // different owner entirely
        $countBefore = Deal::count();

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 1_500_000, 'total_commission' => 86_250,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => 1_000_000, 'allocated_commission' => 57_500],
                ['property_id' => $propB->id, 'allocated_price' => 500_000, 'allocated_commission' => 28_750],
            ],
        ]));

        $response->assertSessionHasErrors('property_id');
        $this->assertStringContainsString('cannot sign for the transfer', (string) session('errors')->first('property_id'));
        $this->assertSame($countBefore, Deal::count(), 'The owner-gate failure must roll back the WHOLE transaction — the deal itself must never have been created, not just left without its second property.');
        $this->assertDatabaseMissing('deal_properties', ['property_id' => $propA->id]);
    }

    /**
     * The exact "31-gap" scenario the eligibility dropdown now shows as a
     * DISABLED option with a reason (PropertyEligibilityDropdownTest)
     * rather than hiding it. A disabled <option> is a client-side hint
     * only — Conductor's explicit requirement, 2026-09-16: prove the same
     * property id is refused when POSTed directly, by the same
     * DealPropertyOwnerGate the dropdown itself queries, not a second,
     * looser check. Steve owns the reference solely; Steve+Dave jointly
     * own the candidate — same seller, different owner SET.
     */
    public function test_a_property_shown_disabled_in_the_dropdown_for_a_mismatched_owner_set_is_still_refused_when_posted_directly(): void
    {
        $steve = $this->makeContact('Steve');
        $dave = $this->makeContact('Dave');
        $propA = $this->makeProperty('7e Same Seller Different Set A Rd');
        $jointlyOwned = $this->makeProperty('7e Same Seller Different Set B Rd');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($jointlyOwned, $steve);
        $this->linkOwner($jointlyOwned, $dave);
        $countBefore = Deal::count();

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 1_500_000, 'total_commission' => 86_250,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => 1_000_000, 'allocated_commission' => 57_500],
                ['property_id' => $jointlyOwned->id, 'allocated_price' => 500_000, 'allocated_commission' => 28_750],
            ],
        ]));

        $response->assertSessionHasErrors('property_id');
        $this->assertSame($countBefore, Deal::count(), 'Disabled in the dropdown is a UI hint only — the gate itself must still refuse this id and roll back the whole transaction when posted directly.');
        $this->assertDatabaseMissing('deal_properties', ['property_id' => $propA->id]);
    }

    public function test_an_ordinary_single_property_submission_is_completely_unaffected(): void
    {
        $propA = $this->makeProperty('7d Single Unaffected Rd');
        $this->linkOwner($propA, $this->makeContact('Steve'));

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            // No `properties[]` at all — the ordinary, overwhelmingly common path.
        ]));

        $response->assertSessionDoesntHaveErrors();
        $deal = Deal::where('property_id', $propA->id)->latest('id')->first();
        $this->assertNotNull($deal);
        $this->assertSame('1000000.00', $deal->property_value);
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propA->id, 'is_primary' => 1]);
    }
}
