<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * AT-479 — reads ONE named sheet of a reOS report. Both reOS reports share a shape: a title on row 1, "Compiled: …" on
 * row 3, the headers on row 4 and the data from row 5. Empty rows are preserved so the index handed back is the REAL
 * spreadsheet row number (the review screen quotes it). Layout problems are a ReosLayoutException with a plain message.
 */
final class ReosWorkbookReader
{
    public const HEADER_ROW = 4;
    public const FIRST_DATA_ROW = 5;

    /**
     * @param list<string> $expectedHeaders  compared (normalised, in order) against the first N headers on row 4
     * @return \Generator<int, array<int, mixed>>  spreadsheet row number => cells, for row 4 onward (row 4 = the headers)
     */
    public static function rows(string $path, string $sheetName, array $expectedHeaders, string $reportLabel): \Generator
    {
        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new XlsxReader($options);

        try {
            $reader->open($path);
        } catch (\Throwable $e) {
            throw new ReosLayoutException("This does not look like an Excel (.xlsx) file, so it cannot be a reOS {$reportLabel}.");
        }

        try {
            $found = [];
            $target = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                $found[] = $sheet->getName();
                if (self::norm($sheet->getName()) === self::norm($sheetName)) {
                    $target = $sheet;
                    break;
                }
            }
            if ($target === null) {
                throw new ReosLayoutException("The sheet \"{$sheetName}\" was not found — this file has: " . ($found ? implode(', ', $found) : 'no sheets') . ". Upload the reOS {$reportLabel} export as it came out of reOS.");
            }

            $rowNumber = 0;
            $headersChecked = false;
            foreach ($target->getRowIterator() as $row) {
                $rowNumber++;
                if ($rowNumber < self::HEADER_ROW) {
                    continue;
                }
                $cells = $row->toArray();

                if (! $headersChecked) {
                    self::assertHeaders($cells, $expectedHeaders, $reportLabel);
                    $headersChecked = true;
                }

                yield $rowNumber => $cells;
            }

            if (! $headersChecked) {
                throw new ReosLayoutException("The sheet \"{$sheetName}\" has no header row (row " . self::HEADER_ROW . ') — it does not look like a reOS ' . $reportLabel . '.');
            }
        } finally {
            $reader->close();
        }
    }

    /** @param array<int,mixed> $cells */
    private static function assertHeaders(array $cells, array $expected, string $reportLabel): void
    {
        foreach ($expected as $i => $header) {
            $actual = trim((string) ($cells[$i] ?? ''));
            if (self::norm($actual) !== self::norm($header)) {
                $col = $i + 1;
                throw new ReosLayoutException(
                    "Row " . self::HEADER_ROW . " of the reOS {$reportLabel} should have \"{$header}\" in column {$col}, but it has "
                    . ($actual === '' ? 'nothing there' : "\"{$actual}\"") . '. The columns must be exactly as reOS exports them.'
                );
            }
        }
    }

    public static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
