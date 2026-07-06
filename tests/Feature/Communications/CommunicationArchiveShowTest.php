<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT — Communication Archive message-detail view (`compliance.comm-archive.show`).
 *
 * Regression guard for the live 500: the detail Blade carried a control directive glued to a
 * word char (`Voice note@if($duration)…@endif`), which Blade left un-compiled while compiling
 * its `@endif` — a dangling `endif` that broke the block-level `@elseif` ("unexpected token
 * elseif"). It was a STATIC compile error, so EVERY message 500'd (and the list's "Open" link
 * points here). These tests drive the real route across the whole message input space — email,
 * WhatsApp voice note (playable / processing), non-audio attachment, threaded / un-threaded —
 * and assert the detail renders (200), plus the list exposes a working "Open" link to it.
 */
final class CommunicationArchiveShowTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

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
    }

    private function message(string $channel, array $over = []): Communication
    {
        return Communication::create(array_merge([
            'agency_id' => $this->agencyId,
            'channel' => $channel,
            'direction' => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14),
            'thread_key' => null,
            'from_identifier' => '27713510291',
            'subject' => 'QA subject',
            'occurred_at' => now(),
            'captured_at' => now(),
            'owner_user_id' => $this->owner->id,
            'has_attachments' => false,
        ], $over));
    }

    private function attach(Communication $comm, string $mime, string $mediaStatus, ?int $duration = null): void
    {
        CommunicationAttachment::create([
            'agency_id' => $this->agencyId,
            'communication_id' => $comm->id,
            'filename' => 'file-' . Str::random(4),
            'mime' => $mime,
            'size_bytes' => 2048,
            'content_hash' => hash('sha256', Str::random(8)),
            'storage_path' => $mediaStatus === CommunicationAttachment::MEDIA_STORED ? "communications/{$this->agencyId}/attachment/x.bin" : null,
            'media_status' => $mediaStatus,
            'duration_seconds' => $duration,
        ]);
        $comm->update(['has_attachments' => true]);
    }

    private function assertDetailRenders(Communication $comm): void
    {
        $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.show', $comm))
            ->assertOk()
            ->assertSee('Communication');
    }

    public function test_email_with_no_attachments_renders(): void
    {
        $this->assertDetailRenders($this->message('email'));
    }

    public function test_whatsapp_playable_voice_note_renders(): void
    {
        // The exact branch that carried the broken glued @if($duration).
        $comm = $this->message(Communication::CHANNEL_WHATSAPP);
        $this->attach($comm, 'audio/ogg', CommunicationAttachment::MEDIA_STORED, duration: 7);
        $this->assertDetailRenders($comm);
    }

    public function test_whatsapp_processing_voice_note_renders(): void
    {
        $comm = $this->message(Communication::CHANNEL_WHATSAPP);
        $this->attach($comm, 'audio/ogg', CommunicationAttachment::MEDIA_PENDING, duration: null);
        $this->assertDetailRenders($comm);
    }

    public function test_non_audio_attachment_renders(): void
    {
        $comm = $this->message(Communication::CHANNEL_WHATSAPP);
        $this->attach($comm, 'application/pdf', CommunicationAttachment::MEDIA_STORED);
        $this->assertDetailRenders($comm);
    }

    public function test_threaded_message_renders_with_view_thread_link(): void
    {
        $comm = $this->message('email', ['thread_key' => 'tk-' . Str::random(6)]);
        $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.show', $comm))
            ->assertOk()
            ->assertSee('view thread');
    }

    public function test_archive_list_exposes_a_working_open_link_to_the_detail(): void
    {
        $comm = $this->message('email');

        $html = $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.index'))
            ->assertOk()
            ->getContent();

        // "Open" is present and points at the detail route for the row.
        $this->assertStringContainsString('>Open</a>', $html);
        $this->assertStringContainsString(route('compliance.comm-archive.show', $comm), $html);
    }
}
