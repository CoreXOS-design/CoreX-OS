<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads ONE named sheet of a reOS report. The reports do not share a fixed layout: a title and a "Compiled" line sit on top, the
 * Rent Roll adds a "Date Range" line, the Accounts Receivable report has its headers on row 3 — so the header row is FOUND, not
 * assumed: the first 15 rows are scanned for the row that holds every expected header, and the data is the rows below it.
 *
 * Columns are found by their header text too, so extra columns, a shifted first column or a different order do not matter: the
 * cells handed to a parser are always in the order of the expected headers. Empty rows are preserved so a row's index is its
 * REAL spreadsheet row number (the review screen quotes it). A file that is not the report is a ReosLayoutException whose message
 * names the sheet / headers that were looked for.
 */
final class ReosWorkbookReader
{
    /** How far down the sheet the header row may be. */
    public const SCAN_ROWS = 15;

    /**
     * Where the header row is, and which sheet column holds each expected header.
     *
     * @param list<string> $expectedHeaders
     * @return array{header_row:int, columns:list<int>}  columns[j] = sheet column index of expectedHeaders[j]
     * @throws ReosLayoutException
     */
    public static function locate(string $path, string $sheetName, array $expectedHeaders, string $reportLabel): array
    {
        $reader = self::open($path, $reportLabel);

        try {
            $sheet = self::findSheet($reader, $sheetName, $reportLabel);
            $rowNumber = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                if ($rowNumber > self::SCAN_ROWS) {
                    break;
                }
                $columns = self::columnsFor($row->toArray(), $expectedHeaders);
                if ($columns !== null) {
                    return ['header_row' => $rowNumber, 'columns' => $columns];
                }
            }
        } finally {
            $reader->close();
        }

        throw new ReosLayoutException(
            "The header row of the reOS {$reportLabel} was not found in the first " . self::SCAN_ROWS . " rows of the sheet \"{$sheetName}\". "
            . 'It should have these columns: ' . implode(', ', $expectedHeaders) . '. Upload the report exactly as it came out of reOS.'
        );
    }

    /**
     * The data rows (everything below the header row), each re-ordered to match the expected headers.
     *
     * @param array{header_row:int, columns:list<int>} $where  from locate()
     * @return \Generator<int, array<int, mixed>>  spreadsheet row number => cells in expected-header order
     */
    public static function dataRows(string $path, string $sheetName, array $where, string $reportLabel): \Generator
    {
        $reader = self::open($path, $reportLabel);

        try {
            $sheet = self::findSheet($reader, $sheetName, $reportLabel);
            $rowNumber = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                if ($rowNumber <= $where['header_row']) {
                    continue;
                }
                $cells = $row->toArray();
                $ordered = [];
                foreach ($where['columns'] as $j => $col) {
                    $ordered[$j] = $cells[$col] ?? null;
                }
                yield $rowNumber => $ordered;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * A row is the header row when it holds every expected header (compared normalised). Returns the sheet column of each, or null.
     *
     * @param array<int,mixed> $cells
     * @param list<string> $expected
     * @return list<int>|null
     */
    private static function columnsFor(array $cells, array $expected): ?array
    {
        $byText = [];
        foreach ($cells as $col => $cell) {
            $text = self::norm((string) $cell);
            if ($text !== '' && ! isset($byText[$text])) {
                $byText[$text] = (int) $col;   // the first column carrying a heading wins
            }
        }
        $columns = [];
        foreach ($expected as $header) {
            $key = self::norm($header);
            if (! isset($byText[$key])) {
                return null;
            }
            $columns[] = $byText[$key];
        }

        return $columns;
    }

    private static function open(string $path, string $reportLabel): XlsxReader
    {
        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new XlsxReader($options);

        try {
            $reader->open($path);
        } catch (\Throwable $e) {
            throw new ReosLayoutException("This does not look like an Excel (.xlsx) file, so it cannot be a reOS {$reportLabel}.");
        }

        return $reader;
    }

    private static function findSheet(XlsxReader $reader, string $sheetName, string $reportLabel): \OpenSpout\Reader\XLSX\Sheet
    {
        $found = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $found[] = $sheet->getName();
            if (self::norm($sheet->getName()) === self::norm($sheetName)) {
                return $sheet;
            }
        }
        $reader->close();

        throw new ReosLayoutException("The sheet \"{$sheetName}\" was not found — this file has: " . ($found ? implode(', ', $found) : 'no sheets') . ". Upload the reOS {$reportLabel} export as it came out of reOS.");
    }

    public static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
