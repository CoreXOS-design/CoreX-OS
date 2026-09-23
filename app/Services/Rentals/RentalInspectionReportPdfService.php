<?php

namespace App\Services\Rentals;

use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Johan, 2026-09-23, approved — the COMPLETED inspection's own report: no
 * photos ("printing the photos will be a shitshow... the inspection
 * reports will turn into 100 pages"), a QR code + clickable link to the
 * public page where the photos live instead. Two columns always —
 * previous vs current — never one column per link in the chain: a chain
 * of four+ inspections would make the table unreadable on A4. The full
 * run per item ("Good, Good, Damaged") still reaches paper, folded into
 * the current-value cell as compact text, never as extra columns.
 *
 * A completely different document from RentalInspectionFormPdfService
 * (the BLANK OMR capture form, generated BEFORE an inspection happens) —
 * same DomPDF machinery, unrelated purpose. Never cached/persisted the
 * way that service's output is (no OMR manifest to keep stable across
 * downloads) — generated fresh every time, streamed straight to the
 * agent.
 *
 * QR CODE — NOT YET DRAWN. No barcode/QR library exists in this
 * codebase (checked composer.json/package.json directly) and Johan's own
 * standing instruction on this exact feature area is "use what's there
 * or stop and ask" (RentalInspectionFormPdfService's own docblock,
 * verbatim). The link itself IS fully functional and printed prominently
 * on every page that needs one — only the scannable graphic is deferred,
 * pending Johan naming a library to add.
 */
class RentalInspectionReportPdfService
{
    public function generate(RentalInspection $inspection)
    {
        $items = RentalInspectionItem::where('property_id', $inspection->property_id)
            ->where('is_retired', false)
            ->with('room')
            ->get();

        $currentByItem = $inspection->observations->groupBy('rental_inspection_item_id')
            ->map(fn ($group) => $group->sortByDesc('created_at')->first());

        $rows = $items
            ->map(function (RentalInspectionItem $item) use ($inspection, $currentByItem) {
                $current = $currentByItem->get($item->id);
                // historyFor() must be called ON the predecessor, not on
                // $inspection itself — $inspection->historyFor($item) walks
                // starting at $inspection and its own observation would be
                // the run's own last entry, making "previous" read back as
                // the CURRENT value. §6 — no predecessor (first inspection
                // in a chain) means an empty run, never an error.
                $history = $inspection->previousInspection?->historyFor($item) ?? collect();
                if (! $current && $history->isEmpty()) {
                    return null;
                }

                return (object) [
                    'item' => $item,
                    'room' => $item->room,
                    'current' => $current,
                    // The immediate predecessor's value is the run's own
                    // last entry (never re-derived separately) — null when
                    // this is the first inspection in its chain.
                    'previous' => $history->last(),
                    'history_text' => $history->map(fn ($h) => ucfirst($h->observation->condition))->implode(' → '),
                ];
            })
            ->filter()
            ->groupBy(fn ($row) => $row->room?->id ?? 'general');

        $publicUrl = $inspection->public_token
            ? route('rental-inspections.public.show', $inspection->public_token)
            : null;

        return Pdf::loadView('corex.rental-inspections.report-pdf', [
            'inspection' => $inspection,
            'rows' => $rows,
            'publicUrl' => $publicUrl,
        ])->setPaper('a4', 'portrait');
    }

    public function filenameFor(RentalInspection $inspection): string
    {
        $address = str($inspection->property?->buildDisplayAddress() ?? 'property')->slug();

        return "inspection-report-{$inspection->type}-{$address}-{$inspection->id}.pdf";
    }
}
