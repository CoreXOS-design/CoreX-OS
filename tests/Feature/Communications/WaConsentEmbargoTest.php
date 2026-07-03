<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\AgentCaptureConsent;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\AgentCaptureConsentService;
use App\Services\Communications\CommunicationStorageService;
use App\Services\Communications\WaArchiveIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-168 Part B — consent embargo (store, don't discard). A body captured while
 * capture-consent is pending is stored EMBARGOED (never displayed), released
 * instantly on opt-in, and purged after the retention window if consent never
 * comes.
 */
final class WaConsentEmbargoTest extends TestCase
{
    use RefreshDatabase;

    private const LID = '222758646611979@lid';

    private int $agencyId;
    private CommunicationWaDevice $device;
    private User $agent;
    private Contact $contact;

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
        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);
        // Extension device (no waha_session) → capture consent defaults to pending.
        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->agent->id,
            'wa_number' => '0820000000', 'device_token' => hash('sha256', Str::random(40)), 'active' => true,
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel', 'phone' => '0713510291',
        ]);
    }

    private function ingest(string $text): string
    {
        return app(WaArchiveIngestor::class)->ingest($this->device, [
            'message_id' => 'WA-' . Str::random(10),
            'direction'  => 'in',
            'timestamp'  => now()->timestamp,
            'text'       => $text,
            'chat_id'    => self::LID,
            'counterpart_phone' => '27713510291@c.us',
        ]);
    }

    public function test_pending_capture_embargoes_the_body_but_stores_it(): void
    {
        $this->ingest('The offer is R1,950,000 — please confirm.');

        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm);
        // Embargoed: never displayed (body_text/preview null), but flagged embargoed.
        $this->assertNull($comm->body_text, 'embargoed body is never displayed');
        $this->assertNull($comm->body_preview);
        $this->assertSame('embargoed', $comm->body_status);
        // …yet the full body is stored at rest, ready to release on opt-in.
        $raw = app(CommunicationStorageService::class)->get($comm->raw_path);
        $this->assertNotNull($raw);
        $this->assertStringContainsString('R1,950,000', $raw, 'the body is stored embargoed (not discarded)');
    }

    public function test_opt_in_releases_the_embargoed_body_instantly(): void
    {
        $this->ingest('Business message that was pending consent.');
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertSame('embargoed', $comm->body_status);

        // The agent opts in to capturing this contact → release fires.
        app(AgentCaptureConsentService::class)->setDecision(
            $this->agent->id, $this->contact, AgentCaptureConsent::STATUS_OPTED_IN, null, $this->agent
        );

        $comm->refresh();
        $this->assertSame('captured', $comm->body_status, 'released on opt-in');
        $this->assertSame('Business message that was pending consent.', $comm->body_text);
        $this->assertNotNull($comm->body_preview);
    }

    public function test_purge_removes_expired_unconsented_body_but_keeps_recent_and_consented(): void
    {
        // Old + un-consented → purged. Recent + un-consented → kept. Consented → kept.
        $old      = $this->seedEmbargoed('old body', now()->subDays(60), $this->contact->id);
        $recent   = $this->seedEmbargoed('recent body', now()->subDays(2), $this->contact->id);

        $consentedContact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Opted', 'last_name' => 'In', 'phone' => '0728889999',
        ]);
        $consented = $this->seedEmbargoed('consented body', now()->subDays(90), $consentedContact->id);
        AgentCaptureConsent::create([
            'agency_id' => $this->agencyId, 'agent_user_id' => $this->agent->id,
            'contact_id' => $consentedContact->id, 'status' => AgentCaptureConsent::STATUS_OPTED_IN,
        ]);

        $this->artisan('communications:purge-embargoed-bodies', ['--agency' => $this->agencyId])->assertSuccessful();

        // Old un-consented → body genuinely gone, envelope kept.
        $old->refresh();
        $this->assertSame('embargo_purged', $old->body_status);
        $this->assertNull($old->body_text);
        $this->assertNull($old->raw_path, 'raw bytes removed');
        $this->assertNull($old->purged_at, 'the row/envelope is NOT soft-deleted — only the body is purged');

        // Recent un-consented → still embargoed (within window).
        $this->assertSame('embargoed', $recent->refresh()->body_status);
        // Consented → never purged (belongs to the agent).
        $this->assertSame('embargoed', $consented->refresh()->body_status);
    }

    public function test_recover_command_releases_embargoed_bodies_from_raw(): void
    {
        // Simulate an embargoed row whose contact the agent has SINCE opted into but
        // whose release was missed — the recovery command releases it from raw.
        $comm = $this->seedEmbargoedWithRaw('recovered by command', $this->contact->id);
        AgentCaptureConsent::create([
            'agency_id' => $this->agencyId, 'agent_user_id' => $this->agent->id,
            'contact_id' => $this->contact->id, 'status' => AgentCaptureConsent::STATUS_OPTED_IN,
        ]);

        $this->artisan('communications:recover-wa-bodies', ['--agency' => $this->agencyId])->assertSuccessful();

        $comm->refresh();
        $this->assertSame('captured', $comm->body_status);
        $this->assertSame('recovered by command', $comm->body_text);
    }

    private function seedEmbargoed(string $body, \DateTimeInterface $when, int $contactId): Communication
    {
        return $this->seedEmbargoedWithRaw($body, $contactId, $when);
    }

    private function seedEmbargoedWithRaw(string $body, int $contactId, ?\DateTimeInterface $when = null): Communication
    {
        $stored = app(CommunicationStorageService::class)->store(
            $this->agencyId, 'whatsapp', json_encode(['text' => $body, 'media' => []])
        );
        $comm = Communication::create([
            'agency_id'   => $this->agencyId,
            'channel'     => Communication::CHANNEL_WHATSAPP,
            'direction'   => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14),
            'thread_key'  => 'wa:713510291',
            'wa_chat_id'  => self::LID,
            'from_identifier' => '27713510291',
            'body_text'   => null,
            'body_status' => 'embargoed',
            'raw_path'    => $stored['path'],
            'content_hash' => $stored['content_hash'],
            'owner_user_id' => $this->agent->id,
            'occurred_at' => $when ?? now(),
            'captured_at' => now(),
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => Contact::class, 'linkable_id' => $contactId,
            'link_method' => CommunicationLink::METHOD_DETERMINISTIC, 'confidence' => 100, 'confirmed_at' => now(),
        ]);
        return $comm;
    }
}
