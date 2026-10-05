<?php

namespace App\Services\Rentals\CatalogueImport;

use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * .ai/specs/rental-work-orders.md §14.19 — reads the catalogue import
 * template BY COLUMN POSITION, same convention as
 * App\Services\Rentals\TakeOnImport\RentalTakeOnRowParser (itself copied
 * from ContactImportController::streamRows()). Fixed column order: code,
 * description, type, unit, vat_type, price_excl, price_incl.
 */
class RentalCatalogueImportRowParser
{
    public const COLUMNS = ['code', 'description', 'type', 'unit', 'vat_type', 'price_excl', 'price_incl'];

    /**
     * @return \Generator<int, array{row_number: int, payload: array<string, mixed>}>
     */
    public function parse(string $absolutePath, string $extension): \Generator
    {
        $rowNumber = 0;
        $isFirstRow = true;

        foreach ($this->streamRows($absolutePath, $extension) as $cells) {
            $rowNumber++;

            if ($isFirstRow) {
                $isFirstRow = false;
                continue;
            }

            if ($this->isBlankRow($cells)) {
                continue;
            }

            $payload = [];
            foreach (self::COLUMNS as $i => $key) {
                $raw = $cells[$i] ?? null;
                // Raw values pass through untouched (price columns included) —
                // RentalCatalogueImportDryRunResolver does the parsing, so an
                // unparseable price shows as a per-row error rather than
                // silently becoming null here.
                $payload[$key] = is_string($raw) ? trim($raw) : ($raw === null ? null : (is_float($raw) || is_int($raw) ? $raw : (string) $raw));
            }

            yield ['row_number' => $rowNumber, 'payload' => $payload];
        }
    }

    private function streamRows(string $path, string $extension): \Generator
    {
        $reader = $extension === 'csv' ? new CsvReader() : new XlsxReader();
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }
                break; // first sheet only — the Instructions sheet is never read
            }
        } finally {
            $reader->close();
        }
    }

    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Same "R 1 250.00" / "1,250" absorption as RentalTakeOnRowParser::parseNumber (BUILD_STANDARD §2). */
    public function parseNumber(mixed $val): ?float
    {
        if ($val === null) {
            return null;
        }
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }
        if (!is_string($val)) {
            return null;
        }

        $cleaned = trim(str_replace(['R', 'r', ' ', ','], '', $val));
        if ($cleaned === '') {
            return null;
        }

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }
}
