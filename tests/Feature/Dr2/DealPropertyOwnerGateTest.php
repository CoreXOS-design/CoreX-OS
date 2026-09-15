<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Exceptions\Deal\PropertyOwnerMismatchException;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Services\Deal\DealPropertyOwnerGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-398 — Johan's ruling, verbatim: "multi property means exact same owners
 * - the problem like steve owns 2 properties - 1 sole other with dave. for
 * the transfer to happen dave cannot sign for the 1 property so it then has
 * to be 2 deals. but if steve owned both outright then ... the multi
 * property allows to add but only where steve is the owner as well." And:
 * "we do not allow owner change, and there cannot be a deal without a
 * owner." Overlap is explicitly NOT sufficient — the set must be IDENTICAL.
 */
final class DealPropertyOwnerGateTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private DealPropertyOwnerGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Owner Co', 'slug' => 'own-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->gate = app(DealPropertyOwnerGate::class);
    }

    private function makeProperty(string $address): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'OG-' . Str::random(8), 'title' => $address, 'address' => $address,
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

    private function makeDeal(Property $primary): Deal
    {
        return Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(9000, 9999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $primary->id, 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);
    }

    // ── sellerSideContactIds() / ownerSetsMatch() — the primitive ─────────────

    public function test_owner_bucket_pools_owner_seller_landlord_lessor_and_ignores_buyer_and_lead(): void
    {
        $property = $this->makeProperty('1 Bucket Rd');
        $steve = $this->makeContact('Steve');
        $buyer = $this->makeContact('Buyer');
        $lead = $this->makeContact('Lead');
        $this->linkOwner($property, $steve, 'owner');
        $this->linkOwner($property, $buyer, 'buyer');
        $this->linkOwner($property, $lead, 'lead');

        $ids = $this->gate->sellerSideContactIds($property);

        $this->assertSame([$steve->id], $ids);
    }

    public function test_joint_owners_are_all_included_in_the_set(): void
    {
        $property = $this->makeProperty('2 Joint Rd');
        $steve = $this->makeContact('Steve');
        $dave = $this->makeContact('Dave');
        $this->linkOwner($property, $steve, 'seller');
        $this->linkOwner($property, $dave, 'seller');

        $this->assertCount(2, $this->gate->sellerSideContactIds($property));
    }

    // ── the exact scenario, verbatim from Johan's own example ─────────────────

    /** Steve owns property A outright, and jointly owns property B with Dave. Refused — not identical. */
    public function test_johans_steve_and_dave_example_refuses_the_partial_overlap(): void
    {
        $propertyA = $this->makeProperty('3 Steve Solo Rd'); // Steve, sole owner
        $propertyB = $this->makeProperty('3 Steve Dave Rd');  // Steve + Dave, joint owners
        $steve = $this->makeContact('Steve');
        $dave = $this->makeContact('Dave');
        $this->linkOwner($propertyA, $steve, 'seller');
        $this->linkOwner($propertyB, $steve, 'seller');
        $this->linkOwner($propertyB, $dave, 'seller');

        $deal = $this->makeDeal($propertyA);

        $this->expectException(PropertyOwnerMismatchException::class);
        $this->expectExceptionMessage("cannot sign for the transfer");
        $this->gate->assertCanAddToDeal($deal, $propertyB);
    }

    /** Same scenario, but Steve owns BOTH outright — multi-property allowed. */
    public function test_johans_steve_owns_both_outright_allows_the_add(): void
    {
        $propertyA = $this->makeProperty('4 Steve A Rd');
        $propertyB = $this->makeProperty('4 Steve B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propertyA, $steve, 'seller');
        $this->linkOwner($propertyB, $steve, 'seller');

        $deal = $this->makeDeal($propertyA);

        // No exception.
        $this->gate->assertCanAddToDeal($deal, $propertyB);
        $this->assertTrue(true);
    }

    /** Overlap (one shared owner, but not the whole set) is explicitly NOT sufficient. */
    public function test_overlap_alone_is_not_sufficient_only_exact_set_match(): void
    {
        $propertyA = $this->makeProperty('5 Overlap A Rd'); // Steve + Dave
        $propertyB = $this->makeProperty('5 Overlap B Rd'); // Steve + Mike
        $steve = $this->makeContact('Steve');
        $dave = $this->makeContact('Dave');
        $mike = $this->makeContact('Mike');
        $this->linkOwner($propertyA, $steve, 'seller');
        $this->linkOwner($propertyA, $dave, 'seller');
        $this->linkOwner($propertyB, $steve, 'seller');
        $this->linkOwner($propertyB, $mike, 'seller');

        $this->assertFalse($this->gate->ownerSetsMatch($propertyA, $propertyB), 'a shared owner is not enough — the sets are not identical');
    }

    /** The mismatch message names WHO can't sign, in plain language — no jargon. */
    public function test_mismatch_message_names_the_seller_who_cannot_sign_in_plain_language(): void
    {
        $propertyA = $this->makeProperty('6 Message A Rd');
        $propertyB = $this->makeProperty('6 Message B Rd');
        $dave = $this->makeContact('Dave');
        $mike = $this->makeContact('Mike');
        $this->linkOwner($propertyA, $dave, 'seller');
        $this->linkOwner($propertyB, $mike, 'seller');

        $deal = $this->makeDeal($propertyA);

        try {
            $this->gate->assertCanAddToDeal($deal, $propertyB);
            $this->fail('expected a PropertyOwnerMismatchException');
        } catch (PropertyOwnerMismatchException $e) {
            $this->assertStringContainsString('Dave', $e->getMessage());
            $this->assertStringContainsString('separate deal', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('overlap', $e->getMessage());
        }
    }

    // ── "there cannot be a deal without an owner" ─────────────────────────────

    public function test_a_property_with_no_resolvable_owner_cannot_become_the_first_property_on_a_deal(): void
    {
        $property = $this->makeProperty('7 No Owner Rd'); // no contact_property rows at all

        $this->expectException(PropertyOwnerMismatchException::class);
        $this->expectExceptionMessage("can't confirm who owns");
        $this->gate->assertHasKnownOwner($property);
    }

    public function test_a_property_with_a_known_owner_passes(): void
    {
        $property = $this->makeProperty('8 Known Owner Rd');
        $this->linkOwner($property, $this->makeContact('Steve'), 'owner');

        $this->gate->assertHasKnownOwner($property);
        $this->assertTrue(true);
    }

    /** The FIRST property on a deal establishes the owner set — nothing to match against yet. */
    public function test_the_first_property_added_to_a_deal_has_nothing_to_match_against(): void
    {
        $property = $this->makeProperty('9 First Rd');
        $this->linkOwner($property, $this->makeContact('Steve'), 'seller');
        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(9000, 9999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]); // no property_id yet — a genuinely empty deal

        $this->gate->assertCanAddToDeal($deal, $property);
        $this->assertTrue(true);
    }
}
