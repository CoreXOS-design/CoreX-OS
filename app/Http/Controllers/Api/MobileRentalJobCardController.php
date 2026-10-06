<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardTask;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §14 (AT-442 req #10) — mobile mirror of
 * the job card screen for Andre's app: read, tick a task, upload a photo.
 * Same {"message": ...} error convention and Sanctum bearer auth as every
 * other /api/v1/mobile/* controller.
 *
 * Scoping is TWO layers, same as the web screen: route-model-binding's
 * global AgencyScope 404s a cross-agency id before this class is ever
 * reached; guardRentalRecordScope() (AT-439's AuthorizesRentalRecordScope,
 * same trait the web RentalJobCardController uses) then 403s a SAME-agency
 * id outside the requesting user's own/branch ceiling — a hidden-link-only
 * guard would have missed exactly that second case.
 */
class MobileRentalJobCardController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function show(Request $request, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);
        $rentalJobCard->load(['property', 'lease.tenants.contact', 'crew.members', 'assignedUser', 'tasks', 'lines.catalogueItem', 'workOrder.photos']);

        return response()->json($this->payload($rentalJobCard));
    }

    public function update(Request $request, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate(['access_notes' => ['nullable', 'string']]);
        $rentalJobCard->update($validated);

        return response()->json($this->payload($rentalJobCard));
    }

    public function tickTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardTask $task): JsonResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);
        abort_unless($task->rental_job_card_id === $rentalJobCard->id, 404);

        // §14.21 — a closed card's tasks never change; a clear 422, not a 500.
        try {
            $service->toggleTask($rentalJobCard, $task, $request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($task->fresh());
    }

    public function storePhoto(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'photo_type' => ['required', 'in:reported,in_progress,completed'],
            'client_idempotency_key' => 'nullable|uuid',
        ]);

        $photo = $service->storePhoto($rentalJobCard, $request->file('photo'), $validated['photo_type'], $request->user(), $validated['client_idempotency_key'] ?? null);

        return response()->json($photo, 201);
    }

    private function payload(RentalJobCard $jobCard): array
    {
        return [
            'id' => $jobCard->id,
            'title' => $jobCard->title,
            'status' => $jobCard->status,
            'property_address' => $jobCard->property?->buildDisplayAddress(),
            'tenant_names' => $jobCard->lease?->tenantNames(),
            'access_notes' => $jobCard->access_notes,
            // 2026-10-05 — crew is the current assignment; assigned_user is
            // LEGACY ONLY (a card from before crews existed, never written
            // again) and null on every job card created from now on.
            'crew' => $jobCard->crew ? [
                'id' => $jobCard->crew->id, 'name' => $jobCard->crew->name,
                'members' => $jobCard->crew->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ] : null,
            'assigned_user' => $jobCard->assignedUser?->only(['id', 'name']),
            'scheduled_at' => $jobCard->scheduled_at?->toIso8601String(),
            'due_at' => $jobCard->due_at?->toIso8601String(),
            'total_amount' => $jobCard->total_amount,
            'prices_on' => RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id),
            'tasks' => $jobCard->tasks->map(fn ($t) => [
                'id' => $t->id, 'description' => $t->description, 'is_done' => $t->is_done,
            ]),
            'lines' => $jobCard->lines->filter(fn ($l) => $l->isAccepted())->values()->map(fn ($l) => [
                'id' => $l->id, 'description' => $l->description, 'type' => $l->type,
                'quantity' => $l->quantity, 'unit' => $l->unit, 'unit_price' => $l->unit_price, 'line_total' => $l->line_total,
            ]),
            'photos' => $jobCard->workOrder?->photos->map(fn ($p) => ['id' => $p->id, 'url' => $p->storage_path, 'type' => $p->photo_type]),
        ];
    }
}
