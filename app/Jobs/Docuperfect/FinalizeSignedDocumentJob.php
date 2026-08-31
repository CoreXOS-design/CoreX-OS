<?php

namespace App\Jobs\Docuperfect;

use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The deferred half of e-sign completion (config('docuperfect.async_completion')) —
 * PDF generation, contact linking, auto-filing, completion emails, lease extraction.
 * Dispatched from SignatureService::completeDocument() inside the same DB transaction
 * as the completion status write (transactional outbox — see the dispatch call sites).
 *
 * Steps run in SignatureService::runPostCompletionCascade(), strictly in order
 * (PDF -> link contacts -> auto-file -> email -> lease) — never parallelised, since
 * auto-filing needs the PDF paths and the email needs the filed-document list.
 *
 * Every step is idempotent, so retries and (the rarer, still-possible) duplicate
 * dispatch are both safe: no double Puppeteer render, no duplicate filed Document,
 * no duplicate client email, no duplicate LeaseRecord. See runPostCompletionCascade()
 * for exactly how each step guards itself.
 */
class FinalizeSignedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Two full Puppeteer renders (client + internal/audit copy) plus filing/emailing —
     * measured ~9s per single render on this box (cold Chromium launch dominates, not
     * page count), so two renders alone can approach a minute under load. 180s covers
     * that with headroom without masking a genuinely stuck Chromium process.
     */
    public int $timeout = 180;

    /**
     * A transient failure (SMTP blip, disk hiccup, a slow Chromium launch under load)
     * deserves a retry; a document that will never render is not fixed by trying
     * five more times. 3 tries, same shape as the other mail-adjacent jobs in this
     * app (SendAgentInviteJob), so a genuinely broken document lands in failed_jobs
     * — visible, not silently retried forever — after a bounded number of attempts.
     */
    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public int $signatureTemplateId,
        public ?array $pdfPaths = null,
    ) {
    }

    public function handle(SignatureService $signatureService): void
    {
        $template = SignatureTemplate::find($this->signatureTemplateId);

        if (!$template) {
            Log::warning('FinalizeSignedDocumentJob: signature template no longer exists', [
                'signature_template_id' => $this->signatureTemplateId,
            ]);

            return;
        }

        // Defensive: this job only ever makes sense for a completed document. It
        // should be unreachable (the dispatch sites only fire from inside
        // completeDocument() itself), but a queue can redeliver against stale state
        // after a manual DB edit — refuse quietly rather than filing/emailing a
        // document that was, for whatever reason, un-completed since this was queued.
        if ($template->status !== SignatureTemplate::STATUS_COMPLETED) {
            Log::warning('FinalizeSignedDocumentJob: template is no longer COMPLETED, skipping', [
                'signature_template_id' => $this->signatureTemplateId,
                'status' => $template->status,
            ]);

            return;
        }

        // Johan, 2026-08-31 — "we cannot have it fail silently". Recorded on
        // SignatureTemplate (never on `status`, which stays COMPLETED) so a
        // failure is visible on the document even before retries are exhausted.
        $signatureService->recordFinalizationStarted($template);

        // Deliberately NOT wrapped in try/catch — an exception here must propagate
        // so Laravel's retry/backoff runs and, on final failure, the job lands in
        // failed_jobs with its exception recorded AND failed() below fires. The
        // signing itself is already COMPLETED and committed (this job only runs
        // after that transaction lands); this failure is about delivery/filing,
        // never about undoing the signing.
        $signatureService->runPostCompletionCascade($template, $this->pdfPaths);

        $signatureService->recordFinalizationSucceeded($template);
    }

    /**
     * Retries exhausted (or the job died in a way Laravel can't recover from) —
     * the terminal failure. Records `finalization_status = failed` on the
     * template AND notifies the approving agent + agency admin in the SAME call
     * (SignatureService::recordFinalizationFailed — record and notify are never
     * split, an unnotified recorded failure is the exact silent failure Johan
     * reported). This job still populates failed_jobs the normal way (by
     * letting handle() throw), for whatever queue-failure tooling also watches
     * that table — this is the document-level visibility layer on top of it.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('FinalizeSignedDocumentJob: exhausted retries — document remains COMPLETED, post-completion delivery/filing did not finish', [
            'signature_template_id' => $this->signatureTemplateId,
            'error' => $e->getMessage(),
        ]);

        $template = SignatureTemplate::find($this->signatureTemplateId);
        if ($template) {
            app(SignatureService::class)->recordFinalizationFailed($template, $e->getMessage());
        }
    }
}
