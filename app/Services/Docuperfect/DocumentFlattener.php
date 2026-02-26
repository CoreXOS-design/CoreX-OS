<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Signature;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        if (!$docTemplate || $docTemplate->page_count < 1) {
            Log::warning('DocumentFlattener::flattenFields — no template or zero pages', ['template_id' => $template->id]);
            return [];
        }

        $fields = $document->fields_json ?? [];
        $newPaths = [];

        for ($pageNum = 0; $pageNum < $docTemplate->page_count; $pageNum++) {
            $imagePath = $this->findOriginalPageImage($docTemplate->id, $pageNum);
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

                // Skip signature/initial fields — those are handled by signature markers
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
                        $value = trim((string) ($field['selectedValue'] ?? ''));
                        if ($value !== '') {
                            $this->renderText($image, $value, $x, $y, $w, $h, $style);
                        }
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
        $imagePath = $currentPages[$pageNum] ?? $this->findOriginalPageImage(
            $template->document->template->id ?? 0, $pageNum
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

        // Handle different signature types
        if ($signature->signature_type === 'drawn' && $signature->signature_data) {
            $this->compositeSignatureImage($image, $signature->signature_data, $x, $y, $w, $h);
        } elseif ($signature->signature_type === 'typed') {
            // Render typed name in script font
            $this->renderTypedSignature($image, $signature->signer_name, $x, $y, $w, $h);
        }

        // Add small signature label below the marker
        $labelY = (int) $y + (int) $h + 2;
        $dateStr = $signature->signed_at ? $signature->signed_at->format('d M Y H:i') : now()->format('d M Y H:i');
        $label = 'Signed: ' . $signature->signer_name . ' — ' . $dateStr;
        $labelColor = imagecolorallocate($image, 100, 100, 100);
        // Use built-in GD font 1 (tiny) for the label
        imagestring($image, 1, (int) $x, $labelY, $label, $labelColor);

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

        $imagePath = $currentPages[$pageNum] ?? $this->findOriginalPageImage(
            $template->document->template->id ?? 0, $pageNum
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
     * Get flattened page image paths for a template, falling back to originals.
     *
     * @return array<int, string> Map of 0-indexed page number => storage path
     */
    public function getPageImages(SignatureTemplate $template): array
    {
        $flattenedPages = $template->flattened_pages_json ?? [];
        $docTemplate = $template->document->template ?? null;

        if (!$docTemplate || $docTemplate->page_count < 1) {
            return $flattenedPages;
        }

        $pages = [];
        for ($pageNum = 0; $pageNum < $docTemplate->page_count; $pageNum++) {
            $pages[$pageNum] = $flattenedPages[$pageNum]
                ?? $this->findOriginalPageImage($docTemplate->id, $pageNum);
        }

        return $pages;
    }

    // ========== HELPER METHODS ==========

    /**
     * Find the original page image storage path for a document template page.
     */
    private function findOriginalPageImage(int $templateId, int $pageNum): ?string
    {
        $pngPath = "docuperfect/templates/{$templateId}/page-{$pageNum}.png";
        $jpgPath = "docuperfect/templates/{$templateId}/page-{$pageNum}.jpg";

        if (Storage::disk('local')->exists($pngPath)) {
            return $pngPath;
        }
        if (Storage::disk('local')->exists($jpgPath)) {
            return $jpgPath;
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
        $color = imagecolorallocate($image, 0, 0, 0); // Black text

        // If solidBackground is set, fill the area white first
        if (!empty($style['solidBackground'])) {
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, (int) $x, (int) $y, (int) ($x + $w), (int) ($y + $h), $white);
        }

        $bold = !empty($style['bold']);
        $ttfFont = $this->findFont($bold);

        if ($ttfFont && function_exists('imagettftext')) {
            // Calculate font size based on field height and style
            $configuredSize = $style['fontSize'] ?? 12;
            // Scale: the configured font size is relative to a browser rendering context.
            // Page images are typically ~1650px wide for an A4 page.
            // A 12px font in browser at ~800px wide container = ~24px at 1650px.
            // Use the field height as the primary guide, capped by a reasonable range.
            $fontSize = max(8, $h * 0.55);
            $fontSize = min($fontSize, $h * 0.8); // Don't exceed field height

            // TTF text baseline is at bottom of text, so offset Y
            $textY = $y + ($h * 0.72);

            imagettftext($image, $fontSize, 0, (int) $x + 2, (int) $textY, $color, $ttfFont, $text);
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
    private function renderTypedSignature($image, string $name, float $x, float $y, float $w, float $h): void
    {
        $color = imagecolorallocate($image, 26, 26, 138); // Dark blue, matching CSS
        $font = $this->findFont(false);

        if ($font && function_exists('imagettftext')) {
            $fontSize = max(10, $h * 0.6);
            $fontSize = min($fontSize, $h * 0.85);
            $textY = $y + ($h * 0.7);
            // Center horizontally
            $bbox = imagettfbbox($fontSize, 0, $font, $name);
            $textWidth = abs($bbox[2] - $bbox[0]);
            $textX = $x + ($w - $textWidth) / 2;
            $textX = max($textX, $x + 2);

            imagettftext($image, $fontSize, 0, (int) $textX, (int) $textY, $color, $font, $name);
        } else {
            imagestring($image, 4, (int) $x + 4, (int) ($y + $h * 0.3), $name, $color);
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
                resource_path('fonts/arial-bold.ttf'),
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            ];
        } else {
            $candidates = [
                'C:/Windows/Fonts/arial.ttf',
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
