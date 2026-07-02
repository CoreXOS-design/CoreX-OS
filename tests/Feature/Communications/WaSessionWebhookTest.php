<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\AgentCaptureConsent;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-149 — the WAHA → WaArchiveIngestor webhook adapter. Proves that a real WAHA
 * per-message webhook payload maps into the ingestor's messages[] contract and
 * archives correctly, that media rides the AT-148 url seam, that broadcasts and
 * groups are dropped, that malformed payloads skip cleanly (no 500), and that an
 * unauthenticated POST is refused. Ingestion/consent/@lid/matching are the
 * ingestor's job (tested elsewhere) — here we test the ADAPTER + receiver.
 */
final class WaSessionWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'default';
    private const SECRET  = 'waha-test-secret-1234567890';
    private const LID     = '222758646611979@lid';

    private const OGG_BYTES = "OggS\x00voice-note-decrypted-opus";

    private int $agencyId;
    private CommunicationWaDevice $device;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['communications.waha.webhook_secret' => self::SECRET]);

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

        // The WAHA session is linked to this agent's device row (AT-149 bridge).
        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $agent->id,
            'wa_number' => '0820000000', 'waha_session' => self::SESSION,
            'device_token' => null, 'active' => true,
        ]);

        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel',
            'phone' => '0713510291', 'email' => null,
        ]);

        // Opt in so bodies + media are captured (AT-136); adapter/ingestor otherwise
        // keep only the envelope.
        AgentCaptureConsent::create([
            'agency_id' => $this->agencyId, 'agent_user_id' => $agent->id,
            'contact_id' => $this->contact->id, 'status' => AgentCaptureConsent::STATUS_OPTED_IN,
        ]);
    }

    /** A WAHA inbound message envelope (real GOWS shape). */
    private function inboundEnvelope(array $payloadOver = []): array
    {
        return [
            'event'   => 'message',
            'session' => self::SESSION,
            'payload' => array_merge([
                'id'        => 'false_' . Str::random(10) . '@c.us_' . Str::random(20),
                'timestamp' => now()->timestamp,
                'from'      => self::LID,
                'to'        => '27820000000@s.whatsapp.net',
                'fromMe'    => false,
                'body'      => 'Hello from Elize',
                'hasMedia'  => false,
                '_data'     => [
                    'Info' => [
                        'SenderAlt' => '27713510291@s.whatsapp.net',
                        'PushName'  => 'Elize',
                        'IsGroup'   => false,
                        'IsFromMe'  => false,
                    ],
                    'Message' => ['conversation' => 'Hello from Elize'],
                ],
            ], $payloadOver),
        ];
    }

    private function postWebhook(array $envelope, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/communications/wa/webhook', $envelope,
            array_merge(['X-Webhook-Secret' => self::SECRET], $headers));
    }

    // ── Fixture 1: inbound text → mapped → archived, no @lid leakage ──────────

    public function test_inbound_text_payload_is_mapped_and_archived(): void
    {
        $this->postWebhook($this->inboundEnvelope())
            ->assertOk()
            ->assertJson(['success' => true, 'result' => 'archived']);

        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm);
        $this->assertSame(Communication::CHANNEL_WHATSAPP, $comm->channel);
        $this->assertSame(Communication::DIRECTION_INBOUND, $comm->direction);
        $this->assertSame(self::LID, $comm->thread_key, 'thread keyed by the @lid chat');
        $this->assertSame('Hello from Elize', $comm->body_text);

        // Zero raw @lid leakage: the resolved identity is the REAL phone, not @lid.
        $this->assertStringNotContainsString('@lid', (string) $comm->from_identifier);
        $this->assertSame('27713510291', $comm->from_identifier);

        // Linked to the matched contact.
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_id' => $this->contact->id,
        ]);
    }

    public function test_hmac_signed_payload_is_accepted(): void
    {
        // Prove the WAHA-native HMAC path (not just the shared-secret header).
        $envelope = $this->inboundEnvelope();
        $raw = json_encode($envelope);
        $hmac = hash_hmac('sha512', $raw, self::SECRET);

        $response = $this->call('POST', '/communications/wa/webhook', [], [], [], [
            'CONTENT_TYPE'        => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => $hmac,
        ], $raw);

        $response->assertOk();
        $this->assertSame(1, Communication::where('agency_id', $this->agencyId)->count());
    }

    // ── Fixture 2: voice note with media.url → AT-148 storeMedia consumes it ──

    public function test_voice_note_media_is_downloaded_and_attached(): void
    {
        Http::fake(['*' => Http::response(self::OGG_BYTES, 200, ['Content-Type' => 'audio/ogg; codecs=opus'])]);

        $waUrl = 'http://127.0.0.1:3111/api/files/default/PTT-' . Str::random(8) . '.opus';
        $this->postWebhook($this->inboundEnvelope([
            'body'     => '',
            'hasMedia' => true,
            'media'    => ['url' => $waUrl, 'mimetype' => 'audio/ogg; codecs=opus', 'filename' => 'PTT-voice.opus'],
            '_data'    => [
                'Info'    => ['SenderAlt' => '27713510291@s.whatsapp.net', 'PushName' => 'Elize', 'IsGroup' => false, 'IsFromMe' => false],
                'Message' => ['audioMessage' => ['seconds' => 7]],
            ],
        ]))->assertOk();

        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm);
        $this->assertTrue((bool) $comm->has_attachments);

        $att = CommunicationAttachment::firstWhere('communication_id', $comm->id);
        $this->assertNotNull($att, 'voice-note attachment created');
        $this->assertSame(CommunicationAttachment::MEDIA_STORED, $att->media_status);
        $this->assertSame(7, $att->duration_seconds);
        $this->assertStringStartsWith('audio/', (string) $att->mime);
        $this->assertStringContainsString("communications/{$this->agencyId}/attachment", (string) $att->storage_path);
        Storage::disk('local')->assertExists($att->storage_path);
    }

    // ── Fixture 3: broadcasts + groups are dropped ───────────────────────────

    public function test_status_broadcast_is_dropped(): void
    {
        $this->postWebhook($this->inboundEnvelope(['from' => 'status@broadcast']))
            ->assertOk()
            ->assertJson(['dropped' => true]);

        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count());
    }

    public function test_group_message_is_dropped(): void
    {
        // Both signals: the @g.us suffix and the IsGroup flag.
        $this->postWebhook($this->inboundEnvelope(['from' => '120363000000000000@g.us']))
            ->assertOk()->assertJson(['dropped' => true]);
        $this->postWebhook($this->inboundEnvelope([
            '_data' => ['Info' => ['SenderAlt' => '27713510291@s.whatsapp.net', 'IsGroup' => true], 'Message' => ['conversation' => 'hi']],
        ]))->assertOk()->assertJson(['dropped' => true]);

        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count());
    }

    // ── Fixture 4: malformed payload skips cleanly (no 500) ──────────────────

    public function test_malformed_payload_skips_without_500(): void
    {
        // Missing id + from.
        $this->postWebhook(['event' => 'message', 'session' => self::SESSION, 'payload' => ['body' => 'x']])
            ->assertOk()->assertJson(['dropped' => true]);

        // Payload not an array.
        $this->postWebhook(['event' => 'message', 'session' => self::SESSION, 'payload' => 'nope'])
            ->assertOk()->assertJson(['skipped' => true]);

        // Non-message event is ignored.
        $this->postWebhook(['event' => 'session.status', 'session' => self::SESSION, 'payload' => []])
            ->assertOk()->assertJson(['ignored' => 'session.status']);

        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count());
    }

    public function test_unknown_session_is_skipped_not_archived(): void
    {
        $env = $this->inboundEnvelope();
        $env['session'] = 'ghost-session'; // no CoreX device linked to this session

        $this->postWebhook($env)->assertOk()->assertJson(['skipped' => true]);

        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count());
    }

    // ── Auth: unauthenticated POST is refused ────────────────────────────────

    public function test_unauthenticated_post_is_rejected(): void
    {
        $this->postJson('/communications/wa/webhook', $this->inboundEnvelope())
            ->assertStatus(401);

        $this->postJson('/communications/wa/webhook', $this->inboundEnvelope(), ['X-Webhook-Secret' => 'wrong'])
            ->assertStatus(401);

        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count());
    }
}
