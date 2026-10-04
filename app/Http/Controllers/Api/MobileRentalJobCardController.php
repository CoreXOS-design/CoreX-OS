<?php

namespace App\Http\Controllers\Api;

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
 * other /api/v1/mobile/* controller; same own/branch/all scoping as the web
 * screen (RentalJobCard::scopeVisibleTo()) — a job card id outside the
 * requesting user's scope 404s, never a hidden-link-only guard.
 */
class MobileRentalJobCardController extends Controller
{
    private function guard(Request $request, RentalJobCard $jobCard): void
    {
        $visible = RentalJobCard::query()->visibleTo($request->user())->whereKey($jobCard->id)->exists();
        abort_unless($visible, 404);
    }

    public function show(Request $request, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guard($request, $rentalJobCard);
        $rentalJobCard->load(['property', 'lease.tenants.contact', 'assignedUser', 'tasks', 'lines.catalogueItem', 'workOrder.photos']);

        return response()->json($this->payload($rentalJobCard));
    }

    public function update(Request $request, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guard($request, $rentalJobCard);

        $validated = $request->validate(['access_notes' => ['nullable', 'string']]);
        $rentalJobCard->update($validated);

        return response()->json($this->payload($rentalJobCard));
    }

    public function tickTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardTask $task): JsonResponse
    {
        $this->guard($request, $rentalJobCard);
        abort_unless($task->rental_job_card_id === $rentalJobCard->id, 404);

        $service->toggleTask($rentalJobCard, $task, $request->user());

        return response()->json($task->fresh());
    }

    public function storePhoto(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guard($request, $rentalJobCard);

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
            'assigned_user' => $jobCard->assignedUser?->only(['id', 'name']),
            'scheduled_at' => $jobCard->scheduled_at?->toIso8601String(),
            'due_at' => $jobCard->due_at?->toIso8601String(),
            'total_amount' => $jobCard->total_amount,
            'prices_on' => RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id),
            'tasks' => $jobCard->tasks->map(fn ($t) => [
                'id' => $t->id, 'description' => $t->description, 'is_done' => $t->is_done,
            ]),
            'lines' => $jobCard->lines->map(fn ($l) => [
                'id' => $l->id, 'description' => $l->description, 'type' => $l->type,
                'quantity' => $l->quantity, 'unit' => $l->unit, 'unit_price' => $l->unit_price, 'line_total' => $l->line_total,
            ]),
            'photos' => $jobCard->workOrder?->photos->map(fn ($p) => ['id' => $p->id, 'url' => $p->storage_path, 'type' => $p->photo_type]),
        ];
    }
}
