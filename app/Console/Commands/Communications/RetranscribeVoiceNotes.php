<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\TranscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * AT-194 — regenerate voice-note transcripts through the CURRENTLY-CONFIGURED model
 * (+ each agency's language hint). The standing tool for a model/language change: run
 * it after flipping COREX_TRANSCRIBE_MODEL (or an agency's language) to bring existing
 * transcripts up to the new engine.
 *
 * Properties:
 *  - IDEMPOTENT / RESUMABLE: by default a note already transcribed by the TARGET model
 *    is skipped, so a re-run continues where it left off and never repeats settled work
 *    (`--force` re-does everything).
 *  - SCOPED to existing voice notes (WhatsApp, a stored audio attachment) and gated by
 *    the same consent rule as the nightly batch (withheld notes have no audio → skipped).
 *  - AUDIO PRESERVED: only the derived transcript_* fields are overwritten; the stored
 *    audio attachment is never touched.
 *  - AUDIT: `transcript_model` records which engine produced the current text (the
 *    archive's provenance field); each regeneration is logged (before → after model).
 *  - NICED: the on-box worker (transcribe.sh) runs whisper under `nice -n 15`.
 *
 * Runs INLINE (synchronous) so it uses the live config/env directly and is safe to
 * drive from a one-shot script — no dependency on a queue worker's cached config.
 */
class RetranscribeVoiceNotes extends Command
{
    protected $signature = 'communications:retranscribe-voice-notes
        {--agency= : Restrict to one agency id}
        {--id= : Restrict to a single communication id (verification)}
        {--model= : Target whisper model (default: the configured communications.transcription.model)}
        {--force : Re-transcribe even notes already produced by the target model}
        {--limit=1000 : Max notes per run (safety cap)}';

    protected $description = 'AT-194 — regenerate existing voice-note transcripts through the configured whisper model + agency language.';

    public function handle(TranscriptionService $service): int
    {
        if (! (bool) config('communications.transcription.enabled', true)) {
            $this->warn('Transcription is disabled (communications.transcription.enabled=false).');
            return self::SUCCESS;
        }

        $target  = (string) ($this->option('model') ?: config('communications.transcription.model', 'medium'));
        $force   = (bool) $this->option('force');
        $limit   = max(1, (int) $this->option('limit'));
        $agencyOpt = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $idOpt     = $this->option('id') !== null ? (int) $this->option('id') : null;

        // Existing WhatsApp voice notes that HAVE a stored audio attachment. Already-done
        // notes are included on purpose (this is a re-run through a new engine); the
        // consent gate lives in the service (a withheld note has no stored audio anyway).
        $query = Communication::query()->withoutGlobalScope(AgencyScope::class)
            ->where('channel', Communication::CHANNEL_WHATSAPP)
            ->whereHas('attachments', function ($q) {
                $q->where('media_status', CommunicationAttachment::MEDIA_STORED)
                  ->where('mime', 'like', 'audio%');
            })
            ->when($agencyOpt !== null, fn ($q) => $q->where('agency_id', $agencyOpt))
            ->when($idOpt !== null, fn ($q) => $q->where('id', $idOpt))
            ->orderByDesc('occurred_at')
            ->limit($limit);

        $notes = $query->get(['id', 'agency_id', 'transcript_model', 'transcript_status']);

        $this->info("Re-transcribe target model: {$target}  (candidates: {$notes->count()})");

        $done = 0; $skipped = 0; $failed = 0;
        foreach ($notes as $note) {
            // Resumable: a note already produced by the target model is settled — skip it.
            if (! $force && $note->transcript_status === 'done' && (string) $note->transcript_model === $target) {
                $skipped++;
                continue;
            }

            $comm = Communication::withoutGlobalScopes()->with('attachments')->find($note->id);
            if (! $comm) { $skipped++; continue; }

            $before = (string) ($comm->transcript_model ?? '—');
            $result = $service->transcribe($comm, $target);
            $status = $result['status'] ?? 'unknown';

            if ($status === 'done') {
                $done++;
                Log::info('AT-194 retranscribe', [
                    'communication_id' => $comm->id, 'agency_id' => $comm->agency_id,
                    'model_before' => $before, 'model_after' => $target, 'lang' => $result['lang'] ?? null,
                ]);
                $this->line("  #{$comm->id}: {$before} → {$target}  (lang " . ($result['lang'] ?? '?') . ')');
            } elseif (in_array($status, ['skipped', 'disabled'], true)) {
                $skipped++;
            } else {
                $failed++;
                $this->warn("  #{$comm->id}: FAILED ({$status})");
            }
        }

        $this->info("Re-transcribe complete — regenerated: {$done}, skipped: {$skipped}, failed: {$failed}.");
        Log::info('AT-194 retranscribe batch', [
            'agency' => $agencyOpt, 'model' => $target, 'regenerated' => $done, 'skipped' => $skipped, 'failed' => $failed,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
