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
            $renderHtml = $this->resolveRenderHtml($document);
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

        // AT-324/AT-325 — the PDF renders the UN-paginated canonical, which has no
        // page-break initial slots (those are created only by the client
        // paginateDocument() during signing). Run the SAME shared pagination +
        // signed_initials restore inside Puppeteer so every captured initial
        // renders IN its real page-break slot on the PDF — exactly as the review
        // and the ceremony show it. Fail-open: on any error the PDF still renders
        // the canonical unchanged.
        $mergedHtml = $this->injectInitialsPagination($template, $document, $mergedHtml);

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
     * AT-324/AT-325 — wrap the canonical body and append the SHARED pagination JS
     * (paginateDocument + restoreStoredInitials, lifted verbatim from the
     * a4-page-styles partial the ceremony/review use) plus a bootstrap that runs
     * them against signed_initials. Puppeteer executes this before print, so the
     * PDF gets the same paginated document with every recipient's initials in their
     * own page-break box. No-op when there are no captured initials; fail-open in
     * the browser (try/catch) so the PDF always at least renders the canonical.
     */
    /**
     * Resolve the faithful render source for a document, in read precedence:
     *   1. signed_paginated_html — the exact per-document .corex-a4-page DOM the
     *      last signer submitted, with every party's ink AND the page-break
     *      initials already IN POSITION (what the on-screen signed document shows).
     *   2. canonical_html WHEN it carries baked ink (canonical_version >= 1) — the
     *      un-paginated vN artifact; Chromium paginates it via injectInitialsPagination.
     *   3. canonical_html at v0 (structure only) if that is all we have.
     *   4. merged_html — legacy / pre-canonical documents only.
     *
     * signed_paginated_html is FIRST because the ink baker stamps a "Signed by {name}"
     * caption into every role-block signature cell of the canonical (including inline
     * clause cells the signer's paginated document collapsed away), so a canonical
     * render shows extra mid-document signature rows. The signer's own DOM is faithful.
     * Its page BREAKS, however, are re-flowed at render time (injectInitialsPagination)
     * so the emailed/downloaded PDF fits one physical A4 sheet per logical page.
     */
    public function resolveRenderHtml($document): string
    {
        $webTemplateData  = $document->web_template_data ?? [];
        $canonicalHtml    = (string) ($webTemplateData['canonical_html'] ?? '');
        $canonicalVersion = (int) ($webTemplateData['canonical_version'] ?? 0);
        $signedPaginated  = $document->signed_paginated_html;

        if (is_string($signedPaginated) && trim($signedPaginated) !== '') {
            return $this->withCeremonyValues($signedPaginated, $webTemplateData);
        }
        if (trim($canonicalHtml) !== '') {
            return $this->withCeremonyValues($canonicalHtml, $webTemplateData); // v>=1 (baked) or v0 (structure)
        }
        return $this->withCeremonyValues((string) ($webTemplateData['merged_html'] ?? ''), $webTemplateData);
    }

    /**
     * Re-apply EVERY party's captured ceremony values (place/date/time) onto their
     * own spans in the resolved render source. `ceremony_values` is the source of
     * truth for what each signer entered; a frozen signed_paginated_html or a
     * canonical baked by the old strict binding can show a party's spans blank.
     * Re-binding here — the ONE place both the filed/emailed PDF (generate) and
     * the live download (buildInjectedRenderHtml) read — makes the rendered PDF
     * faithful and repairs already-signed docs on a no-re-sign re-render. Party-
     * scoped + idempotent; fail-safe returns the HTML unchanged on any error.
     */
    private function withCeremonyValues(string $html, array $webTemplateData): string
    {
        $ceremonyValues = $webTemplateData['ceremony_values'] ?? [];
        if (! is_array($ceremonyValues) || $ceremonyValues === [] || trim($html) === '') {
            return $html;
        }

        return app(CanonicalInkComposer::class)->applyCeremonyValues($html, $ceremonyValues);
    }

    /**
     * The ONE render-source + measure-and-fit-pagination pipeline, shared by the
     * completion-email/filed PDF (generate) AND the live re-render+download route
     * (SigningController::downloadWebPdf). Returns HTML ready for
     * generatePdfFromHtml() — the signed content re-paginated through the corrected
     * engine WITHOUT re-signing (pagination is presentation; ink flows with clauses).
     */
    public function buildInjectedRenderHtml(SignatureTemplate $template): string
    {
        $template->loadMissing('document');
        $document = $template->document;
        if (!$document) {
            return '';
        }
        return $this->injectInitialsPagination($template, $document, $this->resolveRenderHtml($document));
    }

    private function injectInitialsPagination(SignatureTemplate $template, $document, string $html): string
    {
        $js = $this->esignPaginationJs();
        if (trim($js) === '') {
            return $html; // could not read the shared JS — do not risk the PDF
        }

        // AT-332 fix — ALWAYS re-paginate through the shared engine inside Chromium,
        // including an already-paginated signed_paginated_html. Pagination is
        // PRESENTATION, not signed content: the signed content is the clause text +
        // the ink placed against those clauses, and those flow with the content when
        // it re-paginates. Rendering the signer's frozen DOM VERBATIM was the bug —
        // its .corex-a4-page boxes were sized by the signing browser's fonts, then
        // the emailed PDF renders with SUBSTITUTE (taller) fonts, so each box
        // overflowed one physical A4 sheet and spilled its footer/initials onto a
        // near-blank next page (Premilla's doc: 4 logical pages → 6 physical). Re-
        // paginating in the SAME engine that prints the PDF makes measured height ==
        // rendered height, so every logical page fits exactly one physical sheet.
        //
        // Legacy docs are re-rendered without re-signing: a client cannot be asked to
        // re-sign because our renderer was broken (Johan's hard requirement).
        $alreadyPaginated = str_contains($html, 'corex-a4-page');

        $webData = $document->web_template_data ?? [];
        $signed = $webData['signed_initials'] ?? [];
        if (!is_array($signed)) {
            $signed = [];
        }

        // A plain, un-paginated canonical with no captured initials keeps its legacy
        // CSS-flow render (nothing to place, no page boxes to build). Anything already
        // paginated is always re-flowed to kill the physical spill.
        if (!$alreadyPaginated && $signed === []) {
            return $html;
        }

        // Same party set the review passes to paginateDocument().
        $parties = collect($template->parties_json ?? [])
            ->filter(fn ($p) => ($p['role'] ?? '') !== 'supervisor_final')
            ->map(fn ($p) => [
                'role'  => $p['role'] ?? '',
                'label' => ucfirst(str_replace('_', ' ', $p['role_label'] ?? $p['role'] ?? '')),
            ])
            ->unique('role')->values()->all();

        // Default json_encode escapes '/', so any "</script>" inside a data: URI is
        // emitted as "<\/script>" and cannot break out of the script context.
        $partiesJson = json_encode($parties);
        $storedJson  = json_encode($signed);

        // When the source is already paginated, mark the container so paginateDocument
        // takes its idempotent re-anchor path: it snapshots every applied initial /
        // signature by stable key, de-paginates the frozen .corex-a4-page boxes back to
        // flat content, re-paginates with the corrected engine, then re-applies the ink.
        // restoreStoredInitials then backfills any initials box by PARTY (robust to a
        // changed page count) from signed_initials. Ink stays attached to its clauses.
        // run() is idempotent (paginateDocument re-anchors on re-run). It is exposed
        // as window.__corexRepaginate so html-to-pdf.mjs can fire it AFTER fonts +
        // print media are applied (measure with the fonts the PDF actually prints).
        // A document.fonts.ready fallback covers any caller that does not use the hook.
        $boot = '<script>(function(){function run(){var c=document.getElementById("pdfDocContent")||document.body;'
            . 'try{if(c.querySelector(".corex-a4-page")){c.dataset.paginated="true";}'
            . 'paginateDocument(c,' . $partiesJson . ');restoreStoredInitials(c,' . $storedJson . ');}catch(e){}}'
            . 'window.__corexRepaginate=run;'
            . 'if(document.fonts&&document.fonts.ready){document.fonts.ready.then(run).catch(run);}'
            . 'else if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run);}else{run();}})();</script>';

        return '<div id="pdfDocContent">' . $html . '</div>'
            . '<script>' . $js . '</script>'
            . $boot;
    }

    /**
     * The shared page-break pagination + initial-restore JS, lifted verbatim from
     * the ONE partial the ceremony and agent-review already use
     * (resources/views/docuperfect/signatures/partials/a4-page-styles.blade.php).
     * The <script> body is pure JS (no Blade), so a straight read is safe and keeps
     * a single source of truth — the PDF cannot drift from what signers saw.
     */
    private function esignPaginationJs(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $path = resource_path('views/docuperfect/signatures/partials/a4-page-styles.blade.php');
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $cached = preg_match('/<script\b[^>]*>(.*)<\/script>/is', $content, $m) ? $m[1] : '';

        return $cached;
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
     * Render the electronic-signature CERTIFICATE as a STANDALONE PDF (audit pages only),
     * on request. This is deliberately SEPARATE from the clean signed document — the
     * certificate is never stapled onto the distributed/emailed/downloaded copy; it is
     * downloaded on demand (SignatureController::downloadCertificate) from the live audit
     * data, so it always reflects the current record. Returns a temp PDF path or null.
     */
    public function generateCertificatePdf(SignatureTemplate $template): ?string
    {
        try {
            $template->loadMissing(['document.template', 'markers.signatures', 'requests', 'signatures', 'auditLogs']);
            $document = $template->document;
            if (!$document) {
                return null;
            }
            $auditData = $this->buildAuditData($template, $document);
            $auditHtml = view('docuperfect.signatures.pdf.audit-certificate', $auditData)->render();

            return app(\App\Http\Controllers\Docuperfect\SigningController::class)
                ->generatePdfFromHtml($auditHtml, (int) $document->id);
        } catch (\Throwable $e) {
            Log::error('SignaturePdfService: certificate-only PDF generation failed', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
