<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PpLeadService;
use App\Services\Syndication\Property24\P24LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * QA1 scope-fix (2026-10-07, audit defect 3) — the lead-source lookup by NAME in the
 * Property24 / Private Property lead paths ran with no logged-in user, so
 * BelongsToAgency's AgencyScope did not filter and `where('name', 'Property24')`
 * returned whichever agency's row sorted first. A lead for the SECOND agency was
 * stamped with the FIRST agency's source id. Every ingress now looks the source up
 * through ContactSource::idForAgencyByName($agencyId, $name).
 *
 * Agency 1 is created first on purpose, so its source rows have the LOWER ids — the
 * ones the old unscoped lookup would have returned for agency 2.
 */
final class LeadSourceAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $first;
    private Agency $second;
    private User $secondAgent;
    private ContactSource $firstP24;
    private ContactSource $firstPp;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.private_property.webhook_secret', 'test-webhook-secret');

        $this->first  = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->second = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid(), 'pp_lead_pull_enabled' => true]);

        $this->firstP24 = $this->source($this->first, 'Property24');
        $this->firstPp  = $this->source($this->first, 'Private Property');

        $branch = Branch::forceCreate(['name' => 'Sea Point', 'agency_id' => $this->second->id]);
        $this->secondAgent = User::factory()->create([
            'agency_id' => $this->second->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);

        ContactType::firstOrCreate(['name' => 'Buyer'], ['esign_role' => 'buyer']);
    }

    private function source(Agency $agency, string $name): ContactSource
    {
        // agency_id given explicitly: BelongsToAgency trusts it when there is no logged-in user.
        return ContactSource::forceCreate(['agency_id' => $agency->id, 'name' => $name, 'color' => '#6366f1', 'sort_order' => 1, 'is_active' => true]);
    }

    private function secondAgencyListing(array $refs): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id' => $this->second->id, 'agent_id' => $this->secondAgent->id,
            'title' => 'Sea Point flat', 'status' => 'active', 'listing_type' => 'sale',
        ], $refs));
    }

    public function test_helper_returns_only_the_named_agencys_own_row(): void
    {
        $secondP24 = $this->source($this->second, 'Property24');

        $this->assertSame($this->firstP24->id, ContactSource::idForAgencyByName($this->first->id, 'Property24'));
        $this->assertSame($secondP24->id, ContactSource::idForAgencyByName($this->second->id, 'Property24'));
        $this->assertNotSame($this->firstP24->id, $secondP24->id);
    }

    public function test_helper_returns_null_rather_than_another_agencys_row_when_the_agency_has_none(): void
    {
        // Agency 2 has no 'Property24' source; agency 1 does. The old lookup returned agency 1's id.
        $this->assertNull(ContactSource::idForAgencyByName($this->second->id, 'Property24'));
        $this->assertNull(ContactSource::idForAgencyByName($this->second->id, 'No Such Source'));
    }

    public function test_p24_lead_for_the_second_agency_gets_the_second_agencys_source(): void
    {
        $secondP24 = $this->source($this->second, 'Property24');
        $this->secondAgencyListing(['p24_ref' => 'P24-SEC-1']);

        app(P24LeadService::class)->processLead([
            'listingNumber' => 'P24-SEC-1',
            'leadName'      => 'Second Agency Enquirer',
            'leadEmail'     => 'second-p24@example.co.za',
            'contactNumber' => '0821110000',
            'message'       => 'Still available?',
        ], $this->second);

        $contact = Contact::withoutGlobalScopes()->where('email', 'second-p24@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame($this->second->id, (int) $contact->agency_id);
        $this->assertSame($secondP24->id, (int) $contact->contact_source_id);
        $this->assertNotSame($this->firstP24->id, (int) $contact->contact_source_id);
    }

    public function test_p24_lead_for_an_agency_with_no_source_row_is_never_given_another_agencys(): void
    {
        $this->secondAgencyListing(['p24_ref' => 'P24-SEC-2']);

        app(P24LeadService::class)->processLead([
            'listingNumber' => 'P24-SEC-2',
            'leadName'      => 'No Source Enquirer',
            'leadEmail'     => 'no-source-p24@example.co.za',
            'contactNumber' => '0821110001',
            'message'       => 'Hello',
        ], $this->second);

        $contact = Contact::withoutGlobalScopes()->where('email', 'no-source-p24@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertNull($contact->contact_source_id, 'must not borrow agency 1\'s Property24 source');
    }

    public function test_pp_pull_lead_for_the_second_agency_gets_the_second_agencys_source(): void
    {
        $secondPp = $this->source($this->second, 'Private Property');
        $listing  = $this->secondAgencyListing(['pp_ref' => 'PP-SEC-1']);

        app(PpLeadService::class)->processLead([
            'LeadId'            => 'PP-LEAD-SEC-1',
            'Date'              => '2026-10-07T08:30:00',
            'PPRef'             => 'PP-SEC-1',
            'UniqueListingId'   => (string) $listing->id,
            'FromName'          => 'Second PP Enquirer',
            'FromEmail'         => 'second-pp@example.co.za',
            'FromContactNumber' => '0821110002',
            'Message'           => 'Parking?',
        ], $this->second);

        $contact = Contact::withoutGlobalScopes()->where('email', 'second-pp@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame($secondPp->id, (int) $contact->contact_source_id);
        $this->assertNotSame($this->firstPp->id, (int) $contact->contact_source_id);
    }

    public function test_pp_webhook_lead_for_the_second_agency_gets_the_second_agencys_source(): void
    {
        $secondPp = $this->source($this->second, 'Private Property');
        $listing  = $this->secondAgencyListing([]);

        $body = json_encode([
            'messageType' => 'Lead', 'listingExternalReference' => (string) $listing->id, 'leadId' => 'PP-WH-SEC-1',
            'leadName' => 'Webhook Second', 'leadEmail' => 'second-wh@example.co.za', 'leadPhoneNumber' => '0821110003',
            'leadMessage' => 'Hi',
        ]);
        $this->call('POST', '/api/pp/webhook', [], [], [], [
            'HTTP_X-Signature' => base64_encode(hash_hmac('sha256', $body, 'test-webhook-secret', true)),
            'CONTENT_TYPE'     => 'application/json',
        ], $body)->assertStatus(200);

        $contact = Contact::withoutGlobalScopes()->where('email', 'second-wh@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame($secondPp->id, (int) $contact->contact_source_id);
        $this->assertNotSame($this->firstPp->id, (int) $contact->contact_source_id);
    }
}
