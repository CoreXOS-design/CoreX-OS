<?php

namespace App\Jobs\Communications;

use App\Models\Communications\CommunicationAttachment;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\WaMediaRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AT-148 — retry a pending WhatsApp media download in the background. Dispatched
 * (with a growing backoff) when the synchronous download at ingest fails. Keeps
 * re-trying via {@see WaMediaRecoveryService} until the media is stored or the
 * configured max is hit (then the row is terminally 'failed'). Runs under the
 * device Bearer (no auth user) → bypass AgencyScope, like the ingest path.
 */
class RetryWaMediaDownloadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // we manage retries ourselves (backoff + terminal state)

    public function __construct(public int $attachmentId)
    {
    }

    public function handle(WaMediaRecoveryService $recovery): void
    {
        $att = CommunicationAttachment::withoutGlobalScope(AgencyScope::class)->find($this->attachmentId);
        if (! $att || $att->media_status === CommunicationAttachment::MEDIA_STORED) {
            return; // gone or already recovered
        }

        $recovered = $recovery->recover($att);

        // Still pending (under the max) → schedule the next attempt with backoff.
        if (! $recovered && $att->fresh()?->media_status === CommunicationAttachment::MEDIA_PENDING) {
            $backoff = max(5, (int) config('communications.waha.media_retry_backoff_seconds', 30));
            self::dispatch($this->attachmentId)
                ->delay(now()->addSeconds($backoff * max(1, (int) $att->retry_count)));
        }
    }
}
