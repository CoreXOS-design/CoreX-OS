<?php

namespace App\Listeners\Document;

use App\Events\Docuperfect\SupportingBatchFiled;
use App\Models\Docuperfect\SignedDocumentVersion;
use Illuminate\Support\Facades\Log;

/**
 * E-sign recipient supporting docs — Part B step 3.
 *
 * When the PDF splitter finishes filing a batch that was pulled in via the intake-by-reference
 * hook, it fires SupportingBatchFiled. cc2 owns stamping the SOURCE SignedDocumentVersion rows as
 * filed so they drop off the "Recipient additional docs to file" working list into the
 * "Filed additional docs" archive.
 *
 * The event carries the version ids the splitter SUCCESSFULLY split-and-filed — it may be a SUBSET,
 * because the splitter skips some PDFs (its qpdf page-count check; a known pre-existing splitter
 * trait). We file that subset first.
 *
 * ISSUE A (Johan, 2026-08-12) — a doc the splitter cannot split must NOT strand under "to file"
 * forever. For a SUPPORTING document "filed" means "the agent has archived this recipient upload";
 * it is a DIFFERENT concern from whether the splitter could enrich (split/classify) it. Sending the
 * batch to the splitter IS the agent's act of filing it, so once the splitter reports completion we
 * also archive the remainder of the SAME UPLOAD COHORT it skipped. Skipped docs stay fully viewable
 * in the "Filed additional docs" archive — nothing is hidden, only un-stranded.
 *
 * RACE-SAFE COHORT BOUND: we only auto-file remaining unfiled versions whose created_at is at or
 * before the newest created_at among the event's filed versions — i.e. docs uploaded in the same
 * sitting as what the splitter just processed. A supporting doc the recipient uploads AFTER the
 * hand-off (a later cohort) is NOT swept in; it stays on the working list for its own hand-off.
 */
class FileSupportingBatchOnSplitterCompletion
{
    public function handle(SupportingBatchFiled $event): void
    {
        $ids = array_values(array_filter(array_map('intval', $event->signedDocumentVersionIds)));
        if (empty($ids)) {
            return;
        }

        $filed = SignedDocumentVersion::query()
            ->whereIn('id', $ids)
            ->where('kind', SignedDocumentVersion::KIND_SUPPORTING)
            ->whereNull('filed_at')
            ->update([
                'filed_at'         => now(),
                'filed_by_user_id' => $event->actorUserId(),
            ]);

        // ISSUE A — archive the same-cohort remainder the splitter skipped so it never strands.
        // Cohort ceiling = newest upload time among the docs the splitter just filed.
        $cohortCeiling = SignedDocumentVersion::query()
            ->whereIn('id', $ids)
            ->where('kind', SignedDocumentVersion::KIND_SUPPORTING)
            ->max('created_at');

        $skippedFiled = 0;
        if ($cohortCeiling !== null && $event->signatureRequestId) {
            $skippedFiled = SignedDocumentVersion::query()
                ->where('signature_request_id', $event->signatureRequestId)
                ->where('kind', SignedDocumentVersion::KIND_SUPPORTING)
                ->whereNull('filed_at')
                ->where('created_at', '<=', $cohortCeiling)
                ->update([
                    'filed_at'         => now(),
                    'filed_by_user_id' => $event->actorUserId(),
                ]);
        }

        Log::info('Recipient supporting batch filed by PDF splitter', [
            'signature_request_id'    => $event->signatureRequestId,
            'version_ids_in_event'    => $ids,
            'rows_flipped_to_filed'   => $filed,
            'cohort_skipped_filed'    => $skippedFiled,
            'document_ids'            => $event->documentIds,
        ]);
    }
}
