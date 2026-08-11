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
 * IMPORTANT: file ONLY the version ids the event carries — the splitter may skip some PDFs (its
 * qpdf page-count check; a known pre-existing splitter trait), so signedDocumentVersionIds can be a
 * SUBSET. Any skipped upload stays on the working list for the agent to re-handle.
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

        Log::info('Recipient supporting batch filed by PDF splitter', [
            'signature_request_id'  => $event->signatureRequestId,
            'version_ids_in_event'  => $ids,
            'rows_flipped_to_filed' => $filed,
            'document_ids'          => $event->documentIds,
        ]);
    }
}
