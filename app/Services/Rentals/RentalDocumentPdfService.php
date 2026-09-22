<?php

namespace App\Services\Rentals;

use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Johan, 2026-09-22 — "printing," via the existing barryvdh/laravel-dompdf
 * pattern already proven at app/Services/Properties/PropertyBrochureService.php:230
 * (Pdf::loadView(...)->setPaper(...), remote/php disabled, shared font
 * cache dir). Two documents, both simple, fixed-length, data-driven pages —
 * deliberately NOT reusing PropertyBrochureService's own image-grid/QR/
 * shrink-to-fit machinery, which exists to solve a different problem (a
 * property's photo layout staying on one A4 page) this feature doesn't
 * have. What IS shared, on purpose, so there is one dompdf convention in
 * CoreX, not two: the disabled-remote/php options, the dpi, and the
 * writable font-cache directory.
 */
class RentalDocumentPdfService
{
    /** Work order for the supplier — §"Printing", situation named in the task. */
    public function workOrderPdf(RentalWorkOrder $workOrder)
    {
        $workOrder->loadMissing(['property', 'lease.tenants.contact', 'supplier', 'agency', 'branch', 'quotes.supplier']);

        $pdf = Pdf::loadView('corex.rental-work-orders.pdf', [
            'workOrder' => $workOrder,
            'logo' => $this->logoDataUri($workOrder->branch?->logo_path, $workOrder->agency?->logo_path),
            'agencyName' => $workOrder->agency?->name ?: 'CoreX',
        ])->setPaper('a4', 'portrait');

        $this->applyOptions($pdf);

        return $pdf;
    }

    public function workOrderFilename(RentalWorkOrder $workOrder): string
    {
        return $this->safeFilename('Work Order - ' . $this->addressOrFallback($workOrder->property, 'Work Order ' . $workOrder->id));
    }

    /** Fault report for the landlord — the other document named in the task. */
    public function faultReportPdf(RentalFaultReport $faultReport)
    {
        $faultReport->loadMissing(['property', 'lease.tenants.contact', 'agency', 'branch', 'photos']);

        $pdf = Pdf::loadView('corex.rental-fault-reports.pdf', [
            'faultReport' => $faultReport,
            'logo' => $this->logoDataUri($faultReport->branch?->logo_path, $faultReport->agency?->logo_path),
            'agencyName' => $faultReport->agency?->name ?: 'CoreX',
        ])->setPaper('a4', 'portrait');

        $this->applyOptions($pdf);

        return $pdf;
    }

    public function faultReportFilename(RentalFaultReport $faultReport): string
    {
        return $this->safeFilename('Fault Report - ' . $this->addressOrFallback($faultReport->property, 'Fault Report ' . $faultReport->id));
    }

    /** Same dompdf options as PropertyBrochureService::pdf() — one convention, not two. */
    private function applyOptions($pdf): void
    {
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('dpi', 96);

        $fontDir = storage_path('app/dompdf-fonts');
        if (! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }
        if (is_dir($fontDir) && is_writable($fontDir)) {
            $pdf->setOption('fontDir', $fontDir);
            $pdf->setOption('fontCache', $fontDir);
        }
    }

    /**
     * Branch logo, then agency logo, else null (the view falls back to the
     * agency name as a wordmark — never hardcoded to any one agency's own
     * branding). Deliberately simpler than PropertyBrochureService's own
     * logoSrc(): a single small logo needs no GD downscaling pass, only
     * CSS max-height, which dompdf handles directly from the raw bytes.
     */
    private function logoDataUri(?string $branchLogo, ?string $agencyLogo): ?string
    {
        foreach ([$branchLogo, $agencyLogo] as $candidate) {
            $rel = trim((string) $candidate);
            if ($rel === '') {
                continue;
            }
            $rel = preg_replace('#^(public/|storage/)#', '', ltrim($rel, '/'));

            try {
                $disk = Storage::disk('public');
                if ($disk->exists($rel)) {
                    $bytes = $disk->get($rel);
                    $mime = $disk->mimeType($rel) ?: 'image/png';

                    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function addressOrFallback(?\App\Models\Property $property, string $fallback): string
    {
        $address = $property?->buildDisplayAddress();

        return $address ?: $fallback;
    }

    private function safeFilename(string $name): string
    {
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|]+/', ' ', $name));
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        return $name . '.pdf';
    }
}
