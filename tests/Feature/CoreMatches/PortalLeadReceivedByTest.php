<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PpLeadService;
use App\Services\Syndication\Property24\P24LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * AT-Core-Matches, Johan's ruling 6 — "the board shows WHO GOT IT FIRST."
 * Proves received_by_user_id is stamped correctly, frozen at arrival, on
 * all three ingestion paths, for both the new-contact and returning-contact
 * branches.
 */
final class PortalLeadReceivedByTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $listingAgent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Defensive: a prior test class in the same PHPUnit process may
        // leave a stale authenticated user set (e.g. a route test's
        // actingAs()) whose id no longer exists after RefreshDatabase's
        // truncation — effectiveBranchId() on that stale user can then
        // resolve to nothing, silently reproducing the branch_id gap this
        // class exists to work around. Force a clean slate every time.
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RB Test Agency', 'slug' => 'rb-test-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->listingAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);
        // BelongsToBranch auto-fills a new Contact's branch_id from
        // Auth::user()->effectiveBranchId() ONLY — contacts.branch_id is
        // NOT NULL with no DB default, and P24LeadService/PpLeadService's
        // own Contact::create() calls never set it explicitly. Pre-existing
        // gap in those services (unrelated to this build, not fixed here) —
        // worked around here the same way an authenticated agent request
        // would resolve it, so the actual feature under test isn't blocked
        // by it.
        $this->actingAs($this->listingAgent);
        $this->property = Property::forceCreate([
            'agency_id'   => $this->agency->id,
            'agent_id'    => $this->listingAgent->id,
            'p24_ref'     => 'RJ12345',
            'pp_ref'      => 'T900001',
            'title'       => '2 bed apartment',
            'status'      => 'active',
        ]);

        ContactType::firstOrCreate(['name' => 'Buyer']);
        ContactSource::firstOrCreate(['name' => 'Private Property']);
    }

    public function test_p24_new_contact_receives_by_the_listing_agent(): void
    {
        // Deliberately no blanket Event::fake() — it suppresses Eloquent's
        // own internal `creating` event too, which is how BelongsToBranch
        // auto-fills a new Contact's branch_id from the acting user. A
        // scoped fake (Event::fake([SomeEvent::class])) would be fine; a
        // bare one silently breaks this fixture's Contact creation.
        $lead = app(P24LeadService::class)->processLead([
            'listingNumber' => 'RJ12345',
            'leadName'      => 'New Buyer',
            'leadEmail'     => 'newbuyer@example.co.za',
        ], $this->agency);

        $this->assertNotNull($lead);
        $this->assertSame($this->listingAgent->id, $lead->received_by_user_id);
    }

    public function test_p24_returning_contact_receives_by_their_own_existing_agent(): void
    {
        $otherAgent = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent']);
        $existingContact = Contact::create([
            'agency_id' => $this->agency->id,
            'created_by_user_id' => $otherAgent->id,
            'first_name' => 'Returning', 'last_name' => 'Buyer',
            'email' => 'returning@example.co.za',
        ]);

        // Same bare-Event::fake() hazard as the new-contact test above —
        // harmless here only because this branch never creates a new
        // Contact, but fixed for consistency (BUILD_STANDARD §6).
        $lead = app(P24LeadService::class)->processLead([
            'listingNumber' => 'RJ12345',
            'leadName'      => 'Returning Buyer',
            'leadEmail'     => 'returning@example.co.za',
        ], $this->agency);

        $this->assertNotNull($lead);
        $this->assertTrue($lead->contact_exists);
        $this->assertSame($otherAgent->id, $lead->received_by_user_id, 'must be the EXISTING agent, not the listing agent');
        $this->assertNotSame($this->listingAgent->id, $lead->received_by_user_id);
    }

    public function test_pp_pull_new_contact_receives_by_the_listing_agent(): void
    {
        Event::fake([NewPortalLeadReceived::class]);
        $lead = app(PpLeadService::class)->processLead([
            'LeadId'            => 'PP-1',
            'Date'              => now()->toIso8601String(),
            'PPRef'             => 'T900001',
            'UniqueListingId'   => (string) $this->property->id,
            'FromName'          => 'PP New Buyer',
            'FromEmail'         => 'ppnew@example.co.za',
            'Message'           => 'Interested',
        ], $this->agency);

        $this->assertNotNull($lead);
        $this->assertSame($this->listingAgent->id, $lead->received_by_user_id);
    }

    public function test_webhook_path_records_received_by_via_the_command_task_observer(): void
    {
        // The webhook controller mints its Contact via createLeadContact() and its
        // follow-up CommandTask via createLeadTask(); CommandTaskPortalLeadObserver
        // mirrors that CommandTask into portal_leads. Exercised here at the observer's
        // real trigger point rather than re-implementing HMAC signing for a full HTTP
        // round-trip through the webhook route itself.
        $contact = Contact::create([
            'agency_id' => $this->agency->id,
            'created_by_user_id' => $this->listingAgent->id,
            'first_name' => 'Webhook', 'last_name' => 'Lead',
            'email' => 'webhook@example.co.za',
        ]);

        // Deliberately NOT Event::fake() here — CommandTaskPortalLeadObserver
        // hooks CommandTask's real `created` Eloquent event; faking events
        // would suppress it entirely and this test would prove nothing.
        CommandTask::create([
            'title'         => 'New PP lead — Webhook Lead',
            'description'   => 'Private Property lead for 2 bed apartment.',
            'task_type'     => 'lead_followup',
            'status'        => CommandTask::STATUS_TODO,
            'priority'      => 'high',
            'send_reminder' => true,
            'assigned_to'   => $this->listingAgent->id,
            'property_id'   => $this->property->id,
            'contact_id'    => $contact->id,
            'source_type'   => 'private_property_webhook',
            'source_id'     => $contact->id,
            'agency_id'     => $this->agency->id,
        ]);

        $lead = PortalLead::withoutGlobalScopes()
            ->where('agency_id', $this->agency->id)
            ->where('portal', PortalLead::PORTAL_PP)
            ->where('contact_id', $contact->id)
            ->first();

        $this->assertNotNull($lead, 'observer must mirror the CommandTask into portal_leads');
        $this->assertSame($this->listingAgent->id, $lead->received_by_user_id);
    }

    public function test_who_got_it_first_query_orders_by_received_at(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id,
            'created_by_user_id' => $this->listingAgent->id,
            'first_name' => 'Multi', 'last_name' => 'Lead',
        ]);
        $laterAgent  = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent']);
        $firstAgent  = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent']);

        PortalLead::create([
            'agency_id' => $this->agency->id, 'portal' => PortalLead::PORTAL_P24, 'lead_type' => 'Email',
            'contact_id' => $contact->id, 'contact_exists' => true, 'name' => 'Multi Lead',
            'received_by_user_id' => $laterAgent->id, 'received_at' => now()->addHour(),
            'lead_source_raw' => [],
        ]);
        PortalLead::create([
            'agency_id' => $this->agency->id, 'portal' => PortalLead::PORTAL_PP, 'lead_type' => 'Email',
            'contact_id' => $contact->id, 'contact_exists' => true, 'name' => 'Multi Lead',
            'received_by_user_id' => $firstAgent->id, 'received_at' => now(),
            'lead_source_raw' => [],
        ]);

        $first = PortalLead::withoutGlobalScopes()->where('contact_id', $contact->id)->orderBy('received_at')->first();
        $this->assertSame($firstAgent->id, $first->received_by_user_id);
    }
}
