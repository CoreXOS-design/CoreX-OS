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

    /**
     * Dedicated `transcription` queue (not `default`).
     *
     * A voice note costs ~45s of whisper.cpp CPU. Left on `default` a batch of
     * them head-of-line-blocks the fast scheduled work that shares that queue —
     * portal lead pulls, activation syncs, buyer matching. Observed live on
     * 2026-08-27: twelve notes dispatched at 22:02 pushed the oldest `default`
     * job to 764s and tripped the queue-backlog alert while nothing was wedged
     * and every worker was healthy. Same reasoning as PollMailboxJob's `mail`
     * queue: isolate the slow work so neither starves the other.
     *
     * This changes SCHEDULING ONLY. Box-wide transcription concurrency is still
     * exactly 1, enforced by TranscriptionService::WHISPER_LOCK — a second
     * worker on this queue would wait for the lock, never load a second model.
     * Served by supervisor program corex-worker-*-transcription.
     */
    public const QUEUE_NAME = 'transcription';

    public int $timeout = 1200; // > the worker's own cap; a stuck note fails, never hangs the worker forever
    public int $tries = 1;      // retry is modelled in TranscriptionService (retry_count), not job retries

    public function __construct(public int $communicationId, public ?string $modelOverride = null)
    {
        $this->onQueue(self::QUEUE_NAME);
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
