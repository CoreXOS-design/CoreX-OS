<?php

namespace App\Services\Rentals;

use App\Models\RentalInventory;
use App\Models\RentalInventorySignature;
use App\Support\StorageDataUri;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

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
        $inventory->loadMissing([
            'lines.room', 'lines.moveInPhotos', 'signatures.partyContact', 'signatures.recordedByUser', 'property',
            'roomMarks.room',
        ]);

        $linesByRoom = $inventory->lines
            ->sortBy(fn ($line) => [$line->room?->sort_order ?? PHP_INT_MAX, $line->room?->id ?? 0, $line->sort_order])
            ->groupBy(fn ($line) => $line->room?->label ?? ($line->room_label ?: 'General'));

        // Report-fixes, 2026-09-28 (Johan) — a room explicitly marked
        // "nothing in this room" (RentalInventoryRoomMark, §12) has no
        // lines, so it never appeared in $linesByRoom at all — the printed
        // record silently looked identical to a room nobody ever checked,
        // exactly the distinction this mark exists to prove. Every marked
        // room with no lines of its own gets its own section, in the same
        // room order, so the signed record accounts for every room the
        // completion gate itself required to be visited.
        $emptyRoomLabels = $inventory->roomMarks
            ->reject(fn ($mark) => $linesByRoom->has($mark->room?->label))
            ->sortBy(fn ($mark) => [$mark->room?->sort_order ?? PHP_INT_MAX, $mark->room?->id ?? 0])
            ->pluck('room.label', 'room.label')
            ->filter();

        $publicUrl = $inventory->public_token
            ? route('rental-inventories.public.show', $inventory->public_token)
            : null;

        $qrDataUri = null;
        if ($publicUrl) {
            $qrCode = new QrCode(
                data: $publicUrl,
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: 240,
                margin: 8,
            );
            $qrDataUri = (new PngWriter())->write($qrCode)->getDataUri();
        }

        // Johan, 2026-09-28 — "the PDF is the signed record that gets
        // auto-emailed... it must carry the actual signature images. A
        // text-only 'signed' PDF is not acceptable." Inventory has no
        // wet-ink disposition, so unlike the inspection PDF this is a
        // straight "signed → embed" map, keyed by signature id (this
        // view reads $inventory->signatures directly, not a shared
        // signatureSummaryRows()-style resolver).
        $signatureImages = $inventory->signatures
            ->filter(fn (RentalInventorySignature $s) => $s->disposition === RentalInventorySignature::DISPOSITION_SIGNED)
            ->mapWithKeys(fn (RentalInventorySignature $s) => [$s->id => StorageDataUri::fromPublicStoragePath($s->party_signature_path)]);

        return Pdf::loadView('corex.rental-inventories.report-pdf', [
            'inventory' => $inventory,
            'linesByRoom' => $linesByRoom,
            'emptyRoomLabels' => $emptyRoomLabels,
            'publicUrl' => $publicUrl,
            'qrDataUri' => $qrDataUri,
            'signatureImages' => $signatureImages,
        ])->setPaper('a4', 'portrait');
    }

    public function filenameFor(RentalInventory $inventory): string
    {
        $address = str($inventory->property?->buildDisplayAddress() ?? 'property')->slug();

        return "inventory-report-{$address}-{$inventory->id}.pdf";
    }
}
