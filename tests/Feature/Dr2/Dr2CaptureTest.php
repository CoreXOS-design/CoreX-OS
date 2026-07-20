<?php

namespace Tests\Feature\Dr2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-217 (DR2) — the DR2 capture screen writes DR1's OWN `deals` tables (an exact
 * rebuild, not the sunset deals-v2 module) and layers the §2 property link. These
 * tests prove: the capture route renders; a store persists a real `deals` row with
 * DR1-parity fields; a picked property links on deals.property_id with manual/exact
 * provenance; and DR1's own capture stays untouched (both writers coexist).
 */
class Dr2CaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function payload(int $listingAgentId, int $sellingAgentId, array $overrides = []): array
    {
        return array_merge([
            'period'                => '2026-06',
            'deal_date'             => '2026-06-10',
            'deal_type'             => 'bond',
            'property_value'        => 1000000,
            'total_commission'      => 57500,
            'listing_split_percent' => 50,
            'selling_split_percent' => 50,
            'listing_agents'        => [(string) $listingAgentId],
            'selling_agents'        => [(string) $sellingAgentId],
        ], $overrides);
    }

    /** @return array{0:Agency,1:Branch,2:User,3:User,4:User} agency, branch, admin, listing agent, selling agent */
    private function scaffold(string $slug): array
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => $slug]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Southbroom']);
        $admin  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);
        $l = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $s = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $branch, $admin, $l, $s];
    }

    public function test_dr2_create_screen_renders(): void
    {
        [, , $admin] = $this->scaffold('dr2-render');

        $this->withoutVite();
        $this->actingAs($admin)
            ->get(route('deals-dr2.create'))
            ->assertOk()
            // DR1-faithful header (visual parity), plus the DR2 capture enhancements.
            ->assertSee('Add Deal', false)
            ->assertSee('Deal Type', false)      // enhancement 6 (compulsory radios)
            ->assertSee('Commission %', false);  // enhancement 5 (calc-on-load)
    }

    public function test_dr2_store_persists_a_real_deals_row(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-store');

        $before = DB::table('deals')->count();

        $this->actingAs($admin)
            ->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
                'branch_id'        => $branch->id,
                'property_address' => '12 Marine Drive, Uvongo',
                'seller_name'      => 'A Seller',
            ]))
            ->assertRedirect(route('deals-dr2.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1, DB::table('deals')->count(), 'DR2 must write one real deals row.');
        $this->assertDatabaseHas('deals', [
            'agency_id'        => $agency->id,
            'branch_id'        => $branch->id,
            'period'           => '2026-06',
            'deal_type'        => 'bond',
            'property_address' => '12 Marine Drive, Uvongo',
            'seller_name'      => 'A Seller',
        ]);
    }

    public function test_dr2_deal_type_is_compulsory(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-dealtype');

        $before = DB::table('deals')->count();

        $this->actingAs($admin)
            ->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
                'branch_id' => $branch->id,
                'deal_type' => '', // no deal type chosen
            ]))
            ->assertSessionHasErrors('deal_type');

        $this->assertSame($before, DB::table('deals')->count(), 'No deal may be stored without a deal type.');
    }

    public function test_dr2_store_links_the_picked_property_with_manual_exact_provenance(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-link');

        $property = Property::create([
            'title'         => 'DR2 Linked Listing',
            'agency_id'     => $agency->id,
            'agent_id'      => $l->id,
            'branch_id'     => $branch->id,
            'listing_type'  => 'sale',
            'address'       => '12 Marine Drive, Uvongo',
            'street_name'   => 'Marine Drive',
            'suburb'        => 'Uvongo',
            'town'          => 'Uvongo',
            'province'      => 'KwaZulu-Natal',
            'price'         => 1000000,
            'property_type' => 'House',
        ]);

        $this->actingAs($admin)
            ->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
                'branch_id'   => $branch->id,
                'property_id' => $property->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('deals', [
            'agency_id'       => $agency->id,
            'property_id'     => $property->id,
            'link_source'     => 'manual',
            'link_confidence' => 'exact',
        ]);
    }

    public function test_dr2_store_still_enforces_the_branch_gate(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'dr2-gate']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ballito']);
        $admin  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => null, 'role' => 'admin', 'is_active' => true,
        ]);
        $l = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $s = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $before = DB::table('deals')->count();

        $this->actingAs($admin)
            ->post(route('deals-dr2.store'), $this->payload($l->id, $s->id)) // no branch_id
            ->assertSessionHasErrors('branch_id');

        $this->assertSame($before, DB::table('deals')->count(), 'No null-branch DR2 deal may be created.');
    }

    public function test_dr1_capture_is_untouched_and_coexists(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-coexist');

        // DR1's own route still stores to the same table — proof the rebuild left DR1 intact.
        $this->actingAs($admin)
            ->post(route('admin.deals.store'), $this->payload($l->id, $s->id, ['branch_id' => $branch->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('deals', [
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'period' => '2026-06',
        ]);
    }

    /**
     * DR2 reverse link (property-spine doctrine): choosing a buyer/seller contact on
     * capture ALSO links them to the property with the right role — idempotently.
     */
    public function test_dr2_capture_reverse_links_buyer_and_seller_to_the_property(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-revlink');

        $property = Property::create([
            'title' => 'Rev-link Listing', 'agency_id' => $agency->id, 'agent_id' => $l->id,
            'branch_id' => $branch->id, 'listing_type' => 'sale', 'address' => '9 Link Rd',
            'suburb' => 'Uvongo', 'price' => 1000000, 'property_type' => 'House',
        ]);
        $buyerC  = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Bob', 'last_name' => 'Buyer']);
        $sellerC = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Sue', 'last_name' => 'Seller']);

        $post = fn () => $this->actingAs($admin)->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
            'branch_id'          => $branch->id,
            'property_id'        => $property->id,
            'buyer_contact_ids'  => (string) $buyerC->id,
            'seller_contact_ids' => (string) $sellerC->id,
        ]));

        $post()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_property', ['property_id' => $property->id, 'contact_id' => $buyerC->id, 'role' => 'buyer']);
        $this->assertDatabaseHas('contact_property', ['property_id' => $property->id, 'contact_id' => $sellerC->id, 'role' => 'seller']);

        // A second capture of the same parties must not duplicate the links.
        $post()->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('contact_property')->where(['property_id' => $property->id, 'contact_id' => $buyerC->id])->count());
        $this->assertSame(1, DB::table('contact_property')->where(['property_id' => $property->id, 'contact_id' => $sellerC->id])->count());
    }

    /**
     * AT-243 — capture records the parties ON THE DEAL, not only on the property.
     *
     * Without this, a property carrying several offers cannot say which buyer belongs to
     * which deal, so it cannot say who actually bought when one is granted. The property
     * link (tested above) and the deal link (tested here) are two different facts and both
     * must be written.
     */
    public function test_dr2_capture_records_the_parties_on_the_deal_itself(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-dealparties');

        $property = Property::create([
            'title' => 'Party Listing', 'agency_id' => $agency->id, 'agent_id' => $l->id,
            'branch_id' => $branch->id, 'listing_type' => 'sale', 'address' => '3 Party Rd',
            'suburb' => 'Uvongo', 'price' => 1000000, 'property_type' => 'House',
        ]);
        $buyer  = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Thandi', 'last_name' => 'Mkhize']);
        $joint  = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Sipho', 'last_name' => 'Mkhize']);
        $seller = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Sue', 'last_name' => 'Seller']);

        $this->actingAs($admin)->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
            'branch_id'          => $branch->id,
            'property_id'        => $property->id,
            'buyer_contact_ids'  => $buyer->id . ',' . $joint->id, // joint buyers
            'seller_contact_ids' => (string) $seller->id,
        ]))->assertSessionHasNoErrors();

        $deal = Deal::where('property_id', $property->id)->latest('id')->firstOrFail();

        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer']);
        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->id, 'contact_id' => $joint->id, 'role' => 'buyer']);
        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->id, 'contact_id' => $seller->id, 'role' => 'seller']);

        // The deal now knows its own buyers — which is what makes the purchaser derivable.
        $this->assertSame(2, $deal->buyers()->count());
        $this->assertSame(1, $deal->sellers()->count());
    }

    /** The lazy-but-valid shortcut: a deal captured with no contacts named is legal, not an error. */
    public function test_dr2_capture_with_no_parties_named_is_accepted_and_records_none(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-noparties');

        $property = Property::create([
            'title' => 'Bare Listing', 'agency_id' => $agency->id, 'agent_id' => $l->id,
            'branch_id' => $branch->id, 'listing_type' => 'sale', 'address' => '5 Bare Rd',
            'suburb' => 'Uvongo', 'price' => 1000000, 'property_type' => 'House',
        ]);

        $this->actingAs($admin)->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
            'branch_id'   => $branch->id,
            'property_id' => $property->id,
            // no buyer_contact_ids / seller_contact_ids at all
        ]))->assertSessionHasNoErrors();

        $deal = Deal::where('property_id', $property->id)->latest('id')->firstOrFail();
        $this->assertSame(0, DB::table('deal_contacts')->where('deal_id', $deal->id)->count());

        // ...and the property honestly claims no purchaser rather than inventing one.
        $deal->update(['accepted_status' => 'G']);
        $this->assertSame([], $property->fresh()->purchaserContactIds());
    }

    /** Editing a deal to correct a mis-captured buyer must actually correct it. */
    public function test_editing_a_deal_replaces_its_buyer_rather_than_accumulating(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-fixbuyer');

        $property = Property::create([
            'title' => 'Fix Listing', 'agency_id' => $agency->id, 'agent_id' => $l->id,
            'branch_id' => $branch->id, 'listing_type' => 'sale', 'address' => '7 Fix Rd',
            'suburb' => 'Uvongo', 'price' => 1000000, 'property_type' => 'House',
        ]);
        $wrong = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Wrong', 'last_name' => 'Buyer']);
        $right = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Right', 'last_name' => 'Buyer']);

        $this->actingAs($admin)->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
            'branch_id' => $branch->id, 'property_id' => $property->id,
            'buyer_contact_ids' => (string) $wrong->id,
        ]))->assertSessionHasNoErrors();

        $deal = Deal::where('property_id', $property->id)->latest('id')->firstOrFail();

        // Correct the buyer on the deal.
        $this->actingAs($admin)->post(route('deals-dr2.update', $deal), $this->payload($l->id, $s->id, [
            'branch_id' => $branch->id, 'property_id' => $property->id,
            'buyer_contact_ids' => (string) $right->id,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('deal_contacts', ['deal_id' => $deal->id, 'contact_id' => $wrong->id, 'role' => 'buyer']);
        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->id, 'contact_id' => $right->id, 'role' => 'buyer']);
        $this->assertSame(1, $deal->fresh()->buyers()->count(), 'the wrong buyer is replaced, not accumulated');
    }

    /** A contact already linked in one role must NOT be silently re-roled by a deal. */
    public function test_dr2_capture_does_not_reroles_an_existing_link(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-reroute');

        $property = Property::create([
            'title' => 'Reroute Listing', 'agency_id' => $agency->id, 'agent_id' => $l->id,
            'branch_id' => $branch->id, 'listing_type' => 'sale', 'address' => '11 Keep Rd',
            'suburb' => 'Uvongo', 'price' => 1000000, 'property_type' => 'House',
        ]);
        $c = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Joint', 'last_name' => 'Party']);
        $property->contacts()->attach($c->id, ['role' => 'seller']);

        $this->actingAs($admin)->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
            'branch_id'         => $branch->id,
            'property_id'       => $property->id,
            'buyer_contact_ids' => (string) $c->id, // same contact, now picked as buyer
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_property', ['property_id' => $property->id, 'contact_id' => $c->id, 'role' => 'seller']);
        $this->assertDatabaseMissing('contact_property', ['property_id' => $property->id, 'contact_id' => $c->id, 'role' => 'buyer']);
        $this->assertSame(1, DB::table('contact_property')->where(['property_id' => $property->id, 'contact_id' => $c->id])->count());
    }

    /**
     * PART C (AT-262/DR2) — the seller offer is LISTING-TYPE-AWARE. A RENTAL property's
     * landlord IS the seller-side party of the rental deal and MUST pull through; the old
     * fixed ['seller','owner'] set left it invisible (the boboni class, generalised).
     */
    public function test_property_contacts_offers_a_rental_landlord_as_the_seller(): void
    {
        [$agency, $branch, $admin, $l] = $this->scaffold('dr2-rental-offer');

        $property = Property::create([
            'title' => 'Boboni Rental', 'agency_id' => $agency->id, 'agent_id' => $l->id,
            'branch_id' => $branch->id, 'listing_type' => 'rental', 'address' => '651 Boboni Rd',
            'suburb' => 'Shelly Beach', 'price' => 0, 'rental_amount' => 9000, 'property_type' => 'House',
        ]);
        $landlord = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Premilla', 'last_name' => 'Swepath']);
        $tenant   = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Thabo', 'last_name' => 'Ndlovu']);
        $property->contacts()->attach($landlord->id, ['role' => 'landlord']);
        $property->contacts()->attach($tenant->id, ['role' => 'tenant']);

        $res = $this->actingAs($admin)
            ->getJson(route('deals-dr2.search.property-contacts', ['property' => $property->id]))
            ->assertOk()
            ->json();

        $sellerIds = array_column($res['sellers'] ?? [], 'id');
        $buyerIds  = array_column($res['buyers'] ?? [], 'id');
        $this->assertContains($landlord->id, $sellerIds, 'a rental landlord must be offered as the seller-side party');
        $this->assertContains($tenant->id, $buyerIds, 'a rental tenant must be offered as the buyer-side party');
    }

    /** The SALE path is unchanged: seller/owner offered, landlord/lessor are not. */
    public function test_property_contacts_still_offers_seller_owner_on_a_sale(): void
    {
        [$agency, $branch, $admin, $l] = $this->scaffold('dr2-sale-offer');

        $property = Property::create([
            'title' => 'Uvongo Sale', 'agency_id' => $agency->id, 'agent_id' => $l->id,
            'branch_id' => $branch->id, 'listing_type' => 'sale', 'address' => '12 Marine Dr',
            'suburb' => 'Uvongo', 'price' => 1950000, 'property_type' => 'House',
        ]);
        $seller = \App\Models\Contact::create(['agency_id' => $agency->id, 'first_name' => 'Owen', 'last_name' => 'Ridge']);
        $property->contacts()->attach($seller->id, ['role' => 'seller']);

        $res = $this->actingAs($admin)
            ->getJson(route('deals-dr2.search.property-contacts', ['property' => $property->id]))
            ->assertOk()
            ->json();

        $this->assertContains($seller->id, array_column($res['sellers'] ?? [], 'id'),
            'a sale seller must still be offered');
    }
}
