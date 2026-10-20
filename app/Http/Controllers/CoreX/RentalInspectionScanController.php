<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
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
 * contract (§12). The tick columns are read by OMR; since Johan's ruling of
 * 20 Oct 2026 (§13.14) the handwritten note on each row is also read, by AI,
 * and only ever PRE-FILLS the note box. Nothing writes to the inspection
 * until a human confirms on the review screen (apply()) — store() only
 * ever produces a reviewable RentalInspectionScanMark set. On the review
 * screen the agent can also capture notes and photos per row, saved as they
 * are captured through the capture screen's own endpoints (§13.14).
 */
class RentalInspectionScanController extends Controller
{
    use AuthorizesRentalRecordScope;

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
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

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
        $scan->refresh();

        // §64 - uploads route by the form's own QR code, not by the page they were uploaded on: a scan that failed here may be
        // the form of ANOTHER inspection (the agent started a newer one, or uploaded from the wrong tab). Its QR says which.
        if ($scan->status === RentalInspectionScan::STATUS_FAILED) {
            $owner = $this->reader->qrOwner($scan);
            if ($owner !== null && $owner['rental_inspection_id'] !== $rentalInspection->id) {
                return $this->fileOnOwner($request, $scan, $rentalInspection, $owner);
            }
        }

        if ($refused = $this->refuseWrongForm($request, $scan, $rentalInspection)) {
            return $refused;
        }

        return redirect()
            ->route('corex.rental-inspections.scans.review', [$rentalInspection, $scan])
            ->with('success', 'Scan uploaded.');
    }

    /**
     * AT-463 - a form that belongs to ANOTHER inspection (and could not be filed there) is refused outright: it is not kept
     * against this one (that would put someone else's paperwork in this inspection's evidence trail) and nothing was read.
     */
    private function refuseWrongForm(Request $request, RentalInspectionScan $scan, RentalInspection $on): ?RedirectResponse
    {
        if ($scan->status !== RentalInspectionScan::STATUS_FAILED
            || $scan->decoded_inspection_id === null
            || $scan->decoded_inspection_id === $on->id) {
            return null;
        }
        $otherId = $scan->decoded_inspection_id;
        // Audit B26 - archived, and the uploaded file is RETAINED on the private disk (non-negotiable #1: no hard deletes; the form spec
        // keeps the original). Nothing was read against this inspection and the archived row is out of its evidence trail.
        $scan->archive($request->user());

        return redirect()->back()->with('error', "That form is for inspection #{$otherId}, not this one (#{$on->id}). It was not saved here - upload it on its own inspection.");
    }

    /**
     * §64 - the scan's own QR says inspection #X. If X is in this agency and the user may open it, the scan is filed against X
     * and read there, and the agent is told so in plain words with a link back; never silently, never across agencies.
     *
     * @param array{rental_inspection_id: int, page_number: int, page_count: int, form_version: int} $owner
     */
    private function fileOnOwner(Request $request, RentalInspectionScan $scan, RentalInspection $from, array $owner): RedirectResponse
    {
        $targetId = (int) $owner['rental_inspection_id'];
        // Same agency only (the agency scope plus an explicit check) and an inspection that still exists.
        $target = RentalInspection::query()->where('agency_id', $from->agency_id)->with('property')->find($targetId);
        $allowed = false;
        if ($target !== null) {
            try {
                $this->guardRentalRecordScope($target, 'rental_inspections', $target->property?->branch_id);
                $allowed = true;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                // not theirs to open: handled as "cannot file it there" below
            }
        }
        if (! $allowed) {
            // Audit B26 - archived, file retained (see refuseWrongForm()).
            $scan->archive($request->user());

            return redirect()->back()->with('error', "That form's own code says inspection #{$targetId}, which you cannot open here, so it was not saved. Upload it on the inspection it was printed from.");
        }

        $newPath = $scan->storage_path;
        $wanted = "properties/{$target->property_id}/rental-inspection-scans/" . basename($scan->storage_path);
        if ($wanted !== $scan->storage_path && Storage::disk('local')->move($scan->storage_path, $wanted)) {
            $newPath = $wanted;
        }
        $scan->forceFill([
            'agency_id' => $target->agency_id,
            'branch_id' => $target->property?->branch_id,
            'rental_inspection_id' => $target->id,
            'rental_inspection_form_id' => null,
            'storage_path' => $newPath,
            'status' => RentalInspectionScan::STATUS_PROCESSING,
            'failure_reason' => null,
            'decoded_inspection_id' => null,
            'decoded_form_version' => null,
            'read_notes' => null,
            'page_results' => null,
        ])->save();

        $this->reader->process($scan->fresh(), ["Uploaded on inspection #{$from->id}, but this form's own page code says inspection #{$target->id}, so it was filed and read here - nothing was read against #{$from->id}."]);
        $scan->refresh();

        // Pages of more than one inspection in one upload: still refused whole, as always.
        if ($refused = $this->refuseWrongForm($request, $scan, $target)) {
            return $refused;
        }

        return redirect()
            ->route('corex.rental-inspections.scans.review', [$target, $scan])
            ->with('success', 'Scan uploaded.')
            ->with('scan_routed', ['from' => $from->id, 'to' => $target->id]);
    }

    /**
     * Item 5 — the review screen. Never a silent write: the agent sees, per
     * item, what was read and with what confidence, and must confirm or
     * correct before anything reaches the inspection.
     */
    public function review(RentalInspection $rentalInspection, RentalInspectionScan $scan): View
    {
        abort_if($scan->rental_inspection_id !== $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $scan->load(['marks.item.room']);
        $conditionStates = RentalInspectionSetting::conditionStatesFor($rentalInspection->agency_id);
        $itemIds = $scan->marks->pluck('rental_inspection_item_id')->unique()->values()->all();

        // What the web inspection says right now for each item (append-only: the last recorded one), so the agent sees each proposal next
        // to what it would replace - its condition AND its note.
        $onScreen = [];
        $onScreenNotes = [];
        $rows = \App\Models\RentalInspectionObservation::where('rental_inspection_id', $rentalInspection->id)
            ->recorded()->orderBy('id')->get(['rental_inspection_item_id', 'condition', 'notes']);
        foreach ($rows as $row) {
            $onScreen[(int) $row->rental_inspection_item_id] = (string) $row->condition;
            $onScreenNotes[(int) $row->rental_inspection_item_id] = $row->notes !== null && trim($row->notes) !== '' ? trim($row->notes) : null;
        }

        // The photos already on each of these items (through the item's observations on THIS inspection; archived ones are out of the
        // default scope). The model hands back signed, expiring links - the same ones the capture screen shows.
        $obsItem = \App\Models\RentalInspectionObservation::where('rental_inspection_id', $rentalInspection->id)
            ->whereIn('rental_inspection_item_id', $itemIds)->pluck('rental_inspection_item_id', 'id');
        $photos = [];
        foreach (\App\Models\RentalInspectionPhoto::where('rental_inspection_id', $rentalInspection->id)
            ->whereIn('rental_inspection_observation_id', $obsItem->keys())->orderBy('id')->get() as $photo) {
            $photos[(int) $obsItem[$photo->rental_inspection_observation_id]][] = $photo->storage_path;
        }

        // Signed or completed (or cancelled): the whole screen is read-only - the same guard apply() and the capture endpoints use.
        $readOnly = false;
        try {
            $rentalInspection->assertContentEditable();
        } catch (\App\Exceptions\RentalInspectionNotRecordableException) {
            $readOnly = true;
        }

        return view('corex.rental-inspections.scan-review', [
            'inspection' => $rentalInspection,
            'scan' => $scan,
            'conditionStates' => $conditionStates,
            'onScreen' => $onScreen,
            'onScreenNotes' => $onScreenNotes,
            'photos' => $photos,
            'readOnly' => $readOnly,
            'applyKey' => (string) \Illuminate\Support\Str::uuid(),
            'source' => $rentalInspection->type === RentalInspection::TYPE_OUT
                ? \App\Models\RentalInspectionObservation::SOURCE_OUT_INSPECTION
                : \App\Models\RentalInspectionObservation::SOURCE_IN_INSPECTION,
            // Confidence runs 0-1 (1 = a clear tick). Under the agency's line a tick is "picked up but unsure": shown as to check, never
            // pre-selected (form spec §13.15). A read note under its own line is not filled in.
            // The conditions that need a note (the agency's own vocabulary) - what blocks Apply in the header while a row has one with no note.
            'notesRequiredKeys' => collect($conditionStates)->pluck('key')
                ->filter(fn ($k) => RentalInspectionSetting::conditionRequiresNotesFor($rentalInspection->agency_id, $k))->values()->all(),
            'unsureBelow' => RentalInspectionSetting::omrUnsureBelowFor($rentalInspection->agency_id),
            'noteMinConfidence' => RentalInspectionSetting::noteReadMinConfidenceFor($rentalInspection->agency_id),
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
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        // Audit H1 — a scan cannot write observations into a completed / cancelled inspection.
        try {
            $rentalInspection->assertContentEditable();
        } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
            return redirect()
                ->route('corex.rental-inspections.scans.review', [$rentalInspection, $scan])
                ->withErrors(['scan' => $e->getMessage()]);
        }

        // AT-472 - the confirmed NOTE rides with each confirmed condition (`notes[markId]`); `apply_key` makes a repeat of the same press safe.
        $validated = $request->validate([
            'condition_keys' => ['required', 'array'],
            'condition_keys.*' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:4000'],
            'apply_key' => ['nullable', 'uuid'],
        ]);

        $states = collect(RentalInspectionSetting::conditionStatesFor($rentalInspection->agency_id));
        $validKeys = $states->pluck('key')->all();
        $source = $rentalInspection->type === \App\Models\RentalInspection::TYPE_OUT
            ? \App\Models\RentalInspectionObservation::SOURCE_OUT_INSPECTION
            : \App\Models\RentalInspectionObservation::SOURCE_IN_INSPECTION;

        $marks = $scan->marks()->with('item')->whereIn('id', array_keys($validated['condition_keys']))->get();
        $latest = \App\Models\RentalInspectionObservation::query()->recorded()
            ->where('rental_inspection_id', $rentalInspection->id)
            ->whereIn('rental_inspection_item_id', $marks->pluck('rental_inspection_item_id'))
            ->orderBy('id')->get()->groupBy('rental_inspection_item_id')->map(fn ($rows) => $rows->last());

        $refused = [];
        foreach ($marks as $mark) {
            $key = $validated['condition_keys'][$mark->id] ?? null;
            if (! $key || ! in_array($key, $validKeys, true)) {
                continue; // left blank / not confirmed this round — stays for a later visit
            }
            $note = isset($validated['notes'][$mark->id]) && trim((string) $validated['notes'][$mark->id]) !== '' ? trim($validated['notes'][$mark->id]) : null;

            // An applied row can still be corrected (the import got it wrong), but only by a different condition or note than what is
            // recorded now; the same thing again changes nothing (never double-record).
            $current = $latest->get($mark->rental_inspection_item_id);
            if ($mark->applied_observation_id !== null && $current
                && $current->condition === $key && (($current->notes !== null && trim($current->notes) !== '') ? trim($current->notes) : null) === $note) {
                continue;
            }

            // "Damaged needs a note" - the very rule the capture endpoint (storeObservation) enforces, from the agency's own vocabulary.
            if ($note === null && RentalInspectionSetting::conditionRequiresNotesFor($rentalInspection->agency_id, $key)) {
                $refused[] = ['mark' => $mark->id, 'focus' => 'note', 'text' => ($mark->item?->label ?? 'A row') . ': a note is required for ' . ($states->firstWhere('key', $key)['label'] ?? $key) . '.'];

                continue;
            }

            $this->reader->applyMark($mark, $key, $request->user(), $source, $note, $this->replayKeyFor($validated['apply_key'] ?? null, $mark->id));
        }

        $allApplied = $scan->marks()->whereNull('applied_observation_id')->doesntExist();
        if ($allApplied && $scan->status !== RentalInspectionScan::STATUS_APPLIED) {
            $scan->forceFill([
                'status' => RentalInspectionScan::STATUS_APPLIED,
                'applied_by_user_id' => $request->user()->id,
                'applied_at' => now(),
            ])->save();
        }

        $redirect = redirect()
            ->route('corex.rental-inspections.scans.review', [$rentalInspection, $scan])
            ->with('success', $allApplied ? 'All rows applied.' : 'Confirmed rows applied — some rows still need review.');

        // Each refusal is also handed to the header as a link to its row (`scan_refused`); `errors.scan` keeps the plain sentence for anything else.
        return $refused !== []
            ? $redirect->withErrors(['scan' => implode(' ', array_column($refused, 'text'))])->with('scan_refused', $refused)
            : $redirect;
    }

    /** One stable, valid UUID per (this apply press, mark): the same press sent twice replays instead of recording twice. */
    private function replayKeyFor(?string $applyKey, int $markId): ?string
    {
        if (! $applyKey) {
            return null;
        }
        $h = sha1($applyKey . '|' . $markId);

        return sprintf('%s-%s-5%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex((hexdec(substr($h, 16, 2)) & 0x3f) | 0x80), substr($h, 18, 2), substr($h, 20, 12));
    }

    /** Gated download of the retained original — same pattern as PropertyFileController::download(). */
    public function download(Request $request, RentalInspection $rentalInspection, RentalInspectionScan $scan)
    {
        abort_if($scan->rental_inspection_id !== $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);
        abort_unless(Storage::disk('local')->exists($scan->storage_path), 404);

        // ?view=1 opens the original in the browser (a PDF or picture), so it can be looked at against the inspection.
        if ($request->boolean('view')) {
            return Storage::disk('local')->response($scan->storage_path, $scan->original_filename, [
                'Content-Type' => $scan->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . str_replace(['"', "\r", "\n"], '', $scan->original_filename) . '"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return Storage::disk('local')->download($scan->storage_path, $scan->original_filename);
    }

    /** Archived, never hard-deleted (non-negotiable #1) — "a superseded scan is archived, never removed." */
    public function destroy(Request $request, RentalInspection $rentalInspection, RentalInspectionScan $scan): RedirectResponse
    {
        abort_if($scan->rental_inspection_id !== $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $scan->archive($request->user());

        return redirect()->route('corex.rental-inspections.show', $rentalInspection)->with('success', 'Scan archived.');
    }
}
