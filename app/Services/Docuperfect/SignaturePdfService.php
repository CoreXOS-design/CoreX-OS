<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SignaturePdfService
{
    /**
     * Generate the final signed PDF with embedded signatures and audit certificate.
     * Returns the storage path to the final PDF, or null on failure.
     */
    public function generate(SignatureTemplate $template): ?string
    {
        try {
            $template->loadMissing(['document.template', 'markers.signatures', 'requests', 'signatures', 'auditLogs']);
            $document = $template->document;
            $docTemplate = $document->template;

            if (!$docTemplate || $docTemplate->page_count < 1) {
                Log::error('SignaturePdfService: No document template or zero pages', ['template_id' => $template->id]);
                return null;
            }

            // 1. Generate signed document PDF (pages with overlaid signatures)
            $signedPdfPath = $this->generateSignedPdf($template, $document, $docTemplate);

            // 2. Generate audit certificate PDF
            $auditCertPath = $this->generateAuditCertificate($template, $document);

            // 3. Combine both into final PDF
            $finalPath = $this->combinePdfs($template, $signedPdfPath, $auditCertPath);

            // Clean up intermediate files
            if (file_exists($signedPdfPath)) {
                unlink($signedPdfPath);
            }
            if (file_exists($auditCertPath)) {
                unlink($auditCertPath);
            }

            // Store relative path in Storage
            $storagePath = "docuperfect/signed-documents/{$template->id}/final_signed.pdf";
            $storageFullPath = storage_path("app/{$storagePath}");

            // Move final PDF to storage location
            $targetDir = dirname($storageFullPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (file_exists($finalPath)) {
                rename($finalPath, $storageFullPath);
            }

            return $storagePath;
        } catch (\Throwable $e) {
            Log::error('SignaturePdfService: Failed to generate signed PDF', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Generate the signed document PDF — each page as image with signatures overlaid.
     */
    private function generateSignedPdf(SignatureTemplate $template, $document, $docTemplate): string
    {
        $pages = [];

        for ($pageNum = 0; $pageNum < $docTemplate->page_count; $pageNum++) {
            // Get page image as base64
            $pageImageBase64 = $this->getPageImageBase64($docTemplate->id, $pageNum);

            // Get markers for this page (pages in markers are 1-indexed, images are 0-indexed)
            $pageMarkers = $template->markers
                ->where('page_number', $pageNum + 1)
                ->sortBy('sort_order');

            $markerData = [];
            foreach ($pageMarkers as $marker) {
                $signature = $marker->signatures->first();
                $isWetInk = false;
                $wetInkApproved = false;

                // Check if this party used wet ink
                $request = $template->requests->firstWhere('party_role', $marker->assigned_party);
                if ($request && $request->signing_method === 'wet_ink' && $request->wet_ink_status === 'approved') {
                    $isWetInk = true;
                    $wetInkApproved = true;
                }

                $markerData[] = [
                    'x' => $marker->x_position,
                    'y' => $marker->y_position,
                    'w' => $marker->width,
                    'h' => $marker->height,
                    'type' => $marker->type,
                    'assigned_party' => $marker->assigned_party,
                    'has_signature' => $signature !== null,
                    'signature_data' => $signature?->signature_data,
                    'signature_type' => $signature?->signature_type,
                    'signer_name' => $signature?->signer_name,
                    'signed_at' => $signature?->signed_at,
                    'is_wet_ink' => $isWetInk,
                    'wet_ink_approved' => $wetInkApproved,
                ];
            }

            $pages[] = [
                'image_base64' => $pageImageBase64,
                'markers' => $markerData,
            ];
        }

        $html = view('docuperfect.signatures.pdf.signed-document', [
            'pages' => $pages,
            'documentName' => $document->name,
        ])->render();

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isHtml5ParserEnabled', true);

        $tempPath = tempnam(sys_get_temp_dir(), 'signed_doc_') . '.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Generate the audit certificate PDF.
     */
    private function generateAuditCertificate(SignatureTemplate $template, $document): string
    {
        $parties = $template->parties_json ?? [];
        $progress = $template->partyProgress();
        $auditLogs = $template->auditLogs()->orderBy('created_at')->get();

        $html = view('docuperfect.signatures.pdf.audit-certificate', [
            'template' => $template,
            'document' => $document,
            'parties' => $parties,
            'progress' => $progress,
            'auditLogs' => $auditLogs,
            'documentHash' => $template->document_hash,
        ])->render();

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isHtml5ParserEnabled', true);

        $tempPath = tempnam(sys_get_temp_dir(), 'audit_cert_') . '.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Combine signed document PDF and audit certificate into one PDF.
     * Uses DOMPDF to render a wrapper that includes both.
     *
     * Note: DOMPDF can't merge PDFs natively, so we use a simple approach:
     * generate both as separate files, then use a basic PHP PDF merger.
     * If that fails, we just return the signed document with the audit cert
     * regenerated as the final pages.
     */
    private function combinePdfs(SignatureTemplate $template, string $signedPdfPath, string $auditCertPath): string
    {
        // Since we don't have a PDF merger library (like FPDI), we'll generate
        // a single PDF that includes both the signed document pages and the
        // audit certificate in one render pass.
        $document = $template->document;
        $docTemplate = $document->template;

        $pages = [];
        for ($pageNum = 0; $pageNum < $docTemplate->page_count; $pageNum++) {
            $pageImageBase64 = $this->getPageImageBase64($docTemplate->id, $pageNum);
            $pageMarkers = $template->markers
                ->where('page_number', $pageNum + 1)
                ->sortBy('sort_order');

            $markerData = [];
            foreach ($pageMarkers as $marker) {
                $signature = $marker->signatures->first();
                $request = $template->requests->firstWhere('party_role', $marker->assigned_party);
                $isWetInk = $request && $request->signing_method === 'wet_ink' && $request->wet_ink_status === 'approved';

                $markerData[] = [
                    'x' => $marker->x_position,
                    'y' => $marker->y_position,
                    'w' => $marker->width,
                    'h' => $marker->height,
                    'type' => $marker->type,
                    'assigned_party' => $marker->assigned_party,
                    'has_signature' => $signature !== null,
                    'signature_data' => $signature?->signature_data,
                    'signature_type' => $signature?->signature_type,
                    'signer_name' => $signature?->signer_name,
                    'signed_at' => $signature?->signed_at,
                    'is_wet_ink' => $isWetInk,
                    'wet_ink_approved' => $isWetInk,
                ];
            }

            $pages[] = [
                'image_base64' => $pageImageBase64,
                'markers' => $markerData,
            ];
        }

        $parties = $template->parties_json ?? [];
        $progress = $template->partyProgress();
        $auditLogs = $template->auditLogs()->orderBy('created_at')->get();

        // Render combined HTML with document pages + audit certificate
        $html = view('docuperfect.signatures.pdf.signed-document', [
            'pages' => $pages,
            'documentName' => $document->name,
            'includeAuditCert' => true,
            'template' => $template,
            'document' => $document,
            'parties' => $parties,
            'progress' => $progress,
            'auditLogs' => $auditLogs,
            'documentHash' => $template->document_hash,
        ])->render();

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isHtml5ParserEnabled', true);

        $tempPath = tempnam(sys_get_temp_dir(), 'final_signed_') . '.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Get a page image as a base64 data URI.
     */
    private function getPageImageBase64(int $templateId, int $pageNum): ?string
    {
        $pngPath = "docuperfect/templates/{$templateId}/page-{$pageNum}.png";
        $jpgPath = "docuperfect/templates/{$templateId}/page-{$pageNum}.jpg";

        if (Storage::exists($pngPath)) {
            $content = Storage::get($pngPath);
            return 'data:image/png;base64,' . base64_encode($content);
        }

        if (Storage::exists($jpgPath)) {
            $content = Storage::get($jpgPath);
            return 'data:image/jpeg;base64,' . base64_encode($content);
        }

        Log::warning('SignaturePdfService: Page image not found', [
            'template_id' => $templateId,
            'page' => $pageNum,
        ]);

        return null;
    }

    /**
     * Get the human-readable description for an audit log action.
     */
    public static function auditActionDescription(SignatureAuditLog $log): string
    {
        $meta = $log->metadata_json ?? [];
        $name = $log->actor_name;
        $signerEmail = $meta['signer_email'] ?? $name;
        $recipientName = $meta['recipient_name'] ?? 'party';
        $reminderNum = $meta['reminder_number'] ?? '?';

        return match ($log->action) {
            'created' => isset($meta['party_role'])
                ? "Signing request created for {$meta['party_role']}"
                : "Signature template created by {$name}",
            'sent' => "Signing link sent to {$signerEmail}",
            'viewed' => "{$name} viewed the signing link",
            'signed' => isset($meta['marker_type'])
                ? "{$name} signed " . ($meta['marker_type'] === 'initial' ? 'initial' : 'signature') . " on page " . ($meta['page'] ?? '?')
                : "{$name} signed a marker",
            'completed' => isset($meta['phase'])
                ? ucfirst(str_replace('_', ' ', $meta['phase'])) . " completed by {$name}"
                : "Document completed — all parties signed",
            'declined' => "{$name} declined to sign" . (isset($meta['reason']) ? ": {$meta['reason']}" : ''),
            'expired' => "Signing request expired",
            'cancelled' => "Signing cancelled",
            'reminder_sent' => "Reminder #{$reminderNum} sent",
            'manual_reminder_sent' => "Manual reminder sent by {$name}",
            'wet_ink_uploaded' => "{$name} uploaded wet ink document",
            'wet_ink_approved' => "Wet ink document approved by {$name}",
            'wet_ink_rejected' => "Wet ink document rejected by {$name}",
            'team_alert_sent' => "Team alert sent",
            'document_completed' => "Document finalised — signed PDF generated",
            'signed_pdf_emailed' => "Signed PDF emailed to {$recipientName}",
            default => ucfirst(str_replace('_', ' ', $log->action)),
        };
    }
}
