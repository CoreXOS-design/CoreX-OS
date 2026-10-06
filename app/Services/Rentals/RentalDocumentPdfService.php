<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseTimelineService;
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

    /**
     * AT-440 — Lease Hub "Print tenancy report": parties, terms, lifecycle,
     * and the FULL tenancy log with dates. Reuses the exact same timeline/
     * lifecycle data the on-screen Lease Hub shows — one source of truth,
     * never a second computation for print vs. screen.
     */
    public function leaseTenancyReportPdf(Lease $lease)
    {
        $lease->loadMissing(['property', 'tenants.contact', 'escalations.createdByUser', 'branch', 'agency']);

        $timeline = app(LeaseTimelineService::class)->allEntriesFor($lease);
        $lifecycle = app(LeaseHubService::class)->lifecycle($lease);

        $pdf = Pdf::loadView('corex.leases.pdf.tenancy-report', [
            'lease' => $lease,
            'timeline' => $timeline,
            'lifecycle' => $lifecycle,
            'landlords' => $lease->landlordContacts(),
            'logo' => $this->logoDataUri($lease->branch?->logo_path, $lease->agency?->logo_path),
            'agencyName' => $lease->agency?->name ?: 'CoreX',
        ])->setPaper('a4', 'portrait');

        $this->applyOptions($pdf);

        return $pdf;
    }

    public function leaseTenancyReportFilename(Lease $lease): string
    {
        return $this->safeFilename('Tenancy Report - ' . $this->addressOrFallback($lease->property, 'Lease ' . $lease->id));
    }

    /** AT-442 req #5 — the quote PDF generated from a job card and sent to the owner. */
    public function jobCardQuotePdf(RentalJobCard $jobCard, ?int $revision = null)
    {
        // 2026-10-05 overnight re-verification — crew.members and
        // rentalFaultReport were missing from this eager load, so the quote
        // PDF could never show the crew or the fault-report/work-order
        // source reference the printable job card already shows.
        $jobCard->loadMissing(['property', 'lease.tenants.contact', 'tasks.lines.vatType', 'lines.vatType', 'crew.members', 'assignedUser', 'rentalFaultReport', 'workOrder.agency', 'workOrder.branch']);
        $workOrder = $jobCard->workOrder;

        $pdf = Pdf::loadView('corex.rental-job-cards.quote-pdf', [
            'jobCard' => $jobCard,
            // §14.21 — which revision this document is (null = not sent yet / unknown).
            'revision' => $revision,
            'pricesOn' => \App\Models\RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id),
            // Agency VAT set-up — called AFTER sendToOwnerAsQuote() has
            // already frozen the snapshot, so this reads the frozen figures,
            // never a live recompute that could drift from what was sent.
            'vat' => app(RentalJobCardVatService::class)->breakdown($jobCard),
            'vatNumber' => $workOrder?->agency?->vat_no,
            'logo' => $this->logoDataUri($workOrder?->branch?->logo_path, $workOrder?->agency?->logo_path),
            'agencyName' => $workOrder?->agency?->name ?: 'CoreX',
        ])->setPaper('a4', 'portrait');

        $this->applyOptions($pdf);

        return $pdf;
    }

    /** Req #6 — the printable job card: address, access notes, tenant contact, tasks, lines, sign-off lines. */
    public function jobCardPrintPdf(RentalJobCard $jobCard, ?string $crewLinkUrl = null, ?string $crewLinkExpires = null)
    {
        // 2026-10-05 overnight re-verification — rentalFaultReport was
        // missing, so the printable job card could never show which fault
        // report (if any) it came from, only the work order.
        $jobCard->loadMissing(['property', 'lease.tenants.contact', 'tasks.lines.vatType', 'lines.vatType', 'crew.members', 'assignedUser', 'rentalFaultReport', 'workOrder.agency', 'workOrder.branch']);
        $workOrder = $jobCard->workOrder;

        // AT-442 follow-up, conductor's ruling — the worker's printed copy
        // needs BOTH settings on to show a price: prices must be captured
        // at all (capture_prices_on_job_cards), AND the agency has chosen
        // to show them on this specific, worker-facing document
        // (show_prices_on_printed_job_card, default off). The owner quote
        // PDF (jobCardQuotePdf() above) is a different document for a
        // different audience and is never gated by the second setting.
        $pricesOn = \App\Models\RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id)
            && \App\Models\RentalWorkOrderSetting::showPricesOnPrintedJobCardFor($jobCard->agency_id);

        // §14.27.1 Q10 — only when "Print with link" minted one: the QR (pure-PHP
        // endroid/qr-code, no external call) that opens the crew's job link.
        $crewLinkQr = null;
        if ($crewLinkUrl) {
            $crewLinkQr = (new \Endroid\QrCode\Writer\PngWriter())->write(new \Endroid\QrCode\QrCode(
                data: $crewLinkUrl,
                errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::High,
                size: 220,
                margin: 6,
            ))->getDataUri();
        }

        $pdf = Pdf::loadView('corex.rental-job-cards.print', [
            'jobCard' => $jobCard,
            'crewLinkQr' => $crewLinkQr,
            'crewLinkExpires' => $crewLinkExpires,
            'pricesOn' => $pricesOn,
            'vat' => $pricesOn ? app(RentalJobCardVatService::class)->breakdown($jobCard) : ['registered' => false, 'pricesOn' => false, 'groups' => []],
            'vatNumber' => $workOrder?->agency?->vat_no,
            'logo' => $this->logoDataUri($workOrder?->branch?->logo_path, $workOrder?->agency?->logo_path),
            'agencyName' => $workOrder?->agency?->name ?: 'CoreX',
        ])->setPaper('a4', 'portrait');

        $this->applyOptions($pdf);

        return $pdf;
    }

    public function jobCardFilename(RentalJobCard $jobCard): string
    {
        return $this->safeFilename('Job Card - ' . $this->addressOrFallback($jobCard->property, 'Job Card ' . $jobCard->id));
    }

    /**
     * AT-445 — .ai/specs/rental-portal-access.md §8. The rendered notice
     * (breach / notice-to-vacate), already token-substituted by
     * RentalNoticeService::render(). One dompdf convention, not two.
     */
    public function noticePdf(\App\Models\RentalNotice $notice, string $renderedHtml)
    {
        $lease = $notice->lease;
        $lease?->loadMissing(['property', 'branch', 'agency']);

        $pdf = Pdf::loadView('corex.rental-notices.pdf', [
            'notice' => $notice,
            'bodyHtml' => $renderedHtml,
            'logo' => $this->logoDataUri($lease?->branch?->logo_path, $lease?->agency?->logo_path),
            'agencyName' => $lease?->agency?->name ?: 'CoreX',
        ])->setPaper('a4', 'portrait');

        $this->applyOptions($pdf);

        return $pdf;
    }

    public function noticeFilename(\App\Models\RentalNotice $notice): string
    {
        $lease = $notice->lease;

        return $this->safeFilename(ucfirst(str_replace('_', ' ', $notice->notice_type)) . ' - ' . $this->addressOrFallback($lease?->property, 'Lease ' . $notice->lease_id));
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
