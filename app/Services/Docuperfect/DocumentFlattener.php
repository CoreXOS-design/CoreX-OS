<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Signature;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class DocumentFlattener
{
    /**
     * Flatten all document field values onto the original page images.
     * Call this when agent completes field editing and proceeds to signatures.
     *
     * Reads fields from Document->fields_json, converts percentage positions to pixels,
     * and renders text/strikethroughs directly onto page image files using GD.
     *
     * @return array<int, string> Map of page number (0-indexed) => storage path
     */
    public function flattenFields(SignatureTemplate $template): array
    {
        $document = $template->document;
        $docTemplate = $document->template;
        $pageCount = $this->resolvePageCount($document, $docTemplate);

        if ($pageCount < 1) {
            Log::warning('DocumentFlattener::flattenFields — no template or zero pages', ['template_id' => $template->id]);
            return [];
        }

        $fields = $document->fields_json ?? [];
        $newPaths = [];

        for ($pageNum = 0; $pageNum < $pageCount; $pageNum++) {
            $imagePath = $this->findOriginalPageImage($docTemplate ? $docTemplate->id : 0, $pageNum, $document->id);
            if (!$imagePath) {
                Log::warning("DocumentFlattener: page image not found", ['docTemplate' => $docTemplate->id, 'page' => $pageNum]);
                continue;
            }

            $fullPath = Storage::disk('local')->path($imagePath);
            $image = $this->loadImage($fullPath);
            if (!$image) continue;

            $imgWidth = imagesx($image);
            $imgHeight = imagesy($image);

            // Filter fields for this page (pageIndex is 0-indexed)
            $pageFields = array_filter($fields, fn($f) => ($f['pageIndex'] ?? 0) == $pageNum);

            foreach ($pageFields as $field) {
                $type = $field['type'] ?? 'placeholder';

                // Backward compat: migrate old selection+renderMode:"tick" → type "tick"
                if ($type === 'selection' && !empty($field['renderMode']) && $field['renderMode'] === 'tick') {
                    $type = 'tick';
                }

                // Skip signature/initial fields — those are handled by signature markers
                if (in_array($type, ['signature', 'initial'])) {
                    continue;
                }

                // Skip fields assigned to signers — they must remain interactive for the signer
                $assignedTo = $field['assignedTo'] ?? 'creator';
                if ($assignedTo !== 'creator') {
                    continue;
                }

                $pos = $field['position'] ?? [];
                $size = $field['size'] ?? [];
                $style = $field['style'] ?? [];

                $x = (floatval($pos['x'] ?? 0) / 100) * $imgWidth;
                $y = (floatval($pos['y'] ?? 0) / 100) * $imgHeight;
                $w = (floatval($size['width'] ?? 10) / 100) * $imgWidth;
                $h = (floatval($size['height'] ?? 3) / 100) * $imgHeight;

                switch ($type) {
                    case 'placeholder':
                        $value = trim((string) ($field['value'] ?? ''));
                        if ($value !== '') {
                            $this->renderText($image, $value, $x, $y, $w, $h, $style);
                        }
                        break;

                    case 'date':
                        $value = trim((string) ($field['value'] ?? ''));
                        if ($value !== '') {
                            $this->renderText($image, $value, $x, $y, $w, $h, $style);
                        }
                        break;

                    case 'selection':
                        $this->renderSelectionField($image, $field, $x, $y, $w, $h, $style);
                        break;

                    case 'tick':
                        $this->renderTickField($image, $field, $x, $y, $w, $h);
                        break;

                    case 'condition':
                        $value = trim((string) ($field['text'] ?? ''));
                        if ($value !== '') {
                            // Conditions get a white background fill first
                            $white = imagecolorallocatealpha($image, 255, 255, 255, 30); // ~88% opaque
                            imagefilledrectangle($image, (int) $x, (int) $y, (int) ($x + $w), (int) ($y + $h), $white);
                            $this->renderText($image, $value, $x, $y, $w, $h, $style);
                        }
                        break;

                    case 'strikethrough':
                        if (!empty($field['active'])) {
                            $this->renderStrikethrough($image, $x, $y, $w, $h, $field);
                        }
                        break;
                }
            }

            // Save flattened page
            $newPath = $this->saveFlattenedPage($template->id, $pageNum, $image);
            $newPaths[$pageNum] = $newPath;

            imagedestroy($image);
        }

        // Store the flattened page paths on the template
        $template->update([
            'flattened_pages_json' => $newPaths,
        ]);

        return $newPaths;
    }

    /**
     * Flatten signer-completed fields onto the current flattened page images.
     * Call this after a signer completes their fields (before or during signing completion).
     *
     * Only flattens fields where assignedTo matches the given party role.
     *
     * @return array<int, string> Updated flattened page paths
     */
    public function flattenSignerFields(SignatureTemplate $template, string $partyRole): array
    {
        $document = $template->document;
        $docTemplate = $document->template;
        $pageCount = $this->resolvePageCount($document, $docTemplate);

        if ($pageCount < 1) {
            return $template->flattened_pages_json ?? [];
        }

        $fields = $document->fields_json ?? [];
        $currentPages = $template->flattened_pages_json ?? [];

        for ($pageNum = 0; $pageNum < $pageCount; $pageNum++) {
            // Use flattened page if available, otherwise original
            $imagePath = $currentPages[$pageNum] ?? $this->findOriginalPageImage($docTemplate ? $docTemplate->id : 0, $pageNum, $document->id);
            if (!$imagePath) continue;

            $fullPath = Storage::disk('local')->path($imagePath);
            $image = $this->loadImage($fullPath);
            if (!$image) continue;

            $imgWidth = imagesx($image);
            $imgHeight = imagesy($image);

            // Role aliases: assignedTo may use "lessor"/"lessee" while partyRole uses "landlord"/"tenant"
            $roleAliases = ['lessor' => 'landlord', 'lessee' => 'tenant'];
            $pageFields = array_filter($fields, function ($f) use ($pageNum, $partyRole, $roleAliases) {
                $assignedTo = $f['assignedTo'] ?? 'creator';
                $normalized = $roleAliases[$assignedTo] ?? $assignedTo;
                return ($f['pageIndex'] ?? 0) == $pageNum
                    && $normalized === $partyRole;
            });

            if (empty($pageFields)) {
                imagedestroy($image);
                continue;
            }

            foreach ($pageFields as $field) {
                $type = $field['type'] ?? 'placeholder';
                if ($type === 'selection' && !empty($field['renderMode']) && $field['renderMode'] === 'tick') {
                    $type = 'tick';
                }
                if (in_array($type, ['signature', 'initial'])) {
                    continue;
                }

                $pos = $field['position'] ?? [];
                $size = $field['size'] ?? [];
                $style = $field['style'] ?? [];

                $x = (floatval($pos['x'] ?? 0) / 100) * $imgWidth;
                $y = (floatval($pos['y'] ?? 0) / 100) * $imgHeight;
                $w = (floatval($size['width'] ?? 10) / 100) * $imgWidth;
                $h = (floatval($size['height'] ?? 3) / 100) * $imgHeight;

                switch ($type) {
                    case 'placeholder':
                    case 'date':
                        $value = trim((string) ($field['value'] ?? ''));
                        if ($value !== '') {
                            $this->renderText($image, $value, $x, $y, $w, $h, $style);
                        }
                        break;
                    case 'selection':
                        $this->renderSelectionField($image, $field, $x, $y, $w, $h, $style);
                        break;
                    case 'tick':
                        $this->renderTickField($image, $field, $x, $y, $w, $h);
                        break;
                    case 'condition':
                        $value = trim((string) ($field['text'] ?? ''));
                        if ($value !== '') {
                            $white = imagecolorallocatealpha($image, 255, 255, 255, 30);
                            imagefilledrectangle($image, (int) $x, (int) $y, (int) ($x + $w), (int) ($y + $h), $white);
                            $this->renderText($image, $value, $x, $y, $w, $h, $style);
                        }
                        break;
                    case 'strikethrough':
                        if (!empty($field['active'])) {
                            $this->renderStrikethrough($image, $x, $y, $w, $h, $field);
                        }
                        break;
                }
            }

            $newPath = $this->saveFlattenedPage($template->id, $pageNum, $image);
            $currentPages[$pageNum] = $newPath;
            imagedestroy($image);
        }

        $template->update(['flattened_pages_json' => $currentPages]);
        return $currentPages;
    }

    /**
     * Flatten a signature onto the current (possibly already flattened) page image.
     * Call this after each signature is captured.
     *
     * @return string New page image path
     */
    public function flattenSignature(SignatureTemplate $template, SignatureMarker $marker, Signature $signature): string
    {
        $pageNum = $marker->page_number - 1; // markers are 1-indexed, images are 0-indexed
        $currentPages = $template->flattened_pages_json ?? [];

        // Use flattened page if available, otherwise original
        $document = $template->document;
        $imagePath = $currentPages[$pageNum] ?? $this->findOriginalPageImage(
            $document->template->id ?? 0, $pageNum, $document->id
        );

        if (!$imagePath) {
            Log::warning("DocumentFlattener::flattenSignature — image not found", [
                'template_id' => $template->id,
                'page' => $pageNum,
            ]);
            return '';
        }

        $fullPath = Storage::disk('local')->path($imagePath);
        $image = $this->loadImage($fullPath);
        if (!$image) return $imagePath;

        $imgWidth = imagesx($image);
        $imgHeight = imagesy($image);

        // Convert marker percentage positions to pixels
        $x = (floatval($marker->x_position) / 100) * $imgWidth;
        $y = (floatval($marker->y_position) / 100) * $imgHeight;
        $w = (floatval($marker->width) / 100) * $imgWidth;
        $h = (floatval($marker->height) / 100) * $imgHeight;

        // Text and date markers: render crisp text directly via renderText().
        // Signature and initial markers: composite the drawn/typed image.
        $markerType = $marker->type ?? 'signature';

        if (in_array($markerType, ['text', 'date']) && $signature->text_value) {
            $this->renderText($image, $signature->text_value, $x, $y, $w, $h);
        } elseif ($signature->signature_data) {
            $this->compositeSignatureImage($image, $signature->signature_data, $x, $y, $w, $h);
        } elseif ($signature->signature_type === 'typed') {
            // Fallback: render typed name when no image data available
            $isInitial = $markerType === 'initial';
            $this->renderTypedSignature($image, $signature->signer_name, $x, $y, $w, $h, $isInitial);
        }

        // Audit metadata (signer name, timestamp) is stored in the database
        // and appears on the audit certificate — not rendered on the document page.

        // Save the updated page
        $newPath = $this->saveFlattenedPage($template->id, $pageNum, $image);

        // Update the flattened pages record
        $currentPages[$pageNum] = $newPath;
        $template->update(['flattened_pages_json' => $currentPages]);

        imagedestroy($image);

        return $newPath;
    }

    /**
     * Flatten a wet-ink-verified stamp onto the page image.
     */
    public function flattenWetInkStamp(SignatureTemplate $template, SignatureMarker $marker, string $signerName): string
    {
        $pageNum = $marker->page_number - 1;
        $currentPages = $template->flattened_pages_json ?? [];
        $document = $template->document;

        $imagePath = $currentPages[$pageNum] ?? $this->findOriginalPageImage(
            $document->template->id ?? 0, $pageNum, $document->id
        );

        if (!$imagePath) return '';

        $fullPath = Storage::disk('local')->path($imagePath);
        $image = $this->loadImage($fullPath);
        if (!$image) return $imagePath;

        $imgWidth = imagesx($image);
        $imgHeight = imagesy($image);

        $x = (floatval($marker->x_position) / 100) * $imgWidth;
        $y = (floatval($marker->y_position) / 100) * $imgHeight;
        $w = (floatval($marker->width) / 100) * $imgWidth;
        $h = (floatval($marker->height) / 100) * $imgHeight;

        // Draw a green box with "WET INK VERIFIED" text
        $green = imagecolorallocate($image, 39, 103, 73);
        $lightGreen = imagecolorallocatealpha($image, 240, 255, 244, 50);
        imagefilledrectangle($image, (int) $x, (int) $y, (int) ($x + $w), (int) ($y + $h), $lightGreen);
        imagerectangle($image, (int) $x, (int) $y, (int) ($x + $w), (int) ($y + $h), $green);

        // Center text
        $font = $this->findFont(true);
        if ($font && function_exists('imagettftext')) {
            $fontSize = max(8, $h * 0.3);
            $fontSize = min($fontSize, 14);
            $textY1 = $y + ($h * 0.4);
            $textY2 = $y + ($h * 0.7);
            imagettftext($image, $fontSize, 0, (int) $x + 4, (int) $textY1, $green, $font, 'WET INK');
            imagettftext($image, $fontSize, 0, (int) $x + 4, (int) $textY2, $green, $font, 'VERIFIED');
        } else {
            imagestring($image, 2, (int) $x + 4, (int) ($y + $h * 0.3), 'WET INK', $green);
            imagestring($image, 2, (int) $x + 4, (int) ($y + $h * 0.6), 'VERIFIED', $green);
        }

        $newPath = $this->saveFlattenedPage($template->id, $pageNum, $image);
        $currentPages[$pageNum] = $newPath;
        $template->update(['flattened_pages_json' => $currentPages]);

        imagedestroy($image);
        return $newPath;
    }

    /**
     * Replace flattened pages with images from a wet-ink scan upload.
     *
     * After wet-ink approval, the uploaded scan (PDF or images) becomes the
     * new flattened page set so the next signing party sees the physical
     * signatures from the previous party.
     *
     * @param  array<string>  $uploadPaths  Storage paths of uploaded files (local disk)
     * @return array<int, string> Updated flattened_pages_json map
     */
    public function flattenWetInkScan(SignatureTemplate $template, array $uploadPaths): array
    {
        $currentPages = $template->flattened_pages_json ?? [];
        $scanPages = []; // Ordered list of GD images extracted from uploads

        foreach ($uploadPaths as $storagePath) {
            if (!$storagePath || !Storage::disk('local')->exists($storagePath)) {
                Log::warning('DocumentFlattener::flattenWetInkScan — file not found', ['path' => $storagePath]);
                continue;
            }

            $fullPath = Storage::disk('local')->path($storagePath);
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

            if ($ext === 'pdf') {
                $pdfImages = $this->extractPdfPages($fullPath, $template->id);
                foreach ($pdfImages as $img) {
                    $scanPages[] = $img;
                }
            } else {
                // Image file (jpg, jpeg, png)
                $image = $this->loadImage($fullPath);
                if ($image) {
                    $scanPages[] = $image;
                } else {
                    Log::warning('DocumentFlattener::flattenWetInkScan — could not load image', ['path' => $storagePath]);
                }
            }
        }

        if (empty($scanPages)) {
            Log::warning('DocumentFlattener::flattenWetInkScan — no pages extracted from uploads', [
                'template_id' => $template->id,
            ]);
            return $currentPages;
        }

        // Replace flattened pages with scan pages.
        // If scan has fewer pages than existing, keep existing for unmatched pages.
        // If scan has more pages, include all.
        $newPages = $currentPages;
        foreach ($scanPages as $pageNum => $image) {
            $newPath = $this->saveFlattenedPage($template->id, $pageNum, $image);
            $newPages[$pageNum] = $newPath;
            imagedestroy($image);
        }

        $template->update(['flattened_pages_json' => $newPages]);

        Log::info('DocumentFlattener::flattenWetInkScan — replaced flattened pages with wet-ink scan', [
            'template_id' => $template->id,
            'scan_page_count' => count($scanPages),
            'total_pages' => count($newPages),
        ]);

        return $newPages;
    }

    /**
     * Extract all pages from a PDF as GD images using pdftoppm.
     *
     * @return array<\GdImage> Ordered array of GD image resources
     */
    private function extractPdfPages(string $pdfAbsPath, int $templateId): array
    {
        $images = [];
        $pageCount = $this->getPdfPageCount($pdfAbsPath);

        if ($pageCount < 1) {
            Log::warning('DocumentFlattener::extractPdfPages — could not determine page count', ['pdf' => $pdfAbsPath]);
            return [];
        }

        $tempDir = sys_get_temp_dir() . '/dp_wetink_' . $templateId . '_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $pdftoppmPath = config('splitter.pdftoppm_path', 'pdftoppm');

        for ($page = 1; $page <= $pageCount; $page++) {
            $outPrefix = $tempDir . '/page';

            $proc = new Process([
                $pdftoppmPath,
                '-f', (string) $page,
                '-l', (string) $page,
                '-png',
                '-r', '200',
                $pdfAbsPath,
                $outPrefix,
            ]);
            $proc->setTimeout(120);
            $proc->run();

            if (!$proc->isSuccessful()) {
                Log::warning('DocumentFlattener::extractPdfPages — pdftoppm failed', [
                    'page' => $page,
                    'error' => trim($proc->getErrorOutput()),
                ]);
                continue;
            }

            // pdftoppm names output as prefix-NN.png — glob for it
            $files = glob($outPrefix . '-*.png');
            if (!empty($files)) {
                sort($files);
                $image = @imagecreatefrompng($files[0]);
                if ($image) {
                    $images[] = $image;
                }
                // Clean up temp file
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
        }

        // Clean up temp directory
        @rmdir($tempDir);

        return $images;
    }

    /**
     * Get the number of pages in a PDF using pdfinfo.
     */
    private function getPdfPageCount(string $pdfAbsPath): int
    {
        $proc = new Process(['pdfinfo', $pdfAbsPath]);
        $proc->setTimeout(30);
        $proc->run();

        if ($proc->isSuccessful()) {
            if (preg_match('/Pages:\s+(\d+)/', $proc->getOutput(), $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Get flattened page image paths for a template, falling back to originals.
     *
     * @return array<int, string> Map of 0-indexed page number => storage path
     */
    public function getPageImages(SignatureTemplate $template): array
    {
        $flattenedPages = $template->flattened_pages_json ?? [];
        $document = $template->document;
        $docTemplate = $document->template ?? null;
        $pageCount = $this->resolvePageCount($document, $docTemplate);

        if ($pageCount < 1) {
            return $flattenedPages;
        }

        $pages = [];
        for ($pageNum = 0; $pageNum < $pageCount; $pageNum++) {
            $pages[$pageNum] = $flattenedPages[$pageNum]
                ?? $this->findOriginalPageImage($docTemplate ? $docTemplate->id : 0, $pageNum, $document->id);
        }

        return $pages;
    }

    /**
     * Create temporary annotated copies of page images with marker overlays.
     * Used for wet-ink download PDFs so signers see where to sign/initial/fill.
     *
     * @param  \Illuminate\Support\Collection  $markers  Markers for the party
     * @return array<int, string> Map of 0-indexed page => absolute temp file path
     */
    public function createAnnotatedPages(SignatureTemplate $template, $markers): array
    {
        $pageImages = $this->getPageImages($template);
        if (empty($pageImages)) return [];

        $tempPaths = [];

        foreach ($pageImages as $pageNum => $storagePath) {
            if (!$storagePath || !Storage::disk('local')->exists($storagePath)) {
                continue;
            }

            $fullPath = Storage::disk('local')->path($storagePath);
            $image = $this->loadImage($fullPath);
            if (!$image) continue;

            // Filter markers for this page (markers are 1-indexed, pageNum is 0-indexed)
            $pageMarkers = $markers->filter(fn($m) => ($m->page_number - 1) === $pageNum);

            if ($pageMarkers->isNotEmpty()) {
                $this->overlayMarkerAnnotations($image, $pageMarkers);
            }

            // Save to temp file
            $tempPath = sys_get_temp_dir() . '/dp_annotated_' . $template->id . '_page_' . $pageNum . '_' . uniqid() . '.png';
            imagepng($image, $tempPath, 6);
            imagedestroy($image);

            $tempPaths[$pageNum] = $tempPath;
        }

        return $tempPaths;
    }

    /**
     * Delete temporary annotated page images after PDF generation.
     */
    public static function cleanupTempImages(array $tempPaths): void
    {
        foreach ($tempPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Overlay small margin arrows pointing at each marker location.
     * Arrows are placed in the RIGHT margin so the signing area stays clean.
     */
    private function overlayMarkerAnnotations($image, $markers): void
    {
        $imgWidth = imagesx($image);
        $imgHeight = imagesy($image);

        $labels = [
            SignatureMarker::TYPE_SIGNATURE => "\xe2\x86\x92 Sign",
            SignatureMarker::TYPE_INITIAL   => "\xe2\x86\x92 Initial",
            SignatureMarker::TYPE_TEXT      => "\xe2\x86\x92 Fill",
            SignatureMarker::TYPE_DATE      => "\xe2\x86\x92 Date",
        ];

        $labelColor = imagecolorallocate($image, 102, 102, 102); // #666666
        imagealphablending($image, true);

        // Collect Y positions to detect overlaps and offset
        $usedYPositions = [];
        $minYGap = 20; // minimum vertical gap between labels in pixels

        $font = $this->findFont(false);
        $fontSize = 10;

        // Position arrows at 92% of page width
        $arrowX = (int) ($imgWidth * 0.92);

        foreach ($markers as $marker) {
            $type = $marker->type ?? SignatureMarker::TYPE_SIGNATURE;
            $label = $labels[$type] ?? $labels[SignatureMarker::TYPE_SIGNATURE];

            $markerY = (floatval($marker->y_position) / 100) * $imgHeight;
            $markerH = (floatval($marker->height) / 100) * $imgHeight;

            // Vertically center with the marker
            $textY = $markerY + ($markerH / 2);

            // Offset if too close to a previously placed label
            foreach ($usedYPositions as $usedY) {
                if (abs($textY - $usedY) < $minYGap) {
                    $textY = $usedY + $minYGap;
                }
            }

            $usedYPositions[] = $textY;

            if ($font && function_exists('imagettftext')) {
                imagettftext($image, $fontSize, 0, $arrowX, (int) $textY, $labelColor, $font, $label);
            } else {
                imagestring($image, 2, $arrowX, (int) ($textY - 6), $label, $labelColor);
            }
        }
    }

    /**
     * Render a small subtle label at the top-left corner of a marker box.
     */
    private function renderMarkerLabel($image, string $label, float $x, float $y, float $w, float $h, $textColor = null): void
    {
        if (!$textColor) {
            $textColor = imagecolorallocate($image, 153, 153, 153); // Light grey #999
        }

        $font = $this->findFont(false); // Regular weight, not bold

        if ($font && function_exists('imagettftext')) {
            // Small fixed size: 7-8px max, never larger
            $fontSize = min(8, max(6, $h * 0.25));

            // Top-left corner: 2px padding from edges
            $textX = $x + 2;
            $textY = $y + $fontSize + 2; // baseline positioning

            imagettftext($image, $fontSize, 0, (int) $textX, (int) $textY, $textColor, $font, $label);
        } else {
            // Fallback: smallest GD built-in font
            imagestring($image, 1, (int) ($x + 2), (int) ($y + 1), $label, $textColor);
        }
    }

    /**
     * Render a selection field — show the selected value in its column position.
     */
    private function renderSelectionField($image, array $field, float $x, float $y, float $w, float $h, array $style = []): void
    {
        $value = trim((string) ($field['selectedValue'] ?? ''));
        if ($value === '') return;

        $opts = $field['options'] ?? [];
        $optCount = count($opts) ?: 1;
        $selIdx = array_search($value, $opts);
        if ($selIdx === false) $selIdx = 0;

        $sectionW = $w / $optCount;
        $sectionX = $x + $selIdx * $sectionW;

        // Solid background if enabled
        if (!empty($field['solidBg'])) {
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, (int) $sectionX, (int) $y, (int) ($sectionX + $sectionW), (int) ($y + $h), $white);
        }

        $this->renderText($image, $value, $sectionX, $y, $sectionW, $h, $style);
    }

    /**
     * Render a tick field — show "X" in the selected column position.
     */
    private function renderTickField($image, array $field, float $x, float $y, float $w, float $h): void
    {
        $value = trim((string) ($field['selectedValue'] ?? ''));
        if ($value === '') return;

        $opts = $field['options'] ?? [];
        $optCount = count($opts) ?: 1;
        $selIdx = array_search($value, $opts);
        if ($selIdx === false) $selIdx = 0;

        $sectionW = $w / $optCount;
        $sectionX = $x + $selIdx * $sectionW;

        // Solid background (default on for tick)
        if ($field['solidBg'] ?? true) {
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, (int) $sectionX, (int) $y, (int) ($sectionX + $sectionW), (int) ($y + $h), $white);
        }

        // Render bold "X" centered in the section
        $color = imagecolorallocate($image, 0, 0, 0);
        $font = $this->findFont(true);

        if ($font && function_exists('imagettftext')) {
            $fontSize = max(8, $h * 0.6);
            $bbox = imagettfbbox($fontSize, 0, $font, 'X');
            $textW = abs($bbox[2] - $bbox[0]);
            $textH = abs($bbox[7] - $bbox[1]);
            $textX = $sectionX + ($sectionW - $textW) / 2;
            $textY = $y + ($h + $textH) / 2;
            imagettftext($image, $fontSize, 0, (int) $textX, (int) $textY, $color, $font, 'X');
        } else {
            imagestring($image, 4, (int) ($sectionX + $sectionW / 2 - 4), (int) ($y + $h / 2 - 8), 'X', $color);
        }
    }

    // ========== HELPER METHODS ==========

    /**
     * Resolve the page count for a document, handling web template documents
     * that store their page count in web_template_data instead of the template.
     */
    private function resolvePageCount($document, $docTemplate): int
    {
        // Standard PDF template
        if ($docTemplate && $docTemplate->page_count > 0) {
            return $docTemplate->page_count;
        }

        // Flattened web template — page count stored on document
        $webTemplateData = $document->web_template_data ?? [];
        if (!empty($webTemplateData['flattened_page_count'])) {
            return (int) $webTemplateData['flattened_page_count'];
        }

        return 0;
    }

    /**
     * Find the original page image storage path for a document template page.
     * Checks document-level storage first (for flattened web templates),
     * then falls back to template-level storage (for PDF templates).
     */
    private function findOriginalPageImage(int $templateId, int $pageNum, ?int $documentId = null): ?string
    {
        // Check document-level storage first (flattened web templates)
        if ($documentId) {
            $docPath = "docuperfect/documents/{$documentId}/page-{$pageNum}.png";
            if (Storage::disk('local')->exists($docPath)) {
                return $docPath;
            }
        }

        // Fall back to template-level storage (PDF templates)
        if ($templateId > 0) {
            $pngPath = "docuperfect/templates/{$templateId}/page-{$pageNum}.png";
            $jpgPath = "docuperfect/templates/{$templateId}/page-{$pageNum}.jpg";

            if (Storage::disk('local')->exists($pngPath)) {
                return $pngPath;
            }
            if (Storage::disk('local')->exists($jpgPath)) {
                return $jpgPath;
            }
        }

        return null;
    }

    /**
     * Load an image file (supports JPEG, PNG, GIF, WebP).
     *
     * @return \GdImage|false|null
     */
    private function loadImage(string $path)
    {
        if (!file_exists($path)) return null;

        $info = @getimagesize($path);
        if (!$info) return null;

        return match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => null,
        };
    }

    /**
     * Render text onto an image at the specified pixel position.
     */
    private function renderText($image, string $text, float $x, float $y, float $w, float $h, array $style = []): void
    {
        \Log::debug('renderText debug', [
            'text' => $text,
            'image_width' => imagesx($image),
            'image_height' => imagesy($image),
            'field_x' => $x, 'field_y' => $y,
            'field_w' => $w, 'field_h' => $h,
            'font_path' => $this->findFont(),
            'font_exists' => file_exists($this->findFont() ?? ''),
        ]);

        $color = imagecolorallocate($image, 0, 0, 0); // Black text

        // If solidBackground is set, fill the area white first
        if (!empty($style['solidBackground'])) {
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, (int) $x, (int) $y, (int) ($x + $w), (int) ($y + $h), $white);
        }

        $bold = !empty($style['bold']);
        $ttfFont = $this->findFont($bold);

        if ($ttfFont && function_exists('imagettftext')) {
            // Scale font size to match the editor's CSS pixel rendering.
            // The editor displays page images at ~800px CSS width.
            // A CSS font-size of N pixels at 800px display = N/800 of image width.
            // GD's imagettftext uses points at 96 DPI (1pt = 96/72 = 1.333 pixels).
            // Formula: gdPoints = cssPx * (imgWidth / editorDisplayWidth) * (72/96)
            $imgWidth = imagesx($image);
            $cssFontPx = floatval($style['fontSize'] ?? 11);
            $cssFontPx = max(8, min(16, $cssFontPx));
            $editorDisplayWidth = 800; // matches max-width:800px on signing/setup containers
            $imgScalePixels = $cssFontPx * ($imgWidth / $editorDisplayWidth);
            $fontSize = $imgScalePixels * 72 / 96; // convert image pixels to GD points

            // Cap so text never exceeds field height
            $fontSize = min($fontSize, $h * 0.75);

            Log::debug('DocumentFlattener::renderText', [
                'text' => mb_substr($text, 0, 30),
                'imgWidth' => $imgWidth,
                'cssFontPx' => $cssFontPx,
                'finalGdPts' => round($fontSize, 2),
                'fieldH' => round($h, 1),
                'font' => basename($ttfFont),
            ]);

            // Baseline near bottom of field box — text sits ON the line.
            // Leave ~30% of font size below baseline for descenders.
            $textY = $y + $h - ($fontSize * 0.3);

            imagettftext($image, $fontSize, 0, (int) ($x + 2), (int) $textY, $color, $ttfFont, $text);
        } else {
            // Fallback to GD built-in fonts
            $font = $h > 20 ? 3 : 2;
            imagestring($image, $font, (int) $x + 2, (int) $y + 2, $text, $color);
        }
    }

    /**
     * Render a strikethrough line across the field area.
     */
    private function renderStrikethrough($image, float $x, float $y, float $w, float $h, array $field = []): void
    {
        $red = imagecolorallocate($image, 239, 68, 68);
        imagesetthickness($image, 2);

        $strikeType = $field['strikethroughType'] ?? 'horizontal';

        if ($strikeType === 'diagonal') {
            imageline($image, (int) $x, (int) $y, (int) ($x + $w), (int) ($y + $h), $red);
        } else {
            // Horizontal line through the middle
            imageline($image, (int) $x, (int) ($y + $h / 2), (int) ($x + $w), (int) ($y + $h / 2), $red);
        }

        imagesetthickness($image, 1); // Reset
    }

    /**
     * Composite a base64 signature image onto the page image.
     */
    private function compositeSignatureImage($image, string $signatureDataUri, float $x, float $y, float $w, float $h): void
    {
        // Decode the signature image from base64 data URI
        $sigData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signatureDataUri));
        if (!$sigData) return;

        $sigImage = @imagecreatefromstring($sigData);
        if (!$sigImage) return;

        // Create a properly sized version of the signature
        $destW = (int) $w;
        $destH = (int) $h;
        if ($destW < 1 || $destH < 1) {
            imagedestroy($sigImage);
            return;
        }

        $resized = imagecreatetruecolor($destW, $destH);

        // Preserve transparency
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
        imagefilledrectangle($resized, 0, 0, $destW, $destH, $transparent);
        imagealphablending($resized, true);

        // Resize signature to fit marker area
        imagecopyresampled(
            $resized, $sigImage,
            0, 0, 0, 0,
            $destW, $destH,
            imagesx($sigImage), imagesy($sigImage)
        );

        // Composite onto page (with alpha blending)
        imagealphablending($image, true);
        imagecopy($image, $resized, (int) $x, (int) $y, 0, 0, $destW, $destH);

        imagedestroy($sigImage);
        imagedestroy($resized);
    }

    /**
     * Render a typed signature name in a stylised font.
     */
    private function renderTypedSignature($image, string $name, float $x, float $y, float $w, float $h, bool $isInitial = false): void
    {
        $color = imagecolorallocate($image, 0, 0, 0); // Black, matching canvas rendering

        if ($isInitial) {
            // Initials: 80% of field height, bold, centered both ways
            $font = $this->findFont(true);
            if ($font && function_exists('imagettftext')) {
                $fontSize = max(10, $h * 0.8);
                $bbox = imagettfbbox($fontSize, 0, $font, $name);
                $textWidth = abs($bbox[2] - $bbox[0]);
                $textHeight = abs($bbox[7] - $bbox[1]);
                $textX = $x + ($w - $textWidth) / 2;
                $textY = $y + ($h + $textHeight) / 2;
                imagettftext($image, $fontSize, 0, (int) $textX, (int) $textY, $color, $font, $name);
            } else {
                imagestring($image, 5, (int) ($x + $w * 0.3), (int) ($y + $h * 0.2), $name, $color);
            }
        } else {
            $font = $this->findFont(false);
            if ($font && function_exists('imagettftext')) {
                $fontSize = max(10, $h * 0.6);
                $fontSize = min($fontSize, $h * 0.85);
                $textY = $y + ($h * 0.7);
                $bbox = imagettfbbox($fontSize, 0, $font, $name);
                $textWidth = abs($bbox[2] - $bbox[0]);
                $textX = $x + ($w - $textWidth) / 2;
                $textX = max($textX, $x + 2);
                imagettftext($image, $fontSize, 0, (int) $textX, (int) $textY, $color, $font, $name);
            } else {
                imagestring($image, 4, (int) $x + 4, (int) ($y + $h * 0.3), $name, $color);
            }
        }
    }

    /**
     * Find a TTF font file to use for text rendering.
     */
    private function findFont(bool $bold = false): ?string
    {
        if ($bold) {
            $candidates = [
                'C:/Windows/Fonts/arialbd.ttf',
                'C:/Windows/Fonts/segoeuib.ttf',
                'C:/Windows/Fonts/calibrib.ttf',
                resource_path('fonts/arial-bold.ttf'),
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            ];
        } else {
            $candidates = [
                'C:/Windows/Fonts/arial.ttf',
                'C:/Windows/Fonts/segoeui.ttf',
                'C:/Windows/Fonts/calibri.ttf',
                resource_path('fonts/arial.ttf'),
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            ];
        }

        foreach ($candidates as $font) {
            if (file_exists($font)) return $font;
        }

        return null;
    }

    /**
     * Save a flattened page image as PNG.
     */
    private function saveFlattenedPage(int $templateId, int $pageNum, $image): string
    {
        $dir = "docuperfect/signed-documents/{$templateId}/flattened";
        $path = "{$dir}/page_{$pageNum}.png";

        $fullPath = Storage::disk('local')->path($path);

        // Ensure directory exists
        $dirPath = dirname($fullPath);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        // Save as PNG for lossless quality
        imagepng($image, $fullPath, 6);

        return $path;
    }
}
