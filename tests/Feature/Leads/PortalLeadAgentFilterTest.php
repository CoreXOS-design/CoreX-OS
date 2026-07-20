<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-308 — the Portal Leads agent filter.
 *
 * Johan ruling (a): "leads for agent X" = every enquiry on agent X's LISTINGS /
 * stock. The filter binds strictly to the listing's agent (primary OR co-listing
 * second agent) — NOT the enquiring contact's existing agent. The old filter also
 * matched on the contact's agent, so a lead on X's listing whose buyer belonged to
 * Y matched the "X" filter yet the row rendered "Y" (the display shows the listing
 * agent), making the filter look broken.
 *
 * A buyer already belonging to a different agent no longer widens the filter — it
 * surfaces as an informational cross-agent badge (agent name + contact-since +
 * last-interaction dates) so the receiving agent/BM can judge keep-vs-move.
 */
final class PortalLeadAgentFilterTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private \App\Models\Branch $branch;
    private User $agentX;   // the listing agent we filter to
    private User $agentY;   // the buyer's existing agent (a different agent)
    private User $agentZ;   // primary agent of a co-listed property
    private User $admin;    // super_admin → 'all' scope, acts for the HTTP calls

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = \App\Models\Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);

        $mk = fn (string $role, string $name) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'name' => $name,
        ]);

        $this->agentX = $mk('agent', 'Xavier Xolani');
        $this->agentY = $mk('agent', 'Yolanda Yates');
        $this->agentZ = $mk('agent', 'Zanele Zulu');
        $this->admin  = $mk('super_admin', 'Ada Admin');
    }

    private function listing(array $attrs): Property
    {
        $p = new Property();
        $p->forceFill(array_merge([
            'title' => 'Listing ' . uniqid(), 'status' => 'active',
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ], $attrs))->save();

        return $p;
    }

    private function lead(array $attrs): PortalLead
    {
        $lead = new PortalLead(array_merge([
            'portal' => PortalLead::PORTAL_P24, 'lead_type' => 'Email',
            'received_at' => now(), 'lead_source_raw' => [],
        ], $attrs));
        $lead->agency_id = $this->agency->id;
        $lead->save();

        return $lead;
    }

    public function test_filter_returns_leads_on_the_agents_listings_and_excludes_contact_agent_only_leads(): void
    {
        // A lead ON agent X's listing (buyer happens to belong to agent Y).
        $listingX = $this->listing(['agent_id' => $this->agentX->id]);
        $this->lead([
            'listing_id' => $listingX->id, 'name' => 'OnXsListing Buyer',
            'existing_contact_agent_id' => $this->agentY->id, 'contact_exists' => true,
        ]);

        // A lead on agent Y's listing whose buyer's existing agent is X. The OLD
        // filter matched this on the "X" filter (contact-agent branch); it must NOT.
        $listingY = $this->listing(['agent_id' => $this->agentY->id]);
        $this->lead([
            'listing_id' => $listingY->id, 'name' => 'ContactAgentOnly Buyer',
            'existing_contact_agent_id' => $this->agentX->id, 'contact_exists' => true,
        ]);

        // A co-listed property: primary = Z, second agent = X. X's stock too → included.
        $listingColist = $this->listing([
            'agent_id' => $this->agentZ->id, 'pp_second_agent_id' => $this->agentX->id,
        ]);
        $this->lead(['listing_id' => $listingColist->id, 'name' => 'CoListed Buyer']);

        $res = $this->actingAs($this->admin)
            ->get(route('corex.portal-leads.index', ['agent_id' => $this->agentX->id]));

        $res->assertOk();
        $res->assertSee('OnXsListing Buyer');   // on X's own listing → in
        $res->assertSee('CoListed Buyer');       // X is the second agent → in
        $res->assertDontSee('ContactAgentOnly Buyer'); // X only the contact's agent → OUT (the fix)
    }

    public function test_cross_agent_badge_renders_the_other_agent_and_the_keep_vs_move_dates(): void
    {
        $contact = Contact::forceCreate([
            'agency_id' => $this->agency->id, 'first_name' => 'Bea', 'last_name' => 'Buyer',
            'created_by_user_id' => $this->agentY->id,
            'created_at' => '2025-11-03 09:00:00',
            'last_contacted_at' => '2026-02-14 16:30:00',
        ]);

        $listingX = $this->listing(['agent_id' => $this->agentX->id]);
        $this->lead([
            'listing_id' => $listingX->id, 'name' => 'Bea Buyer',
            'contact_id' => $contact->id, 'contact_exists' => true,
            'existing_contact_agent_id' => $this->agentY->id,
        ]);

        $res = $this->actingAs($this->admin)
            ->get(route('corex.portal-leads.index', ['agent_id' => $this->agentX->id]));

        $res->assertOk();
        $res->assertSee('Xavier Xolani');          // Agent column = the LISTING agent
        $res->assertSee('Buyer known to Yolanda Yates'); // the cross-agent badge
        $res->assertSee('2025-11-03');             // contact-since date
        $res->assertSee('2026-02-14');             // last-interaction date
    }

    public function test_no_badge_when_the_buyer_belongs_to_the_listing_agent(): void
    {
        $contact = Contact::forceCreate([
            'agency_id' => $this->agency->id, 'first_name' => 'Sam', 'last_name' => 'Same',
            'created_by_user_id' => $this->agentX->id,
        ]);

        $listingX = $this->listing(['agent_id' => $this->agentX->id]);
        $this->lead([
            'listing_id' => $listingX->id, 'name' => 'Sam Same',
            'contact_id' => $contact->id, 'contact_exists' => true,
            'existing_contact_agent_id' => $this->agentX->id, // same as listing agent
        ]);

        $res = $this->actingAs($this->admin)
            ->get(route('corex.portal-leads.index', ['agent_id' => $this->agentX->id]));

        $res->assertOk();
        $res->assertSee('Sam Same');
        $res->assertDontSee('Buyer known to'); // no cross-agent badge — same agent
    }
}
