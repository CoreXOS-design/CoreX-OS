<?php

namespace Tests\Feature\Api\Client;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests for the Client Seller Insights API.
 * Spec: .ai/specs/client-seller-insights.md
 *
 * Shares the same DB-test caveat as ClientAuthFlowTest — these pass once the
 * test DB is up (MySQL/MariaDB on PATH, see CLAUDE.md non-negotiable #12a).
 */
class ClientSellerInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $name = 'Agency A'): Agency
    {
        $agency = Agency::create(['name' => $name, 'slug' => str()->slug($name . '-' . uniqid())]);
        Branch::create([
            'agency_id' => $agency->id,
            'name'      => $name . ' Main',
            'code'      => 'MAIN-' . $agency->id,
            'is_active' => true,
        ]);
        return $agency;
    }

    private function makeContact(Agency $agency, array $overrides = []): Contact
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');
        return Contact::query()->withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id'  => $agency->id,
            'branch_id'  => $branchId,
            'first_name' => 'Sally',
            'last_name'  => 'Seller',
            'phone'      => '0820000000',
            'email'      => 'seller+' . uniqid() . '@example.com',
        ], $overrides));
    }

    private function makeProperty(Agency $agency, array $overrides = []): Property
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');
        return Property::query()->withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branchId,
            'title'     => 'Beach House',
            'suburb'    => 'Shelly Beach',
            'status'    => 'active',
            'price'     => 2500000,
        ], $overrides));
    }

    private function linkSeller(Contact $contact, Property $property, string $role = 'seller'): void
    {
        DB::table('contact_property')->insert([
            'contact_id'  => $contact->id,
            'property_id' => $property->id,
            'role'        => $role,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function authClient(Agency $agency, Contact $contact): string
    {
        $cu = ClientUser::create([
            'email'             => $contact->email,
            'password'          => Hash::make('pw-12345678'),
            'current_agency_id' => $agency->id,
        ]);
        $contact->forceFill(['client_user_id' => $cu->id])->save();
        return $cu->createToken('t', ['client'])->plainTextToken;
    }

    public function test_index_lists_seller_linked_properties_only(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $mine    = $this->makeProperty($agency, ['title' => 'My Listing']);
        $other   = $this->makeProperty($agency, ['title' => 'Not Mine']);
        $this->linkSeller($contact, $mine, 'seller');

        $token = $this->authClient($agency, $contact);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/seller-properties');

        $res->assertOk()
            ->assertJsonCount(1, 'properties')
            ->assertJsonPath('properties.0.id', $mine->id)
            ->assertJsonPath('properties.0.role', 'seller')
            ->assertJsonStructure(['agency_id', 'properties' => [['id', 'title', 'role', 'headline' => ['viewings', 'days_on_market']]]]);
    }

    public function test_insights_returns_seller_payload_for_linked_property(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $property = $this->makeProperty($agency);
        $this->linkSeller($contact, $property, 'owner');

        $token = $this->authClient($agency, $contact);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/client/seller-properties/{$property->id}/insights");

        $res->assertOk()
            ->assertJsonPath('property.id', $property->id)
            ->assertJsonPath('role', 'owner')
            ->assertJsonStructure([
                'property', 'role', 'agency', 'performance' => ['viewings', 'days_on_market'],
                'feedback_summary', 'agent_insights', 'marketing_activity', 'comparables',
                'listing_status' => ['published', 'mandate_active'], 'last_refreshed_at',
            ]);
    }

    public function test_insights_404_when_not_seller_linked(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $property = $this->makeProperty($agency);
        // No linkSeller — client is not the seller.

        $token = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/client/seller-properties/{$property->id}/insights")
            ->assertStatus(404);
    }

    public function test_buyer_role_link_does_not_grant_seller_insights(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $property = $this->makeProperty($agency);
        $this->linkSeller($contact, $property, 'buyer'); // non-seller role

        $token = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/client/seller-properties/{$property->id}/insights")
            ->assertStatus(404);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/seller-properties')
            ->assertOk()
            ->assertJsonCount(0, 'properties');
    }

    public function test_requires_client_ability(): void
    {
        $this->getJson('/api/v1/client/seller-properties')->assertStatus(401);
    }
}
