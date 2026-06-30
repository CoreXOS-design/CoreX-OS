<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-125 adjustment — POPIA-precise: a contact opt-out is a SEND suppression,
 * NOT an INGEST suppression.
 *
 * Opt-out stops us SENDING to the contact (outbound email + WhatsApp), but it
 * must NEVER stop us INGESTING a message the contact sends US — an inbound from
 * an opted-out contact is evidence they re-initiated contact (POPIA re-consent),
 * and the archive is the proof. This test locks that separation so a future
 * change can't quietly bolt an opt-out check onto the ingest path.
 *
 * The send gate is MarketingConsentService::canMarketTo (the single predicate
 * every send surface calls). The ingest path is the real WA ingest
 * route/middleware/controller → WaArchiveIngestor.
 */
final class OptOutSendSuppressionNotIngestTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $agentId;
    private string $plainToken;
    private CommunicationWaDevice $device;
    private MarketingConsentService $consent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->agentId = (int) $agent->id;

        $this->plainToken = Str::random(48);
        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $agent->id,
            'wa_number' => '0820000000', 'device_token' => hash('sha256', $this->plainToken), 'active' => true,
        ]);

        $this->consent = app(MarketingConsentService::class);
    }

    private function makeContact(string $phone, string $first): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => $first, 'last_name' => 'Test', 'phone' => $phone,
        ]);
    }

    /** An inbound WA message FROM $number (1:1 chat). */
    private function inboundPayload(string $localNumber, string $idSuffix): array
    {
        $jid = '27' . ltrim($localNumber, '0') . '@c.us';

        return ['messages' => [[
            'message_id' => 'WA-' . $idSuffix,
            'chat_id'    => $jid,
            'direction'  => 'in',
            'sender'     => $jid,
            'timestamp'  => now()->timestamp,
            'text'       => 'I changed my mind — please call me about my house.',
            'has_media'  => false,
        ]]];
    }

    public function test_opted_out_contact_is_blocked_from_sending(): void
    {
        $contact = $this->makeContact('0821110001', 'Olivia');
        $this->consent->optOutContact($contact, 'Self-service POPIA opt-out', 'public_link', $this->agentId);

        $this->assertNotNull($contact->fresh()->messaging_opt_out_at);
        // The single send gate every outreach surface calls — must refuse.
        $this->assertFalse($this->consent->canMarketTo($contact->fresh(), 'whatsapp'), 'opted-out contact must NOT be marketable on WhatsApp');
        $this->assertFalse($this->consent->canMarketTo($contact->fresh(), 'email'), 'opted-out contact must NOT be marketable on email');
    }

    public function test_inbound_from_opted_out_contact_is_still_ingested(): void
    {
        $contact = $this->makeContact('0821110001', 'Olivia');
        $this->consent->optOutContact($contact, 'Self-service POPIA opt-out', 'public_link', $this->agentId);
        $this->assertNotNull($contact->fresh()->messaging_opt_out_at, 'precondition: contact is opted out');

        // The SAME opted-out contact sends US a WhatsApp → it MUST archive (re-consent evidence),
        // not be dropped by the opt-out.
        $this->withToken($this->plainToken)
            ->postJson(route('communications.wa.ingest'), $this->inboundPayload('0821110001', 'OO-1'))
            ->assertOk()
            ->assertJson(['success' => true, 'stats' => ['archived' => 1, 'dropped' => 0]]);

        $comm = Communication::firstWhere('external_id', 'WA-OO-1');
        $this->assertNotNull($comm, 'inbound from an opted-out contact must be archived');
        $this->assertSame('whatsapp', $comm->channel);
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_type' => Contact::class, 'linkable_id' => $contact->id,
        ]);

        // Ingestion does NOT silently re-consent the contact — opt-out latch stays raised.
        $this->assertNotNull($contact->fresh()->messaging_opt_out_at, 'ingest must not clear the opt-out');
    }

    public function test_non_opted_out_contact_sends_and_ingests_normally(): void
    {
        $contact = $this->makeContact('0821110002', 'Ned');

        $this->assertTrue($this->consent->canMarketTo($contact, 'whatsapp'), 'a normal contact stays marketable');

        $this->withToken($this->plainToken)
            ->postJson(route('communications.wa.ingest'), $this->inboundPayload('0821110002', 'OK-1'))
            ->assertOk()
            ->assertJson(['stats' => ['archived' => 1]]);

        $this->assertNotNull(Communication::firstWhere('external_id', 'WA-OK-1'));
    }
}
