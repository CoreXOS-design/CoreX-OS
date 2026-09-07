<?php

namespace App\Services\RentalApplications;

use App\Http\Controllers\Docuperfect\SigningController;
use App\Models\RentalApplication;

/**
 * AT-392 — renders a RentalApplication to PDF for the "download, complete,
 * scan, return" route (spec §4a) and for the agent's own reference copy.
 *
 * Reuses the SAME Puppeteer HTML->PDF pipeline as e-sign
 * (SigningController::generatePdfFromHtml() / wrapHtmlForPdf()) rather than
 * inventing a second renderer — this is a static fill, not an e-sign
 * document, so it deliberately does NOT go anywhere near SignatureTemplate.
 */
class RentalApplicationPdfService
{
    public function __construct(private SigningController $signingController) {}

    /**
     * @return string Absolute path to a temp PDF file. Caller is responsible
     *                 for the file's lifetime (the controller streams it with
     *                 deleteFileAfterSend).
     */
    public function generate(RentalApplication $rentalApplication): string
    {
        $rentalApplication->loadMissing(['contact', 'property', 'signatures']);

        $html = view('corex.rental-applications.pdf', [
            'application' => $rentalApplication,
            'agency' => $rentalApplication->agency,
            'branch' => $rentalApplication->branch,
        ])->render();

        // generatePdfFromHtml() wraps the shell (fonts, print CSS) itself
        // internally via wrapHtmlForPdf() — pass the raw body HTML, not
        // pre-wrapped, or the document shell doubles up.
        $path = $this->signingController->generatePdfFromHtml($html, $rentalApplication->id);

        if (! $path) {
            throw new \RuntimeException('Rental application PDF generation failed for id ' . $rentalApplication->id);
        }

        return $path;
    }
}
