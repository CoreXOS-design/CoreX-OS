<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Property → Contacts tab "Link Existing Contact" picker.
 *
 * Regression: the picker applied the role-based ContactScope ('own' for agents),
 * so an agent searching for a contact captured by another agent saw nothing and
 * was pushed into creating a duplicate. A link picker must surface ANY existing
 * agency contact (Non-Negotiable #10 — Universal Match-or-Create), while still
 * honouring agency isolation (AgencyScope) and soft-deletes.
 */
final class PropertyContactPickerScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Agent can find an agency contact created by someone else (ContactScope bypassed). */
    public function test_search_returns_contact_created_by_another_user(): void
    {
        [$agencyId, $propertyId, $agent, $otherUser] = $this->seedFixture();

        $contact = $this->makeContact($agencyId, $otherUser->id, 'Andre', 'Roets');

        $resp = $this->actingAs($agent)->getJson(
            route('corex.properties.contacts.search', $propertyId) . '?q=Andre'
        );

        $resp->assertOk();
        $resp->assertJsonFragment(['id' => $contact->id]);
    }

    /** Agency isolation still holds — a contact in another agency is never returned. */
    public function test_search_excludes_other_agency_contact(): void
    {
        [$agencyId, $propertyId, $agent] = $this->seedFixture();
        $otherAgencyId = $this->makeAgency();

        $foreign = $this->makeContact($otherAgencyId, null, 'Andre', 'Foreign');

        $resp = $this->actingAs($agent)->getJson(
            route('corex.properties.contacts.search', $propertyId) . '?q=Andre'
        );

        $resp->assertOk();
        $resp->assertJsonMissing(['id' => $foreign->id]);
    }

    /** Already-linked contacts are excluded so they can't be linked twice. */
    public function test_search_excludes_already_linked_contact(): void
    {
        [$agencyId, $propertyId, $agent, $otherUser] = $this->seedFixture();

        $contact = $this->makeContact($agencyId, $otherUser->id, 'Andre', 'Linked');
        DB::table('contact_property')->insert([
            'contact_id' => $contact->id, 'property_id' => $propertyId,
            'role' => 'buyer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->actingAs($agent)->getJson(
            route('corex.properties.contacts.search', $propertyId) . '?q=Andre'
        );

        $resp->assertOk();
        $resp->assertJsonMissing(['id' => $contact->id]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:User,3:User} [agencyId, propertyId, agent, otherUser] */
    private function seedFixture(): array
    {
        $agencyId = $this->makeAgency();

        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);
        $otherUser = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);

        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'TEST-' . Str::random(8),
            'title' => 'Test Property',
            'address' => '18 Golf Course Road',
            'suburb' => 'Uvongo',
            'price' => 1_200_000,
            'status' => 'active',
            'is_demo' => false,
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'agent_id' => $agent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $propertyId, $agent, $otherUser];
    }

    private function makeAgency(): int
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
        return $agencyId;
    }

    private function makeContact(int $agencyId, ?int $createdBy, string $first, string $last): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $createdBy,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => '0813230105',
            'email' => Str::lower($first . '.' . $last) . '@example.com',
        ]);
    }
}
