<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-150 — the Communication Archive WhatsApp thread renders as a real chat:
 * outbound bubbles align RIGHT, inbound LEFT; the AT-148 voice-note player still
 * renders inside its bubble; per-message metadata (sender / time / direction /
 * channel tag) is present; and no emojis leak into the markup.
 */
final class WaThreadChatViewTest extends TestCase
{
    use RefreshDatabase;

    private const THREAD = 'chat-thread-1';

    private int $agencyId;
    private User $owner;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); // the app layout uses @vite; no manifest in tests

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->owner = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
            'is_active' => true, 'name' => 'Agent Smith',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel',
            'phone' => '0713510291',
        ]);
    }

    private function makeComm(string $direction, ?string $body): Communication
    {
        $comm = Communication::create([
            'agency_id'   => $this->agencyId,
            'channel'     => Communication::CHANNEL_WHATSAPP,
            'direction'   => $direction,
            'external_id' => Str::random(14),
            'thread_key'  => self::THREAD,
            'from_identifier' => '27713510291',
            'occurred_at' => now(),
            'captured_at' => now(),
            'body_text'   => $body,
            'body_status' => $body ? 'captured' : null,
            'owner_user_id' => $this->owner->id,
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => Contact::class, 'linkable_id' => $this->contact->id,
            'link_method' => CommunicationLink::METHOD_DETERMINISTIC, 'confidence' => 100,
        ]);

        return $comm;
    }

    public function test_thread_renders_inbound_left_and_outbound_right_with_player_and_metadata(): void
    {
        // Inbound text (contact → agent).
        $this->makeComm(Communication::DIRECTION_INBOUND, 'Hi, is the house still available?');

        // Outbound voice note (agent → contact) — media-only, stored + playable.
        $out = $this->makeComm(Communication::DIRECTION_OUTBOUND, null);
        $out->update(['has_attachments' => true]);
        CommunicationAttachment::create([
            'agency_id' => $this->agencyId, 'communication_id' => $out->id,
            'filename' => 'PTT-voice.opus', 'mime' => 'audio/ogg; codecs=opus',
            'size_bytes' => 2048, 'content_hash' => str_repeat('a', 64),
            'storage_path' => "communications/{$this->agencyId}/attachment/aa/" . str_repeat('a', 64),
            'media_status' => CommunicationAttachment::MEDIA_STORED, 'duration_seconds' => 7,
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.thread', ['threadKey' => self::THREAD]))
            ->assertOk()
            ->getContent();

        // Direction-based alignment: both an outbound (right) and inbound (left) row.
        $this->assertStringContainsString('justify-end', $html, 'outbound bubble aligns right');
        $this->assertStringContainsString('justify-start', $html, 'inbound bubble aligns left');

        // The AT-148 voice-note player is still rendered inside the bubble.
        $this->assertStringContainsString('<audio controls', $html);
        $this->assertStringContainsString(
            route('compliance.comm-archive.attachment', $out->attachments()->first()->id),
            $html,
            'player sources the authenticated attachment route'
        );
        $this->assertStringContainsString('Voice note', $html);

        // Per-message metadata: sender, direction words, channel tag, body.
        $this->assertStringContainsString('Outbound', $html);
        $this->assertStringContainsString('Inbound', $html);
        $this->assertStringContainsString('ds-badge-success', $html, 'WhatsApp channel tag present');
        $this->assertStringContainsString('Whatsapp', $html); // ucfirst('whatsapp')
        $this->assertStringContainsString('Hi, is the house still available?', $html);

        // Tasteful green tint on outbound (CoreX --ds-green), neutral inbound surface.
        $this->assertStringContainsString('--ds-green', $html);

        // No emojis anywhere in the rendered markup.
        $this->assertSame(0, preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F399}\x{1F4CE}]/u', $html),
            'no emoji glyphs in the thread markup');
    }

    public function test_media_only_message_is_not_a_blank_bubble(): void
    {
        // A voice note with NO body must still render a bubble with its player.
        $out = $this->makeComm(Communication::DIRECTION_INBOUND, null);
        $out->update(['has_attachments' => true]);
        CommunicationAttachment::create([
            'agency_id' => $this->agencyId, 'communication_id' => $out->id,
            'filename' => 'PTT.opus', 'mime' => 'audio/ogg', 'size_bytes' => 1024,
            'content_hash' => str_repeat('b', 64),
            'storage_path' => "communications/{$this->agencyId}/attachment/bb/" . str_repeat('b', 64),
            'media_status' => CommunicationAttachment::MEDIA_STORED, 'duration_seconds' => 4,
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.thread', ['threadKey' => self::THREAD]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<audio controls', $html);
        $this->assertStringNotContainsString('No message content captured', $html,
            'a media-only message renders its player, not the empty placeholder');
    }
}
