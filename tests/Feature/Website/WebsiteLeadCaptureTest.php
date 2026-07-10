<?php

namespace Tests\Feature\Website;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/v1/website/leads — inbound website enquiry capture.
 *
 * Spec: .ai/specs/agency-public-api.md §9 (built).
 */
class WebsiteLeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function mintKey(Agency $agency, array $scopes): string
    {
        $minted = AgencyApiKey::mintSecret();
        AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'  => $agency->id,
            'name'       => 'Site',
            'key_prefix' => $minted['prefix'],
            'secret_hash'=> $minted['hash'],
            'scopes'     => $scopes,
        ]);
        return $minted['plaintext'];
    }

    public function test_website_lead_creates_portal_lead_contact_and_routes_to_listing_agent(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $branch = Branch::forceCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $listing = new Property();
        $listing->forceFill([
            'title' => 'Beachfront Villa', 'agent_id' => $agent->id,
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'status' => 'active',
        ])->save();

        $token = $this->mintKey($agency, [AgencyApiKey::SCOPE_LEADS_WRITE]);

        $resp = $this->withToken($token)->postJson('/api/v1/website/leads', [
            'source'            => 'website',
            'listing_id'        => $listing->id,
            'listing_reference' => 'HFC0042',
            'agent_ids'         => [$agent->id],
            'name'              => 'Jane Smith',
            'email'             => 'jane@example.com',
            'phone'             => '+27 82 123 4567',
            'message'           => "I'm interested in this property.",
        ])->assertCreated();

        $resp->assertJson([
            'ok'              => true,
            'contact_matched' => false,
            'listing_id'      => $listing->id,
        ]);
        $this->assertContains($agent->id, $resp->json('assigned_agent_ids'));

        $lead = PortalLead::withoutGlobalScope(AgencyScope::class)->find($resp->json('lead_id'));
        $this->assertSame(PortalLead::PORTAL_WEBSITE, $lead->portal);
        $this->assertSame($listing->id, $lead->listing_id);
        $this->assertSame('jane@example.com', $lead->email);

        // Enquirer captured as a contact in the agency CRM.
        $this->assertNotNull($lead->contact_id);
        $contact = Contact::withoutGlobalScopes()->find($lead->contact_id);
        $this->assertSame($agency->id, $contact->agency_id);
        $this->assertSame($agent->id, $contact->created_by_user_id);
    }

    public function test_empty_phone_string_is_accepted(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $branch = Branch::forceCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $listing = new Property();
        $listing->forceFill(['title' => 'X', 'agent_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id, 'status' => 'active'])->save();

        $token = $this->mintKey($agency, [AgencyApiKey::SCOPE_LEADS_WRITE]);

        $this->withToken($token)->postJson('/api/v1/website/leads', [
            'source'     => 'website',
            'listing_id' => $listing->id,
            'name'       => 'No Phone',
            'email'      => 'nophone@example.com',
            'phone'      => '',
        ])->assertCreated();
    }

    public function test_website_lead_notifies_listing_agent_only_not_matched_contact_owner(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $branch = Branch::forceCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        $listingAgent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $otherAgent   = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $listing = new Property();
        $listing->forceFill([
            'title' => 'Villa', 'agent_id' => $listingAgent->id,
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'status' => 'active',
        ])->save();

        // The enquirer already exists as a contact OWNED BY a different agent.
        $existing = new Contact();
        $existing->forceFill([
            'first_name' => 'Repeat', 'last_name' => 'Buyer', 'email' => 'repeat@example.com',
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'created_by_user_id' => $otherAgent->id,
        ])->save();

        $token = $this->mintKey($agency, [AgencyApiKey::SCOPE_LEADS_WRITE]);

        $resp = $this->withToken($token)->postJson('/api/v1/website/leads', [
            'source' => 'website', 'listing_id' => $listing->id,
            'name' => 'Repeat Buyer', 'email' => 'repeat@example.com',
        ])->assertCreated();

        // Routed to the listing agent only — the matched contact's owner is NOT notified.
        $this->assertSame([$listingAgent->id], $resp->json('assigned_agent_ids'));
        $this->assertNotContains($otherAgent->id, $resp->json('assigned_agent_ids'));

        // ...but the contact owner is still RECORDED on the lead (visibility intact).
        $lead = PortalLead::withoutGlobalScope(AgencyScope::class)->find($resp->json('lead_id'));
        $this->assertTrue((bool) $lead->contact_exists);
        $this->assertSame($otherAgent->id, $lead->existing_contact_agent_id);
        $this->assertSame([$listingAgent->id], $lead->agentIds());
    }

    public function test_key_without_leads_write_scope_is_forbidden(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $token  = $this->mintKey($agency, [AgencyApiKey::SCOPE_LISTINGS_READ]);

        $this->withToken($token)->postJson('/api/v1/website/leads', [
            'listing_id' => 1, 'name' => 'Jane', 'email' => 'jane@example.com',
        ])->assertStatus(403);
    }

    public function test_foreign_listing_id_never_resolves_cross_tenant(): void
    {
        $agencyA = Agency::create(['name' => 'A', 'slug' => 'a', 'website_enabled' => true]);
        Branch::forceCreate(['agency_id' => $agencyA->id, 'name' => 'Main']);
        $agencyB = Agency::create(['name' => 'B', 'slug' => 'b', 'website_enabled' => true]);
        $branchB = Branch::forceCreate(['agency_id' => $agencyB->id, 'name' => 'Main']);
        $agentB  = User::factory()->create(['agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'role' => 'agent']);
        $listingB = new Property();
        $listingB->forceFill(['title' => 'B villa', 'agent_id' => $agentB->id, 'agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'status' => 'active'])->save();

        // Agency A key submits agency B's listing id — must NOT anchor to it.
        $token = $this->mintKey($agencyA, [AgencyApiKey::SCOPE_LEADS_WRITE]);

        $resp = $this->withToken($token)->postJson('/api/v1/website/leads', [
            'listing_id' => $listingB->id,
            'name'       => 'Cross Tenant',
            'email'      => 'cross@example.com',
        ])->assertCreated();

        $this->assertNull($resp->json('listing_id'), 'A foreign listing id must not resolve.');
    }
}
