<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\AgencyTransformationNote;
use App\Models\Compliance\PpraInspectionPack;
use App\Models\Deal;
use App\Models\User;
use App\Services\Compliance\Concerns\GeneratesPdfViaPuppeteer;

/**
 * PPRA Inspection Pack — the Inspection Report (the primary deliverable).
 * .ai/specs/ppra-inspection-pack.md §6.2.
 *
 * Renders synchronously (no queue — pure querying + rendering, no large
 * file bundling) via the same Puppeteer HTML-to-PDF pipeline every other
 * PDF-producing module in CoreX uses (scripts/html-to-pdf.mjs) — see
 * WhistleblowComplaintService::generatePdf()/invokePuppeteer() for the
 * sibling implementation this one mirrors; there is no shared cross-module
 * PDF service in this codebase, every module owns its own thin wrapper.
 */
class PpraInspectionReportPdfService
{
    use GeneratesPdfViaPuppeteer;

    public function __construct(
        private PpraInspectionPackChecklistService $checklist = new PpraInspectionPackChecklistService(),
        private PractitionerFfcRosterService $practitionerRoster = new PractitionerFfcRosterService(),
        private PpraSalesFileAggregationService $salesFileAggregation = new PpraSalesFileAggregationService(),
    ) {
    }

    /**
     * Render the Inspection Report and return the path to the generated PDF.
     */
    public function generate(Agency $agency, User $generatedBy): string
    {
        $rows = $this->checklist->checklistFor($agency);
        $gaps = $rows->whereIn('status', ['amber', 'red'])->values();
        $roster = $this->practitionerRoster->rosterFor($agency->id);
        $principals = $this->practitionerRoster->principalsFor($agency->id);
        $transformation = AgencyTransformationNote::currentFor($agency->id);
        $kSample = $this->salesFileSample($agency);

        $reportReference = 'PPRA-' . $agency->id . '-' . now()->format('Ymd-Hi');

        $html = view('admin.ppra-inspection-pack.report', [
            'agency'          => $agency,
            'rows'            => $rows,
            'gaps'            => $gaps,
            'roster'          => $roster,
            'principals'      => $principals,
            'transformation'  => $transformation,
            'kSample'         => $kSample,
            'reportReference' => $reportReference,
            'generatedBy'     => $generatedBy,
            'generatedAt'     => now(),
        ])->render();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/ppra-report-' . $agency->id . '-' . uniqid() . '.html';
        file_put_contents($htmlPath, $html);

        $pdfDir = storage_path('app/ppra-inspection-pack/' . $agency->id);
        if (! is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $pdfPath = $pdfDir . '/' . $reportReference . '.pdf';

        $this->invokePuppeteer($htmlPath, $pdfPath, 'report-' . $agency->id);

        @unlink($htmlPath);

        return $pdfPath;
    }

    /**
     * Item k's per-file index (§6.2 item 5 / §6.8b) — one manifest per
     * currently-sampled deal, from whichever DRAFT pack the picker (§6.8a)
     * has saved a selection onto. Empty array (not null) when nothing has
     * been sampled yet, so the Report can print "No sales files sampled
     * for this pack" (§9 edge case) rather than a missing section.
     *
     * @return array<int, object>
     */
    private function salesFileSample(Agency $agency): array
    {
        $draft = PpraInspectionPack::currentDraftFor($agency);
        $ids = $draft?->sample_deal_ids ?? [];

        if (empty($ids)) {
            return [];
        }

        $deals = Deal::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn ($id) => $deals->get($id))
            ->filter()
            ->map(fn (Deal $deal) => $this->salesFileAggregation->aggregate($deal))
            ->values()
            ->all();
    }
}
