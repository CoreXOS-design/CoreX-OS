<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Services\Compliance\Concerns\GeneratesPdfViaPuppeteer;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack Phase E — item (j), .ai/specs/ppra-inspection-pack.md §6.7.
 * Renders the current-FY "active and advertised" sales/rentals split as a PDF.
 */
class PpraSalesRentalsPdfService
{
    use GeneratesPdfViaPuppeteer;

    public function generate(Agency $agency, Collection $sales, Collection $rentals, string $rangeLabel): string
    {
        $html = view('admin.ppra-inspection-pack.sales-rentals-pdf', [
            'agency'     => $agency,
            'sales'      => $sales,
            'rentals'    => $rentals,
            'rangeLabel' => $rangeLabel,
        ])->render();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/ppra-sales-rentals-' . $agency->id . '-' . uniqid() . '.html';
        file_put_contents($htmlPath, $html);

        $pdfDir = storage_path('app/ppra-inspection-pack/' . $agency->id);
        if (! is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $pdfPath = $pdfDir . '/sales-rentals-' . now()->format('Ymd-His') . '.pdf';

        $this->invokePuppeteer($htmlPath, $pdfPath, 'sales-rentals-' . $agency->id);

        @unlink($htmlPath);

        return $pdfPath;
    }
}
