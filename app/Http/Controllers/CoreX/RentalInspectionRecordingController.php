<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionSignature;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-inspections.md §14.1/§14.2 — where an inspection is
 * actually RECORDED: adding items, recording observations, resolving
 * discrepancies, capturing signatures, uploading photos. Deliberately
 * separate from RentalInspectionController (the agency-level Read +
 * administrative-lifecycle surface, per that controller's own docblock).
 *
 * Every action here is a thin call into a model method — the same methods
 * a future mobile API controller will call (§14.2's endpoint table lists
 * the exact mobile equivalents). No business rule is decided in this
 * controller; it validates the request shape and hands off.
 */
class RentalInspectionRecordingController extends Controller
{
    /**
     * GET /corex/properties/{property}/rental-inspection-tab — the data
     * layer the rebuilt tab (§4) reads from: this property's items (every
     * one, including retired — §3.3, retiring never hides history) and
     * whichever in/out inspection is currently under way, each with its
     * observations, discrepancies, and signatures loaded. Read-only:
     * currentFor() never creates an inspection just because the tab was
     * opened (§0.5).
     */
    public function tabData(Request $request, Property $property): JsonResponse
    {
        return response()->json(RentalInspection::tabPayloadFor($property));
    }

    /** POST /corex/properties/{property}/rental-inspections/start — §0.5, the deliberate action that begins one. */
    public function start(Request $request, Property $property): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', [RentalInspection::TYPE_IN, RentalInspection::TYPE_OUT, RentalInspection::TYPE_AD_HOC])],
        ]);

        try {
            $inspection = RentalInspection::start($property, $validated['type'], $request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($inspection, 201);
    }

    /** POST /corex/properties/{property}/rental-inspection-items — Johan's ruling §0.6: the agent adds items per property. */
    public function storeItem(Request $request, Property $property): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:' . RentalInspectionItem::KIND_SPACE . ',' . RentalInspectionItem::KIND_METER],
            'label' => ['required', 'string', 'max:191'],
            'space_type' => ['nullable', 'string', 'max:60'],
        ]);

        $item = RentalInspectionItem::create(array_merge($validated, [
            'agency_id' => $property->agency_id,
            'property_id' => $property->id,
            'created_by_user_id' => $request->user()->id,
        ]));

        return response()->json($item);
    }

    /** POST /corex/properties/{property}/rental-inspection-items/{item}/retire — §3.3, never deleted, only retired. */
    public function retireItem(Request $request, Property $property, RentalInspectionItem $item): JsonResponse
    {
        abort_if($item->property_id !== $property->id, 404);

        $item->update(['is_retired' => true]);

        return response()->json(['message' => 'Item retired.']);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/observations — the ONE
     * path (§14.1 fix 2): RentalInspectionObservation::record() creates the
     * observation and runs discrepancy detection atomically.
     */
    public function storeObservation(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $validated = $request->validate([
            'rental_inspection_item_id' => ['required', 'integer', 'exists:rental_inspection_items,id'],
            'condition' => ['required', 'string', 'in:' . implode(',', [
                RentalInspectionObservation::CONDITION_GOOD,
                RentalInspectionObservation::CONDITION_FAIR,
                RentalInspectionObservation::CONDITION_DAMAGED,
                RentalInspectionObservation::CONDITION_NOT_WORKING,
                RentalInspectionObservation::CONDITION_MISSING,
                RentalInspectionObservation::CONDITION_OTHER,
            ])],
            'notes' => ['nullable', 'string'],
            'source' => ['required', 'string', 'in:' . implode(',', [
                RentalInspectionObservation::SOURCE_IN_INSPECTION,
                RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT,
                RentalInspectionObservation::SOURCE_OUT_INSPECTION,
                RentalInspectionObservation::SOURCE_AD_HOC,
            ])],
            'client_idempotency_key' => ['nullable', 'uuid'],
        ]);

        // §0.3 — a bad rating needs a reason on record.
        if ($validated['condition'] !== RentalInspectionObservation::CONDITION_GOOD && empty($validated['notes'])) {
            return response()->json(['message' => 'Notes are required when the condition is not "good".'], 422);
        }

        $observation = RentalInspectionObservation::record(array_merge($validated, [
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'observed_by_user_id' => $request->user()->id,
        ]));

        return response()->json($observation->load('item'));
    }

    /** POST /corex/rental-inspections/{inspection}/observations/{observation}/photos — §14.5, reuses PropertyImageStorer, never a second pipeline. */
    public function storePhoto(Request $request, RentalInspection $rentalInspection, RentalInspectionObservation $observation): JsonResponse
    {
        abort_if($observation->rental_inspection_id !== $rentalInspection->id, 404);

        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'client_idempotency_key' => 'nullable|uuid',
        ]);

        $clientKey = $request->input('client_idempotency_key');
        if ($clientKey) {
            $existing = RentalInspectionPhoto::where('client_idempotency_key', $clientKey)->first();
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $url = app(PropertyImageStorer::class)->store($request->file('photo'), $rentalInspection->property_id);

        $photo = RentalInspectionPhoto::create([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_observation_id' => $observation->id,
            'storage_path' => $url,
            'uploaded_by_user_id' => $request->user()->id,
            'client_idempotency_key' => $clientKey,
            'file_size_bytes' => $request->file('photo')->getSize(),
        ]);

        return response()->json($photo, 201);
    }

    /** POST /corex/rental-inspections/{inspection}/discrepancies/{discrepancy}/resolve */
    public function resolveDiscrepancy(Request $request, RentalInspection $rentalInspection, RentalInspectionDiscrepancy $discrepancy): JsonResponse
    {
        abort_if($discrepancy->rental_inspection_id !== $rentalInspection->id, 404);

        $validated = $request->validate([
            'accepted_observation_id' => ['required', 'integer', 'exists:rental_inspection_observations,id'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $accepted = RentalInspectionObservation::findOrFail($validated['accepted_observation_id']);
        abort_if(!$discrepancy->observations()->where('rental_inspection_observations.id', $accepted->id)->exists(), 422, 'That observation is not one of this discrepancy\'s participants.');

        $discrepancy->resolve($accepted, $request->user(), $validated['resolution_note'] ?? null);

        return response()->json($discrepancy->fresh());
    }

    /**
     * POST /corex/rental-inspections/{inspection}/signatures
     *
     * .ai/specs/rental-inspections.md §15 (Johan's 2026-09-20 fuller ruling,
     * building in stages) — §15's new canonical request shape is
     * `party_role`/`disposition`/`party_contact_id`. Stage 1 lands the model
     * only (per the conductor's own staging); the property tab's UI still
     * sends the OLD shape (`signer_role`/`refused_note`) until Stage 2-4
     * rebuild it, so this endpoint accepts both, translating the old shape
     * to the new one rather than breaking the live tenant-signing flow
     * mid-rebuild.
     *
     * `signer_role='agent_on_behalf'` (the old refusal path) is temporarily
     * unavailable — Stage 4 ("refusal capture and the agent attestation")
     * is what rebuilds it properly, per-party, with the agent-signs-last
     * rule (§15.2a). Returning a clear 422 here rather than silently
     * mis-mapping it into the new shape is deliberate: a refusal is
     * evidence for a lease agreement, and guessing at it is worse than
     * saying plainly it isn't ready yet.
     */
    public function storeSignature(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        if ($request->filled('party_role')) {
            return $this->storeSignatureNewShape($request, $rentalInspection);
        }

        $validated = $request->validate([
            'signer_role' => ['required', 'string', 'in:tenant,agent_on_behalf,landlord'],
            'signature_image' => ['nullable', 'string'], // base64 PNG from the canvas capture, §3.6
            'refused_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['signer_role'] === 'agent_on_behalf') {
            return response()->json([
                'message' => 'Recording a refusal is being rebuilt for the new three-party signing model and is temporarily unavailable — coming back in a later stage of this build.',
            ], 422);
        }

        $partyRole = $validated['signer_role']; // 'tenant' | 'landlord'

        if ($partyRole === RentalInspectionSignature::PARTY_TENANT) {
            $tenantContactIds = LeaseTenant::where('lease_id', $rentalInspection->lease_id)->pluck('contact_id');
            if ($tenantContactIds->count() !== 1) {
                return response()->json([
                    'message' => $tenantContactIds->count() === 0
                        ? 'This lease has no tenant on record to sign.'
                        : 'This lease has more than one tenant — per-tenant signing is coming in a later stage of this build; each tenant cannot yet be individually selected here.',
                ], 422);
            }
            $partyContactId = $tenantContactIds->first();
        } else { // landlord
            $partyContactId = $rentalInspection->property?->sellerOwnerContact()?->id;
            if (! $partyContactId) {
                return response()->json(['message' => 'This property has no resolvable landlord contact to sign.'], 422);
            }
        }

        $attributes = ['party_contact_id' => $partyContactId, 'recorded_by_user_id' => $request->user()->id];
        if (!empty($validated['signature_image'])) {
            $attributes['party_signature_path'] = RentalInspectionSignature::storeCanvasImage($validated['signature_image'], $rentalInspection->property_id);
        }

        try {
            $signature = RentalInspectionSignature::capture($rentalInspection, $partyRole, RentalInspectionSignature::DISPOSITION_SIGNED, $attributes);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($signature, 201);
    }

    /** §15.10's canonical shape — what Stage 2+'s rebuilt UI and the future mobile API both call. */
    private function storeSignatureNewShape(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $validated = $request->validate([
            'party_role' => ['required', 'string', 'in:' . implode(',', [
                RentalInspectionSignature::PARTY_TENANT,
                RentalInspectionSignature::PARTY_LANDLORD,
                RentalInspectionSignature::PARTY_AGENT,
            ])],
            'disposition' => ['required', 'string', 'in:' . implode(',', [
                RentalInspectionSignature::DISPOSITION_SIGNED,
                RentalInspectionSignature::DISPOSITION_REFUSED,
            ])],
            'party_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'signature_image' => ['nullable', 'string'],
            'refusal_reason_preset' => ['nullable', 'string', 'max:60'],
            'refusal_reason_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['party_role'] !== RentalInspectionSignature::PARTY_AGENT) {
            $attributes = [
                'party_contact_id' => $validated['party_contact_id'] ?? null,
                'refusal_reason_preset' => $validated['refusal_reason_preset'] ?? null,
                'refusal_reason_note' => $validated['refusal_reason_note'] ?? null,
                'recorded_by_user_id' => $request->user()->id,
            ];
        } else {
            $attributes = ['recorded_by_user_id' => $request->user()->id];
        }

        if (!empty($validated['signature_image'])) {
            $attributes['party_signature_path'] = RentalInspectionSignature::storeCanvasImage($validated['signature_image'], $rentalInspection->property_id);
        }

        try {
            $signature = RentalInspectionSignature::capture($rentalInspection, $validated['party_role'], $validated['disposition'], $attributes);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($signature, 201);
    }

    /** POST /corex/rental-inspections/{inspection}/start-awaiting-signature */
    public function startAwaitingSignature(RentalInspection $rentalInspection): JsonResponse
    {
        try {
            $rentalInspection->startAwaitingSignature();
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($rentalInspection->fresh());
    }

    /** POST /corex/rental-inspections/{inspection}/complete */
    public function complete(RentalInspection $rentalInspection): JsonResponse
    {
        try {
            $rentalInspection->markCompleted();
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($rentalInspection->fresh());
    }
}
