<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Services\Presentations\PresentationPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Generate (or regenerate) the pack file and stream it to the browser.
     *
     * GET /presentations/{presentation}/versions/{version}/pdf
     */
    public function download(Request $request, Presentation $presentation, PresentationVersion $version): StreamedResponse
    {
        abort_unless(config('features.presentation_pdf_v1', false), 404);

        // Ensure the version belongs to this presentation
        abort_if($version->presentation_id !== $presentation->id, 404);

        $path = $this->pdfService->storagePath($version);

        // Regenerate if missing
        if (!Storage::disk(PresentationPdfService::STORAGE_DISK)->exists($path)) {
            $path = $this->pdfService->generate($version);
        }

        $filename = sprintf(
            'presentation-%d-v%d.html',
            $presentation->id,
            $version->id,
        );

        return Storage::disk(PresentationPdfService::STORAGE_DISK)->download($path, $filename, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
