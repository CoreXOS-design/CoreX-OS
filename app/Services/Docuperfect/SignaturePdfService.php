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
            $hasDocPages = !empty($webTemplateData['flattened_page_count']);
            if ((!$docTemplate || $docTemplate->page_count < 1) && !$hasDocPages) {
                Log::error('SignaturePdfService: No document template or zero pages', ['template_id' => $template->id]);
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

            $targetDir = storage_path("app/{$baseDir}");
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (file_exists($clientTempPath)) {
                rename($clientTempPath, storage_path("app/{$clientStoragePath}"));
            }
            if (file_exists($internalTempPath)) {
                rename($internalTempPath, storage_path("app/{$internalStoragePath}"));
            }

            return [
                'internal' => $internalStoragePath,
                'client' => $clientStoragePath,
            ];
        } catch (\Throwable $e) {
            Log::error('SignaturePdfService: Failed to generate signed PDFs', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
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
