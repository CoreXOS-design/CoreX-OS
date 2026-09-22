<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalInspection;
use App\Models\RentalInspectionScan;
use App\Models\RentalInspectionSetting;
use App\Services\Rentals\RentalInspectionScanReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inspection-form.md §13 — upload, review, confirm, apply
 * a scanned/photographed wet-ink form against cc5's own printable-form
 * contract (§12). "We read the form back by OMR — COLUMN MARKING ONLY. We
 * do NOT read handwriting, ever" (Johan). Nothing writes to the inspection
 * until a human confirms on the review screen (apply()) — store() only
 * ever produces a reviewable RentalInspectionScanMark set.
 */
class RentalInspectionScanController extends Controller
{
    public function __construct(private RentalInspectionScanReaderService $reader)
    {
    }

    /**
     * One PDF (any page count) OR one image (treated as a single page) per
     * upload — a multi-page form photographed rather than scanned as one
     * PDF is uploaded as several separate scans, one per photo; each
     * contributes marks for whichever items its own page covers. The
     * original file is retained on the private disk regardless of whether
     * the reader can decode it (Johan: "the scan on file is what backs up
     * anything we could not read").
     */
    public function store(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        $request->validate([
            'scan' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic,heif', 'max:20480'],
        ]);

        $file = $request->file('scan');
        $path = $file->store("properties/{$rentalInspection->property_id}/rental-inspection-scans", 'local');

        $scan = RentalInspectionScan::create([
            'agency_id' => $rentalInspection->agency_id,
            'branch_id' => $rentalInspection->property?->branch_id,
            'rental_inspection_id' => $rentalInspection->id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'status' => RentalInspectionScan::STATUS_PROCESSING,
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        // Synchronous — QA has no queue worker (BUILD_STANDARD §8h), and a
        // few-page scan's rasterize-and-sample pass is a few seconds' work,
        // well within a normal request.
        $this->reader->process($scan->fresh());

        return redirect()
            ->route('corex.rental-inspections.scans.review', [$rentalInspection, $scan])
            ->with('success', 'Scan uploaded.');
    }

    /**
     * Item 5 — the review screen. Never a silent write: the agent sees, per
     * item, what was read and with what confidence, and must confirm or
     * correct before anything reaches the inspection.
     */
    public function review(RentalInspection $rentalInspection, RentalInspectionScan $scan): View
    {
        abort_if($scan->rental_inspection_id !== $rentalInspection->id, 404);

        $scan->load(['marks.item.room']);
        $conditionStates = RentalInspectionSetting::conditionStatesFor($rentalInspection->agency_id);

        return view('corex.rental-inspections.scan-review', [
            'inspection' => $rentalInspection,
            'scan' => $scan,
            'conditionStates' => $conditionStates,
        ]);
    }

    /**
     * Item 6 — applying a confirmed row creates an ORDINARY observation
     * (RentalInspectionObservation::record(), the exact same entry point a
     * screen tap uses) via the reader service — never a parallel storage
     * path. Partial application is allowed: an agent can confirm some rows
     * now and come back for the rest — `condition_keys` only ever contains
     * the rows submitted in THIS request.
     */
    public function apply(Request $request, RentalInspection $rentalInspection, RentalInspectionScan $scan): RedirectResponse
    {
        abort_if($scan->rental_inspection_id !== $rentalInspection->id, 404);

        $validated = $request->validate([
            'condition_keys' => ['required', 'array'],
            'condition_keys.*' => ['nullable', 'string', 'max:60'],
        ]);

        $validKeys = collect(RentalInspectionSetting::conditionStatesFor($rentalInspection->agency_id))->pluck('key')->all();
        $source = $rentalInspection->type === \App\Models\RentalInspection::TYPE_OUT
            ? \App\Models\RentalInspectionObservation::SOURCE_OUT_INSPECTION
            : \App\Models\RentalInspectionObservation::SOURCE_IN_INSPECTION;

        $marks = $scan->marks()->whereIn('id', array_keys($validated['condition_keys']))->get();
        foreach ($marks as $mark) {
            if ($mark->applied_observation_id !== null) {
                continue; // already applied earlier — never double-record the same mark
            }
            $key = $validated['condition_keys'][$mark->id] ?? null;
            if (! $key || ! in_array($key, $validKeys, true)) {
                continue; // left blank / not confirmed this round — stays for a later visit
            }
            $this->reader->applyMark($mark, $key, $request->user(), $source);
        }

        $allApplied = $scan->marks()->whereNull('applied_observation_id')->doesntExist();
        if ($allApplied) {
            $scan->forceFill([
                'status' => RentalInspectionScan::STATUS_APPLIED,
                'applied_by_user_id' => $request->user()->id,
                'applied_at' => now(),
            ])->save();
        }

        return redirect()
            ->route('corex.rental-inspections.scans.review', [$rentalInspection, $scan])
            ->with('success', $allApplied ? 'All rows applied.' : 'Confirmed rows applied — some rows still need review.');
    }

    /** Gated download of the retained original — same pattern as PropertyFileController::download(). */
    public function download(RentalInspection $rentalInspection, RentalInspectionScan $scan)
    {
        abort_if($scan->rental_inspection_id !== $rentalInspection->id, 404);
        abort_unless(Storage::disk('local')->exists($scan->storage_path), 404);

        return Storage::disk('local')->download($scan->storage_path, $scan->original_filename);
    }

    /** Archived, never hard-deleted (non-negotiable #1) — "a superseded scan is archived, never removed." */
    public function destroy(Request $request, RentalInspection $rentalInspection, RentalInspectionScan $scan): RedirectResponse
    {
        abort_if($scan->rental_inspection_id !== $rentalInspection->id, 404);

        $scan->archive($request->user());

        return redirect()->route('corex.rental-inspections.show', $rentalInspection)->with('success', 'Scan archived.');
    }
}
