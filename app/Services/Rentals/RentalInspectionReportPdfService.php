<?php

namespace App\Services\Rentals;

use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionSetting;
use App\Support\StorageDataUri;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

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
 * QR CODE — built 2026-09-28 (report fixes, Johan's go-ahead) with
 * `endroid/qr-code` (pure-PHP, no external service call — a PDF-generation
 * path has no business depending on a third party's uptime, and the
 * existing agent-QR feature's `api.qrserver.com` client-side pattern was
 * never appropriate here for exactly that reason). Rendered to a PNG data
 * URI in generate() below and embedded straight into the DomPDF view —
 * DomPDF resolves `data:` URIs locally, no `isRemoteEnabled` config and no
 * filesystem write needed.
 */
class RentalInspectionReportPdfService
{
    /**
     * Conductor brief 2026-09-29 — "print for signature": the SAME report
     * content generate() already builds, plus blank signature blocks for
     * every outstanding party. A thin wrapper rather than a second
     * generate-shaped method — the item table, the QR/public-link cover,
     * and the signature roster are all identical; only how the
     * report-pdf.blade.php view renders an OUTSTANDING row differs
     * ($forSignature branches that one section — see the view itself).
     */
    public function generateForSignature(RentalInspection $inspection)
    {
        return $this->generate($inspection, forSignature: true);
    }

    public function generate(RentalInspection $inspection, bool $forSignature = false)
    {
        $inspection->loadMissing([
            'property', 'lease.tenants.contact', 'previousInspection', 'createdBy',
            'signatures.partyContact', 'roomNotes',
        ]);

        // §45.3 (Build I-1) — the latest note per room, printed under that room's table (the PDF used to
        // carry only item rows). Photos stay out of the PDF (Johan, 23 Sep); the per-flaw photo-time line
        // waits for his answer to Q8.
        $roomNotes = $inspection->roomNotes
            ->groupBy('property_room_id')
            ->map(fn ($notes) => $notes->sortByDesc('created_at')->first());

        $items = RentalInspectionItem::where('property_id', $inspection->property_id)
            ->where('is_retired', false)
            ->with('room')
            ->get();

        // AT-433, 2026-09-26 — a photo-anchor row (RentalInspectionObservation
        // ::CONDITION_PENDING) is not an assessment; excluded before the
        // latest-per-item pick so this COMPLETED inspection's own printed
        // report never shows an empty string as the item's recorded
        // condition just because a photo arrived before a condition did.
        $currentByItem = $inspection->observations
            ->where('condition', '!=', \App\Models\RentalInspectionObservation::CONDITION_PENDING)
            ->groupBy('rental_inspection_item_id')
            ->map(fn ($group) => $group->sortByDesc('created_at')->first());

        // §36, 2026-09-28 — "a condition and its note must JUMP OUT," on
        // the printed report as much as on screen. Resolved once per
        // generate() call, not per row — the agency's own condition
        // vocabulary/severity mapping never varies within one inspection.
        $agencyId = $inspection->property?->agency_id;
        $conditionStates = collect(RentalInspectionSetting::conditionStatesFor($agencyId))->keyBy('key');
        $severityFor = fn (?string $key) => $key ? ($conditionStates->get($key)['severity'] ?? 'red') : null;
        // Report-fixes, 2026-09-28 — Johan: "Use the display label
        // everywhere (page, PDF, emails)." A raw condition key like `n_a`
        // is not a display label; ucfirst()-ing it printed "N_a". Falls
        // back to the same humanised-key rendering the public page uses
        // for an agency's own custom key not present in its own configured
        // vocabulary (shouldn't happen via real UI input, but never crash
        // or print the raw key verbatim on a printed legal record).
        $labelFor = fn (?string $key) => $key ? ($conditionStates->get($key)['label'] ?? ucfirst(str_replace('_', ' ', $key))) : null;

        $rows = $items
            ->map(function (RentalInspectionItem $item) use ($inspection, $currentByItem, $severityFor, $labelFor) {
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

                $previous = $history->last();

                return (object) [
                    'item' => $item,
                    'room' => $item->room,
                    'current' => $current,
                    'current_label' => $labelFor($current?->condition),
                    'current_severity' => $severityFor($current?->condition),
                    // The immediate predecessor's value is the run's own
                    // last entry (never re-derived separately) — null when
                    // this is the first inspection in its chain.
                    'previous' => $previous,
                    'previous_label' => $labelFor($previous?->observation?->condition),
                    'previous_severity' => $severityFor($previous?->observation?->condition),
                    'history_text' => $history->map(fn ($h) => $labelFor($h->observation->condition))->implode(' → '),
                ];
            })
            ->filter()
            ->groupBy(fn ($row) => $row->room?->id ?? 'general');

        $publicUrl = $inspection->public_token
            ? route('rental-inspections.public.show', $inspection->public_token)
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
        // text-only 'signed' PDF is not acceptable." Wet-ink evidence is
        // deliberately excluded — RentalInspectionSignature::
        // DISPOSITION_WET_INK's own docblock: "never presentable as [a
        // signature] on screen," the same reason it already renders as
        // text + a link rather than an image everywhere else. Only a real
        // canvas-drawn signature (disposition=signed) gets embedded.
        $signatureRows = collect($inspection->signatureSummaryRows())->map(function (array $row) {
            $row['signature_image_data_uri'] = $row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_SIGNED
                ? StorageDataUri::fromPublicStoragePath($row['signature']->party_signature_path)
                : null;

            return $row;
        })->all();

        return Pdf::loadView('corex.rental-inspections.report-pdf', [
            'inspection' => $inspection,
            'rows' => $rows,
            'roomNotes' => $roomNotes,
            'publicUrl' => $publicUrl,
            'qrDataUri' => $qrDataUri,
            'signatureRows' => $signatureRows,
            'refusalReasonLabels' => collect(RentalInspectionSetting::refusalReasonPresetsFor($agencyId))->pluck('label', 'key'),
            'severityColors' => RentalInspectionSetting::SEVERITY_COLORS,
            'forSignature' => $forSignature,
        ])->setPaper('a4', 'portrait');
    }

    public function filenameFor(RentalInspection $inspection): string
    {
        $address = str($inspection->property?->buildDisplayAddress() ?? 'property')->slug();

        return "inspection-report-{$inspection->type}-{$address}-{$inspection->id}.pdf";
    }

    public function filenameForSignatureFor(RentalInspection $inspection): string
    {
        $address = str($inspection->property?->buildDisplayAddress() ?? 'property')->slug();

        return "inspection-for-signature-{$inspection->type}-{$address}-{$inspection->id}.pdf";
    }
}
