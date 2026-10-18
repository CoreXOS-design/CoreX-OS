<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PpLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * QA1 scope-fix (2026-10-07, audit defect 4) — Private Property re-delivers a webhook on
 * any non-2xx / timeout, and the receiver had no deduplication: a retry gave a second
 * contact note, a second follow-up task, a second portal_leads row and a second
 * notification to the agent.
 *
 * THE KEY: PP's own reference for the enquiry — the webhook payload's `leadId` — within the
 * property's agency. With no leadId on the delivery, a fingerprint of (property, email,
 * phone, message) inside a 10-minute window stands in. A duplicate is logged at INFO and
 * answered 200, never an error.
 */
final class PpWebhookDuplicateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Property $property;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.private_property.webhook_secret', 'test-webhook-secret');

        $this->logFile = tempnam(sys_get_temp_dir(), 'pp-webhook-log-');
        Config::set('logging.channels.private_property', ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug']);
        Log::forgetChannel('private_property');

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'House in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'sale',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
        ]);
        ContactType::firstOrCreate(['name' => 'Buyer'], ['esign_role' => 'buyer']);
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('private_property');
        @unlink($this->logFile);
        parent::tearDown();
    }

    private function deliver(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/pp/webhook', [], [], [], [
            'HTTP_X-Signature' => base64_encode(hash_hmac('sha256', $body, 'test-webhook-secret', true)),
            'CONTENT_TYPE'     => 'application/json',
        ], $body);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'messageType' => 'Lead',
            'listingExternalReference' => (string) $this->property->id,
            'leadId' => 'PP-LEAD-1001',
            'leadName' => 'Dup Enquirer',
            'leadEmail' => 'dup-enquirer@example.co.za',
            'leadPhoneNumber' => '0829998888',
            'leadMessage' => 'Is this still available?',
        ];
    }

    private function counts(): array
    {
        return [
            'leads'    => PortalLead::withoutGlobalScopes()->where('portal', 'pp')->count(),
            'tasks'    => CommandTask::withoutGlobalScopes()->where('source_type', 'private_property_webhook')->count(),
            'contacts' => Contact::withoutGlobalScopes()->where('email', 'dup-enquirer@example.co.za')->count(),
        ];
    }

    public function test_the_same_enquiry_delivered_twice_creates_one_lead_one_task_and_one_notification(): void
    {
        Event::fake([NewPortalLeadReceived::class]);

        $this->deliver($this->payload())->assertStatus(200);
        $this->deliver($this->payload())->assertStatus(200); // PP retry — same leadId

        $this->assertSame(['leads' => 1, 'tasks' => 1, 'contacts' => 1], $this->counts());
        Event::assertDispatchedTimes(NewPortalLeadReceived::class, 1);

        // The contact's note holds the enquiry once, not twice.
        $notes = (string) Contact::withoutGlobalScopes()->where('email', 'dup-enquirer@example.co.za')->value('notes');
        $this->assertSame(1, substr_count($notes, 'Is this still available?'));
    }

    public function test_the_duplicate_is_logged_as_info_and_not_as_an_error(): void
    {
        $this->deliver($this->payload())->assertStatus(200);
        $this->deliver($this->payload())->assertStatus(200);

        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('PP webhook: duplicate lead ignored', $log);
        $this->assertStringContainsString('PP-LEAD-1001', $log);
        $this->assertStringContainsString('.INFO: PP webhook: duplicate lead ignored', $log);
        $this->assertStringNotContainsString('lead processing failed', $log);
    }

    public function test_a_different_leadId_from_the_same_person_is_a_new_lead(): void
    {
        $this->deliver($this->payload(['leadId' => 'PP-LEAD-2001']))->assertStatus(200);
        $this->deliver($this->payload(['leadId' => 'PP-LEAD-2002', 'leadMessage' => 'A second, different question']))->assertStatus(200);

        $this->assertSame(2, $this->counts()['leads']);
        $this->assertSame(2, $this->counts()['tasks']);
        $this->assertSame(1, $this->counts()['contacts'], 'same person still resolves to one contact');
    }

    public function test_the_same_leadId_on_a_different_agency_is_not_a_duplicate(): void
    {
        $other  = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'Sea Point']);
        $agent  = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $otherProperty = Property::create([
            'agency_id' => $other->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Flat in Sea Point', 'status' => 'active', 'property_type' => 'apartment', 'listing_type' => 'sale',
            'suburb' => 'Sea Point', 'city' => 'Cape Town', 'province' => 'Western Cape', 'address' => '2 Test Road',
        ]);

        $this->deliver($this->payload())->assertStatus(200);
        $this->deliver($this->payload(['listingExternalReference' => (string) $otherProperty->id]))->assertStatus(200);

        $this->assertSame(2, PortalLead::withoutGlobalScopes()->where('portal', 'pp')->count());
    }

    public function test_a_delivery_without_a_leadId_is_deduplicated_on_the_enquiry_fingerprint(): void
    {
        Event::fake([NewPortalLeadReceived::class]);
        $noId = $this->payload();
        unset($noId['leadId']);

        $this->deliver($noId)->assertStatus(200);
        $this->deliver($noId)->assertStatus(200);

        $this->assertSame(['leads' => 1, 'tasks' => 1, 'contacts' => 1], $this->counts());
        Event::assertDispatchedTimes(NewPortalLeadReceived::class, 1);

        // A different message from the same person on the same listing is a genuine new enquiry.
        $this->deliver(array_diff_key($this->payload(['leadMessage' => 'Different question entirely']), ['leadId' => 1]))->assertStatus(200);
        $this->assertSame(2, $this->counts()['leads']);
    }

    public function test_the_webhook_leadId_is_recorded_so_the_pp_pull_does_not_double_record_it(): void
    {
        $this->deliver($this->payload(['leadId' => 'PP-LEAD-3001']))->assertStatus(200);

        $lead = PortalLead::withoutGlobalScopes()->where('portal', 'pp')->firstOrFail();
        $this->assertSame('PP-LEAD-3001', $lead->lead_source_raw['__corex_lead_id'] ?? null);

        // The pull's own duplicate check reads that same key.
        $this->agency->forceFill(['pp_lead_pull_enabled' => true])->save();
        $pulled = app(PpLeadService::class)->processLead([
            'LeadId' => 'PP-LEAD-3001', 'Date' => '2026-10-07T09:00:00', 'UniqueListingId' => (string) $this->property->id,
            'FromName' => 'Dup Enquirer', 'FromEmail' => 'dup-enquirer@example.co.za', 'Message' => 'Is this still available?',
        ], $this->agency->fresh());

        $this->assertNull($pulled, 'the pull must recognise the webhook-recorded LeadId as already received');
        $this->assertSame(1, PortalLead::withoutGlobalScopes()->where('portal', 'pp')->count());
    }
}
