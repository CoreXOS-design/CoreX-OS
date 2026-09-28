<?php

namespace App\Services\Rentals;

use App\Models\RentalInventory;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * §41-follow-up (Job 3, 2026-09-28) — the COMPLETED inventory's own signed
 * report: grouped by room, each line's quantity + description + capture-time
 * condition (§13's own vocabulary, distinct from §8's move-out disposition —
 * this report is the move-in record itself, not a comparison), a link to
 * the public page where the move-in photos live (same "no photos in the
 * PDF, a link instead" pattern RentalInspectionReportPdfService already
 * established, for the same reason — a 40-line, multi-photo-per-line
 * inventory would make the PDF unmanageable), and the recorded signatures.
 *
 * A completely different document from the move-in-vs-now comparison (§8/
 * §14, RentalInventoryComparisonService) — that screen is a live, read-time
 * proposal an agent reviews; this is the frozen, signed record of what was
 * true at completion, generated fresh on every request (never cached), same
 * as the inspection report's own generate() contract.
 */
class RentalInventoryReportPdfService
{
    public function generate(RentalInventory $inventory)
    {
        $inventory->loadMissing(['lines.room', 'lines.moveInPhotos', 'signatures.partyContact', 'property']);

        $linesByRoom = $inventory->lines
            ->sortBy(fn ($line) => [$line->room?->sort_order ?? PHP_INT_MAX, $line->room?->id ?? 0, $line->sort_order])
            ->groupBy(fn ($line) => $line->room?->label ?? ($line->room_label ?: 'General'));

        $publicUrl = $inventory->public_token
            ? route('rental-inventories.public.show', $inventory->public_token)
            : null;

        return Pdf::loadView('corex.rental-inventories.report-pdf', [
            'inventory' => $inventory,
            'linesByRoom' => $linesByRoom,
            'publicUrl' => $publicUrl,
        ])->setPaper('a4', 'portrait');
    }

    public function filenameFor(RentalInventory $inventory): string
    {
        $address = str($inventory->property?->buildDisplayAddress() ?? 'property')->slug();

        return "inventory-report-{$address}-{$inventory->id}.pdf";
    }
}
