<?php

namespace App\Services\Rentals;

use App\Models\RentalInspectionSetting;
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
    /**
     * Conductor brief 2026-09-29 — "print for signature": the SAME report
     * content generate() already builds, plus blank signature blocks for
     * every outstanding party. Mirrors RentalInspectionReportPdfService::
     * generateForSignature() exactly — see that method's own docblock.
     */
    public function generateForSignature(RentalInventory $inventory)
    {
        return $this->generate($inventory, forSignature: true);
    }

    public function generate(RentalInventory $inventory, bool $forSignature = false)
    {
        $inventory->loadMissing([
            'lines.room', 'lines.moveInPhotos', 'signatures.partyContact', 'signatures.recordedByUser', 'property',
            'roomMarks.room', 'lease.tenants.contact', 'createdBy',
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
        // text-only 'signed' PDF is not acceptable." Conductor brief
        // 2026-09-29 — now reads the full required roster via
        // RentalInventory::signatureSummaryRows() (the SAME resolver the
        // "print for signature" blank blocks need to know who's still
        // outstanding), not a raw filter over $inventory->signatures —
        // matches RentalInspectionReportPdfService's own signatureRows
        // shape exactly, including embedding wet-ink as text + a link
        // rather than an image (never presentable as an e-signature).
        // Signature image resolved via signature_image_src (audit M5) —
        // party_signature_path lives on the private disk now, never the
        // public-URL form StorageDataUri::fromPublicStoragePath() expects.
        $signatureRows = collect($inventory->signatureSummaryRows())->map(function (array $row) {
            $row['signature_image_data_uri'] = $row['signature']?->disposition === RentalInventorySignature::DISPOSITION_SIGNED
                ? $row['signature']->signature_image_src
                : null;

            return $row;
        })->all();

        return Pdf::loadView('corex.rental-inventories.report-pdf', [
            'inventory' => $inventory,
            'linesByRoom' => $linesByRoom,
            'emptyRoomLabels' => $emptyRoomLabels,
            'publicUrl' => $publicUrl,
            'qrDataUri' => $qrDataUri,
            'signatureRows' => $signatureRows,
            // §5 — inventory reuses inspections' own refusal-reason list
            // directly ("why didn't this party sign" is one concept, not
            // two lists for two documents) — same resolver
            // RentalInventoryController::show() already passes to the view.
            'refusalReasonLabels' => collect(RentalInspectionSetting::refusalReasonPresetsFor($inventory->agency_id))->pluck('label', 'key'),
            'forSignature' => $forSignature,
        ])->setPaper('a4', 'portrait');
    }

    public function filenameFor(RentalInventory $inventory): string
    {
        $address = str($inventory->property?->buildDisplayAddress() ?? 'property')->slug();

        return "inventory-report-{$address}-{$inventory->id}.pdf";
    }

    public function filenameForSignatureFor(RentalInventory $inventory): string
    {
        $address = str($inventory->property?->buildDisplayAddress() ?? 'property')->slug();

        return "inventory-for-signature-{$address}-{$inventory->id}.pdf";
    }
}
