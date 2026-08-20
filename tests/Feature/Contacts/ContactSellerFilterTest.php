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
 * Regression for the Contacts page "Seller" filter coming up blank for
 * agencies that do have sellers. The filter resolved a "Seller" ContactType
 * to esign_role='seller' and then matched contacts against a
 * contact_property pivot role of 'owner' ONLY — but PropertyWizardController
 * (every new sale listing) and DeedsCaptureController's "link as seller" flow
 * both write role='seller', not 'owner'. Only legacy/manually-linked contacts
 * ever carried 'owner', so most agencies' sellers never matched.
 *
 * Fix: match the canonical seller-side pivot set — ['seller', 'owner'], per
 * Property::pivotRolesForContactRole('seller_owner') — in both the index
 * filter and its mirrored Export filter.
 *
 * The same audit found an identical gap on the "Landlord" filter (esign_role
 * 'lessor'), which fell through to matching contact_type_id alone — a mostly-
 * unpopulated column — instead of the contact_property pivot role 'landlord'
 * that PropertyWizardController actually writes for rental listings.
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

        $viaOwnerRole = $this->makeContact($agencyId, $user->id, 'Via', 'OwnerRole');
        $this->linkContactToProperty($viaOwnerRole, $propertyId, 'owner');

        $unrelated = $this->makeContact($agencyId, $user->id, 'Unrelated', 'Tenant');
        $this->linkContactToProperty($unrelated, $propertyId, 'tenant');

        $response = $this->actingAs($user)
            ->get(route('corex.contacts.index', ['type' => $sellerTypeId, 'agent_id' => 'all']))
            ->assertOk();

        $ids = collect($response->viewData('contacts')->items())->pluck('id');

        $this->assertTrue($ids->contains($viaSellerRole->id), 'contact linked via role=seller is shown');
        $this->assertTrue($ids->contains($viaOwnerRole->id), 'contact linked via role=owner is still shown');
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
