<?php

namespace App\Jobs;

use App\Models\RentalApplication;
use App\Services\RentalApplications\RentalApplicationPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AT-392 — files the decision PDF ("Approved"/"Declined Rental Application")
 * on the contact. Queued rather than run inline (2026-09-13, cc5 — see
 * .ai/specs/rental-applications.md "Authoriser approve/decline consistency")
 * after tracing a real bug to the synchronous call: PDF generation shells
 * out to a real headless-Chromium Puppeteer subprocess (~9s observed) —
 * long enough for an unrelated concurrent request on the SAME session (the
 * portal-leads background poller, confirmed live) to read-modify-write the
 * session first and silently clobber the flash message the controller had
 * already set, so the authoriser who declined saw no confirmation at all
 * while an authoriser who approved (no PDF step, near-instant) always did.
 * Running this after the response is already sent removes the multi-second
 * window that race depends on — not just for this one interference source,
 * for any concurrent session-touching request during that window.
 *
 * Filing was always "best-effort" by the controller's own comment
 * (fileAsDocument() catches its own failures) — queuing doesn't change that
 * contract, it just moves where "best-effort" happens to after the human
 * stops waiting on it.
 */
class FileRentalApplicationDecisionPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public int $rentalApplicationId, public string $label) {}

    public function handle(RentalApplicationPdfService $pdfService): void
    {
        $rentalApplication = RentalApplication::find($this->rentalApplicationId);
        if (!$rentalApplication) {
            return;
        }

        $pdfService->fileAsDocument($rentalApplication, $this->label);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('FileRentalApplicationDecisionPdfJob: permanent failure', [
            'rental_application_id' => $this->rentalApplicationId,
            'label' => $this->label,
            'error' => $e->getMessage(),
        ]);
    }
}
