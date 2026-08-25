<?php

namespace App\Services\Compliance;

use App\Models\FicaSubmission;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The FICA Completion Report — Johan's spec, verbatim: "recipient what they
 * selected and their answers and their signature, including agent who
 * processed it plus their ticks etc. plus the ro or co who approved it -
 * not the audit report."
 *
 * Deliberately separate from FicaStatusHistory (the FIC-Act audit trail —
 * an append-only log of workflow hops). This is a content/completion
 * record: what was captured and who signed off, not a system event log.
 *
 * FREEZE RULE: generated exactly once, at the moment FicaController::
 * complianceApprove() sets status='approved', and the PDF FILE (not a live
 * re-render) is what downloadPdf() serves ever after. If the submission is
 * ever reopened and re-approved (corrections -> resubmit -> re-approve),
 * generate() runs again and overwrites the stored file/path — the download
 * always reflects the MOST RECENT approval, never a live query against
 * whatever the contact/submission record says today.
 *
 * Reuses the same Puppeteer renderer (scripts/html-to-pdf.mjs) the
 * Docuperfect e-sign pipeline uses — not a second PDF pipeline, just a
 * FICA-owned call site so this module never depends on SigningController
 * (which is under active regression-gate coverage tonight and out of scope
 * to touch).
 */
class FicaCompletionReportService
{
    /**
     * Render the completion report and store it. Returns the storage path
     * (relative to the 'local' disk), or null on failure (logged, never
     * thrown — a PDF-generation hiccup must never block the approval that
     * already committed).
     */
    public function generate(FicaSubmission $submission): ?string
    {
        $submission->loadMissing(['contact', 'agency', 'requestedBy', 'agentVerifiedBy', 'coVerifiedBy', 'documents']);

        $html = view('compliance.fica.completion-report', [
            'submission' => $submission,
            'agentVerifiedByUser' => $this->resolveVerifyingUser($submission->agent_verified_by),
            'wetInkFormEmbed' => $this->wetInkFormEmbed($submission),
        ])->render();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $stamp = time();
        $htmlPath = $tempDir . "/fica_{$submission->id}_{$stamp}.html";
        $pdfPath  = $tempDir . "/fica_{$submission->id}_{$stamp}.pdf";
        file_put_contents($htmlPath, $html);

        $scriptPath = base_path('scripts/html-to-pdf.mjs');
        $browserPath = config('services.pdf.puppeteer_browser_path', '');
        $envPrefix = 'HOME=/tmp';
        if ($browserPath) {
            $envPrefix .= ' PUPPETEER_BROWSER_PATH=' . escapeshellarg($browserPath);
        }

        $command = sprintf(
            '%s node %s %s %s > %s 2>&1',
            $envPrefix,
            escapeshellarg($scriptPath),
            escapeshellarg($htmlPath),
            escapeshellarg($pdfPath),
            escapeshellarg($tempDir . "/fica_pdf_gen_{$submission->id}.log")
        );

        Log::info('FicaCompletionReportService: generating PDF', ['submission_id' => $submission->id, 'command' => $command]);
        shell_exec($command);
        @unlink($htmlPath);

        if (! file_exists($pdfPath) || filesize($pdfPath) === 0) {
            Log::error('FicaCompletionReportService: PDF generation failed', ['submission_id' => $submission->id]);
            @unlink($pdfPath);
            return null;
        }

        $storedPath = "compliance/fica/{$submission->id}/completion-report-{$stamp}.pdf";
        Storage::disk('local')->put($storedPath, file_get_contents($pdfPath));
        @unlink($pdfPath);

        // Remove the previous snapshot, if any — one current file per submission,
        // never an accumulating pile from repeated corrections/re-approval cycles.
        if ($submission->pdf_path && $submission->pdf_path !== $storedPath && Storage::disk('local')->exists($submission->pdf_path)) {
            Storage::disk('local')->delete($submission->pdf_path);
        }

        return $storedPath;
    }

    /**
     * Resolve the agent who verified this submission, bypassing the
     * agency-tenancy scope for this display purpose only. 2026-08-25,
     * Johan: "the FICA report ready for all FICA, with agents" — the
     * agent must SHOW, never a silent dash and never gracefully omitted.
     *
     * Why this is needed: FicaSubmission::agentVerifiedBy() is a plain
     * belongsTo(User::class), which goes through AgencyScope like any
     * other User query. AgencyScope treats a NULL-agency user as an
     * orphan and hides it — correct for its actual purpose (stopping
     * cross-agency data leaks) but wrong here: a super-admin platform
     * account (agency_id NULL BY DESIGN — e.g. Johan's own
     * johan@corexos.co.za login, id 46) can legitimately be the agent who
     * processed a real FICA, and the scope then hides that agent from
     * their OWN compliance document. This is not a cross-agency listing —
     * it's rendering one specific, already-known user id (the FK is
     * valid and untouched) onto the one document about their own action.
     * withTrashed() too: an agent who has since left the agency is a
     * real agent, not "no agent recorded".
     *
     * Deliberately narrow: this bypasses the scope for exactly one
     * lookup, by exact id, for display only. It does not touch the User
     * model, does not weaken AgencyScope, and every other consumer of
     * FicaSubmission::agentVerifiedBy() (or of AgencyScope generally)
     * is completely unaffected.
     */
    private function resolveVerifyingUser(?int $userId): ?\App\Models\User
    {
        if ($userId === null) {
            return null;
        }

        return \App\Models\User::withoutGlobalScopes()->withTrashed()->find($userId);
    }

    /**
     * The wet-ink signature/answers gap, made honest rather than silently
     * blank: the recipient's PEP/service answers and their signature were
     * never digitised for a wet-ink intake — only the scanned paper form
     * exists (App\Models\FicaDocument, document_type='fica_form'). Embed it
     * as a base64 image when it's an image; for a PDF scan, reference it by
     * name rather than attempting to merge two PDFs — that's a real,
     * separate piece of work this report doesn't take on.
     *
     * @return array{type:string, data:?string, name:?string}|null
     */
    private function wetInkFormEmbed(FicaSubmission $submission): ?array
    {
        if (! $submission->isWetInk()) {
            return null;
        }

        $doc = $submission->documents->firstWhere('document_type', 'fica_form');
        if (! $doc) {
            return null;
        }

        // Only decrypt+read the file when we can actually use the bytes (an
        // image to embed). The far more common case on real data is a PDF
        // scan, where all the report needs is the filename — reading and
        // decrypting the whole file just to discard it was both wasteful
        // and fragile: a storage/decryption hiccup on the unused bytes was
        // silently deleting the reference note too (found on real submission
        // #9781 during verification — bytes() returned null for reasons
        // unrelated to whether the filename itself is known).
        if (! str_starts_with((string) $doc->mime_type, 'image/')) {
            return ['type' => 'reference', 'data' => null, 'name' => $doc->file_name];
        }

        $storage = app(FicaDocumentStorage::class);
        $bytes = $storage->bytes($doc);
        if ($bytes === null) {
            // Still surface that a form is on file, even though we can't
            // embed it — never silently drop the section entirely.
            return ['type' => 'reference', 'data' => null, 'name' => $doc->file_name];
        }

        return [
            'type' => 'image',
            'data' => 'data:' . $doc->mime_type . ';base64,' . base64_encode($bytes),
            'name' => $doc->file_name,
        ];
    }
}
