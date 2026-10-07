<?php

namespace App\Services\Compliance;

use App\Models\FicaSubmission;
use App\Support\Compliance\FicaQuestionnaire;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The FICA "Questions & answers" form a client's online submission produces — questions WITH the client's answers
 * AND the client's signature — as a PDF (download) or a print page, for the agent to keep on file
 * (.ai/specs/compliance.md "Questions & answers — Download PDF and Print").
 *
 * ONE SOURCE OF TRUTH. The questions, their order, grouping, follow-up conditions and "not answered" all come from
 * FicaQuestionnaire::forSubmission() — the very call the on-screen partial uses. This class and its view hold no
 * copy of any wording (the one fixed piece of text, the declaration, is FicaQuestionnaire::DECLARATION_TEXT).
 *
 * Deliberately separate from FicaCompletionReportService (the approval certificate, frozen at approval): this is a
 * live rendering of what the client was asked and answered, available as soon as the client has submitted, and it
 * never touches the certificate or its stored file. Same PDF engine though (scripts/html-to-pdf.mjs, Puppeteer).
 *
 * A paper (wet-ink) intake has no online questions and answers — nothing is fabricated: available() is false.
 */
class FicaQuestionsAnswersDocument
{
    /** A paper intake has no online Q&A; a form the client has not completed has nothing to print. */
    public function available(FicaSubmission $submission): bool
    {
        return ! $submission->isWetInk()
            && is_array($submission->form_data)
            && $submission->form_data !== []
            && $submission->signed_at !== null;
    }

    /**
     * Only an inline PNG/JPEG/WebP data URI is ever placed in an <img>. The public form posts the signature as a
     * free string ('required|string'), and this page is rendered by a server-side browser, so any other value
     * (a URL, an SVG) is refused rather than fetched — the signature then prints as "not captured".
     */
    public function safeSignature(?string $signatureData): ?string
    {
        $signatureData = trim((string) $signatureData);

        return preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=\s]+$#', $signatureData) === 1 ? $signatureData : null;
    }

    /** The client or entity name as it should head the document. */
    public function clientName(FicaSubmission $submission): string
    {
        $data = is_array($submission->form_data) ? $submission->form_data : [];
        $entityName = match ($data['entity_type'] ?? $submission->entity_type) {
            'company' => data_get($data, 'entity.company_name'),
            'trust' => data_get($data, 'entity.trust_name'),
            'partnership' => data_get($data, 'entity.partnership_name'),
            default => null,
        };

        foreach ([$entityName, $submission->contact?->full_name, data_get($data, 'personal.full_name')] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return 'Client';
    }

    /** FICA-questions-answers-<client>-<date>.pdf — the date is the day the client submitted. */
    public function fileName(FicaSubmission $submission): string
    {
        $date = ($submission->signed_at ?? now())->format('Y-m-d');

        return 'FICA-questions-answers-' . (Str::slug($this->clientName($submission)) ?: 'client') . '-' . $date . '.pdf';
    }

    /** The agency logo as an inline data URI (the letterhead the other FICA documents carry), or null. */
    private function logoDataUri(FicaSubmission $submission): ?string
    {
        $path = $submission->agency?->logo_path;
        if (! $path) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                return null;
            }

            return 'data:' . ($disk->mimeType($path) ?: 'image/png') . ';base64,' . base64_encode($disk->get($path));
        } catch (\Throwable $e) {
            Log::warning('FicaQuestionsAnswersDocument: agency logo unreadable', ['submission_id' => $submission->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The document as HTML — the PDF is rendered from this and the print page IS this. */
    public function html(FicaSubmission $submission, bool $forPrint = false): string
    {
        $submission->loadMissing(['contact', 'agency', 'requestedBy', 'documents']);

        $agency = $submission->agency;

        return view('compliance.fica.questions-answers-document', [
            'submission' => $submission,
            'qa' => FicaQuestionnaire::forSubmission($submission),
            'agency' => $agency,
            // Multi-agency: the agency's own theme colour, with the same platform default the completion report falls back to.
            'brandColor' => ($agency && $agency->default_color) ? $agency->default_color : '#0b2a4a',
            'logo' => $this->logoDataUri($submission),
            'clientName' => $this->clientName($submission),
            'signature' => $this->safeSignature($submission->signature_data),
            'declarationText' => FicaQuestionnaire::DECLARATION_TEXT,
            'forPrint' => $forPrint,
        ])->render();
    }

    /**
     * Render the PDF through the same Puppeteer script the completion report uses, with page numbers on.
     * Returns the PDF bytes, or null on failure (logged).
     */
    public function pdf(FicaSubmission $submission): ?string
    {
        $html = $this->html($submission);

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $stamp = time() . '_' . Str::random(6);
        $htmlPath = $tempDir . "/fica_qa_{$submission->id}_{$stamp}.html";
        $pdfPath = $tempDir . "/fica_qa_{$submission->id}_{$stamp}.pdf";
        $logPath = $tempDir . "/fica_qa_pdf_gen_{$submission->id}_{$stamp}.log";
        file_put_contents($htmlPath, $html);

        $browserPath = config('services.pdf.puppeteer_browser_path', '');
        $env = 'HOME=/tmp PDF_PAGE_NUMBERS=1 PDF_FOOTER_LABEL=' . escapeshellarg('FICA questions & answers — ' . $this->clientName($submission) . ' — FICA #' . $submission->id);
        if ($browserPath) {
            $env .= ' PUPPETEER_BROWSER_PATH=' . escapeshellarg($browserPath);
        }

        $command = sprintf(
            '%s node %s %s %s > %s 2>&1',
            $env,
            escapeshellarg(base_path('scripts/html-to-pdf.mjs')),
            escapeshellarg($htmlPath),
            escapeshellarg($pdfPath),
            escapeshellarg($logPath)
        );

        Log::info('FicaQuestionsAnswersDocument: generating PDF', ['submission_id' => $submission->id]);
        shell_exec($command);
        @unlink($htmlPath);

        $bytes = (file_exists($pdfPath) && filesize($pdfPath) > 0) ? file_get_contents($pdfPath) : null;
        @unlink($pdfPath);
        if ($bytes === null) {
            Log::error('FicaQuestionsAnswersDocument: PDF generation failed', ['submission_id' => $submission->id, 'log' => is_file($logPath) ? substr((string) file_get_contents($logPath), 0, 500) : null]);
        }
        @unlink($logPath);

        return $bytes;
    }
}
