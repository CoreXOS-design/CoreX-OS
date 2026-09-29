<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Services\Compliance\Concerns\GeneratesPdfViaPuppeteer;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack Phase B — items (c)/(f), .ai/specs/ppra-inspection-pack.md §6.6.
 * Renders the practitioner FFC register (name/designation/FFC status/expiry)
 * as a PDF for the "Export to PDF" button on /compliance/agents.
 */
class PpraPractitionerRegisterPdfService
{
    use GeneratesPdfViaPuppeteer;

    public function generate(Agency $agency, Collection $roster): string
    {
        $html = view('admin.ppra-inspection-pack.practitioner-register-pdf', [
            'agency' => $agency,
            'roster' => $roster,
        ])->render();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/ppra-practitioners-' . $agency->id . '-' . uniqid() . '.html';
        file_put_contents($htmlPath, $html);

        $pdfDir = storage_path('app/ppra-inspection-pack/' . $agency->id);
        if (! is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $pdfPath = $pdfDir . '/practitioner-register-' . now()->format('Ymd-His') . '.pdf';

        $this->invokePuppeteer($htmlPath, $pdfPath, 'practitioners-' . $agency->id);

        @unlink($htmlPath);

        return $pdfPath;
    }
}
