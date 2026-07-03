<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\TranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-163 — voice-note transcription: the consent gate (a withheld note is never
 * transcribed), the AT-148-style state machine (done / failed+retry), and search.
 *
 * A stub worker stands in for whisper.cpp so the test exercises the service's
 * parse + state logic deterministically without the real model.
 */
final class WaTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private Contact $contact;
    private string $stubBin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(6), 'slug' => 't-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Andre', 'last_name' => 'Roets', 'phone' => '0799522551',
        ]);

        config(['communications.transcription.enabled' => true, 'communications.transcription.model' => 'medium']);
    }

    protected function tearDown(): void
    {
        if (isset($this->stubBin) && is_file($this->stubBin)) {
            @unlink($this->stubBin);
        }
        parent::tearDown();
    }

    /** Point the service's worker at a stub that echoes a fixed whisper JSON. */
    private function useStubWorker(string $text, string $lang = 'af', bool $fail = false): void
    {
        $this->stubBin = sys_get_temp_dir() . '/cx-whisper-stub-' . Str::random(6) . '.sh';
        if ($fail) {
            $script = "#!/usr/bin/env bash\nexit 7\n";
        } else {
            $json = json_encode(['result' => ['language' => $lang], 'transcription' => [['text' => ' ' . $text]]]);
            $script = "#!/usr/bin/env bash\ncat <<'JSON'\n{$json}\nJSON\n";
        }
        file_put_contents($this->stubBin, $script);
        chmod($this->stubBin, 0755);
        config(['communications.transcription.binary' => $this->stubBin]);
    }

    private function voiceNote(?string $bodyStatus, string $mediaStatus = 'stored', bool $withFile = true): Communication
    {
        $comm = Communication::create([
            'agency_id' => $this->agencyId, 'channel' => 'whatsapp', 'direction' => 'inbound',
            'external_id' => Str::random(14), 'thread_key' => 'wa:799522551', 'wa_chat_id' => '244632797597780@lid',
            'from_identifier' => '27799522551', 'body_status' => $bodyStatus, 'has_attachments' => true,
            'owner_user_id' => $this->agent->id, 'occurred_at' => now(), 'captured_at' => now(),
        ]);
        $path = "communications/{$this->agencyId}/attachment/xx/" . Str::random(20);
        if ($withFile) {
            Storage::disk('local')->put($path, 'OPUSBYTES');
        }
        CommunicationAttachment::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'filename' => 'voice.oga', 'mime' => 'audio/ogg; codecs=opus',
            'size_bytes' => 9, 'content_hash' => hash('sha256', 'OPUSBYTES'),
            'storage_path' => $mediaStatus === 'stored' ? $path : null, 'media_status' => $mediaStatus,
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => Contact::class, 'linkable_id' => $this->contact->id,
            'link_method' => 'deterministic', 'confidence' => 100, 'confirmed_at' => now(),
        ]);
        return $comm->load('attachments');
    }

    // ── Consent gate ────────────────────────────────────────────────────────

    public function test_embargoed_and_withheld_notes_are_never_transcribable(): void
    {
        $svc = app(TranscriptionService::class);
        $this->assertNull($svc->transcribableAttachment($this->voiceNote('embargoed')), 'embargoed → no transcription');
        $this->assertNull($svc->transcribableAttachment($this->voiceNote('consent_pending')), 'pending → no transcription');
        $this->assertNull($svc->transcribableAttachment($this->voiceNote('embargo_purged')), 'purged → no transcription');
        // A consent-captured media-only note (body_status null, stored audio) IS eligible.
        $this->assertNotNull($svc->transcribableAttachment($this->voiceNote(null)), 'captured media-only → transcribable');
    }

    public function test_scope_excludes_withheld_and_already_done(): void
    {
        $ok       = $this->voiceNote(null);
        $embargo  = $this->voiceNote('embargoed');
        $done     = $this->voiceNote(null);
        $done->update(['transcript_status' => 'done', 'transcript_text' => 'x']);

        $ids = Communication::query()->where('agency_id', $this->agencyId)->needsTranscription()->pluck('id')->all();
        $this->assertContains($ok->id, $ids);
        $this->assertNotContains($embargo->id, $ids, 'embargoed excluded');
        $this->assertNotContains($done->id, $ids, 'already-done excluded');
    }

    // ── State machine ───────────────────────────────────────────────────────

    public function test_successful_transcription_writes_text_lang_and_done(): void
    {
        $this->useStubWorker('ok wel die website is klaar', 'af');
        $note = $this->voiceNote(null);

        $result = app(TranscriptionService::class)->transcribe($note);

        $this->assertSame('done', $result['status']);
        $note->refresh();
        $this->assertSame('done', $note->transcript_status);
        $this->assertSame('ok wel die website is klaar', $note->transcript_text);
        $this->assertSame('af', $note->transcript_lang);
        $this->assertSame('medium', $note->transcript_model);
        $this->assertNotNull($note->transcript_at);
        $this->assertTrue($note->hasTranscript());
    }

    public function test_failure_retries_then_terminally_fails(): void
    {
        config(['communications.transcription.max_retries' => 2]);
        $this->useStubWorker('', 'af', fail: true);
        $note = $this->voiceNote(null);

        $r1 = app(TranscriptionService::class)->transcribe($note);
        $this->assertSame('pending', $r1['status'], 'first failure → pending (retryable)');
        $this->assertSame(1, $note->fresh()->transcript_retry_count);

        $r2 = app(TranscriptionService::class)->transcribe($note->fresh());
        $this->assertSame('failed', $r2['status'], 'second failure → terminal failed');
        $this->assertSame('failed', $note->fresh()->transcript_status);
    }

    // ── Search + purge interplay ────────────────────────────────────────────

    public function test_transcript_is_searchable_in_thread(): void
    {
        $this->useStubWorker('vergaderings volgende week Dinsdag', 'af');
        $note = $this->voiceNote(null);
        app(TranscriptionService::class)->transcribe($note);

        $admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin']);
        $resp = $this->actingAs($admin)->getJson(
            route('api.v1.communications.threads.search', ['threadKey' => 'wa:799522551']) . '?q=' . urlencode('Dinsdag')
        );
        $resp->assertOk();
        $ids = collect($resp->json('matches'))->pluck('id')->all();
        $this->assertContains($note->id, $ids, 'a voice-note transcript is found by in-thread search');
    }

    public function test_purging_the_body_purges_the_transcript(): void
    {
        // A note that somehow carries a transcript is embargoed + expired → purge
        // removes the transcript with the body.
        $note = $this->voiceNote('embargoed');
        $note->update([
            'occurred_at' => now()->subDays(60),
            'transcript_text' => 'leaked', 'transcript_preview' => 'leaked', 'transcript_status' => 'done',
        ]);

        $this->artisan('communications:purge-embargoed-bodies', ['--agency' => $this->agencyId])->assertSuccessful();

        $note->refresh();
        $this->assertSame('embargo_purged', $note->body_status);
        $this->assertNull($note->transcript_text, 'transcript purged with the body');
        $this->assertNull($note->transcript_status);
    }
}
