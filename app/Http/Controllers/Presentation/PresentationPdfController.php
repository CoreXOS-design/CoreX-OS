<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Services\PermissionService;
use App\Services\Presentations\PresentationPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the HTML presentation pack for a compiled version (P18).
 *
 * Feature-flagged via config('features.presentation_pdf_v1').
 * When the flag is off the route returns 404 so the UI can hide the button.
 */
class PresentationPdfController extends Controller
{
    public function __construct(private readonly PresentationPdfService $pdfService) {}

    private function authorizePresentation(Presentation $presentation): void
    {
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'presentations');
        if ($scope === 'all') return;
        if ($scope === 'branch' && (int) $presentation->branch_id === (int) $user->effectiveBranchId()) return;
        if ($scope === 'own' && (int) $presentation->created_by_user_id === (int) $user->id) return;
        abort(403);
    }

    /**
     * Generate and download the market analysis as a real PDF file.
     *
     * GET /presentations/{presentation}/versions/{version}/pdf
     */
    public function download(Request $request, Presentation $presentation, PresentationVersion $version): BinaryFileResponse
    {
        $this->authorizePresentation($presentation);
        abort_unless(config('features.presentation_pdf_v1', false), 404);

        // Ensure the version belongs to this presentation
        abort_if($version->presentation_id !== $presentation->id, 404);

        // Always regenerate HTML to ensure latest data/sections are included
        $htmlStoragePath = $this->pdfService->generate($version);

        // Convert HTML to PDF via headless Edge/Chromium
        $htmlFullPath = Storage::disk(PresentationPdfService::STORAGE_DISK)->path($htmlStoragePath);
        $pdfPath = $this->convertHtmlToPdf($htmlFullPath);

        // Build a clean filename from the property address
        $address = $presentation->property_address ?? $presentation->suburb ?? 'Property';
        $cleanAddress = preg_replace('/[^A-Za-z0-9_ -]/', '', $address);
        $cleanAddress = str_replace(' ', '_', trim(substr($cleanAddress, 0, 60)));
        $filename = 'Market_Analysis_' . $cleanAddress . '.pdf';

        return response()->download($pdfPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Download a ZIP containing the HTML pack + original CMA/evidence PDFs.
     *
     * GET /presentations/{presentation}/versions/{version}/complete-pack
     */
    public function downloadCompletePack(Request $request, Presentation $presentation, PresentationVersion $version): BinaryFileResponse
    {
        $this->authorizePresentation($presentation);
        abort_unless(config('features.presentation_pdf_v1', false), 404);
        abort_if($version->presentation_id !== $presentation->id, 404);

        // Ensure HTML pack exists
        $htmlPath = $this->pdfService->storagePath($version);
        if (!Storage::disk(PresentationPdfService::STORAGE_DISK)->exists($htmlPath)) {
            $htmlPath = $this->pdfService->generate($version);
        }

        // Clean address for filename
        $address = $presentation->property_address ?? $presentation->suburb ?? 'Property';
        $cleanAddress = preg_replace('/[^A-Za-z0-9_ -]/', '', $address);
        $cleanAddress = trim(substr($cleanAddress, 0, 60));

        // Build ZIP
        $zipName = 'Market_Analysis_Pack_' . $presentation->id . '_v' . $version->id . '.zip';
        $zipPath = storage_path('app/' . $zipName);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Unable to create ZIP archive.');
        }

        $counter = 1;

        // 1. Market analysis report as PDF (rendered via headless Edge/Chromium)
        $htmlFullPath = Storage::disk(PresentationPdfService::STORAGE_DISK)->path($htmlPath);
        $pdfReportPath = $this->convertHtmlToPdf($htmlFullPath);
        $zip->addFile($pdfReportPath, sprintf('%02d_Market_Analysis_%s.pdf', $counter, $cleanAddress));
        $counter++;

        // 2. Uploaded CMA/evidence PDFs in defined order
        $typeOrder = ['suburb_stats', 'vicinity_sales', 'cma'];
        $typeLabels = [
            'suburb_stats'   => 'Suburb_Report',
            'vicinity_sales' => 'Vicinity_Sales',
            'cma'            => 'CMA_Valuation',
        ];

        foreach ($typeOrder as $type) {
            $uploads = $presentation->uploads()->where('type', $type)->get();
            foreach ($uploads as $upload) {
                if (!$upload->storage_path) continue;
                $fullPath = Storage::disk('local')->path($upload->storage_path);
                if (!file_exists($fullPath)) continue;

                $ext = pathinfo($upload->original_filename ?? 'document.pdf', PATHINFO_EXTENSION) ?: 'pdf';
                $label = $typeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type));
                $zip->addFile($fullPath, sprintf('%02d_%s.%s', $counter, $label, $ext));
                $counter++;
            }
        }

        // 3. Document library items
        $docLibraryItems = $presentation->documentLibraryItems;
        foreach ($docLibraryItems as $docItem) {
            $storedPath = $docItem->stored_path ?? null;
            if (!$storedPath) continue;
            $fullPath = Storage::disk('local')->path($storedPath);
            if (!file_exists($fullPath)) continue;

            $originalName = $docItem->original_name ?? $docItem->title ?? 'Document';
            // Sanitize filename
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            $ext = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'pdf';
            $zip->addFile($fullPath, sprintf('%02d_%s.%s', $counter, $safeName, $ext));
            $counter++;
        }

        $zip->close();

        // Clean up temp PDF file after ZIP is built
        if (isset($pdfReportPath) && file_exists($pdfReportPath)) {
            @unlink($pdfReportPath);
        }

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }

    /**
     * Convert a self-contained HTML file to PDF using headless Edge (Chromium)
     * via puppeteer-core. Produces output identical to Ctrl+P → Save as PDF.
     *
     * Returns the path to the generated temporary PDF file.
     */
    private function convertHtmlToPdf(string $htmlFilePath): string
    {
        $tmpPath = storage_path('app/tmp_market_analysis_' . uniqid() . '.pdf');
        $scriptPath = base_path('scripts/html-to-pdf.mjs');

        $wrapper = config('services.pdf.node_wrapper', '');
        $browserPath = config('services.pdf.puppeteer_browser_path', '');
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        $scriptArg = escapeshellarg(str_replace('\\', '/', $scriptPath));
        $htmlArg   = escapeshellarg(str_replace('\\', '/', $htmlFilePath));
        $outArg    = escapeshellarg(str_replace('\\', '/', $tmpPath));

        if ($wrapper) {
            // Server mode: use sudo wrapper script (handles env, browser path, etc.)
            $command = sprintf('sudo %s %s %s %s 2>&1', escapeshellarg($wrapper), $scriptArg, $htmlArg, $outArg);
        } else {
            // Local dev mode
            $envPrefix = '';
            if (!$isWindows) {
                $envPrefix = 'HOME=/tmp';
                if ($browserPath) {
                    $envPrefix .= sprintf(' PUPPETEER_BROWSER_PATH=%s', escapeshellarg($browserPath));
                }
                $envPrefix .= ' ';
            }
            $command = sprintf('%snode %s %s %s 2>&1', $envPrefix, $scriptArg, $htmlArg, $outArg);
        }

        $output = shell_exec($command);

        if (!file_exists($tmpPath)) {
            $errorMsg = $output ?: 'unknown error';
            logger()->error('PDF generation failed', ['command' => $command, 'output' => $errorMsg]);
            abort(500, 'PDF generation failed. Check that Node.js and a Chromium-based browser are installed.');
        }

        return $tmpPath;
    }
}
