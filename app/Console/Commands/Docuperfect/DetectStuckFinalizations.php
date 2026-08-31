<?php

namespace App\Console\Commands\Docuperfect;

use App\Models\Docuperfect\EsignSettings;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Console\Command;

/**
 * Johan, 2026-08-31 — the exact scenario he warned about: async completion on,
 * but no queue worker actually running, so FinalizeSignedDocumentJob is
 * dispatched and just sits in the `jobs` table forever, never picked up,
 * never failing, never notifying anyone. A completed document with no signed
 * PDF/filing/emails and nothing that ever says so.
 *
 * A SignatureTemplate is "stuck" here if it reached STATUS_COMPLETED longer
 * ago than its agency's own finalization_stuck_threshold_minutes (Finalisation
 * Settings, default 15) and its finalization_status is still null (never even
 * started — the dispatch-but-no-worker case) or `running` (started but never
 * finished — a job that died without reaching failed(), e.g. the worker
 * process itself was killed). Anything already `succeeded` or `failed` is
 * left alone — `failed` already went through the real notify path.
 */
class DetectStuckFinalizations extends Command
{
    protected $signature = 'docuperfect:detect-stuck-finalizations';

    protected $description = 'Flag completed e-sign documents whose post-completion work (PDF/filing/emails) never finished in time';

    public function handle(SignatureService $signatureService): int
    {
        $candidates = SignatureTemplate::query()
            ->where('status', SignatureTemplate::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->where(function ($q) {
                $q->whereNull('finalization_status')
                    ->orWhere('finalization_status', SignatureTemplate::FINALIZATION_RUNNING);
            })
            ->get();

        $flagged = 0;

        foreach ($candidates as $template) {
            $thresholdMinutes = EsignSettings::forAgency((int) ($template->agency_id ?: 0))
                ->finalizationStuckThresholdMinutes();

            if ($template->completed_at->diffInMinutes(now()) < $thresholdMinutes) {
                continue;
            }

            $signatureService->recordFinalizationFailed(
                $template,
                "No worker picked up this document's finalisation within {$thresholdMinutes} minute(s) of completion — the queue worker may not be running."
            );
            $flagged++;

            $this->warn("Flagged signature_template #{$template->id} (document #{$template->document_id}) as stuck.");
        }

        $this->info("Checked {$candidates->count()} candidate(s), flagged {$flagged} as stuck.");

        return 0;
    }
}
