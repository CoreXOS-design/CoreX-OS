<?php

namespace App\Http\Controllers\Concerns;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * .ai/specs/rentals-rebuild.md §1.1 — the shared rental list standard's
 * Export control. One streaming implementation (xlsx via OpenSpout, same
 * row-at-a-time writer {@see \App\Http\Controllers\CoreX\ContactExportController}
 * already uses for flat memory usage; csv via a plain streamed response),
 * reused by every rentals list controller's own export() action instead of
 * four near-identical copies of writer boilerplate.
 *
 * Callers supply the header row and an iterable of already-scoped,
 * already-filtered row arrays — this trait has no knowledge of any
 * entity's own columns or scoping.
 */
trait ExportsRentalList
{
    private function streamRentalListXlsx(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($headers, $rows) {
            $tmp = tempnam(sys_get_temp_dir(), 'rlx');
            $writer = new Writer();
            $writer->openToFile($tmp);

            try {
                $writer->addRow(Row::fromValues($headers));
                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
            } finally {
                $writer->close();
            }

            readfile($tmp);
            @unlink($tmp);
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0, no-store, no-cache, must-revalidate',
        ]);
    }

    private function streamRentalListCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0, no-store, no-cache, must-revalidate',
        ]);
    }
}
