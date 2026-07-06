<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\AgentCaptureConsent;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Communications\WaCapturePurgeEvent;
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
 * AT-183 — WhatsApp capture OPT-OUT is a POPIA exclusion. Declaring an opt-out must
 * (1) retroactively PURGE the already-captured bodies for that agent↔contact pairing (with an
 * immutable, content-free audit event), and (2) DROP new messages for the pairing before storage
 * (not stored-then-hidden). Re-enabling resumes capture forward-only — purged history stays gone.
 */
final class WaCaptureOptOutPurgeTest extends TestCase
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
        // SELF-LINKED device (waha_session set) → capture defaults to opted_in (the live scenario:
        // Johan self-linked, so Elize's message was captured with a full body).
        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->agent->id,
            'wa_number' => '0820000000', 'device_token' => hash('sha256', Str::random(40)),
            'active' => true, 'waha_session' => 'agency' . $this->agencyId . '-agent-' . $this->agent->id,
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel', 'phone' => '0713510291',
        ]);
    }

    private function ingest(string $text, string $direction = 'out'): string
    {
        return app(WaArchiveIngestor::class)->ingest($this->device, [
            'message_id' => 'WA-' . Str::random(10),
            'direction'  => $direction,
            'timestamp'  => now()->timestamp,
            'text'       => $text,
            'chat_id'    => self::LID,
            'counterpart_phone' => '27713510291@c.us',
        ]);
    }

    private function optOut(?string $reason = 'Its my wife'): void
    {
        app(AgentCaptureConsentService::class)->setDecision(
            $this->agent->id, $this->contact, AgentCaptureConsent::STATUS_OPTED_OUT, $reason, $this->agent
        );
    }

    public function test_opt_out_purges_the_already_captured_body_and_leaves_an_audit_event(): void
    {
        // Self-linked default opts the pairing in → the outbound test message is captured in full.
        $this->ingest('More my engel — test message to Elize.');
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm, 'the message was captured (self-link default opted-in)');
        $this->assertSame('captured', $comm->body_status);
        $this->assertNotNull($comm->body_text);
        $rawPath = $comm->raw_path;
        $this->assertNotNull($rawPath);

        // Attach a media descriptor with real stored bytes to prove media is purged too.
        $stored = app(CommunicationStorageService::class)->store($this->agencyId, 'whatsapp', 'FAKE-MEDIA-BYTES');
        CommunicationAttachment::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'filename' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 16,
            'content_hash' => $stored['content_hash'], 'storage_path' => $stored['path'], 'media_status' => 'stored',
        ]);
        $this->assertTrue(Storage::disk('local')->exists($stored['path']));

        // The agent declares the opt-out ("Its my wife") → retroactive purge fires.
        $this->optOut('Its my wife');

        $comm->refresh();
        // Body content genuinely gone; envelope retained.
        $this->assertNull($comm->body_text, 'the captured body is purged');
        $this->assertNull($comm->body_preview);
        $this->assertNull($comm->raw_path, 'raw pointer cleared');
        $this->assertSame('consent_revoked', $comm->body_status);
        $this->assertNotNull($comm->purged_at);
        $this->assertSame('capture_opt_out', $comm->purged_reason);
        $this->assertNotNull($comm->external_id, 'the FICA envelope (identity) is retained');
        $this->assertFalse(Storage::disk('local')->exists($rawPath), 'raw bytes deleted from the store');

        // Media purged.
        $att = CommunicationAttachment::where('communication_id', $comm->id)->first();
        $this->assertSame('purged', $att->media_status);
        $this->assertNull($att->storage_path);
        $this->assertFalse(Storage::disk('local')->exists($stored['path']), 'media bytes deleted');

        // Audit event: who/when/reason + count, NEVER content.
        $event = WaCapturePurgeEvent::where('contact_id', $this->contact->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(1, $event->message_count);
        $this->assertSame('Its my wife', $event->reason);
        $this->assertSame($this->agent->id, $event->actor_user_id);
        $this->assertSame($this->agent->id, $event->agent_user_id);
    }

    public function test_new_message_after_opt_out_is_dropped_before_storage(): void
    {
        $this->optOut();
        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count());

        $result = $this->ingest('A new message that must never be stored.');

        $this->assertSame(WaArchiveIngestor::RESULT_DROPPED, $result);
        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count(), 'nothing stored — not stored-then-hidden');
    }

    public function test_self_linked_default_never_re_enables_an_explicit_opt_out(): void
    {
        // The race-window guard: even though the device is self-linked (default opt-in), an
        // explicit opt-out is never overridden, so a new message is still dropped.
        $this->optOut();
        $this->ingest('Self-linked device message after opt-out.');

        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count());
        $this->assertSame(
            AgentCaptureConsent::STATUS_OPTED_OUT,
            AgentCaptureConsent::where('contact_id', $this->contact->id)->value('status')
        );
    }

    public function test_re_enable_resumes_forward_only_and_does_not_resurrect_purged_history(): void
    {
        // Capture → opt-out (purge) → opt back in → the purged message stays gone; a new one captures.
        $this->ingest('Original captured message.');
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->optOut();
        $comm->refresh();
        $this->assertSame('consent_revoked', $comm->body_status);

        // Agent flips back to Archive (e.g. after a BM "flag as business" reconsideration).
        app(AgentCaptureConsentService::class)->setDecision(
            $this->agent->id, $this->contact, AgentCaptureConsent::STATUS_OPTED_IN, null, $this->agent
        );

        // Purged history is NOT resurrected (release only touches withheld statuses).
        $comm->refresh();
        $this->assertSame('consent_revoked', $comm->body_status, 'purged history is not resurrected on opt-in');
        $this->assertNull($comm->body_text);

        // A NEW message now captures normally (forward-only resume).
        $this->ingest('New message after re-enabling capture.');
        $fresh = Communication::where('agency_id', $this->agencyId)
            ->where('id', '!=', $comm->id)->first();
        $this->assertNotNull($fresh);
        $this->assertSame('captured', $fresh->body_status);
        $this->assertSame('New message after re-enabling capture.', $fresh->body_text);
    }

    public function test_purge_audit_event_is_append_only(): void
    {
        $this->ingest('Message to be purged.');
        $this->optOut();

        $event = WaCapturePurgeEvent::where('contact_id', $this->contact->id)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $event->update(['message_count' => 999]); // POPIA evidence: immutable
    }
}
