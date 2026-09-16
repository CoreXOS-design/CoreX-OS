<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
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
 * Prod-promotion audit 2026-09-16 (audit-commission-and-misc.md §3, audit-core-
 * matches-dr2.md M1) — money integrity of the DR2 EDIT form once a deal carries
 * more than one property, and of a plain price edit on a single-property deal:
 *
 *  A2/A3  swapping the PRIMARY via the edit form on a 2+-property deal is refused
 *         (it would land an unpriced pivot row and skip the same-owner gate);
 *  A7     the edit form's posted Selling Price / Commission are ignored on a
 *         2+-property deal — the totals are re-derived from the rows;
 *  M1     a price-only edit on a single-property deal mirrors onto its pivot
 *         row, so a later add/remove does not resurrect the old figure;
 *  A10    every re-sum reaches the DR2 twin (deals_v2) so a later loud save on
 *         the V2 side cannot write a stale commission back over it.
 *
 * Every scenario goes through the REAL HTTP endpoints — nothing here does by
 * hand what production does not do.
 */
final class DealEditFormMultiPropertyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $bm;
    private User $listingAgent;
    private User $sellingAgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'EditForm Co', 'slug' => 'ef-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
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
        $this->listingAgent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);
        $this->sellingAgent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);
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
            'external_id' => 'EF-' . Str::random(8), 'title' => $address, 'address' => $address,
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

    /** 1,000,000 / 57,500 on $primary — the primary's pivot row is mirrored on create. */
    private function makeDeal(Property $primary): Deal
    {
        return Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(6000, 6999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $primary->id, 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);
    }

    /** The DR2 edit form's POST, exactly as the screen submits it (readonly fields included). */
    private function editPayload(Deal $deal, array $over = []): array
    {
        return array_merge([
            'period'                => '2026-06',
            'deal_date'             => '2026-06-10',
            'property_value'        => $deal->property_value,
            'total_commission'      => $deal->total_commission,
            'listing_split_percent' => 50,
            'selling_split_percent' => 50,
            'listing_agents'        => [(string) $this->listingAgent->id],
            'selling_agents'        => [(string) $this->sellingAgent->id],
            'branch_id'             => $this->branchId,
            'property_id'           => $deal->property_id,
            'accepted_status'       => 'P',
            'commission_status'     => 'Not Paid',
        ], $over);
    }

    /** @return array{0:Deal,1:Property,2:Property,3:Contact} deal (A+B, 1,500,000 / 86,250), A, B, Steve */
    private function twoPropertyDeal(): array
    {
        $propA = $this->makeProperty('1 Two A Rd');
        $propB = $this->makeProperty('1 Two B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA);

        $this->actingAs($this->bm)
            ->post(route('deals-dr2.properties.add', $deal), ['add_property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('1500000.00', $deal->fresh()->property_value);
        $this->assertSame('86250.00', $deal->fresh()->total_commission);

        return [$deal->fresh(), $propA, $propB, $steve];
    }

    // ── A2 / A3 — the primary may not be swapped via the edit form on a 2+-property deal ──

    public function test_changing_the_primary_property_on_a_two_property_deal_is_refused_and_nothing_changes(): void
    {
        [$deal, $propA, $propB, $steve] = $this->twoPropertyDeal();
        $propC = $this->makeProperty('1 Two C Rd');
        $this->linkOwner($propC, $steve); // even a same-owner candidate — the swap path is closed, not gated

        $response = $this->actingAs($this->bm)
            ->post(route('deals-dr2.update', $deal), $this->editPayload($deal, ['property_id' => $propC->id]));

        $response->assertSessionHasErrors('property_id');
        $message = session('errors')->get('property_id')[0];
        $this->assertStringContainsString('Add / Remove property', $message);

        $fresh = $deal->fresh();
        $this->assertSame($propA->id, (int) $fresh->property_id, 'the primary is unchanged');
        $this->assertDatabaseMissing('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propC->id]);
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propA->id, 'is_primary' => 1, 'deleted_at' => null, 'allocated_price' => 1000000]);
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id, 'is_primary' => 0, 'deleted_at' => null, 'allocated_price' => 500000]);
        $this->assertSame('1500000.00', $fresh->property_value, 'A2: the deal total is not collapsed by an unpriced swap');
        $this->assertSame('86250.00', $fresh->total_commission);
    }

    public function test_unlinking_the_primary_property_on_a_two_property_deal_is_refused_too(): void
    {
        [$deal, $propA] = $this->twoPropertyDeal();

        $response = $this->actingAs($this->bm)
            ->post(route('deals-dr2.update', $deal), $this->editPayload($deal, ['property_id' => '']));

        $response->assertSessionHasErrors('property_id');
        $this->assertSame($propA->id, (int) $deal->fresh()->property_id);
        $this->assertSame(2, $deal->fresh()->properties()->count());
    }

    public function test_the_edit_form_still_saves_a_two_property_deal_when_the_primary_is_unchanged(): void
    {
        [$deal] = $this->twoPropertyDeal();

        $this->actingAs($this->bm)
            ->post(route('deals-dr2.update', $deal), $this->editPayload($deal, ['remarks' => 'Edited with the primary left alone']))
            ->assertSessionDoesntHaveErrors();

        $fresh = $deal->fresh();
        $this->assertSame('Edited with the primary left alone', $fresh->remarks);
        $this->assertSame(2, $fresh->properties()->count());
        $this->assertSame('1500000.00', $fresh->property_value);
        $this->assertSame('86250.00', $fresh->total_commission);
    }

    // ── A7 — posted totals are ignored on a 2+-property deal; the rows are the truth ──

    public function test_a_stale_edit_form_cannot_revert_a_colleagues_property_price_update(): void
    {
        [$deal, , $propB] = $this->twoPropertyDeal(); // 1,500,000 / 86,250
        $staleForm = $this->editPayload($deal);          // rendered BEFORE the colleague's change

        // A colleague (second tab) re-prices B — the deal re-sums to 1,600,000 / 92,000.
        $this->actingAs($this->bm)
            ->patch(route('deals-dr2.properties.updatePrice', [$deal, $propB]), ['allocated_price' => 600000, 'allocated_commission' => 34500])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('1600000.00', $deal->fresh()->property_value);

        // The stale form (still carrying 1,500,000 / 86,250 in its read-only fields) is saved.
        $this->actingAs($this->bm)
            ->post(route('deals-dr2.update', $deal), $staleForm)
            ->assertSessionDoesntHaveErrors();

        $fresh = $deal->fresh();
        $this->assertSame('1600000.00', $fresh->property_value, 'A7: the deal total stays the sum of the rows, not the stale figure');
        $this->assertSame('92000.00', $fresh->total_commission);
    }

    public function test_crafted_totals_on_a_two_property_deal_are_overridden_by_the_true_sum(): void
    {
        [$deal] = $this->twoPropertyDeal();

        $this->actingAs($this->bm)
            ->post(route('deals-dr2.update', $deal), $this->editPayload($deal, ['property_value' => 1, 'total_commission' => 1]))
            ->assertSessionDoesntHaveErrors();

        $fresh = $deal->fresh();
        $this->assertSame('1500000.00', $fresh->property_value);
        $this->assertSame('86250.00', $fresh->total_commission);
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'allocated_price' => 1000000, 'allocated_commission' => 57500]);
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'allocated_price' => 500000, 'allocated_commission' => 28750]);
    }

    // ── M1 / A7(a) — a single-property price edit mirrors onto its pivot row ──

    public function test_a_price_edit_on_a_single_property_deal_mirrors_onto_its_pivot_so_a_later_add_keeps_the_correction(): void
    {
        $propA = $this->makeProperty('2 Mirror A Rd');
        $propB = $this->makeProperty('2 Mirror B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA); // 1,000,000 / 57,500, mirrored on create
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propA->id, 'allocated_price' => 1000000, 'allocated_commission' => 57500]);

        // The agent corrects the price on the main form — property_id unchanged.
        $this->actingAs($this->bm)
            ->post(route('deals-dr2.update', $deal), $this->editPayload($deal, ['property_value' => 1200000, 'total_commission' => 72000]))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('1200000.00', $deal->fresh()->property_value);
        // M1: the sole property's allocation follows the corrected deal figures.
        $this->assertDatabaseHas('deal_properties', [
            'deal_id' => $deal->id, 'property_id' => $propA->id, 'allocated_price' => 1200000, 'allocated_commission' => 72000,
        ]);

        // A second property is added later — the re-sum must build on the CORRECTED figure.
        $this->actingAs($this->bm)
            ->post(route('deals-dr2.properties.add', $deal), ['add_property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 30000])
            ->assertSessionDoesntHaveErrors();

        $fresh = $deal->fresh();
        $this->assertSame('1700000.00', $fresh->property_value, 'was 1,500,000 before the fix — the correction silently reverted');
        $this->assertSame('102000.00', $fresh->total_commission, 'was 87,500 before the fix');
    }

    public function test_a_totals_change_on_a_two_property_deal_never_touches_the_rows(): void
    {
        [$deal] = $this->twoPropertyDeal();

        // A direct model write of the totals on a 2+-property deal (the direction of
        // truth is reversed there) must leave every row's own allocation alone.
        $deal->update(['property_value' => 999, 'total_commission' => 9]);

        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'allocated_price' => 1000000, 'allocated_commission' => 57500]);
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'allocated_price' => 500000, 'allocated_commission' => 28750]);
    }

    // ── A10 — every re-sum reaches the DR2 twin ──

    public function test_a_property_add_and_remove_re_sum_reaches_the_dr2_twin(): void
    {
        $propA = $this->makeProperty('3 Twin A Rd');
        $propB = $this->makeProperty('3 Twin B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA); // 57,500 inc = 50,000 + 7,500

        // The twin, already in step (created quietly so its own observer does not
        // write anything back during setup).
        $v2 = DealV2::withoutEvents(fn () => DealV2::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'legacy_deal_id' => $deal->id,
            'reference' => 'DR2-' . Str::random(6), 'deal_type' => 'bond', 'status' => 'active',
            'listing_agent_id' => $this->listingAgent->id, 'created_by_id' => $this->listingAgent->id,
            'purchase_price' => 1_000_000, 'offer_date' => '2026-06-10',
            'commission_amount' => 50_000, 'commission_vat' => 7_500,
            'listing_split_percent' => 50, 'listing_external' => 0, 'listing_our_share_percent' => 100,
            'selling_split_percent' => 50, 'selling_external' => 0, 'selling_our_share_percent' => 100,
        ]));
        DB::table('deals')->where('id', $deal->id)->update(['deal_v2_id' => $v2->id]);

        // Add B → V1 re-sums (quietly) to 86,250 inc = 75,000 + 11,250.
        $this->actingAs($this->bm)
            ->post(route('deals-dr2.properties.add', $deal->fresh()), ['add_property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('86250.00', $deal->fresh()->total_commission);
        $twin = DealV2::withoutGlobalScopes()->find($v2->id);
        $this->assertEqualsWithDelta(75_000.0, (float) $twin->commission_amount, 0.01, 'A10: the twin sees the re-summed commission (was still 50,000)');
        $this->assertEqualsWithDelta(11_250.0, (float) $twin->commission_vat, 0.01);
        $this->assertSame(1_500_000, (int) $twin->purchase_price);

        // Remove B → back to 57,500; the twin follows again.
        $this->actingAs($this->bm)
            ->delete(route('deals-dr2.properties.remove', [$deal->fresh(), $propB]))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('57500.00', $deal->fresh()->total_commission);
        $twin = DealV2::withoutGlobalScopes()->find($v2->id);
        $this->assertEqualsWithDelta(50_000.0, (float) $twin->commission_amount, 0.01);
        $this->assertEqualsWithDelta(7_500.0, (float) $twin->commission_vat, 0.01);
    }
}
