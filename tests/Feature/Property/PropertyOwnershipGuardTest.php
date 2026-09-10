<?php

declare(strict_types=1);

namespace Tests\Feature\Property;

use App\Exceptions\Property\OwnershipLockedException;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Services\Property\PropertyOwnershipGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-398 — Johan's ruling, verbatim: "we do not allow owner change, and
 * there cannot be a deal without a owner." A property's owner set is locked
 * for the whole time it sits on an open (pending/granted) deal — the deal
 * was built on who owns it now, and every seller on the deal must be able
 * to sign for it. The lock covers every real mutation path (link, unlink,
 * change-role, bulk resync) and lifts once the deal is declined or
 * registered. It must never self-block the deal's OWN routine seller-sync.
 */
final class PropertyOwnershipGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private PropertyOwnershipGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Guard Co', 'slug' => 'guard-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->guard = app(PropertyOwnershipGuard::class);
    }

    private function makeProperty(string $address): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'PG-' . Str::random(8), 'title' => $address, 'address' => $address,
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

    private function linkContact(Property $property, Contact $contact, string $role): void
    {
        DB::table('contact_property')->insert([
            'property_id' => $property->id, 'contact_id' => $contact->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeDeal(Property $property, string $acceptedStatus = 'P'): Deal
    {
        return Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(7000, 7999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $property->id, 'accepted_status' => $acceptedStatus, 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);
    }

    // ── isLocked() / lockingDeals() ────────────────────────────────────────────

    public function test_a_property_with_no_deals_is_not_locked(): void
    {
        $property = $this->makeProperty('1 Free Rd');

        $this->assertFalse($this->guard->isLocked($property));
    }

    public function test_a_property_on_a_pending_deal_is_locked(): void
    {
        $property = $this->makeProperty('2 Pending Rd');
        $this->makeDeal($property, 'P');

        $this->assertTrue($this->guard->isLocked($property));
    }

    public function test_a_property_on_a_granted_deal_is_locked(): void
    {
        $property = $this->makeProperty('3 Granted Rd');
        $this->makeDeal($property, 'G');

        $this->assertTrue($this->guard->isLocked($property));
    }

    public function test_a_property_on_a_declined_deal_is_not_locked(): void
    {
        $property = $this->makeProperty('4 Declined Rd');
        $this->makeDeal($property, 'D');

        $this->assertFalse($this->guard->isLocked($property), 'Johan: a declined deal is dead — nothing left to protect');
    }

    public function test_a_property_on_a_registered_deal_is_not_locked(): void
    {
        $property = $this->makeProperty('5 Registered Rd');
        $this->makeDeal($property, 'R');

        $this->assertFalse($this->guard->isLocked($property), 'the transfer already happened — an ownership update afterward is the normal next step');
    }

    // ── assertCanLink() ─────────────────────────────────────────────────────────

    public function test_link_is_blocked_for_a_seller_side_role_on_a_locked_property(): void
    {
        $property = $this->makeProperty('6 Link Block Rd');
        $this->makeDeal($property, 'P');

        $this->expectException(OwnershipLockedException::class);
        $this->guard->assertCanLink($property, 'seller');
    }

    public function test_link_is_allowed_for_a_non_seller_role_on_a_locked_property(): void
    {
        $property = $this->makeProperty('7 Buyer Link Rd');
        $this->makeDeal($property, 'P');

        $this->guard->assertCanLink($property, 'buyer'); // no exception — buyer/tenant/lead are unaffected
        $this->assertTrue(true);
    }

    public function test_link_is_allowed_for_a_seller_side_role_on_an_unlocked_property(): void
    {
        $property = $this->makeProperty('8 Free Link Rd');

        $this->guard->assertCanLink($property, 'landlord');
        $this->assertTrue(true);
    }

    // ── assertCanUnlink() ───────────────────────────────────────────────────────

    public function test_unlink_is_blocked_when_removing_a_seller_side_contact_from_a_locked_property(): void
    {
        $property = $this->makeProperty('9 Unlink Block Rd');
        $owner = $this->makeContact('Steve');
        $this->linkContact($property, $owner, 'owner');
        $this->makeDeal($property, 'P');

        $this->expectException(OwnershipLockedException::class);
        $this->guard->assertCanUnlink($property, $owner->id);
    }

    public function test_unlink_is_allowed_when_removing_a_buyer_from_a_locked_property(): void
    {
        $property = $this->makeProperty('10 Unlink Buyer Rd');
        $buyer = $this->makeContact('Buyer');
        $this->linkContact($property, $buyer, 'buyer');
        $this->makeDeal($property, 'P');

        $this->guard->assertCanUnlink($property, $buyer->id);
        $this->assertTrue(true);
    }

    // ── assertCanChangeRole() ───────────────────────────────────────────────────

    public function test_role_change_is_blocked_when_the_old_role_is_seller_side_on_a_locked_property(): void
    {
        $property = $this->makeProperty('11 Role From Rd');
        $contact = $this->makeContact('Steve');
        $this->linkContact($property, $contact, 'seller');
        $this->makeDeal($property, 'P');

        $this->expectException(OwnershipLockedException::class);
        $this->guard->assertCanChangeRole($property, $contact->id, 'buyer');
    }

    public function test_role_change_is_blocked_when_the_new_role_is_seller_side_on_a_locked_property(): void
    {
        $property = $this->makeProperty('12 Role To Rd');
        $contact = $this->makeContact('Buyer');
        $this->linkContact($property, $contact, 'buyer');
        $this->makeDeal($property, 'P');

        $this->expectException(OwnershipLockedException::class);
        $this->guard->assertCanChangeRole($property, $contact->id, 'owner');
    }

    public function test_role_change_between_two_non_seller_roles_is_allowed_on_a_locked_property(): void
    {
        $property = $this->makeProperty('13 Role Neutral Rd');
        $contact = $this->makeContact('Lead');
        $this->linkContact($property, $contact, 'lead');
        $this->makeDeal($property, 'P');

        $this->guard->assertCanChangeRole($property, $contact->id, 'buyer');
        $this->assertTrue(true);
    }

    // ── assertOwnershipMutable() — the bulk/wholesale path ─────────────────────

    public function test_ownership_mutable_is_blocked_unconditionally_on_a_locked_property(): void
    {
        $property = $this->makeProperty('14 Mutable Block Rd');
        $this->makeDeal($property, 'G');

        $this->expectException(OwnershipLockedException::class);
        $this->guard->assertOwnershipMutable($property);
    }

    // ── The self-exclusion behaviour — the deal's own routine seller-sync ─────

    public function test_excluding_deal_id_lets_a_deal_sync_its_own_seller_onto_its_own_locked_property(): void
    {
        $property = $this->makeProperty('15 Self Sync Rd');
        $deal = $this->makeDeal($property, 'P'); // this deal IS what locks the property

        // No exception — the deal is allowed to touch the property it itself locks.
        $this->guard->assertCanLink($property, 'seller', excludingDealId: $deal->id);
        $this->assertTrue(true);
    }

    public function test_excluding_deal_id_does_not_lift_the_lock_from_a_different_deal(): void
    {
        $property = $this->makeProperty('16 Other Deal Rd');
        $lockingDeal = $this->makeDeal($property, 'P');
        $unrelatedDealId = $lockingDeal->id + 999_000; // definitely not the locking deal

        $this->expectException(OwnershipLockedException::class);
        $this->guard->assertCanLink($property, 'seller', excludingDealId: $unrelatedDealId);
    }

    public function test_excluding_one_deal_still_blocks_when_a_second_unrelated_deal_also_locks_the_property(): void
    {
        $property = $this->makeProperty('17 Two Lockers Rd');
        $dealA = $this->makeDeal($property, 'P');
        // A second, independent pending deal also touching this property (multi-deal-per-property is normal pre-grant).
        $dealB = $this->makeDeal($property, 'P');

        $this->expectException(OwnershipLockedException::class);
        $this->guard->assertCanLink($property, 'seller', excludingDealId: $dealA->id); // dealB still locks it
    }

    public function test_lock_message_names_the_deal_number_in_plain_language(): void
    {
        $property = $this->makeProperty('18 Message Rd');
        $deal = $this->makeDeal($property, 'P');

        try {
            $this->guard->assertCanLink($property, 'owner');
            $this->fail('expected an OwnershipLockedException');
        } catch (OwnershipLockedException $e) {
            $this->assertStringContainsString($deal->deal_no, $e->getMessage());
            $this->assertStringContainsString('sign', $e->getMessage());
        }
    }
}
