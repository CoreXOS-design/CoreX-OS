<?php

namespace App\Domain\Presentation;

use App\Models\Presentation;
use App\Models\PresentationField;
use App\Models\PresentationUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadProcessor
{
    public function __construct(private TextExtractionService $extractor) {}

    /**
     * Store an uploaded file, extract text, and detect structured fields.
     *
     * Steps:
     * 1. Store file under presentations/{id}/ in the local disk.
     * 2. Create presentation_upload record (status = pending).
     * 3. Extract text via TextExtractionService.
     * 4. Update record: text_extracted + status (ok|failed).
     * 5. If ok — run deterministic field detection and create presentation_fields rows.
     *
     * Never throws. Never calls AI. Audit-safe: extracted_value is never overwritten.
     */
    public function process(UploadedFile $file, Presentation $presentation, int $userId): PresentationUpload
    {
        $storagePath = $file->store("presentations/{$presentation->id}", 'local');

        /** @var PresentationUpload $upload */
        $upload = PresentationUpload::create([
            'presentation_id'     => $presentation->id,
            'uploaded_by_user_id' => $userId,
            'type'                => $file->getClientMimeType(),
            'original_filename'   => $file->getClientOriginalName(),
            'storage_path'        => $storagePath,
            'extraction_status'   => 'pending',
        ]);

        $absolutePath = Storage::disk('local')->path($storagePath);
        $text = $this->extractor->extractText($absolutePath, $file->getClientMimeType());

        if ($text !== '') {
            $upload->text_extracted = $text;
            $upload->extraction_status = 'ok';
        } else {
            $upload->extraction_status = 'failed';
        }

        $upload->save();

        if ($upload->extraction_status === 'ok') {
            $this->detectFields($text, $presentation, $upload);
        }

        return $upload;
    }

    /**
     * Deterministic keyword + regex pattern detection.
     *
     * Proof-of-pipeline only — simple patterns against raw extracted text.
     * Each match creates a presentation_fields row with extracted_value set.
     * override_value and final_value remain null until a human or later process sets them.
     */
    private function detectFields(string $text, Presentation $presentation, PresentationUpload $upload): void
    {
        $patterns = [
            'suburb.avg_price'  => '/Average\s+Selling\s+Price[\s:R]*([\d\s,]+)/i',
            'suburb.sold_count' => '/Properties\s+Sold[\s:]*(\d+)/i',
            'suburb.dom'        => '/Days\s+on\s+Market[\s:]*(\d+)/i',
        ];

        foreach ($patterns as $fieldKey => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $extracted = trim($matches[1]);

                PresentationField::create([
                    'presentation_id'  => $presentation->id,
                    'field_key'        => $fieldKey,
                    'extracted_value'  => $extracted,
                    // override_value and final_value intentionally null — set by human later
                    'source_upload_id' => $upload->id,
                    'confidence'       => 0.70,
                ]);
            }
        }
    }
}
