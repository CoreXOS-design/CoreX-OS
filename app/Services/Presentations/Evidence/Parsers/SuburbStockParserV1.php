<?php

namespace App\Services\Presentations\Evidence\Parsers;

use App\Models\PresentationActiveListing;
use App\Models\PresentationUpload;

/**
 * Parses active-listing rows from "suburb" or "stock" report PDFs.
 *
 * Approach: scan each line for a price (R …).
 * Lines without a price are skipped. Other fields are opportunistic.
 *
 * parseText() is pure (no DB); parse() calls parseText() + persists.
 */
class SuburbStockParserV1
{
    public const PARSER_VERSION = 'suburb_stock_v1';
    public const DOC_TYPE       = 'suburb_stock';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Parse text into row arrays.  No DB writes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseText(string $text): array
    {
        $rows = [];
        foreach (explode("\n", $text) as $line) {
            $row = $this->parseLine($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Parse text + persist rows + return extraction_json data.
     */
    public function parse(string $text, PresentationUpload $upload): array
    {
        $rows   = $this->parseText($text);
        $count  = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                PresentationActiveListing::create([
                    'presentation_id'  => $upload->presentation_id,
                    'source_upload_id' => $upload->id,
                    'listing_date'     => $row['listing_date'],
                    'list_price_inc'   => $row['list_price_inc'],
                    'suburb'           => $row['suburb'],
                    'property_type'    => $row['property_type'],
                    'beds'             => $row['beds'],
                    'baths'            => $row['baths'],
                    'size_m2'          => $row['size_m2'],
                    'status'           => $row['status'],
                    'raw_row_json'     => json_encode($row, JSON_THROW_ON_ERROR),
                    'parser_version'   => self::PARSER_VERSION,
                ]);
                $count++;
            } catch (\Throwable $e) {
                $errors[] = 'persist_failed: ' . $e->getMessage();
            }
        }

        return [
            'parser_version' => self::PARSER_VERSION,
            'doc_type_guess' => self::DOC_TYPE,
            'parsed_counts'  => ['active_listings' => $count],
            'errors'         => $errors,
        ];
    }

    // ── Private parsing helpers ───────────────────────────────────────────────

    private function parseLine(string $line): ?array
    {
        $line  = trim($line);
        $price = $this->extractPrice($line);

        if ($price === null || strlen($line) < 8) {
            return null;
        }

        return [
            'list_price_inc' => $price,
            'listing_date'   => $this->extractDate($line),
            'beds'           => $this->extractBeds($line),
            'baths'          => $this->extractBaths($line),
            'size_m2'        => $this->extractSize($line),
            'suburb'         => null,
            'property_type'  => null,
            'status'         => $this->extractStatus($line),
        ];
    }

    private function extractPrice(string $line): ?int
    {
        // Structured format: R1,800,000 or R 1 800 000
        if (preg_match('/R\s*(\d{1,3}(?:[, ]\d{3})+)/i', $line, $m)) {
            $cleaned = preg_replace('/[\s,]/', '', $m[1]);
            if (is_numeric($cleaned) && (int)$cleaned >= 10000) {
                return (int)$cleaned;
            }
        }
        // Unformatted: R1800000 (6–10 raw digits)
        if (preg_match('/R\s*(\d{6,10})\b/i', $line, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function extractDate(string $line): ?string
    {
        if (preg_match('/\b(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})\b/', $line, $m)) {
            $y = (int)$m[3];
            $mo = (int)$m[2];
            $d = (int)$m[1];
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        if (preg_match('/\b(\d{4})[\/\-\.](\d{2})[\/\-\.](\d{2})\b/', $line, $m)) {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }
        return null;
    }

    private function extractBeds(string $line): ?int
    {
        if (preg_match('/\b(\d)\s*(?:bed(?:room)?s?|BR)\b/i', $line, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function extractBaths(string $line): ?int
    {
        if (preg_match('/\b(\d)\s*(?:bath(?:room)?s?|BA)\b/i', $line, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function extractSize(string $line): ?int
    {
        if (preg_match('/\b(\d{2,4})\s*(?:m2|m²|sqm)\b/i', $line, $m)) {
            $size = (int)$m[1];
            return ($size >= 20 && $size <= 9999) ? $size : null;
        }
        return null;
    }

    private function extractStatus(string $line): ?string
    {
        if (preg_match('/\b(active|available|sold|pending|under offer)\b/i', $line, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }
}
