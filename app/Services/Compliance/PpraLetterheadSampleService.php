<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Services\Compliance\Concerns\GeneratesPdfViaPuppeteer;

/**
 * PPRA Inspection Pack Phase C (v3) — item (g), .ai/specs/ppra-inspection-pack.md §6.6b.
 *
 * Generates a one-page, genuinely PLAIN letterhead PDF from the same
 * agency-branding fields BaseSignatureMail::getAgentFooter() already reads
 * (logo, PPRA number cascade, disclaimer, POPI URL) — a blank, usable
 * template, not a demo page. v3 correction: Phase B's version carried a
 * "SAMPLE" watermark and explanatory body copy; both are removed.
 */
class PpraLetterheadSampleService
{
    use GeneratesPdfViaPuppeteer;

    public function generate(Agency $agency): string
    {
        $html = view('admin.ppra-inspection-pack.letterhead-sample', [
            'agency' => $agency,
        ])->render();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/ppra-letterhead-' . $agency->id . '-' . uniqid() . '.html';
        file_put_contents($htmlPath, $html);

        $pdfDir = storage_path('app/ppra-inspection-pack/' . $agency->id);
        if (! is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $pdfPath = $pdfDir . '/letterhead-' . now()->format('Ymd-His') . '.pdf';

        $this->invokePuppeteer($htmlPath, $pdfPath, 'letterhead-' . $agency->id);

        @unlink($htmlPath);

        return $pdfPath;
    }
}
