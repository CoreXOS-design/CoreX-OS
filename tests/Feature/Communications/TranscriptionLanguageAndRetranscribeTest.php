<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Agency;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\User;
use App\Services\Communications\TranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-194 — per-agency transcription LANGUAGE hint + the re-transcription tool.
 *
 * Proves the language reaches the on-box worker (4th arg), the setting round-trips +
 * validates, and the re-transcription command is idempotent/resumable and audio-safe —
 * using a STUB worker binary that echoes its args as the "transcript" (so no real
 * whisper run is needed and the exact args are observable).
 */
final class TranscriptionLanguageAndRetranscribeTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;
    private string $stub;
    private string $disk = 'local';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC ' . Str::random(6), 'slug' => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);

        // Stub worker: emits whisper-shaped JSON whose text encodes the args it received
        // (model + language), so the test can assert what reached the worker.
        $this->stub = sys_get_temp_dir() . '/at191-stub-' . Str::random(6) . '.sh';
        file_put_contents($this->stub, <<<'SH'
#!/usr/bin/env bash
# $1=audio $2=model $3=threads $4=language
printf '{"result":{"language":"%s"},"transcription":[{"text":"MODEL=%s LANG=%s"}]}\n' "${4:-auto}" "${2:-medium}" "${4:-auto}"
SH);
        chmod($this->stub, 0755);
        config([
            'communications.transcription.enabled' => true,
            'communications.transcription.binary'  => $this->stub,
            'communications.transcription.model'   => 'medium',
            'communications.disk'                  => $this->disk,
        ]);
        Storage::fake($this->disk);
    }

    protected function tearDown(): void
    {
        @unlink($this->stub);
        parent::tearDown();
    }

    private function voiceNote(string $model = null, string $status = null): Communication
    {
        $comm = Communication::withoutGlobalScopes()->create([
            'agency_id'   => $this->agencyId,
            'channel'     => Communication::CHANNEL_WHATSAPP,
            'direction'   => 'inbound',
            'external_id' => 'wa-' . Str::random(16),
            'occurred_at' => now(),
            'captured_at' => now(),
            'transcript_model'  => $model,
            'transcript_status' => $status,
        ]);
        $path = "communications/{$this->agencyId}/att/" . Str::random(8) . '.oga';
        Storage::disk($this->disk)->put($path, 'FAKE-OPUS-BYTES');
        CommunicationAttachment::withoutGlobalScopes()->create([
            'agency_id'        => $this->agencyId,
            'communication_id' => $comm->id,
            'mime'             => 'audio/ogg; codecs=opus',
            'media_status'     => CommunicationAttachment::MEDIA_STORED,
            'storage_path'     => $path,
            'content_hash'     => hash('sha256', $path),
            'size_bytes'       => 15,
        ]);
        return $comm->fresh();
    }

    // ── Agency setting ──

    public function test_language_defaults_to_auto_and_rejects_unknown(): void
    {
        $a = Agency::find($this->agencyId);
        $this->assertSame('auto', $a->transcriptionLanguage(), 'null => auto');

        $a->update(['wa_transcription_language' => 'af']);
        $this->assertSame('af', $a->fresh()->transcriptionLanguage());

        $a->update(['wa_transcription_language' => 'klingon']);
        $this->assertSame('auto', $a->fresh()->transcriptionLanguage(), 'unknown => auto');
    }

    public function test_settings_endpoint_persists_and_validates(): void
    {
        // No role_permissions seeded → the test-env permission bypass grants the admin
        // gate (the gate itself is exercised in production; here we prove persist+validate).
        $this->actingAs($this->admin)
            ->post(route('communications.wa-devices.transcription-language'), ['language' => 'af'])
            ->assertRedirect();
        $this->assertSame('af', Agency::find($this->agencyId)->fresh()->transcriptionLanguage());

        // Invalid language rejected.
        $this->actingAs($this->admin)
            ->post(route('communications.wa-devices.transcription-language'), ['language' => 'zz'])
            ->assertSessionHasErrors('language');
        $this->assertSame('af', Agency::find($this->agencyId)->fresh()->transcriptionLanguage(), 'unchanged after invalid');
    }

    // ── Language reaches the worker ──

    public function test_agency_language_is_passed_to_the_worker(): void
    {
        Agency::find($this->agencyId)->update(['wa_transcription_language' => 'af']);
        $comm = $this->voiceNote();

        $res = app(TranscriptionService::class)->transcribe($comm);

        $this->assertSame('done', $res['status']);
        $this->assertSame('af', $comm->fresh()->transcript_lang);
        $this->assertStringContainsString('LANG=af', (string) $comm->fresh()->transcript_text, 'worker received -l af');
    }

    public function test_no_agency_language_falls_back_to_auto(): void
    {
        $comm = $this->voiceNote();
        $res = app(TranscriptionService::class)->transcribe($comm);
        $this->assertSame('done', $res['status']);
        $this->assertStringContainsString('LANG=auto', (string) $comm->fresh()->transcript_text);
    }

    // ── Re-transcription command: idempotent / resumable / model-target / audio-safe ──

    public function test_retranscribe_is_idempotent_at_target_model_but_redoes_on_model_change(): void
    {
        Agency::find($this->agencyId)->update(['wa_transcription_language' => 'af']);
        // A note already produced by 'medium'.
        $comm = $this->voiceNote('medium', 'done');
        $comm->forceFill(['transcript_text' => 'OLD medium text'])->save();
        $audioPath = $comm->attachments->first()->storage_path;

        // Same target model → skipped (idempotent/resumable), transcript untouched.
        $this->artisan('communications:retranscribe-voice-notes', ['--agency' => $this->agencyId, '--model' => 'medium'])
            ->assertExitCode(0);
        $this->assertSame('OLD medium text', $comm->fresh()->transcript_text, 'idempotent: not redone at same model');

        // New target model → regenerated through the (stub) worker with the agency language.
        $this->artisan('communications:retranscribe-voice-notes', ['--agency' => $this->agencyId, '--model' => 'large-v3'])
            ->assertExitCode(0);
        $fresh = $comm->fresh();
        $this->assertSame('large-v3', $fresh->transcript_model, 'transcript_model records new engine (audit)');
        $this->assertStringContainsString('MODEL=large-v3', (string) $fresh->transcript_text);
        $this->assertStringContainsString('LANG=af', (string) $fresh->transcript_text);

        // Audio preserved — the attachment file is untouched.
        $this->assertTrue(Storage::disk($this->disk)->exists($audioPath), 'stored audio preserved');
        $this->assertSame('FAKE-OPUS-BYTES', Storage::disk($this->disk)->get($audioPath));
    }

    public function test_retranscribe_force_redoes_even_at_target_model(): void
    {
        Agency::find($this->agencyId)->update(['wa_transcription_language' => 'af']);
        $comm = $this->voiceNote('large-v3', 'done');
        $comm->forceFill(['transcript_text' => 'stale'])->save();

        $this->artisan('communications:retranscribe-voice-notes', ['--agency' => $this->agencyId, '--model' => 'large-v3', '--force' => true])
            ->assertExitCode(0);
        $this->assertStringContainsString('MODEL=large-v3', (string) $comm->fresh()->transcript_text, 'force re-does at same model');
    }
}
