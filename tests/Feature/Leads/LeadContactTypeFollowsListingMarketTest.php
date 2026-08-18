<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PpLeadService;
use App\Services\Syndication\Property24\P24LeadService;
use App\Services\Website\WebsiteLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Live bug (2026-08-18): contact 16355 came in as a P24 RENTAL enquiry but
 * was created typed as 'Buyer' — Johan saw it "on sales stock" instead of
 * rental. Root cause: P24LeadService/PpLeadService/WebsiteLeadService::
 * resolveContact() all hardcoded contact_type_id to 'Buyer' regardless of
 * the enquired listing's listing_type. Scope check on live: 118 of 119
 * rental-listing leads that created a new contact were mis-typed this way
 * — systemic across all three intake channels, not a one-off.
 *
 * Fix: resolve the listing's listing_type at contact-creation time and pick
 * 'Tenant' for a rental enquiry, 'Buyer' otherwise (existing fallback to
 * 'Lead' preserved). is_buyer / BuyerLeadCascadeService are untouched — the
 * Buyer Pipeline already correctly separates rental wishlists via
 * ContactMatch.listing_type (BuyerPipelineController::applyLeadTypeFilter),
 * this fix is purely about the CONTACT's own type, not the pipeline.
 */
final class LeadContactTypeFollowsListingMarketTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->agency = Agency::create([
            'name' => 'Coastal Realty', 'slug' => 'coastal',
            'pp_lead_pull_enabled' => true,
        ]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);

        ContactType::firstOrCreate(['name' => 'Buyer']);
        ContactType::firstOrCreate(['name' => 'Tenant']);
        ContactSource::firstOrCreate(['name' => 'Property24']);
        ContactSource::firstOrCreate(['name' => 'Private Property']);
    }

    private function property(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'    => $this->agency->id,
            'agent_id'     => $this->agent->id,
            'title'        => 'Test listing',
            'status'       => 'active',
            'listing_type' => 'sale',
        ], $overrides));
    }

    // ──────────────────────────────── P24 ────────────────────────────────

    public function test_p24_rental_enquiry_creates_a_tenant_contact(): void
    {
        $listing = $this->property(['listing_type' => 'rental', 'p24_ref' => 'P24-RENTAL-1']);

        app(P24LeadService::class)->processLead([
            'listingNumber' => 'P24-RENTAL-1',
            'leadName'      => 'Rental Enquirer',
            'leadEmail'     => 'rental-enquirer@example.co.za',
            'contactNumber' => '0820001111',
            'message'       => 'Are water and electricity included?',
        ], $this->agency);

        $contact = Contact::where('email', 'rental-enquirer@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Tenant', $contact->type?->name);
    }

    public function test_p24_sale_enquiry_creates_a_buyer_contact(): void
    {
        $listing = $this->property(['listing_type' => 'sale', 'p24_ref' => 'P24-SALE-1']);

        app(P24LeadService::class)->processLead([
            'listingNumber' => 'P24-SALE-1',
            'leadName'      => 'Sale Enquirer',
            'leadEmail'     => 'sale-enquirer@example.co.za',
            'contactNumber' => '0820002222',
            'message'       => 'Is this still available?',
        ], $this->agency);

        $contact = Contact::where('email', 'sale-enquirer@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Buyer', $contact->type?->name);
    }

    // ──────────────────────────────── PP ──────────────────────────────────

    public function test_pp_rental_enquiry_creates_a_tenant_contact(): void
    {
        $listing = $this->property(['listing_type' => 'rental', 'pp_ref' => 'PP-RENTAL-1']);

        app(PpLeadService::class)->processLead([
            'LeadId'            => 'PP-LEAD-RENTAL-1',
            'Date'              => '2026-08-18T08:30:00',
            'PPRef'             => 'PP-RENTAL-1',
            'UniqueListingId'   => (string) $listing->id,
            'FromName'          => 'PP Rental Enquirer',
            'FromEmail'         => 'pp-rental@example.co.za',
            'FromContactNumber' => '0820003333',
            'Message'           => 'Is parking included?',
        ], $this->agency);

        $contact = Contact::where('email', 'pp-rental@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Tenant', $contact->type?->name);
    }

    public function test_pp_sale_enquiry_creates_a_buyer_contact(): void
    {
        $listing = $this->property(['listing_type' => 'sale', 'pp_ref' => 'PP-SALE-1']);

        app(PpLeadService::class)->processLead([
            'LeadId'            => 'PP-LEAD-SALE-1',
            'Date'              => '2026-08-18T08:30:00',
            'PPRef'             => 'PP-SALE-1',
            'UniqueListingId'   => (string) $listing->id,
            'FromName'          => 'PP Sale Enquirer',
            'FromEmail'         => 'pp-sale@example.co.za',
            'FromContactNumber' => '0820004444',
            'Message'           => 'Is this still available?',
        ], $this->agency);

        $contact = Contact::where('email', 'pp-sale@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Buyer', $contact->type?->name);
    }

    // ────────────────────────────── Website ───────────────────────────────

    private function apiKey(): AgencyApiKey
    {
        return AgencyApiKey::forceCreate([
            'agency_id'   => $this->agency->id,
            'name'        => 'Test Website Key',
            'key_prefix'  => 'test_' . uniqid(),
            'secret_hash' => hash('sha256', 'secret'),
            'scopes'      => ['leads:write'],
        ]);
    }

    public function test_website_rental_enquiry_creates_a_tenant_contact(): void
    {
        $listing = $this->property(['listing_type' => 'rental']);

        app(WebsiteLeadService::class)->capture($this->apiKey(), [
            'listing_id' => $listing->id,
            'name'       => 'Website Rental Enquirer',
            'email'      => 'web-rental@example.co.za',
            'phone'      => '0820005555',
            'message'    => 'Is this pet friendly?',
        ]);

        $contact = Contact::where('email', 'web-rental@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Tenant', $contact->type?->name);
    }

    public function test_website_sale_enquiry_creates_a_buyer_contact(): void
    {
        $listing = $this->property(['listing_type' => 'sale']);

        app(WebsiteLeadService::class)->capture($this->apiKey(), [
            'listing_id' => $listing->id,
            'name'       => 'Website Sale Enquirer',
            'email'      => 'web-sale@example.co.za',
            'phone'      => '0820006666',
            'message'    => 'Is this still available?',
        ]);

        $contact = Contact::where('email', 'web-sale@example.co.za')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Buyer', $contact->type?->name);
    }
}
