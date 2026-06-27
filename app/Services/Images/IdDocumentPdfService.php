<?php

namespace App\Services\Images;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;

/**
 * Combines a front and back ID photo into a single, two-page PDF so an ID Copy
 * captured as two phone photos still lands as ONE document — preserving the
 * `id_copy` document_type keying (latest-per-type) the portal relies on and
 * giving verifiers a single file to review.
 *
 * dompdf is pure PHP (no imagick), so this runs identically on local XAMPP CLI
 * and on the server. Images are embedded as base64 data URIs — no remote fetch.
 */
class IdDocumentPdfService
{
    public function combine(UploadedFile $front, UploadedFile $back): string
    {
        $html = view('agent.documents.id-photo-pdf', [
            'front' => $this->toDataUri($front),
            'back'  => $this->toDataUri($back),
        ])->render();

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isHtml5ParserEnabled', true);

        return $pdf->output();
    }

    private function toDataUri(UploadedFile $file): string
    {
        $mime   = $file->getMimeType() ?: 'image/jpeg';
        $base64 = base64_encode(file_get_contents($file->getRealPath()));

        return "data:{$mime};base64,{$base64}";
    }
}
