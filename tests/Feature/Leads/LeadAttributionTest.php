<?php

namespace Tests\Feature\Leads;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\NewPortalLeadAgentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT lead-ownership attribution hotfix — a portal lead notifies the listing agent
 * AND the matched buyer's agent (who may also be an admin). Every copy must state
 * whose listing it is, so an admin-who-is-also-an-agent can never mistake another
 * agent's client for their own.
 */
class LeadAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $listingAgent;   // Rochelle
    private User $buyersAgent;    // Elize (also admin) — the matched contact's agent
    private Property $property;
    private PortalLead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);

        $this->listingAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Rochelle Combrink',
        ]);
        $this->buyersAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Elize Reichel',
        ]);

        $this->property = Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Sea-view home', 'address' => '12 Marine Drive, Shelly Beach',
            'suburb' => 'Shelly Beach', 'agent_id' => $this->listingAgent->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ]));

        $this->lead = PortalLead::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'portal' => 'p24', 'lead_type' => 'enquiry',
            'lead_source_raw' => ['ref' => '117013602'], 'received_at' => now(), 'portal_listing_id' => 6060,
            'listing_id' => $this->property->id, 'listing_portal_ref' => '117013602', 'name' => 'Belinda Erasmus',
            'email' => 'belinda@example.co.za', 'phone' => '0821234567',
            'message' => 'Is this still available?', 'existing_contact_agent_id' => $this->buyersAgent->id,
        ]);
    }

    private function lines($mail): string
    {
        return implode(' | ', array_merge($mail->introLines ?? [], $mail->outroLines ?? []));
    }

    public function test_listing_agent_gets_the_act_now_agent_copy(): void
    {
        $mail = (new NewPortalLeadAgentNotification($this->lead))->toMail($this->listingAgent);

        $this->assertStringContainsString('on your listing', $mail->subject);
        $this->assertStringContainsString('12 Marine Drive', $mail->subject);
        $body = $this->lines($mail);
        $this->assertStringContainsString('your listing', $body);
        $this->assertStringContainsString('Reach out while the enquiry is hot', $body);
        $this->assertSame('Open the lead', $mail->actionText);
    }

    public function test_buyers_agent_gets_the_oversight_copy_attributed_to_the_listing_agent(): void
    {
        $mail = (new NewPortalLeadAgentNotification($this->lead))->toMail($this->buyersAgent);

        // Subject leads with attribution — FOR Rochelle, with the property.
        $this->assertStringContainsString('FOR Rochelle Combrink', $mail->subject);
        $this->assertStringContainsString('12 Marine Drive', $mail->subject);

        $body = $this->lines($mail);
        $this->assertStringContainsString('Rochelle Combrink', $body);         // whose listing (name may be **bold**)
        $this->assertStringContainsString("'s listing", $body);
        $this->assertStringContainsString('has been notified', $body);        // oversight, not action
        $this->assertStringContainsString('for oversight', $body);
        $this->assertStringNotContainsString('your listing', $body);           // never "your listing"
        $this->assertStringNotContainsString('Reach out while the enquiry is hot', $body);
        $this->assertSame('View lead (oversight)', $mail->actionText);         // not an act-now invite
        // Client details still present for reference.
        $this->assertStringContainsString('belinda@example.co.za', $body);
    }

    public function test_in_app_record_is_attributed_per_recipient(): void
    {
        $owner = (new NewPortalLeadAgentNotification($this->lead))->toArray($this->listingAgent);
        $this->assertFalse($owner['is_oversight']);
        $this->assertStringContainsString('your listing', $owner['title']);

        $oversight = (new NewPortalLeadAgentNotification($this->lead))->toArray($this->buyersAgent);
        $this->assertTrue($oversight['is_oversight']);
        $this->assertStringContainsString('Rochelle Combrink', $oversight['title']);
        $this->assertStringContainsString('notified', $oversight['body']);
    }
}
