<?php

namespace App\Services\Presentations\Evidence\Parsers;

use App\Models\PresentationUpload;

/**
 * Parses CMA / valuation report PDFs.
 *
 * Does NOT create sold comp or active listing rows.
 * Instead it extracts a suggested price band and any recommended
 * value mentions into extraction_json for display / audit.
 *
 * parseText() is pure (no DB); parse() is the full entry point.
 */
class CmaParserV1
{
    public const PARSER_VERSION = 'cma_v1';
    public const DOC_TYPE       = 'cma';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Parse text and return structured data (no DB writes).
     * Used directly by unit tests.
     */
    public function parseText(string $text): array
    {
        return [
            'suggested_band' => $this->extractPriceBand($text),
            'notes'          => $this->extractNotes($text),
        ];
    }

    /**
     * Full entry point: parse + return extraction_json data.
     * (CMA parser does not persist rows — audit only.)
     */
    public function parse(string $text, PresentationUpload $upload): array
    {
        $parsed = $this->parseText($text);

        return [
            'parser_version' => self::PARSER_VERSION,
            'doc_type_guess' => self::DOC_TYPE,
            'suggested_band' => $parsed['suggested_band'],
            'notes'          => $parsed['notes'],
            'errors'         => [],
        ];
    }

    // ── Private parsing helpers ───────────────────────────────────────────────

    /**
     * Looks for a price range: R2,000,000 to R2,500,000
     * or R2,000,000 - R2,500,000.
     */
    private function extractPriceBand(string $text): ?array
    {
        if (preg_match('/R\s*([\d\s,]+)\s*(?:to|\-)\s*R\s*([\d\s,]+)/i', $text, $m)) {
            $low  = (int)preg_replace('/[\s,]/', '', $m[1]);
            $high = (int)preg_replace('/[\s,]/', '', $m[2]);
            if ($low >= 10000 && $high >= $low) {
                return ['low' => $low, 'high' => $high];
            }
        }
        return null;
    }

    /**
     * Looks for "Recommended price: R…" or "Suggested value: R…".
     */
    private function extractNotes(string $text): array
    {
        $notes = [];
        if (preg_match('/(?:recommended|suggested|estimated)\s+(?:price|value)[\s:]+R\s*([\d\s,]+)/i', $text, $m)) {
            $val = (int)preg_replace('/[\s,]/', '', $m[1]);
            if ($val >= 10000) {
                $notes[] = 'suggested_value:' . $val;
            }
        }
        return $notes;
    }
}
