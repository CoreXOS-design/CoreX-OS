<?php

namespace App\Console\Commands;

use App\Models\RentalInspection;
use App\Services\Distribution\SignedDocumentDistributionService;
use App\Services\Rentals\RentalInspectionReportPdfService;
use Illuminate\Console\Command;

/**
 * §41, 2026-09-28 — files every already-completed inspection's signed
 * report to its property, for inspections that completed before this
 * build existed (the automatic filing at complete() only covers
 * inspections completing FROM NOW ON). Idempotent — safe to re-run:
 * SignedDocumentDistributionService::fileToProperty() is itself keyed on
 * (source_type, source_id), so a second run against an already-filed
 * inspection is a no-op, not a duplicate.
 *
 * Deliberately NEVER sends email for a backfilled row — see this file's
 * own --dry-run output and docblock on emailParties(): auto-emailing
 * potentially years of historical tenants/landlords out of nowhere was
 * never asked for and would be a real, harmful surprise. Filing and the
 * public link only.
 */
class BackfillRentalInspectionReportFiling extends Command
{
    protected $signature = 'rental-inspections:backfill-report-filing {--agency=} {--dry-run}';

    protected $description = 'File every already-completed inspection\'s signed report to its property (idempotent, never emails).';

    public function handle(RentalInspectionReportPdfService $pdfService, SignedDocumentDistributionService $distributionService): int
    {
        $query = RentalInspection::withoutGlobalScopes()->where('status', RentalInspection::STATUS_COMPLETED);
        if ($agencyId = $this->option('agency')) {
            $query->where('agency_id', $agencyId);
        }

        $inspections = $query->get();
        $filed = 0;
        $alreadyFiled = 0;
        $errors = 0;

        foreach ($inspections as $inspection) {
            $existing = \App\Models\Document::where('source_type', $inspection->distributionSourceType())
                ->where('source_id', $inspection->distributionSourceId())
                ->exists();

            if ($existing) {
                $alreadyFiled++;
                continue;
            }

            if ($this->option('dry-run')) {
                $filed++;
                continue;
            }

            try {
                $distributionService->ensurePublicLink($inspection);
                $pdf = $pdfService->generate($inspection);
                $document = $distributionService->fileToProperty($inspection, $pdf->output(), $pdfService->filenameFor($inspection));
                $document ? $filed++ : $errors++;
            } catch (\Throwable $e) {
                $this->error("Inspection {$inspection->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->table(
            ['Total completed', $this->option('dry-run') ? 'Would file' : 'Filed', 'Already filed', 'Errors'],
            [[count($inspections), $filed, $alreadyFiled, $errors]],
        );

        return self::SUCCESS;
    }
}
