<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\AgentCaptureConsent;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\WaArchiveIngestor;
use App\Services\Communications\WahaMediaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * AT-148 — WhatsApp voice-note media: download from WAHA → store the decrypted
 * .ogg on the MOUNTED VOLUME → attach to the archived message → play inline via
 * an authenticated route. Robustness: a failed download must NEVER lose the
 * message — it archives with the media marked pending. The status@broadcast +
 * group noise filter is preserved. Media is stored ONLY when the agent has opted
 * in (AT-136), same as any body content.
 */
final class WaVoiceNoteMediaTest extends TestCase
{
    use RefreshDatabase;

    // A tiny but valid-looking Ogg/Opus header + payload (bytes are opaque to us;
    // WAHA hands over already-decrypted media). "OggS" is the Ogg magic.
    private const OGG_BYTES = "OggS\x00\x02voice-note-decrypted-opus-payload";

    private int $agencyId;
    private int $agentUserId;
    private CommunicationWaDevice $device;
    private Contact $contact;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // communications.disk defaults to 'local'
        $this->withoutVite(); // 404 error views extend the app layout (@vite); no manifest in tests

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->owner = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->agentUserId = (int) $this->owner->id;

        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->agentUserId,
            'wa_number' => '0820000000', 'device_token' => hash('sha256', Str::random(48)), 'active' => true,
        ]);

        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel',
            'phone' => '0713510291', 'email' => null,
        ]);
    }

    private function setConsent(string $status): void
    {
        AgentCaptureConsent::create([
            'agency_id' => $this->agencyId, 'agent_user_id' => $this->agentUserId,
            'contact_id' => $this->contact->id, 'status' => $status,
        ]);
    }

    /** An inbound voice note whose media is a WAHA URL to fetch. */
    private function voiceNoteMessage(array $over = []): array
    {
        return array_merge([
            'message_id'        => 'WA-' . Str::random(10),
            'chat_id'           => '222758646611979@lid',
            'direction'         => 'in',
            'sender'            => null,
            'timestamp'         => now()->timestamp,
            'text'              => null,
            'has_media'         => true,
            'media'             => [[
                'url'      => 'http://127.0.0.1:3111/api/files/default/PTT-' . Str::random(8) . '.opus',
                'mimetype' => 'audio/ogg; codecs=opus',
                'filename' => 'PTT-voice.opus',
                'duration' => 7,
            ]],
            'counterpart_phone' => '27713510291@c.us',
            'counterpart_lid'   => '222758646611979@lid',
        ], $over);
    }

    private function ingest(array $msg): string
    {
        return app(WaArchiveIngestor::class)->ingest($this->device, $msg);
    }

    // ── STEP 2 — download + store ────────────────────────────────────────────

    public function test_voice_note_is_downloaded_from_waha_and_stored_on_the_volume(): void
    {
        $this->setConsent(AgentCaptureConsent::STATUS_OPTED_IN);
        Http::fake(['*' => Http::response(self::OGG_BYTES, 200, ['Content-Type' => 'audio/ogg; codecs=opus'])]);

        $result = $this->ingest($this->voiceNoteMessage());
        $this->assertSame(WaArchiveIngestor::RESULT_ARCHIVED, $result);

        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertTrue((bool) $comm->has_attachments);

        $att = $comm->attachments()->first();
        $this->assertNotNull($att, 'a voice-note attachment row exists');
        $this->assertSame(CommunicationAttachment::MEDIA_STORED, $att->media_status);
        $this->assertSame(7, $att->duration_seconds);
        $this->assertStringStartsWith('audio/', (string) $att->mime);
        $this->assertSame(strlen(self::OGG_BYTES), (int) $att->size_bytes);

        // Stored on the volume-resident content-addressed path, and the bytes match.
        $this->assertNotNull($att->storage_path);
        $this->assertStringContainsString("communications/{$this->agencyId}/attachment", $att->storage_path);
        Storage::disk('local')->assertExists($att->storage_path);
        $this->assertSame(self::OGG_BYTES, Storage::disk('local')->get($att->storage_path));
    }

    // ── STEP 2 — robustness: download fails → archived media-pending, not lost ─

    public function test_failed_download_still_archives_message_and_marks_media_pending(): void
    {
        $this->setConsent(AgentCaptureConsent::STATUS_OPTED_IN);
        Http::fake(['*' => Http::response('upstream error', 500)]);

        $msg = $this->voiceNoteMessage();
        $result = $this->ingest($msg);

        // The MESSAGE is never dropped just because its media could not be fetched.
        $this->assertSame(WaArchiveIngestor::RESULT_ARCHIVED, $result);

        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertTrue((bool) $comm->has_attachments);

        $att = $comm->attachments()->first();
        $this->assertNotNull($att);
        $this->assertSame(CommunicationAttachment::MEDIA_PENDING, $att->media_status);
        $this->assertNull($att->storage_path, 'no file written on failure');
        $this->assertSame($msg['media'][0]['url'], $att->remote_ref, 'the WAHA url is kept for retry');
        $this->assertFalse($att->isPlayable());
    }

    // ── STEP 2 — noise filter preserved ──────────────────────────────────────

    public function test_status_broadcast_and_group_messages_are_dropped(): void
    {
        $this->setConsent(AgentCaptureConsent::STATUS_OPTED_IN);
        Http::fake(['*' => Http::response(self::OGG_BYTES, 200, ['Content-Type' => 'audio/ogg'])]);

        $this->assertSame(WaArchiveIngestor::RESULT_DROPPED,
            $this->ingest($this->voiceNoteMessage(['chat_id' => 'status@broadcast'])));
        $this->assertSame(WaArchiveIngestor::RESULT_DROPPED,
            $this->ingest($this->voiceNoteMessage(['chat_id' => '120363000000000000@g.us'])));
        $this->assertSame(WaArchiveIngestor::RESULT_DROPPED,
            $this->ingest($this->voiceNoteMessage(['is_group' => true])));

        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count(),
            'no noise message reaches the archive');
    }

    // ── STEP 3 — playable via the authenticated route ────────────────────────

    public function test_authenticated_route_serves_stored_voice_note(): void
    {
        $this->setConsent(AgentCaptureConsent::STATUS_OPTED_IN);
        Http::fake(['*' => Http::response(self::OGG_BYTES, 200, ['Content-Type' => 'audio/ogg'])]);
        $this->ingest($this->voiceNoteMessage());

        $att = CommunicationAttachment::firstWhere('agency_id', $this->agencyId);
        $this->assertTrue($att->isPlayable());

        // The owner of the message may play it (owner path of applyArchiveVisibility;
        // the full gate is exercised in CommsAccessGateFlowTest).
        $response = $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.attachment', $att->id));

        $response->assertOk();
        $this->assertStringStartsWith('audio/', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_pending_media_is_not_served(): void
    {
        // A media-pending attachment has no file → the route 404s (nothing to play).
        $comm = Communication::create([
            'agency_id' => $this->agencyId, 'channel' => Communication::CHANNEL_WHATSAPP,
            'direction' => Communication::DIRECTION_INBOUND, 'external_id' => Str::random(12),
            'thread_key' => 'tk', 'from_identifier' => '27713510291', 'occurred_at' => now(),
            'captured_at' => now(), 'owner_user_id' => $this->agentUserId, 'has_attachments' => true,
        ]);
        $att = CommunicationAttachment::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'filename' => 'PTT.opus', 'mime' => 'audio/ogg', 'size_bytes' => 0,
            'content_hash' => '', 'storage_path' => null,
            'media_status' => CommunicationAttachment::MEDIA_PENDING,
            'remote_ref' => 'http://127.0.0.1:3111/api/files/default/PTT.opus',
        ]);

        $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.attachment', $att->id))
            ->assertNotFound();
    }

    public function test_other_agency_user_cannot_reach_the_attachment(): void
    {
        $this->setConsent(AgentCaptureConsent::STATUS_OPTED_IN);
        Http::fake(['*' => Http::response(self::OGG_BYTES, 200, ['Content-Type' => 'audio/ogg'])]);
        $this->ingest($this->voiceNoteMessage());
        $att = CommunicationAttachment::firstWhere('agency_id', $this->agencyId);

        // A user in a DIFFERENT agency: the BelongsToAgency global scope filters the
        // attachment out of the route-model binding → 404 (agency isolation).
        $otherAgencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(5), 'slug' => 'other-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $otherAgencyId, 'agency_id' => $otherAgencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stranger = User::factory()->create([
            'agency_id' => $otherAgencyId, 'branch_id' => $otherAgencyId, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->actingAs($stranger)
            ->get(route('compliance.comm-archive.attachment', $att->id))
            ->assertNotFound();
    }

    // ── WahaMediaClient guards ───────────────────────────────────────────────

    public function test_media_client_refuses_a_host_outside_the_allow_list(): void
    {
        $client = app(WahaMediaClient::class);
        $this->expectException(RuntimeException::class);
        $client->download('http://169.254.169.254/latest/meta-data/'); // SSRF target
    }

    public function test_media_client_enforces_the_size_cap(): void
    {
        config(['communications.waha.max_media_bytes' => 8]);
        Http::fake(['*' => Http::response(self::OGG_BYTES, 200, ['Content-Type' => 'audio/ogg'])]);

        $client = app(WahaMediaClient::class);
        $this->expectException(RuntimeException::class);
        $client->download('http://127.0.0.1:3111/api/files/default/big.opus');
    }

    public function test_media_client_throws_on_http_error(): void
    {
        Http::fake(['*' => Http::response('nope', 404)]);
        $client = app(WahaMediaClient::class);
        $this->expectException(RuntimeException::class);
        $client->download('http://127.0.0.1:3111/api/files/default/missing.opus');
    }
}
