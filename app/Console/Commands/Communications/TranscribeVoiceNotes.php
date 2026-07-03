<?php

namespace App\Console\Commands\Communications;

use App\Jobs\Communications\TranscribeVoiceNoteJob;
use App\Models\Agency;
use App\Models\Communications\Communication;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * AT-163 — nightly voice-note transcription batch (§2.3).
 *
 * Scheduled HOURLY; each run transcribes the not-yet-done, consent-eligible voice
 * notes for agencies whose configured nightly time (agencies.wa_transcription_time,
 * default 22:00, well clear of the 03:30 restic backup) matches the current hour
 * and who have it enabled. `--now` ignores the schedule (on-demand / testing);
 * `--sync` runs inline instead of queueing (for a controlled run / tinker proof).
 *
 * Consent: selection uses Communication::scopeNeedsTranscription — a withheld
 * (embargoed/consent_pending) note has no stored audio and is excluded, so a body
 * is never transcribed without capture consent (§4).
 */
class TranscribeVoiceNotes extends Command
{
    protected $signature = 'communications:transcribe-voice-notes
        {--agency= : Restrict to one agency id}
        {--now : Ignore the per-agency scheduled time; run for all enabled agencies now}
        {--sync : Transcribe inline instead of dispatching to the queue}
        {--limit=500 : Max notes per run (safety cap)}';

    protected $description = 'Transcribe consent-eligible WhatsApp voice notes (nightly batch + on-demand).';

    public function handle(): int
    {
        if (! (bool) config('communications.transcription.enabled', true)) {
            $this->warn('Transcription is disabled (communications.transcription.enabled=false).');
            return self::SUCCESS;
        }

        $agencyOpt = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $runNow    = (bool) $this->option('now');
        $sync      = (bool) $this->option('sync');
        $limit     = max(1, (int) $this->option('limit'));
        $currentHm = now()->format('H:i');

        $agencies = Agency::query()
            ->when($agencyOpt !== null, fn ($q) => $q->where('id', $agencyOpt))
            ->get(['id', 'wa_transcription_enabled', 'wa_transcription_time']);

        $dispatched = 0;
        foreach ($agencies as $agency) {
            if (! (bool) $agency->wa_transcription_enabled) {
                continue;
            }
            // Hour-granular schedule match unless --now / --agency forces it.
            $scheduled = substr((string) ($agency->wa_transcription_time ?: '22:00'), 0, 2);
            if (! $runNow && $agencyOpt === null && $scheduled !== substr($currentHm, 0, 2)) {
                continue;
            }

            $notes = Communication::query()->withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agency->id)
                ->needsTranscription()
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get(['id']);

            foreach ($notes as $note) {
                if ($sync) {
                    Communication::withoutGlobalScopes()->find($note->id)?->load('attachments');
                    dispatch_sync(new TranscribeVoiceNoteJob($note->id));
                } else {
                    TranscribeVoiceNoteJob::dispatch($note->id);
                }
                $dispatched++;
            }
        }

        Log::info('AT-163 transcription batch', [
            'agency' => $agencyOpt, 'now' => $runNow, 'sync' => $sync, 'notes' => $dispatched,
        ]);
        $this->info("Transcription batch: {$dispatched} voice note(s) " . ($sync ? 'transcribed inline' : 'queued') . '.');

        return self::SUCCESS;
    }
}
