<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The highest-risk item in the contact_property hard-delete fix, per the
 * conductor's own instruction: this is the ONE write path that is inbound,
 * automatic, and unattended. Everything else fails loudly in front of an
 * agent; this fails silently — PpWebhookController wraps its whole write in
 * a try/catch that logs and STILL returns 200 to Private Property (so PP
 * never retries), meaning a duplicate-key collision here would have looked
 * like success from both sides while the lead was actually lost.
 *
 * createLeadContact() can return an EXISTING, deduped contact (matched by
 * email/phone) — not always a fresh one — so a repeat lead for someone who
 * was previously linked to this exact property and later unlinked is a
 * REAL scenario, not a hypothetical. Full investigation:
 * .ai/specs/rental-applications.md, "The contact_property hard-delete fix".
 */
final class PpWebhookContactPropertyRelinkTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Property $property;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.private_property.webhook_secret', 'test-webhook-secret');

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'House in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'sale',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Repeat', 'last_name' => 'Enquirer', 'email' => 'repeat-enquirer@example.co.za', 'phone' => '0821234567',
        ]);
    }

    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $body, 'test-webhook-secret', true));

        return $this->call('POST', '/api/pp/webhook', [], [], [], [
            'HTTP_X-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function leadPayload(): array
    {
        return [
            'messageType' => 'Lead',
            'listingExternalReference' => (string) $this->property->id,
            'leadId' => 'PP-' . uniqid(),
            'leadName' => 'Repeat Enquirer',
            'leadEmail' => $this->contact->email,
            'leadPhoneNumber' => $this->contact->phone,
            'leadMessage' => 'Is this still available?',
        ];
    }

    public function test_a_repeat_lead_for_a_previously_unlinked_contact_restores_the_link_and_returns_200(): void
    {
        // The contact was linked to this property before (e.g. an earlier
        // enquiry), then unlinked (soft-deleted) — a real, not hypothetical,
        // prior state once unlink is soft-delete everywhere.
        \App\Services\Property\ContactPropertyLinker::link($this->contact->id, $this->property->id, 'lead');
        \App\Services\Property\ContactPropertyLinker::unlink($this->contact->id, $this->property->id);
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertNotNull(ContactProperty::onlyTrashed()->first());

        $response = $this->postWebhook($this->leadPayload());

        $response->assertStatus(200);
        $response->assertSee('OK');
        // No second row — restored the one that existed, not a duplicate,
        // and no swallowed duplicate-key exception hiding behind the 200.
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertTrue(
            $this->contact->properties()->where('properties.id', $this->property->id)->wherePivot('role', 'lead')->exists()
        );
    }

    public function test_a_genuinely_new_lead_creates_exactly_one_link(): void
    {
        $payload = $this->leadPayload();
        $payload['leadEmail'] = 'brand-new-' . uniqid() . '@example.co.za';
        $payload['leadPhoneNumber'] = null;

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);
        $this->assertDatabaseCount('contact_property', 1);
    }

    public function test_wrong_signature_is_rejected_and_writes_nothing(): void
    {
        $body = json_encode($this->leadPayload());
        $response = $this->call('POST', '/api/pp/webhook', [], [], [], [
            'HTTP_X-Signature' => base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true)),
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);
        $this->assertDatabaseCount('contact_property', 0);
    }
}
