<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Link-time FICA gate (Johan, post-incident — property "30 Captain Smith" /
 * Kym Pollard, 2026-08-05): "if an agent picks up a contact and wants to link
 * them to a property they have to pass FICA compliance first." An agent may
 * not attach a contact to a property as owner/seller/landlord/lessor unless
 * that contact has a genuine, current FICA approval — a stale/deleted/bulk-
 * import-auto-approved record does NOT count (FicaMarketingGateTest covers
 * that check itself; this file proves the gate actually blocks the HTTP
 * action an agent would take).
 *
 * Both directions are covered: linking FROM the contact page
 * (ContactPropertyController) and FROM the property page
 * (PropertyContactController) — an agent could reach either.
 */
final class FicaLinkGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_linking_from_contact_page_is_blocked_for_stale_import_only_fica(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $seller = $this->contact($agencyId, $agent->id);
        $property = $this->property($agencyId, $agent->id);
        $this->bulkImportApproval($seller->id, $agencyId, $agent->id)->delete();

        $response = $this->actingAs($agent)->post(route('corex.contacts.properties.link', $seller), [
            'property_id' => $property->id,
            'role' => 'seller',
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertStringContainsString(
            'must pass FICA verification',
            (string) session('error'),
            'the block message must clearly explain why'
        );
        $this->assertDatabaseMissing('contact_property', ['contact_id' => $seller->id, 'property_id' => $property->id]);
    }

    public function test_linking_from_contact_page_is_allowed_for_genuine_current_fica(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $seller = $this->contact($agencyId, $agent->id);
        $property = $this->property($agencyId, $agent->id);
        $this->genuineApproval($seller->id, $agencyId, $agent->id);

        $response = $this->actingAs($agent)->post(route('corex.contacts.properties.link', $seller), [
            'property_id' => $property->id,
            'role' => 'seller',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('contact_property', ['contact_id' => $seller->id, 'property_id' => $property->id, 'role' => 'seller']);
    }

    public function test_linking_from_property_page_is_blocked_for_stale_import_only_fica(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $seller = $this->contact($agencyId, $agent->id);
        $property = $this->property($agencyId, $agent->id);
        $this->bulkImportApproval($seller->id, $agencyId, $agent->id)->delete();

        $response = $this->actingAs($agent)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('corex.properties.contacts.link', $property), [
                'contact_id' => $seller->id,
                'role' => 'seller',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'fica_not_approved');
        $response->assertJsonPath('contact_id', $seller->id);
        $this->assertDatabaseMissing('contact_property', ['contact_id' => $seller->id, 'property_id' => $property->id]);
    }

    public function test_linking_from_property_page_is_allowed_for_genuine_current_fica(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $seller = $this->contact($agencyId, $agent->id);
        $property = $this->property($agencyId, $agent->id);
        $this->genuineApproval($seller->id, $agencyId, $agent->id);

        $response = $this->actingAs($agent)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('corex.properties.contacts.link', $property), [
                'contact_id' => $seller->id,
                'role' => 'seller',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $this->assertDatabaseHas('contact_property', ['contact_id' => $seller->id, 'property_id' => $property->id, 'role' => 'seller']);
    }

    public function test_buyer_role_is_not_gated_by_fica(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->contact($agencyId, $agent->id); // no FICA at all
        $property = $this->property($agencyId, $agent->id);

        $response = $this->actingAs($agent)->post(route('corex.contacts.properties.link', $buyer), [
            'property_id' => $property->id,
            'role' => 'buyer',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('contact_property', ['contact_id' => $buyer->id, 'property_id' => $property->id, 'role' => 'buyer']);
    }

    // ── Harness ───────────────────────────────────────────────────────────

    private function bulkImportApproval(int $contactId, int $agencyId, int $agentId): FicaSubmission
    {
        return FicaSubmission::create([
            'contact_id' => $contactId, 'agency_id' => $agencyId, 'requested_by' => $agentId,
            'entity_type' => 'natural', 'status' => 'approved',
            'verification_method' => ['source' => FicaSubmission::BULK_IMPORT_SOURCE],
            'verified_by' => $agentId, 'verified_at' => now(),
        ]);
    }

    private function genuineApproval(int $contactId, int $agencyId, int $agentId): FicaSubmission
    {
        return FicaSubmission::create([
            'contact_id' => $contactId, 'agency_id' => $agencyId, 'requested_by' => $agentId,
            'entity_type' => 'natural', 'status' => 'approved',
            'verification_method' => ['source' => 'co_review'],
            'verified_by' => $agentId, 'verified_at' => now(),
        ]);
    }

    /** @return array{0:int,1:User} */
    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);

        return [$agencyId, $agent];
    }

    private function contact(int $agencyId, int $agentId): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $agentId,
            'first_name' => 'Test', 'last_name' => 'Contact ' . Str::random(5),
            'phone' => '082' . random_int(1000000, 9999999),
        ]);
    }

    private function property(int $agencyId, int $agentId): Property
    {
        return Property::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $agentId,
            'title' => 'Test property', 'address' => Str::random(6) . ' Test Road',
            'status' => 'draft', 'listing_type' => 'sale', 'price' => 850000,
        ]);
    }
}
