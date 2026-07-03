<?php

namespace App\Jobs\Communications;

use App\Models\Communications\Communication;
use App\Services\Communications\TranscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AT-163 — transcribe one voice note off the queue (nightly batch + on-demand).
 *
 * The heavy work (whisper.cpp) runs one note at a time on the worker so it never
 * saturates the box; the TranscriptionService owns the AT-148-style state machine
 * (processing → done/failed, retry-capped), so this job is a thin dispatch shell.
 * ShouldBeUnique on the communication id prevents the nightly batch and a manual
 * "Transcribe now" from double-running the same note.
 */
class TranscribeVoiceNoteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200; // > the worker's own cap; a stuck note fails, never hangs the worker forever
    public int $tries = 1;      // retry is modelled in TranscriptionService (retry_count), not job retries

    public function __construct(public int $communicationId, public ?string $modelOverride = null)
    {
    }

    public function uniqueId(): string
    {
        return 'transcribe-' . $this->communicationId;
    }

    public function handle(TranscriptionService $service): void
    {
        $comm = Communication::withoutGlobalScopes()->find($this->communicationId);
        if (! $comm) {
            return;
        }
        $comm->load('attachments');
        $service->transcribe($comm, $this->modelOverride);
    }
}
