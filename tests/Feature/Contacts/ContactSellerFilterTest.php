<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression for the Contacts page "Seller" filter.
 *
 * Round 1 (too few results): the filter matched contact_property pivot role
 * 'owner' ONLY — but PropertyWizardController (every new sale listing) and
 * DeedsCaptureController's "link as seller" flow write role='seller', not
 * 'owner'. Most agencies' sellers never matched.
 *
 * Round 2 (too MANY results, wrong ones): the round-1 fix matched role IN
 * ['seller', 'owner']. Live data showed 'owner' is a generic Deeds Capture
 * "current owner of record" signal written for ANY contact who owns a
 * property — buyers who now own their purchase, plain owner contacts with no
 * sale intent — independent of selling. Of 52 contacts reachable only via
 * 'owner', the large majority were typed "Owner"/untyped/is_buyer, not
 * "Seller" — the filter surfaced buyers and owners alongside real sellers.
 *
 * Fix: match role 'seller' ONLY — the precise signal written exclusively by
 * an actual sale listing or the deliberate "link as seller" action. Mirrored
 * in the Export filter.
 *
 * The Landlord filter (esign_role 'lessor') doesn't share this problem:
 * 'landlord' is written specifically by the rental-listing flow (a dedicated,
 * intentional signal), not the generic ownership bucket 'owner' is.
 */
final class ContactSellerFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_filter_matches_contacts_linked_via_seller_pivot_role(): void
    {
        [$agencyId, $user, $sellerTypeId] = $this->seedAgencyUserAndSellerType();

        $propertyId = $this->seedProperty($agencyId, $user->id);

        $viaSellerRole = $this->makeContact($agencyId, $user->id, 'Via', 'SellerRole');
        $this->linkContactToProperty($viaSellerRole, $propertyId, 'seller');

        // A buyer who now owns their purchase (Deeds Capture "current owner of
        // record") — must NOT show up under Seller just because role='owner'.
        $buyerWhoOwns = $this->makeContact($agencyId, $user->id, 'Buyer', 'WhoOwns');
        $this->linkContactToProperty($buyerWhoOwns, $propertyId, 'owner');

        $unrelated = $this->makeContact($agencyId, $user->id, 'Unrelated', 'Tenant');
        $this->linkContactToProperty($unrelated, $propertyId, 'tenant');

        $response = $this->actingAs($user)
            ->get(route('corex.contacts.index', ['type' => $sellerTypeId, 'agent_id' => 'all']))
            ->assertOk();

        $ids = collect($response->viewData('contacts')->items())->pluck('id');

        $this->assertTrue($ids->contains($viaSellerRole->id), 'contact linked via role=seller is shown');
        $this->assertFalse($ids->contains($buyerWhoOwns->id), 'contact linked only via role=owner is excluded (not a seller signal)');
        $this->assertFalse($ids->contains($unrelated->id), 'unrelated tenant-role contact is excluded');
    }

    public function test_landlord_filter_matches_contacts_linked_via_landlord_pivot_role(): void
    {
        [$agencyId, $user, $landlordTypeId] = $this->seedAgencyUserAndType('Landlord', 'lessor');

        $propertyId = $this->seedProperty($agencyId, $user->id);

        $viaLandlordRole = $this->makeContact($agencyId, $user->id, 'Via', 'LandlordRole');
        $this->linkContactToProperty($viaLandlordRole, $propertyId, 'landlord');

        $unrelated = $this->makeContact($agencyId, $user->id, 'Unrelated', 'Buyer');
        $this->linkContactToProperty($unrelated, $propertyId, 'buyer');

        $response = $this->actingAs($user)
            ->get(route('corex.contacts.index', ['type' => $landlordTypeId, 'agent_id' => 'all']))
            ->assertOk();

        $ids = collect($response->viewData('contacts')->items())->pluck('id');

        $this->assertTrue($ids->contains($viaLandlordRole->id), 'contact linked via role=landlord is shown');
        $this->assertFalse($ids->contains($unrelated->id), 'unrelated buyer-role contact is excluded');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgencyUserAndSellerType(): array
    {
        return $this->seedAgencyUserAndType('Seller', 'seller');
    }

    private function seedAgencyUserAndType(string $typeName, string $esignRole): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        $typeId = (int) DB::table('contact_types')->insertGetId([
            'name' => $typeName . ' ' . Str::random(4), 'esign_role' => $esignRole,
            'sort_order' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $user, $typeId];
    }

    private function seedProperty(int $agencyId, int $userId, array $overrides = []): int
    {
        return (int) DB::table('properties')->insertGetId(array_merge([
            'external_id' => 'TEST-' . Str::random(8), 'title' => '14 Marine Drive',
            'address' => '14 Marine Drive', 'suburb' => 'Uvongo', 'price' => 1_850_000,
            'property_type' => 'house', 'status' => 'active', 'is_demo' => false,
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function makeContact(int $agencyId, int $userId, string $first, string $last): Contact
    {
        $id = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $userId, 'agent_id' => $userId,
            'first_name' => $first, 'last_name' => $last,
            'phone' => '0821234567',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Contact::withoutGlobalScopes()->findOrFail($id);
    }

    private function linkContactToProperty(Contact $contact, int $propertyId, string $role): void
    {
        DB::table('contact_property')->insert([
            'contact_id' => $contact->id, 'property_id' => $propertyId, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
