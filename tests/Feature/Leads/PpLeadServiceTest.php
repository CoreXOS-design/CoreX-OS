<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PpLeadService;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * PP lead pull — the P24-parity intake channel. Proves: correct field mapping
 * into portal_leads, STRICT idempotent dedup by PP LeadId (re-pull = 0 dupes),
 * cursor advance, dormancy when the toggle is OFF, and SOAP-fault containment.
 */
final class PpLeadServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->agency = Agency::create([
            'name' => 'Coastal Realty', 'slug' => 'coastal',
            'pp_lead_pull_enabled' => true,
            'pp_username' => 'feed@coastal.co.za',
            'pp_password' => 'secret',
            'pp_branch_guid' => '6f0a1b2c-3d4e-5f6a-7b8c-9d0e1f2a3b4c',
        ]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->agent->id,
            'pp_ref'    => 'T2870133',
            'title'     => '3 bed house',
            'status'    => 'active',
        ]);

        ContactType::firstOrCreate(['name' => 'Buyer']);
        ContactSource::firstOrCreate(['name' => 'Private Property']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function service(): PpLeadService
    {
        return app(PpLeadService::class);
    }

    private function rawLead(array $overrides = []): array
    {
        return array_merge([
            'LeadId'            => 'PP-LEAD-1001',
            'Date'              => '2026-07-07T08:30:00',
            'PPRef'             => 'T2870133',
            'UniqueListingId'   => (string) $this->property->id,
            'FromName'          => 'Thabo Mokoena',
            'FromEmail'         => 'thabo@example.co.za',
            'FromContactNumber' => '083 555 1234',
            'ToEmail'           => 'agent@coastal.co.za',
            'Message'           => 'Is this still available?',
        ], $overrides);
    }

    public function test_pp_lead_maps_into_portal_leads_correctly(): void
    {
        Event::fake([NewPortalLeadReceived::class]);

        $lead = $this->service()->processLead($this->rawLead(), $this->agency);

        $this->assertNotNull($lead);
        $this->assertDatabaseHas('portal_leads', [
            'id'                 => $lead->id,
            'agency_id'          => $this->agency->id,
            'portal'             => PortalLead::PORTAL_PP,
            'name'               => 'Thabo Mokoena',
            'email'              => 'thabo@example.co.za',
            'phone'              => '083 555 1234',
            'message'            => 'Is this still available?',
            'listing_portal_ref' => 'T2870133',
            'listing_id'         => $this->property->id,
        ]);

        // Buyer contact auto-created, sourced Private Property, owned by the listing agent.
        $contact = Contact::where('email', 'thabo@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame($this->agent->id, $contact->created_by_user_id);
        $this->assertSame($contact->id, $lead->contact_id);

        // The dedup key is persisted.
        $this->assertSame('PP-LEAD-1001', $lead->lead_source_raw['__corex_lead_id']);

        Event::assertDispatched(NewPortalLeadReceived::class);
    }

    public function test_strict_dedup_by_lead_id_creates_zero_duplicates_on_repull(): void
    {
        Event::fake([NewPortalLeadReceived::class]);

        $first  = $this->service()->processLead($this->rawLead(), $this->agency);
        // Same LeadId, even with a different received time — must dedup.
        $second = $this->service()->processLead($this->rawLead(['Date' => '2026-07-07T09:00:00']), $this->agency);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A repeated PP LeadId must not create a second row.');
        $this->assertSame(1, PortalLead::where('portal', PortalLead::PORTAL_PP)->count());
    }

    public function test_pull_is_dormant_when_toggle_off(): void
    {
        $this->agency->update(['pp_lead_pull_enabled' => false]);

        // Bind a client that MUST NOT be called.
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->never();
        $client->shouldReceive('listingLeadDetailsFeed')->never();
        $this->app->instance(PrivatePropertySoapClient::class, $client);

        $result = $this->service()->pullForAllAgencies();

        $this->assertSame(['dormant' => true], $result);
        $this->assertSame(0, PortalLead::count());
    }

    public function test_full_pull_advances_cursor_and_ingests(): void
    {
        Event::fake([NewPortalLeadReceived::class]);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('listingLeadDetailsFeed')->once()->andReturn([
            'ListingLeadDetailsFeedResult' => [
                'ListingLeadDetail' => [ $this->rawLead() ],
            ],
        ]);
        $this->app->instance(PrivatePropertySoapClient::class, $client);

        $result = $this->service()->pullLeads($this->agency->fresh());

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, PortalLead::where('portal', PortalLead::PORTAL_PP)->count());

        // Cursor advanced past the lead's own timestamp.
        $cursor = Cache::get('pp.leads.cursor.agency.' . $this->agency->id);
        $this->assertNotNull($cursor);
        $this->assertTrue(
            \Carbon\Carbon::parse($cursor)->greaterThan(\Carbon\Carbon::parse('2026-07-07T08:30:00')),
            'Cursor must advance beyond the newest ingested lead.'
        );
    }

    public function test_soap_fault_is_contained_and_never_throws(): void
    {
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        // The SOAP client surfaces faults as an error envelope — not an exception.
        $client->shouldReceive('listingLeadDetailsFeed')->once()->andReturn([
            'error' => true, 'message' => 'Error Fetching http headers',
        ]);
        $this->app->instance(PrivatePropertySoapClient::class, $client);

        $result = $this->service()->pullLeads($this->agency->fresh());

        $this->assertSame(0, $result['inserted']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, PortalLead::count(), 'A fault must ingest nothing.');
    }
}
