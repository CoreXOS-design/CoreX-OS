<?php

namespace App\Observers;

use App\Models\FicaSubmission;
use App\Services\Compliance\Tfs\TfsScreeningService;
use Illuminate\Support\Facades\Log;

/**
 * Auto-run TFS sanctions screening as soon as a FICA submission's identifying details
 * land (name + ID on save/create), and re-run automatically when those details change.
 * Idempotent via TfsScreeningService::screenIfNeeded() — a save that doesn't change the
 * screened identity is a no-op. Screening is DB-only (no network), so this is cheap and
 * safe on the save path; a failure never breaks the submission save (logged, swallowed).
 */
class FicaSubmissionObserver
{
    public function saved(FicaSubmission $submission): void
    {
        try {
            app(TfsScreeningService::class)->screenIfNeeded($submission);
        } catch (\Throwable $e) {
            Log::warning('TFS auto-screen failed on save', [
                'submission_id' => $submission->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
