<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardTask;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\User;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rental-work-orders.md §14 (AT-442) — a job card BUILDS its work
 * order: creating one creates the linked rental_work_orders row (or links
 * to an already-existing one, when raised from a fault report) with
 * assignment_type='internal'. Mirrors RentalWorkOrderService's own split —
 * creation + cross-cutting actions (quote PDF, photos) live here; per-record
 * lifecycle transitions that need no I/O live on the model, matching
 * RentalJobCard's own established pattern.
 */
class RentalJobCardService
{
    /**
     * A job card raised directly from the property (no upstream fault
     * report) — req #1/#4. Creates the work order and the job card
     * together, atomically.
     */
    public function createForProperty(Property $property, array $attributes, User $by): RentalJobCard
    {
        $workOrder = RentalWorkOrder::create([
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'lease_id' => $attributes['lease_id'] ?? null,
            'rental_inspection_item_id' => $attributes['rental_inspection_item_id'] ?? null,
            // §15 (AT-447) — the "Create job card" shortcut from an
            // inspection's Follow-up block carries this through so the job
            // card's own "From inspection <type> <date>" back-link (reached
            // via workOrder.reportedInspectionObservation.inspection) works
            // exactly like a directly-raised work order's.
            'reported_inspection_observation_id' => $attributes['reported_inspection_observation_id'] ?? null,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_INTERNAL,
            'title' => $attributes['title'],
            // rental_work_orders.description is NOT NULL — BUILD_STANDARD §2:
            // an optional-and-empty field must never 500. Falls back to the
            // title, matching RentalWorkOrderController::store()'s own
            // same-shaped fallback for the directly-raised path.
            'description' => $attributes['description'] ?? $attributes['title'],
            'status' => RentalWorkOrder::STATUS_REPORTED,
            'reported_by_type' => $attributes['reported_by_type'] ?? RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => ($attributes['reported_by_type'] ?? null) === RentalWorkOrder::REPORTED_BY_AGENT_NOTICED ? $by->id : null,
            'reported_by_contact_id' => $attributes['reported_by_contact_id'] ?? null,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(),
            'created_by_user_id' => $by->id,
        ]);

        return $this->createJobCard($workOrder, $attributes, $by);
    }

    /**
     * §14 — a job card raised FROM an already-approved fault report, both
     * for the supplier and the internal path (req #4: "works for both").
     * Delegates work-order creation to the SAME method the existing supplier
     * flow uses (RentalWorkOrderService::fromFaultReport()) so the approval-
     * inheritance/status rules never have two implementations.
     */
    public function createFromFaultReport(RentalFaultReport $faultReport, array $attributes, User $by): RentalJobCard
    {
        $workOrder = app(RentalWorkOrderService::class)->fromFaultReport($faultReport, $by, [
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? $attributes['title'],
        ]);

        $workOrder->forceFill(['assignment_type' => RentalWorkOrder::ASSIGNMENT_INTERNAL])->save();

        return $this->createJobCard($workOrder, $attributes, $by);
    }

    private function createJobCard(RentalWorkOrder $workOrder, array $attributes, User $by): RentalJobCard
    {
        // No update row is logged here — matching RentalWorkOrder's own
        // creation path exactly: the "Logged" history entry is synthesized
        // from created_by_user_id/created_at (RentalJobCard::history()),
        // never written as a real row. A real row here would permanently
        // block isDeletable() (updates()->doesntExist()) on every job
        // card, even one with nothing else logged against it yet.
        return RentalJobCard::create([
            'agency_id' => $workOrder->agency_id,
            'branch_id' => $workOrder->branch_id,
            'rental_work_order_id' => $workOrder->id,
            'property_id' => $workOrder->property_id,
            'lease_id' => $workOrder->lease_id,
            'title' => $attributes['title'],
            'status' => RentalJobCard::STATUS_DRAFT,
            'access_notes' => $attributes['access_notes'] ?? null,
            'created_by_user_id' => $by->id,
        ]);
    }

    public function addTask(RentalJobCard $jobCard, string $description, User $by): RentalJobCardTask
    {
        $task = $jobCard->tasks()->create([
            'agency_id' => $jobCard->agency_id,
            'description' => $description,
            'sort_order' => (int) ($jobCard->tasks()->max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $by->id,
        ]);
        $jobCard->logUpdate('task_added', $by, $description);

        return $task;
    }

    public function toggleTask(RentalJobCard $jobCard, RentalJobCardTask $task, User $by): void
    {
        abort_unless($task->rental_job_card_id === $jobCard->id, 404);

        $isDone = ! $task->is_done;
        $task->forceFill([
            'is_done' => $isDone,
            'done_by_user_id' => $isDone ? $by->id : null,
            'done_at' => $isDone ? now() : null,
        ])->save();

        $jobCard->logUpdate('task_ticked', $by, ($isDone ? 'Ticked: ' : 'Unticked: ') . $task->description);
    }

    /** @param array<int> $orderedIds */
    public function reorderTasks(RentalJobCard $jobCard, array $orderedIds, User $by): void
    {
        foreach ($orderedIds as $i => $id) {
            RentalJobCardTask::where('rental_job_card_id', $jobCard->id)->where('id', $id)->update(['sort_order' => $i + 1]);
        }
        $jobCard->logUpdate('task_added', $by, 'Tasks reordered');
    }

    public function archiveTask(RentalJobCard $jobCard, RentalJobCardTask $task, User $by): void
    {
        abort_unless($task->rental_job_card_id === $jobCard->id, 404);
        $task->delete();
        $jobCard->logUpdate('task_archived', $by, $task->description);
    }

    public function restoreTask(RentalJobCard $jobCard, int $taskId, User $by): void
    {
        $task = RentalJobCardTask::onlyTrashed()->where('rental_job_card_id', $jobCard->id)->findOrFail($taskId);
        $task->restore();
        $jobCard->logUpdate('task_added', $by, 'Restored: ' . $task->description);
    }

    /**
     * A line from the catalogue, or free text when nothing fits. Prices are
     * applied only when the agency's capture_prices_on_job_cards setting is
     * on (rental_work_order_settings) — with it off, unit_price/line_total
     * always save null regardless of what's posted, never silently kept
     * from a stale catalogue default.
     */
    public function addLine(RentalJobCard $jobCard, array $attributes, User $by): RentalJobCardLine
    {
        $catalogueItem = isset($attributes['rental_catalogue_item_id'])
            ? RentalCatalogueItem::find($attributes['rental_catalogue_item_id'])
            : null;

        $pricesOn = \App\Models\RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id);
        $quantity = (float) ($attributes['quantity'] ?? 1);
        $unitPrice = $pricesOn ? ($attributes['unit_price'] ?? $catalogueItem?->default_price) : null;

        $line = $jobCard->lines()->create([
            'agency_id' => $jobCard->agency_id,
            'rental_catalogue_item_id' => $catalogueItem?->id,
            // AT-442 fix #5 — a catalogue item keeps its OWN type; the posted
            // 'type' only applies to a free-text line (no catalogue item).
            'type' => $catalogueItem?->type ?? $attributes['type'] ?? RentalCatalogueItem::TYPE_LABOUR,
            'description' => $attributes['description'] ?? $catalogueItem?->name ?? '',
            'unit' => $attributes['unit'] ?? $catalogueItem?->unit,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice !== null ? round($quantity * (float) $unitPrice, 2) : null,
            'sort_order' => (int) ($jobCard->lines()->max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $by->id,
        ]);

        $jobCard->recalcTotal();
        $jobCard->logUpdate('line_added', $by, $line->description);

        return $line;
    }

    public function updateLine(RentalJobCard $jobCard, RentalJobCardLine $line, array $attributes, User $by): void
    {
        abort_unless($line->rental_job_card_id === $jobCard->id, 404);

        $pricesOn = \App\Models\RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id);
        $quantity = (float) ($attributes['quantity'] ?? $line->quantity);
        $unitPrice = $pricesOn ? ($attributes['unit_price'] ?? $line->unit_price) : null;

        $line->forceFill([
            'description' => $attributes['description'] ?? $line->description,
            'unit' => $attributes['unit'] ?? $line->unit,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice !== null ? round($quantity * (float) $unitPrice, 2) : null,
        ])->save();

        $jobCard->recalcTotal();
        $jobCard->logUpdate('line_changed', $by, $line->description);
    }

    public function archiveLine(RentalJobCard $jobCard, RentalJobCardLine $line, User $by): void
    {
        abort_unless($line->rental_job_card_id === $jobCard->id, 404);
        $line->delete();
        $jobCard->recalcTotal();
        $jobCard->logUpdate('line_archived', $by, $line->description);
    }

    public function restoreLine(RentalJobCard $jobCard, int $lineId, User $by): void
    {
        $line = RentalJobCardLine::onlyTrashed()->where('rental_job_card_id', $jobCard->id)->findOrFail($lineId);
        $line->restore();
        $jobCard->recalcTotal();
        $jobCard->logUpdate('line_added', $by, 'Restored: ' . $line->description);
    }

    /**
     * Req #5 — "Send to owner as quote." Generates the PDF, records it on
     * the linked work order via the EXISTING recordQuote()/selectQuote()
     * mechanism (rental-work-orders.md §3.4c) — the property's landlord
     * spend threshold applies exactly as it does to a supplier quote: at or
     * under it, auto-approved; over it, owner approval required. No second
     * approval mechanism is built here.
     */
    public function sendToOwnerAsQuote(RentalJobCard $jobCard, User $by, RentalDocumentPdfService $pdfService): \App\Models\RentalWorkOrderQuote
    {
        if ($jobCard->lines()->doesntExist()) {
            throw new \LogicException('Add at least one line before sending this job card to the owner as a quote.');
        }

        // AT-442 follow-up (item 8) — an owner quote must NEVER be addressed
        // to a tenant. landlordContact() (unlike sellerOwnerContact()) has no
        // sole-contact fallback, so a property with only a tenant linked
        // resolves null here — block the whole send, not just the mail.
        if (!$jobCard->property?->landlordContact()) {
            throw new \LogicException('No landlord linked — link a landlord before sending the quote.');
        }

        $jobCard->recalcTotal();
        $jobCard->refresh();

        $pdf = $pdfService->jobCardQuotePdf($jobCard);
        $path = 'rental-job-card-quotes/' . $jobCard->id . '/' . now()->timestamp . '.pdf';
        Storage::disk('local')->put($path, $pdf->output());

        $workOrder = $jobCard->workOrder;
        $quote = $workOrder->recordQuote([
            'rental_job_card_id' => $jobCard->id,
            'agency_service_provider_id' => null,
            'amount' => $jobCard->total_amount ?? 0,
            'quote_date' => now()->toDateString(),
            'document_storage_path' => $path,
            'detail_text' => 'Quote generated from job card #' . $jobCard->id,
        ], $by);

        $workOrder->selectQuote($quote, $by);

        $fromStatus = $jobCard->status;
        $jobCard->forceFill(['status' => RentalJobCard::STATUS_QUOTED])->save();
        $jobCard->logUpdate('quote_sent', $by, 'R' . number_format((float) $quote->amount, 2), $fromStatus, RentalJobCard::STATUS_QUOTED);

        app(RentalWorkOrderService::class)->notifyOwner($workOrder->fresh(), \App\Mail\Rentals\RentalWorkOrderOwnerMail::STAGE_CREATED);

        $jobCard->syncStatusFromWorkOrder();

        return $quote;
    }

    /** Job card photos ARE the linked work order's photos — one photo pipeline, not two. */
    public function storePhoto(RentalJobCard $jobCard, UploadedFile $file, string $photoType, User $uploadedBy, ?string $clientKey = null): RentalWorkOrderPhoto
    {
        return app(RentalWorkOrderService::class)->storePhoto($jobCard->workOrder, $file, $photoType, $uploadedBy, $clientKey);
    }

    /**
     * Completing a job card also completes its linked work order — found
     * missing via a live Tinker verification: $jobCard->complete() alone
     * (the model's own sign-off gate) never touched the work order at all,
     * because that sync had been written only inside the web controller.
     * Moved here so every caller (web, a future mobile "complete" action,
     * a test calling this service directly) gets the same behaviour —
     * never duplicated in a controller.
     */
    public function complete(RentalJobCard $jobCard, User $by): void
    {
        $jobCard->complete($by);

        $workOrder = $jobCard->workOrder;
        if ($workOrder && $workOrder->status !== RentalWorkOrder::STATUS_COMPLETED) {
            try {
                $workOrder->complete($by, [
                    'paid_by' => RentalWorkOrder::PAID_BY_OWNER,
                    'cost_amount' => $jobCard->total_amount,
                    'completion_notes' => 'Completed via job card #' . $jobCard->id,
                ]);
            } catch (\LogicException) {
                // e.g. agency requires a completed photo — the job card is
                // still marked complete (worker+agent both signed off); the
                // linked work order stays open until that evidence is added
                // via the existing photo upload on the work order itself.
            }
        }
    }
}
