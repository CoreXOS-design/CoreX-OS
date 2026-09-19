<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
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

    /** POST /corex/rental-inspections/{inspection}/signatures — §0.7, the required-phrase gate lives in the model. */
    public function storeSignature(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $validated = $request->validate([
            'signer_role' => ['required', 'string', 'in:' . implode(',', [
                RentalInspectionSignature::SIGNER_TENANT,
                RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF,
                RentalInspectionSignature::SIGNER_LANDLORD,
            ])],
            'signer_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'signature_image' => ['nullable', 'string'], // base64 PNG from the canvas capture, §3.6
            'refused_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = [
            'signer_contact_id' => $validated['signer_contact_id'] ?? null,
            'refused_note' => $validated['refused_note'] ?? null,
        ];

        if ($validated['signer_role'] === RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF) {
            // §6 — sign_on_behalf is gated separately from the base .create
            // permission because it overrides a party's own consent to sign.
            // A route-level permission:* middleware can't see the request
            // body, so this check has to live here, on the one code path
            // that actually reaches the on-behalf case.
            abort_unless($request->user()->hasPermission('rental_inspections.sign_on_behalf'), 403);
            $attributes['signed_by_user_id'] = $request->user()->id;
        }

        if (!empty($validated['signature_image'])) {
            $attributes['signature_path'] = RentalInspectionSignature::storeCanvasImage($validated['signature_image'], $rentalInspection->property_id);
        }

        try {
            $signature = RentalInspectionSignature::capture($rentalInspection, $validated['signer_role'], $attributes);
        } catch (\InvalidArgumentException $e) {
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
