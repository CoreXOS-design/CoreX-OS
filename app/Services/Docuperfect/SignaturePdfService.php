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
     * Generate both signed PDF versions:
     *   - 'internal': document pages + signatures + audit certificate (for agent)
     *   - 'client': document pages + signatures only (for external signers)
     *
     * Returns ['internal' => storagePath, 'client' => storagePath] or null on failure.
     */
    public function generate(SignatureTemplate $template): ?array
    {
        try {
            $template->loadMissing(['document.template', 'markers.signatures', 'requests', 'signatures', 'auditLogs']);
            $document = $template->document;
            $docTemplate = $document->template;

            $webTemplateData = $document->web_template_data ?? [];
            // §19 Option 2 — generate the PDF from the EXACT signed-and-
            // paginated DOM the signer saw (per-document .corex-a4-page +
            // per-page initials). Fall back to canonical merged_html for
            // legacy / never-web-signed documents. No server re-pagination.
            // ESIGN-WETINK — read precedence for the final signed render:
            //   1. canonical_html WHEN it carries baked ink (canonical_version
            //      >= 1) — this is vN, the ONE artifact bearing every party's
            //      identity-scoped ink (doctrine: "the signed PDF is generated
            //      from vN"). Un-paginated; Chromium paginates via CSS.
            //   2. signed_paginated_html — the exact paginated DOM the last
            //      signer saw (pre-1c documents that never baked into canonical).
            //   3. canonical_html at v0 (structure only) if that is all we have.
            //   4. merged_html — legacy / pre-canonical documents only.
            $canonicalHtml    = (string) ($webTemplateData['canonical_html'] ?? '');
            $canonicalVersion = (int) ($webTemplateData['canonical_version'] ?? 0);
            $signedPaginated  = $document->signed_paginated_html;
            if ($canonicalVersion >= 1 && trim($canonicalHtml) !== '') {
                $renderHtml = $canonicalHtml;
            } elseif (is_string($signedPaginated) && trim($signedPaginated) !== '') {
                $renderHtml = $signedPaginated;
            } elseif (trim($canonicalHtml) !== '') {
                $renderHtml = $canonicalHtml;
            } else {
                $renderHtml = $webTemplateData['merged_html'] ?? '';
            }
            $hasMergedHtml = trim((string) $renderHtml) !== '';
            $hasDocPages = !empty($webTemplateData['flattened_page_count']);
            $isWebTemplate = $docTemplate && ($docTemplate->render_type ?? 'pdf') === 'web';

            // Web templates with merged_html: use Puppeteer (Chromium) for pixel-perfect rendering
            if ($isWebTemplate && $hasMergedHtml) {
                return $this->generateFromHtml($template, $document, $renderHtml);
            }

            // Page-image templates: use DomPDF with overlay rendering
            if ((!$docTemplate || $docTemplate->page_count < 1) && !$hasDocPages) {
                Log::error('SignaturePdfService: No pages and no merged_html — cannot generate PDF', [
                    'template_id' => $template->id,
                    'document_id' => $document->id,
                    'render_type' => $docTemplate->render_type ?? 'unknown',
                    'page_count' => $docTemplate->page_count ?? 0,
                    'has_merged_html' => $hasMergedHtml,
                    'has_flattened_pages' => $hasDocPages,
                ]);
                return null;
            }

            // Build page data once (pages + markers + fields)
            $pageData = $this->buildPageData($template, $document, $docTemplate);

            // 1. Client copy — no audit certificate
            $clientTempPath = $this->renderPdf($pageData, $document->name, false);

            // 2. Internal copy — with audit certificate
            $auditData = $this->buildAuditData($template, $document);
            $internalTempPath = $this->renderPdf($pageData, $document->name, true, $auditData);

            // Store in final locations
            $baseDir = "docuperfect/signed-documents/{$template->id}";
            $clientStoragePath = "{$baseDir}/client_signed.pdf";
            $internalStoragePath = "{$baseDir}/final_signed.pdf";

            // Write to the 'local' disk ROOT (Laravel 11: storage/app/private)
            // so Storage::disk('local') — used by Document::downloadResponse()
            // and the completion-email / signing-download readers — resolves
            // the EXACT file. Raw storage_path('app/..') put PDFs one dir
            // OUTSIDE the disk, causing Flysystem 500s on download.
            $disk = \Illuminate\Support\Facades\Storage::disk('local');
            $disk->makeDirectory($baseDir);

            if (file_exists($clientTempPath)) {
                rename($clientTempPath, $disk->path($clientStoragePath));
            }
            if (file_exists($internalTempPath)) {
                rename($internalTempPath, $disk->path($internalStoragePath));
            }

            return [
                'internal' => $internalStoragePath,
                'client' => $clientStoragePath,
            ];
        } catch (\Throwable $e) {
            Log::error('SignaturePdfService: Failed to generate signed PDFs', [
                'template_id' => $template->id,
                'document_id' => $template->document_id,
                'render_type' => $template->document?->template?->render_type ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Generate signed PDFs for web templates using Puppeteer (Chromium).
     * Produces identical rendering to the browser — no raster/overlay approach needed.
     *
     * @return array{internal: string, client: string}|null
     */
    private function generateFromHtml(SignatureTemplate $template, $document, string $mergedHtml): ?array
    {
        $signingController = app(\App\Http\Controllers\Docuperfect\SigningController::class);

        // Per-step timing (the ~83s gap between copy 1 finishing and copy 2
        // starting was unexplained — measure each step, do not assume).
        $step = function (string $label, callable $fn) use ($template, $document) {
            $t0 = microtime(true);
            $result = $fn();
            Log::info('SignaturePdfService timing', [
                'step'        => $label,
                'elapsed_ms'  => (int) round((microtime(true) - $t0) * 1000),
                'template_id' => $template->id,
                'document_id' => $document->id,
            ]);
            return $result;
        };

        // 1. Client copy — document with signatures (no audit certificate)
        $clientTempPath = $step('copy1_generatePdfFromHtml', fn () => $signingController->generatePdfFromHtml($mergedHtml, $document->id));
        if (!$clientTempPath || !file_exists($clientTempPath)) {
            Log::error('SignaturePdfService: Puppeteer client PDF generation failed', [
                'template_id' => $template->id,
                'document_id' => $document->id,
            ]);
            return null;
        }

        // 2. Internal copy — document + audit certificate appended
        $auditData = $step('buildAuditData', fn () => $this->buildAuditData($template, $document));
        $auditHtml = $step('audit_certificate_view_render', fn () => view('docuperfect.signatures.pdf.audit-certificate', $auditData)->render());
        $htmlWithAudit = $step('htmlWithAudit_concat', fn () => $mergedHtml
            . '<div style="page-break-before:always;"></div>'
            . $auditHtml);
        $internalTempPath = $step('copy2_generatePdfFromHtml', fn () => $signingController->generatePdfFromHtml($htmlWithAudit, $document->id));
        if (!$internalTempPath || !file_exists($internalTempPath)) {
            Log::warning('SignaturePdfService: Puppeteer internal PDF failed, using client copy as fallback', [
                'template_id' => $template->id,
            ]);
            $internalTempPath = $clientTempPath;
        }

        // Store in final locations
        $baseDir = "docuperfect/signed-documents/{$template->id}";
        $clientStoragePath = "{$baseDir}/client_signed.pdf";
        $internalStoragePath = "{$baseDir}/final_signed.pdf";

        // Write to the 'local' disk ROOT (see generate()) so the filed
        // Document and every reader resolve the same physical file.
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $disk->makeDirectory($baseDir);

        if ($clientTempPath !== $internalTempPath) {
            rename($clientTempPath, $disk->path($clientStoragePath));
            rename($internalTempPath, $disk->path($internalStoragePath));
        } else {
            // Same file — copy for client, move for internal
            copy($clientTempPath, $disk->path($clientStoragePath));
            rename($clientTempPath, $disk->path($internalStoragePath));
        }

        Log::info('SignaturePdfService: Web template PDFs generated via Puppeteer', [
            'template_id' => $template->id,
            'document_id' => $document->id,
            'client_path' => $clientStoragePath,
            'internal_path' => $internalStoragePath,
        ]);

        return [
            'internal' => $internalStoragePath,
            'client' => $clientStoragePath,
        ];
    }

    /**
     * Build page data array: page images + signature markers + field overlays.
     *
     * When flattened page images are available, uses those instead of originals
     * and skips field/signature overlays since they are already baked into the images.
     */
    private function buildPageData(SignatureTemplate $template, $document, $docTemplate): array
    {
        $flattenedPages = $template->flattened_pages_json ?? [];
        $hasFlattened = !empty($flattenedPages);

        $documentFields = $document->fields_json ?? [];
        $fieldsByPage = $hasFlattened ? [] : $this->groupFieldsByPage($documentFields);

        // Resolve page count — check document-level pages for flattened web templates
        $webTemplateData = $document->web_template_data ?? [];
        $hasDocPages = !empty($webTemplateData['flattened_page_count']);
        $pageCount = ($docTemplate && $docTemplate->page_count > 0)
            ? $docTemplate->page_count
            : ($hasDocPages ? (int) $webTemplateData['flattened_page_count'] : 0);

        $pages = [];

        for ($pageNum = 0; $pageNum < $pageCount; $pageNum++) {
            // Use flattened page image if available, otherwise original
            if ($hasFlattened && isset($flattenedPages[$pageNum])) {
                $pageImageBase64 = $this->getStorageImageBase64($flattenedPages[$pageNum]);
            } elseif ($hasDocPages) {
                // Document-level page images (flattened web templates)
                $docPagePath = "docuperfect/documents/{$document->id}/page-{$pageNum}.png";
                $pageImageBase64 = $this->getStorageImageBase64($docPagePath);
            } else {
                $pageImageBase64 = $this->getPageImageBase64($docTemplate->id, $pageNum);
            }

            // When flattened, skip overlays — everything is baked into the image
            if ($hasFlattened) {
                $pages[] = [
                    'image_base64' => $pageImageBase64,
                    'markers' => [],
                    'fields' => [],
                ];
                continue;
            }

            // Original overlay-based rendering (fallback)
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
                'fields' => $fieldsByPage[$pageNum] ?? [],
            ];
        }

        return $pages;
    }

    /**
     * Build audit certificate data.
     */
    private function buildAuditData(SignatureTemplate $template, $document): array
    {
        return [
            'template' => $template,
            'document' => $document,
            'parties' => $template->parties_json ?? [],
            'progress' => $template->partyProgress(),
            'auditLogs' => $template->auditLogs()->orderBy('created_at')->get(),
            'documentHash' => $template->document_hash,
        ];
    }

    /**
     * Render the signed document as a PDF file.
     * Returns path to the temporary PDF file.
     */
    private function renderPdf(array $pages, string $documentName, bool $includeAuditCert, array $auditData = []): string
    {
        $viewData = [
            'pages' => $pages,
            'documentName' => $documentName,
            'includeAuditCert' => $includeAuditCert,
        ];

        if ($includeAuditCert && !empty($auditData)) {
            $viewData = array_merge($viewData, $auditData);
        }

        $html = view('docuperfect.signatures.pdf.signed-document', $viewData)->render();

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isHtml5ParserEnabled', true);

        $tempPath = tempnam(sys_get_temp_dir(), 'signed_pdf_') . '.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Group document field values by page index for PDF overlay.
     * Returns array keyed by 0-indexed page number.
     */
    private function groupFieldsByPage(array $fields): array
    {
        $grouped = [];

        foreach ($fields as $field) {
            $pageIndex = $field['pageIndex'] ?? 0;
            $type = $field['type'] ?? 'placeholder';

            // Skip signature/initial fields — those are handled by signature markers
            if (in_array($type, ['signature', 'initial'])) {
                continue;
            }

            // Get the display value
            $displayValue = $this->getFieldDisplayValue($field);
            if ($displayValue === null || $displayValue === '') {
                continue;
            }

            $position = $field['position'] ?? [];
            $size = $field['size'] ?? [];
            $style = $field['style'] ?? [];

            $grouped[$pageIndex][] = [
                'x' => $position['x'] ?? 0,
                'y' => $position['y'] ?? 0,
                'w' => $size['width'] ?? 10,
                'h' => $size['height'] ?? 3,
                'type' => $type,
                'value' => $displayValue,
                'fontSize' => $style['fontSize'] ?? 10,
                'bold' => $style['bold'] ?? false,
                'underline' => $style['underline'] ?? false,
                'solidBackground' => $style['solidBackground'] ?? false,
            ];
        }

        return $grouped;
    }

    /**
     * Extract the display value from a field based on its type.
     */
    private function getFieldDisplayValue(array $field): ?string
    {
        $type = $field['type'] ?? 'placeholder';

        return match ($type) {
            'placeholder' => trim((string) ($field['value'] ?? '')),
            'date' => trim((string) ($field['value'] ?? '')),
            'selection' => trim((string) ($field['selectedValue'] ?? '')),
            'condition' => trim((string) ($field['text'] ?? '')),
            'strikethrough' => null, // handled visually
            default => trim((string) ($field['value'] ?? '')),
        };
    }

    /**
     * Get a storage-path image as a base64 data URI.
     */
    private function getStorageImageBase64(string $storagePath): ?string
    {
        if (!Storage::disk('local')->exists($storagePath)) {
            return null;
        }

        $content = Storage::disk('local')->get($storagePath);
        $ext = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return "data:{$mime};base64," . base64_encode($content);
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
