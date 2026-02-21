<?php

namespace App\Services\Presentations\Evidence;

use App\Models\PresentationUpload;
use App\Services\Presentations\Evidence\Parsers\CmaParserV1;
use App\Services\Presentations\Evidence\Parsers\SalesReportParserV1;
use App\Services\Presentations\Evidence\Parsers\SuburbStockParserV1;
use App\Services\Presentations\Evidence\Parsers\UnknownParser;

/**
 * Routes a PresentationUpload to the correct deterministic parser,
 * runs it, and persists extraction_json on the upload record.
 *
 * Called sync after UploadProcessor has already set text_extracted
 * and extraction_status on the upload.
 *
 * Never throws. Never calls AI.
 */
class UploadExtractionService
{
    public const SERVICE_VERSION = 'extraction_service_v1';

    public function run(PresentationUpload $upload): void
    {
        $docType = $this->detectDocType($upload->original_filename ?? '');

        if ($upload->extraction_status !== 'ok') {
            $upload->update([
                'extraction_json' => json_encode([
                    'parser_version' => self::SERVICE_VERSION,
                    'doc_type_guess' => $docType,
                    'parsed_counts'  => [],
                    'errors'         => ['text_extraction_failed'],
                ], JSON_THROW_ON_ERROR),
            ]);
            return;
        }

        $text   = $upload->text_extracted ?? '';
        $result = $this->runParser($docType, $text, $upload);

        $upload->update([
            'extraction_json' => json_encode($result, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Determine document type from filename keywords.
     * Deterministic; does not read file contents.
     */
    public function detectDocType(string $filename): string
    {
        $lower = mb_strtolower($filename);

        if (str_contains($lower, 'vicinity') || str_contains($lower, 'sales')) {
            return SalesReportParserV1::DOC_TYPE;
        }

        if (str_contains($lower, 'suburb') || str_contains($lower, 'stock')) {
            return SuburbStockParserV1::DOC_TYPE;
        }

        if (str_contains($lower, 'cma') || str_contains($lower, 'valuation')) {
            return CmaParserV1::DOC_TYPE;
        }

        return UnknownParser::DOC_TYPE;
    }

    private function runParser(string $docType, string $text, PresentationUpload $upload): array
    {
        return match ($docType) {
            SalesReportParserV1::DOC_TYPE  => (new SalesReportParserV1())->parse($text, $upload),
            SuburbStockParserV1::DOC_TYPE  => (new SuburbStockParserV1())->parse($text, $upload),
            CmaParserV1::DOC_TYPE          => (new CmaParserV1())->parse($text, $upload),
            default                        => (new UnknownParser())->parse($text),
        };
    }
}
