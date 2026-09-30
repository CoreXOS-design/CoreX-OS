<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryLineDisposition;
use App\Models\RentalInventoryPhoto;
use App\Models\RentalInventoryRoomMark;
use App\Models\RentalInventorySignature;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rental-inventory.md §4/§5 — adding/retiring lines and capturing
 * signatures. Deliberately separate from RentalInventoryController, same
 * split RentalInspectionRecordingController already established between
 * Read/lifecycle and recording.
 */
class RentalInventoryRecordingController extends Controller
{
    use EnforcesRecordVisibility;

    /**
     * Move-out dispositions and move-out photos are the ONE write that is
     * allowed on a COMPLETED inventory (and only a completed one): the
     * comparison screen only opens once the inventory is completed, and
     * RentalInventoryLineDisposition::record() requires it. Everything else
     * stays draft-only via assertEditable(); cancelled stays locked (audit H1).
     */
    private function assertMoveOutRecordable(RentalInventory $rentalInventory): void
    {
        if ($rentalInventory->status === RentalInventory::STATUS_COMPLETED) {
            return;
        }
        if ($rentalInventory->status === RentalInventory::STATUS_CANCELLED) {
            throw new \App\Exceptions\RentalInventoryNotEditableException($rentalInventory);
        }

        abort(409, 'Move-out findings can only be recorded once the inventory is completed.');
    }

    /**
     * POST /corex/rental-inventories/{inventory}/lines — §0b: the capture
     * surface sends property_room_id (the property's own PropertyRoom, the
     * agent never retypes a room name); room_label is derived from it for
     * back-compat display. room_label alone still validates on its own for
     * any caller that hasn't moved to room-based capture yet.
     */
    public function storeLine(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        $rentalInventory->assertEditable();

        $validated = $request->validate([
            'property_room_id' => ['nullable', 'integer', Rule::exists('property_rooms', 'id')->where('property_id', $rentalInventory->property_id)],
            'room_label' => ['nullable', 'required_without:property_room_id', 'string', 'max:100'],
            // Johan, 2026-10-02: a new line's qty starts blank, not 1 — "lets
            // get that to null and it will work perfect." Blank stays blank;
            // never silently defaulted.
            'quantity' => ['nullable', 'integer', 'min:0'],
            'description' => ['required', 'string'],
            // §13 — the move-in condition chip. Optional: the lazy-but-valid
            // shortcut (type quantity + description, move on) must still
            // work end to end, so a line is never blocked on picking one.
            'condition_key' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! empty($validated['property_room_id']) && empty($validated['room_label'])) {
            $validated['room_label'] = \App\Models\PropertyRoom::find($validated['property_room_id'])?->label;
        }

        $line = RentalInventoryLine::create(array_merge($validated, [
            'agency_id' => $rentalInventory->agency_id,
            'rental_inventory_id' => $rentalInventory->id,
            'created_by_user_id' => $request->user()->id,
        ]));

        return response()->json($line, 201);
    }

    public function updateLine(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);
        $rentalInventory->assertEditable();

        $validated = $request->validate([
            'property_room_id' => ['nullable', 'integer', Rule::exists('property_rooms', 'id')->where('property_id', $rentalInventory->property_id)],
            'room_label' => ['nullable', 'required_without:property_room_id', 'string', 'max:100'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'description' => ['required', 'string'],
            'condition_key' => ['nullable', 'string', 'max:60'],
        ]);

        if (! empty($validated['property_room_id']) && empty($validated['room_label'])) {
            $validated['room_label'] = \App\Models\PropertyRoom::find($validated['property_room_id'])?->label;
        }

        $line->update($validated);

        return response()->json($line->fresh());
    }

    /** POST /corex/rental-inventories/{inventory}/lines/{line}/retire — §3.3-style, never a hard delete. */
    public function retireLine(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);
        $rentalInventory->assertEditable();

        $line->retire();

        return response()->json(['message' => 'Line retired.']);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/lines/{line}/dispositions —
     * §8, the move-out finding. Append-only: this always CREATES a new row,
     * even if one already exists for this line (a correction is a fresh
     * row, never an edit — RentalInventoryLineDisposition has no update()).
     */
    public function storeLineDisposition(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);
        $this->assertMoveOutRecordable($rentalInventory);

        $validated = $request->validate([
            'disposition_key' => ['required', 'string', 'max:60'],
            // §8 — genuinely optional. Absent/null means "not yet counted,"
            // never coerced to 0 by this validation or anywhere downstream.
            'quantity_found' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $disposition = RentalInventoryLineDisposition::record($line, $validated['disposition_key'], [
                'quantity_found' => $validated['quantity_found'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'recorded_by_user_id' => $request->user()->id,
            ]);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($disposition, 201);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/rooms/{room}/mark-empty —
     * §12: the completion gate needs to tell "nobody opened this room" apart
     * from "this room genuinely has nothing in it" — the same distinction
     * rental-inspections' markRoomNa() makes, but recorded as its own row
     * (RentalInventoryRoomMark) rather than a per-item observation, because
     * an inventory room has no checklist items to write one against.
     * Idempotent: marking an already-marked room just refreshes who/when.
     */
    public function markRoomEmpty(Request $request, RentalInventory $rentalInventory, PropertyRoom $room): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        abort_if((int) $room->property_id !== (int) $rentalInventory->property_id, 404);
        $rentalInventory->assertEditable();

        $mark = RentalInventoryRoomMark::updateOrCreate(
            [
                'rental_inventory_id' => $rentalInventory->id,
                'property_room_id' => $room->id,
            ],
            [
                'agency_id' => $rentalInventory->agency_id,
                'marked_empty_by_user_id' => $request->user()->id,
                'marked_empty_at' => now(),
            ]
        );

        return response()->json($mark, 201);
    }

    /**
     * DELETE /corex/rental-inventories/{inventory}/rooms/{room}/mark-empty —
     * Johan, 2026-09-28 — the "Undo" half of markRoomEmpty() above: a mark
     * is a mutable "is this room currently considered checked-and-empty"
     * STATE, not an append-only evidence record like a line/photo/signature
     * (non-negotiable #1's soft-delete rule protects evidence, not a
     * reversible checkbox) — hard-deleting the mark row itself is the
     * correct un-mark, exactly mirroring how markRoomEmpty() itself writes
     * via updateOrCreate rather than an append-only history table.
     * Idempotent: unmarking an already-unmarked (or never-marked) room is a
     * no-op, not an error — there's nothing wrong with clicking Undo twice.
     */
    public function unmarkRoomEmpty(Request $request, RentalInventory $rentalInventory, PropertyRoom $room): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        abort_if((int) $room->property_id !== (int) $rentalInventory->property_id, 404);
        $rentalInventory->assertEditable();

        RentalInventoryRoomMark::where('rental_inventory_id', $rentalInventory->id)
            ->where('property_room_id', $room->id)
            ->delete();

        return response()->json(['message' => 'Mark removed.']);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/copy-from-last — §13,
     * Johan's approved mockup: "a furnished flat is re-let with the same
     * contents, and re-typing forty lines is the work we are supposed to be
     * doing for them." Copies every active line from the property's most
     * recent OTHER inventory (RentalInventory::priorInventory()) into this
     * one. 404s with a plain message when no prior inventory exists — this
     * control is only ever shown on the capture screen when one does, but
     * the server is the real gate, not the button being hidden.
     */
    public function copyFromLastInventory(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        $rentalInventory->assertEditable();

        $prior = $rentalInventory->priorInventory();
        if (! $prior) {
            return response()->json(['message' => 'This property has no earlier inventory to copy from.'], 404);
        }

        $lines = $rentalInventory->copyLinesFrom($prior, $request->user());

        return response()->json(['lines' => $lines->values()], 201);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/lines/{line}/move-out-photos
     * — §14, Johan's approved comparison mockup: "Where there is no photo on
     * the current side, SHOW that rather than hiding it. A claim with no
     * photo is a weak claim and the agent should see it while they can
     * still take one." Uploads AND tags to this line in ONE request, same
     * "no staging, ever" contract as the capture screen's own per-line
     * strip (§13.3) — the SAME PropertyImageStorer pipeline, SAME
     * client-batching contract, just `side = move_out` and no dependency on
     * a disposition row existing yet (a disposition is recorded separately,
     * via storeLineDisposition() above; a photo is evidence taken the
     * moment the agent is standing there, whether or not they've typed a
     * note yet).
     */
    public function storeLineMoveOutPhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);
        $this->assertMoveOutRecordable($rentalInventory);

        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'client_idempotency_keys' => ['nullable', 'array'],
            'client_idempotency_keys.*' => ['nullable', 'uuid'],
        ]);

        $storer = app(PropertyImageStorer::class);
        $created = [];

        foreach ($validated['photos'] as $i => $file) {
            $clientKey = $validated['client_idempotency_keys'][$i] ?? null;
            if ($clientKey) {
                $existing = RentalInventoryPhoto::where('client_idempotency_key', $clientKey)
                    ->where('rental_inventory_id', $rentalInventory->id)->first();
                if ($existing) {
                    $created[] = $existing;
                    continue;
                }
            }

            $url = $storer->store($file, $rentalInventory->property_id);

            $photo = RentalInventoryPhoto::create([
                'agency_id' => $rentalInventory->agency_id,
                'rental_inventory_id' => $rentalInventory->id,
                'property_room_id' => $line->property_room_id,
                'side' => RentalInventoryPhoto::SIDE_MOVE_OUT,
                'storage_path' => $url,
                'file_size_bytes' => $file->getSize(),
                'uploaded_by_user_id' => $request->user()->id,
                'client_idempotency_key' => $clientKey,
            ]);

            $line->photos()->syncWithoutDetaching([$photo->id => ['agency_id' => $line->agency_id]]);

            $created[] = $photo;
        }

        return response()->json([
            'photos' => collect($created)->map(fn (RentalInventoryPhoto $p) => [
                'id' => $p->id,
                'storage_path' => $p->storage_path,
            ])->values(),
        ], 201);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/signatures — same shape and
     * invariants as RentalInspectionRecordingController::storeSignature()
     * (minus wet-ink, minus the sign_on_behalf-gated refusal-on-behalf
     * permission — not built here, see the migration's own docblock).
     */
    public function storeSignature(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        $rentalInventory->assertEditable();

        $validated = $request->validate([
            'party_role' => ['required', 'string', 'in:' . implode(',', [
                RentalInventorySignature::PARTY_TENANT,
                RentalInventorySignature::PARTY_LANDLORD,
                RentalInventorySignature::PARTY_AGENT,
            ])],
            'disposition' => ['required', 'string', 'in:' . implode(',', [
                RentalInventorySignature::DISPOSITION_SIGNED,
                RentalInventorySignature::DISPOSITION_REFUSED,
            ])],
            'party_contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('agency_id', $rentalInventory->agency_id)],
            'signature_image' => ['nullable', 'string', 'max:1000000'],
            'refusal_reason_preset' => ['nullable', 'string', 'max:60'],
            'refusal_reason_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['party_role'] !== RentalInventorySignature::PARTY_AGENT) {
            $attributes = [
                'party_contact_id' => $validated['party_contact_id'] ?? null,
                'refusal_reason_preset' => $validated['refusal_reason_preset'] ?? null,
                'refusal_reason_note' => $validated['refusal_reason_note'] ?? null,
                'recorded_by_user_id' => $request->user()->id,
            ];
        } else {
            $attributes = ['recorded_by_user_id' => $request->user()->id];
        }

        try {
            if (!empty($validated['signature_image'])) {
                $attributes['party_signature_path'] = RentalInventorySignature::storeCanvasImage($validated['signature_image'], $rentalInventory->property_id);
            }

            $signature = RentalInventorySignature::capture($rentalInventory, $validated['party_role'], $validated['disposition'], $attributes);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($signature, 201);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/complete — §41-follow-up
     * (Job 3), same trigger/shape as RentalInspectionRecordingController::
     * complete(): the moment every required party has signed or been
     * dispositioned (markCompleted()'s own guard), the signed report is
     * filed to the property AND — when the agency's own
     * auto_send_report_enabled setting is on (default) — emailed
     * automatically via the SHARED SignedDocumentDistributionService.
     * Filing itself is never optional; only the automatic EMAIL is gated
     * on the setting. Wrapped in its own try/catch — a distribution
     * failure must never undo or fail the completion the agent just
     * successfully performed.
     */
    public function complete(
        RentalInventory $rentalInventory,
        \App\Services\Rentals\RentalInventoryReportPdfService $pdfService,
        \App\Services\Distribution\SignedDocumentDistributionService $distributionService,
    ): JsonResponse {
        $this->assertVisible(request(), $rentalInventory);
        try {
            $rentalInventory->assertEditable();
            $rentalInventory->markCompleted();
        } catch (\App\Exceptions\RentalInventoryUnvisitedRoomsException $e) {
            // Johan, 2026-09-28 — the structured room list, so the show
            // page can render each name as a link to that space instead of
            // just the plain-language sentence.
            return response()->json(['message' => $e->getMessage(), 'unvisited_rooms' => $e->rooms], 409);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        try {
            $this->fileAndMaybeEmailReport($rentalInventory, $pdfService, $distributionService, autoOnly: true);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Rental inventory completion distribution failed', [
                'inventory_id' => $rentalInventory->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($rentalInventory->fresh());
    }

    /**
     * POST /corex/rental-inventories/{inventory}/resend-report —
     * §41-follow-up (Job 3). The manual path: always sends (never gated on
     * auto_send_report_enabled — that setting only governs the AUTOMATIC
     * send at completion), always logs mode='manual' with the triggering
     * user, always re-files first (a no-op if already filed —
     * fileToProperty() is idempotent).
     */
    public function resendReport(
        Request $request,
        RentalInventory $rentalInventory,
        \App\Services\Rentals\RentalInventoryReportPdfService $pdfService,
        \App\Services\Distribution\SignedDocumentDistributionService $distributionService,
    ): JsonResponse {
        $this->assertVisible($request, $rentalInventory);
        if ($rentalInventory->status !== RentalInventory::STATUS_COMPLETED) {
            return response()->json(['message' => 'This inventory is not yet completed.'], 409);
        }

        $results = $this->fileAndMaybeEmailReport($rentalInventory, $pdfService, $distributionService, autoOnly: false, triggeredBy: $request->user());

        return response()->json(['results' => $results]);
    }

    /**
     * Shared by complete() (auto path) and resendReport() (manual path) so
     * the two can never drift on WHAT gets filed/emailed — only whether the
     * auto_send_report_enabled gate applies (auto path only) and which
     * mode/triggering user gets logged. Byte-for-byte the same shape as
     * RentalInspectionRecordingController::fileAndMaybeEmailReport().
     *
     * @return array<int, array{role:string, email:string, status:string, message_id:?string, error:?string}>
     */
    private function fileAndMaybeEmailReport(
        RentalInventory $rentalInventory,
        \App\Services\Rentals\RentalInventoryReportPdfService $pdfService,
        \App\Services\Distribution\SignedDocumentDistributionService $distributionService,
        bool $autoOnly,
        ?\App\Models\User $triggeredBy = null,
    ): array {
        $distributionService->ensurePublicLink($rentalInventory);
        $pdf = $pdfService->generate($rentalInventory);
        $pdfBytes = $pdf->output();
        $filename = $pdfService->filenameFor($rentalInventory);

        $distributionService->fileToProperty($rentalInventory, $pdfBytes, $filename);

        if ($autoOnly && ! \App\Models\RentalInventorySetting::autoSendReportEnabledFor($rentalInventory->agency_id)) {
            return [];
        }

        $pdfPath = tempnam(sys_get_temp_dir(), 'inv-report-') . '.pdf';
        file_put_contents($pdfPath, $pdfBytes);

        try {
            return $distributionService->emailParties(
                $rentalInventory,
                $pdfPath,
                $filename,
                mode: $autoOnly ? 'auto' : 'manual',
                triggeredBy: $triggeredBy,
            );
        } finally {
            @unlink($pdfPath);
        }
    }
}
