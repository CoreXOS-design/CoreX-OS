<?php

namespace App\Services\Proforma;

use App\Models\Agency;
use App\Models\Proforma\AgencyProformaSettings;
use App\Models\Proforma\ProformaInvoice;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a ProformaInvoice record to a single-page A4 PDF via dompdf (synchronous,
 * in-process bytes — no Chromium/node dependency). Keep the Blade dompdf-safe:
 * tables + inline styles, no CSS grid/flex.
 */
class ProformaPdfRenderer
{
    /** Render to raw PDF bytes. */
    public function render(ProformaInvoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'deal']);
        $agency   = Agency::withoutGlobalScopes()->find($invoice->agency_id);
        $settings = AgencyProformaSettings::forAgency((int) $invoice->agency_id);

        $data = [
            'invoice'  => $invoice,
            'agency'   => $agency,
            'settings' => $settings,
            'logoData' => $this->logoDataUri($agency),
        ];

        return (string) Pdf::setOptions([
            'isRemoteEnabled' => false,   // never fetch remote assets from a document
            'isPhpEnabled'    => false,
            'dpi'             => 96,
            'defaultFont'     => 'DejaVu Sans',
        ])->loadView('proforma.pdf', $data)
          ->setPaper('a4', 'portrait')
          ->output();
    }

    /** Suggested filename for the filed/emailed PDF. */
    public function filename(ProformaInvoice $invoice): string
    {
        return 'Proforma-' . str_replace(['/', ' '], '-', $invoice->number) . '.pdf';
    }

    /**
     * Inline the agency logo as a data URI so dompdf (isRemoteEnabled=false) can render
     * it without a network/filesystem fetch. Returns null if there is no readable logo.
     */
    private function logoDataUri(?Agency $agency): ?string
    {
        $path = $agency?->logo_path;
        if (! $path) {
            return null;
        }
        $full = storage_path('app/public/' . ltrim($path, '/'));
        if (! is_file($full) || ! is_readable($full)) {
            return null;
        }
        $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
            'png'        => 'image/png',
            'webp'       => 'image/webp',
            'gif'        => 'image/gif',
            default      => 'image/jpeg',
        };
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($full));
    }
}
