<?php

namespace App\Services\Rentals\TakeOnImport;

use Carbon\Carbon;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * .ai/specs/rental-takeon-import.md §5.1 — reads the fixed take-on template
 * BY COLUMN POSITION (Landing 1 has no user-configurable column mapping —
 * that is Landing 2). Streaming read pattern copied from
 * App\Http\Controllers\CoreX\ContactImportController::streamRows().
 */
class RentalTakeOnRowParser
{
    /**
     * @return \Generator<int, array{row_number: int, payload: array<string, mixed>}>
     */
    public function parse(string $absolutePath, string $extension): \Generator
    {
        $keys = RentalTakeOnFieldSchema::keys();
        $rowNumber = 0;
        $isFirstRow = true;

        foreach ($this->streamRows($absolutePath, $extension) as $cells) {
            $rowNumber++;

            if ($isFirstRow) {
                // Header row — always present in our own generated template,
                // always skipped. We read by position, not by matching its text.
                $isFirstRow = false;
                continue;
            }

            if ($this->isBlankRow($cells)) {
                continue;
            }

            $payload = [];
            foreach ($keys as $i => $key) {
                $raw = $cells[$i] ?? null;
                $payload[$key] = $this->normaliseCell($key, $raw);
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

    private function normaliseCell(string $key, mixed $raw): mixed
    {
        $field = $this->fieldFor($key);
        $type = $field['type'] ?? 'string';

        if ($type === 'date') {
            return $this->parseDate($raw);
        }

        if ($type === 'number') {
            return $this->parseNumber($raw);
        }

        if (is_string($raw)) {
            return trim($raw);
        }

        return $raw === null ? null : (string) $raw;
    }

    private function fieldFor(string $key): ?array
    {
        static $byKey = null;
        $byKey ??= array_column(RentalTakeOnFieldSchema::fields(), null, 'key');

        return $byKey[$key] ?? null;
    }

    /** Mirrors ContactImportController::parseDate() exactly — same cell shapes, same contract. */
    public function parseDate(mixed $val): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }

        if ($val instanceof \DateTimeInterface) {
            return Carbon::parse($val->format('Y-m-d H:i:s'))->toDateString();
        }

        try {
            if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val))) {
                $dt = ExcelDate::excelToDateTimeObject((float) $val);

                return $dt ? Carbon::instance($dt)->toDateString() : null;
            }
            if (is_string($val)) {
                $val = trim($val);
                if ($val === '') {
                    return null;
                }

                return Carbon::parse($val)->toDateString();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

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

        // Absorb "R 1 250.00" / "1,250" style entries rather than rejecting
        // the lazy-but-valid shortcut of pasting straight from a spreadsheet
        // that already formatted the cell as currency (BUILD_STANDARD §2).
        $cleaned = trim(str_replace(['R', 'r', ' ', ','], '', $val));
        if ($cleaned === '') {
            return null;
        }

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }
}
