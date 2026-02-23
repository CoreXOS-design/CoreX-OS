<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Services\Presentations\PresentationPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    /**
     * Serve the pack HTML inline in the browser for viewing/printing.
     *
     * GET /presentations/{presentation}/versions/{version}/pdf
     */
    public function download(Request $request, Presentation $presentation, PresentationVersion $version): Response
    {
        abort_unless(config('features.presentation_pdf_v1', false), 404);

        // Ensure the version belongs to this presentation
        abort_if($version->presentation_id !== $presentation->id, 404);

        $path = $this->pdfService->storagePath($version);

        // Regenerate if missing
        if (!Storage::disk(PresentationPdfService::STORAGE_DISK)->exists($path)) {
            $path = $this->pdfService->generate($version);
        }

        $html = Storage::disk(PresentationPdfService::STORAGE_DISK)->get($path);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Download a ZIP containing the HTML pack + original CMA/evidence PDFs.
     *
     * GET /presentations/{presentation}/versions/{version}/complete-pack
     */
    public function downloadCompletePack(Request $request, Presentation $presentation, PresentationVersion $version): BinaryFileResponse
    {
        abort_unless(config('features.presentation_pdf_v1', false), 404);
        abort_if($version->presentation_id !== $presentation->id, 404);

        // Ensure HTML pack exists
        $htmlPath = $this->pdfService->storagePath($version);
        if (!Storage::disk(PresentationPdfService::STORAGE_DISK)->exists($htmlPath)) {
            $htmlPath = $this->pdfService->generate($version);
        }

        // Build ZIP
        $zipName = 'Market_Analysis_Pack_' . $presentation->id . '_v' . $version->id . '.zip';
        $zipPath = storage_path('app/' . $zipName);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Unable to create ZIP archive.');
        }

        // Add HTML report
        $htmlFullPath = Storage::disk(PresentationPdfService::STORAGE_DISK)->path($htmlPath);
        $zip->addFile($htmlFullPath, 'Market_Analysis_Report.html');

        // Add uploaded CMA/evidence PDFs
        $uploads = $presentation->uploads()
            ->whereIn('type', ['cma', 'suburb_stats', 'vicinity_sales'])
            ->get();

        $counter = 1;
        foreach ($uploads as $upload) {
            if (!$upload->storage_path) continue;
            $fullPath = Storage::disk('local')->path($upload->storage_path);
            if (!file_exists($fullPath)) continue;

            $ext = pathinfo($upload->original_filename ?? 'document.pdf', PATHINFO_EXTENSION) ?: 'pdf';
            $label = ucfirst(str_replace('_', ' ', $upload->type));
            $zip->addFile($fullPath, sprintf('%02d_%s.%s', $counter, $label, $ext));
            $counter++;
        }

        $zip->close();

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }
}
