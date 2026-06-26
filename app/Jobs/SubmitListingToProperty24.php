<?php

namespace App\Jobs;

use App\Models\Agency;
use App\Models\Property;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SubmitListingToProperty24 implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    // AT-P24 remediation (#4): dispatch-level dedupe — only one submit job per
    // property may be queued/in-flight at a time. Prevents the observer
    // re-submit racing a manual submit (P24 "Cannot call the method
    // simultaneously"). The lock is released when the job finishes; uniqueFor
    // caps it so a lost job can't block the property forever.
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return (string) $this->property->id;
    }
    // Must exceed Property24ApiClient's HTTP read timeout so the HTTP layer
    // times out first and the ConnectionException is caught/handled — rather
    // than Laravel SIGKILL-ing the job mid-request when the two are equal (the
    // old 2-minute hard-kill + retry storm). AT-101: the read timeout is now
    // per-agency configurable, so the job timeout is derived as read + 60 in
    // the constructor (default read 120 → 180, the prior hardcoded value).
    public int $timeout = 180;

    public function __construct(public Property $property)
    {
        $readTimeout = $property->agency?->p24HttpReadTimeout() ?? Agency::P24_DEFAULT_HTTP_READ_TIMEOUT;
        $this->timeout = $readTimeout + 60;
    }

    public function handle(Property24SyndicationService $service): void
    {
        $service->submitListing($this->property);
    }

    /**
     * Called by the queue after all $tries are exhausted — including a job
     * SIGKILL'd on timeout, which throws MaxAttemptsExceededException on the
     * final attempt. submitListing()'s own ->update(['error']) writes live
     * INSIDE handle(), so a timed-out / hard-failed job never reaches them and
     * the property would otherwise sit at 'submitting' forever (the UI shows
     * "Syncing…" indefinitely). Resolve it to a visible, retryable 'error'.
     *
     * Only touch a row still mid-sync — never clobber a status the user or a
     * later successful run has already moved on to.
     */
    public function failed(\Throwable $e): void
    {
        $fresh = $this->property->fresh();
        if (! $fresh || ! in_array($fresh->p24_syndication_status, ['submitting', 'submitted'], true)) {
            return;
        }

        $fresh->update([
            'p24_syndication_status' => 'error',
            'p24_last_error'         => 'Sync failed (job exhausted/timed out): ' . $e->getMessage(),
        ]);

        \Illuminate\Support\Facades\Log::channel('property24')->error(
            "SubmitListingToProperty24 failed for property #{$this->property->id}",
            ['error' => $e->getMessage()]
        );
    }
}
