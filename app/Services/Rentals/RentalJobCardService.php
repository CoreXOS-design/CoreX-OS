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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rental-work-orders.md §14 (AT-442). Rebuilt 2026-10-05 — Johan
 * rejected the original "a job card always builds its own work order"
 * design after testing /rental-job-cards/create and /2 on QA1: "today
 * creating a job card silently made a second work order."
 *
 * createStandalone() below is the ONLY creation path
 * RentalJobCardController::store() calls now — it never creates a work
 * order, only ever links to one that already exists, and builds every
 * task + its lines in one transaction (one screen, one Save).
 *
 * createForProperty()/createFromFaultReport()/createJobCard() below are
 * UNCHANGED and still deliberately build a work order — they are called
 * by RentalWorkOrderController::store()'s own assignment_type='internal'
 * path and RentalFaultReportController@raiseWorkOrder, a different,
 * already-tested feature ("an internal work order gets its own job card
 * automatically") this rebuild does not touch.
 */
class RentalJobCardService
{
    public function __construct(private RentalJobCardVatService $vat)
    {
    }

    /**
     * REBUILD, 2026-10-05 — the create/store path for /rental-job-cards
     * itself. Never creates a RentalWorkOrder: links to one only when
     * $attributes['rental_work_order_id'] (an existing work order this
     * card is being built for) or $attributes['fault_report_id'] (whose
     * OWN already-raised work order, if any, is inherited — never a new
     * one) names one explicitly. "No source — created directly" (both
     * null) is a normal, permanent outcome, e.g. a garden-service job.
     *
     * Builds every task + its own lines, and every General (task-less)
     * line, in the SAME transaction as the card — nothing is saved until
     * this one call, no stray records on a failed submit.
     *
     * @param array{
     *   property_id?: int, lease_id?: int, rental_work_order_id?: int,
     *   fault_report_id?: int, title: string, access_notes?: string,
     *   tasks?: array<int, array{description?: string, lines?: array<int, array>}>,
     *   general_lines?: array<int, array>,
     * } $attributes
     */
    public function createStandalone(array $attributes, User $by): RentalJobCard
    {
        return DB::transaction(function () use ($attributes, $by) {
            $workOrder = null;
            $faultReport = null;
            $property = null;
            $leaseId = $attributes['lease_id'] ?? null;

            if (!empty($attributes['rental_work_order_id'])) {
                $workOrder = RentalWorkOrder::findOrFail($attributes['rental_work_order_id']);
                $property = $workOrder->property;
                $leaseId = $leaseId ?? $workOrder->lease_id;
            }

            if (!empty($attributes['fault_report_id'])) {
                $faultReport = RentalFaultReport::findOrFail($attributes['fault_report_id']);
                $property = $property ?? $faultReport->property;
                $leaseId = $leaseId ?? $faultReport->lease_id;
                // A fault report that already has a work order raised
                // against it carries that link straight through — never a
                // second one created here.
                if (!$workOrder && $faultReport->rental_work_order_id) {
                    $workOrder = $faultReport->workOrder;
                }
            }

            if (!$property) {
                $property = Property::findOrFail($attributes['property_id']);
            }

            $jobCard = RentalJobCard::create([
                'agency_id' => $property->agency_id,
                'branch_id' => $property->branch_id,
                'rental_work_order_id' => $workOrder?->id,
                'rental_fault_report_id' => $faultReport?->id,
                'property_id' => $property->id,
                'lease_id' => $leaseId,
                'title' => $attributes['title'],
                'status' => RentalJobCard::STATUS_DRAFT,
                'access_notes' => $attributes['access_notes'] ?? null,
                'created_by_user_id' => $by->id,
            ]);

            foreach ($attributes['tasks'] ?? [] as $i => $taskData) {
                $description = trim((string) ($taskData['description'] ?? ''));
                if ($description === '') {
                    continue;
                }
                $task = $jobCard->tasks()->create([
                    'agency_id' => $jobCard->agency_id,
                    'description' => $description,
                    'sort_order' => $i + 1,
                    'created_by_user_id' => $by->id,
                ]);
                foreach ($taskData['lines'] ?? [] as $lineData) {
                    if ($this->lineHasContent($lineData)) {
                        $this->addLine($jobCard, $lineData, $by, $task, false);
                    }
                }
            }
            foreach ($attributes['general_lines'] ?? [] as $lineData) {
                if ($this->lineHasContent($lineData)) {
                    $this->addLine($jobCard, $lineData, $by, null, false);
                }
            }

            $jobCard->recalcTotal();

            return $jobCard->fresh(['tasks.lines', 'lines']);
        });
    }

    private function lineHasContent(array $lineData): bool
    {
        return !empty($lineData['rental_catalogue_item_id']) || trim((string) ($lineData['description'] ?? '')) !== '';
    }

    /**
     * A job card raised directly from the property (no upstream fault
     * report) — req #1/#4. Creates the work order and the job card
     * together, atomically. UNCHANGED by the 2026-10-05 rebuild — called
     * by RentalWorkOrderController::store()'s internal-assignment path,
     * not by RentalJobCardController any more (see createStandalone()
     * above).
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

    public function renameTask(RentalJobCard $jobCard, RentalJobCardTask $task, string $description, User $by): void
    {
        abort_unless($task->rental_job_card_id === $jobCard->id, 404);
        $old = $task->description;
        $task->forceFill(['description' => $description])->save();
        $jobCard->logUpdate('task_renamed', $by, "{$old} \u{2192} {$description}");
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
     *
     * $task — the task this line sits under; null puts it in the built-in
     * "General" group. $log — false during createStandalone()'s own bulk
     * build, so a single Save doesn't write one history row per line; true
     * for every line added one at a time after the card already exists.
     */
    public function addLine(RentalJobCard $jobCard, array $attributes, User $by, ?RentalJobCardTask $task = null, bool $log = true): RentalJobCardLine
    {
        $catalogueItem = isset($attributes['rental_catalogue_item_id'])
            ? RentalCatalogueItem::find($attributes['rental_catalogue_item_id'])
            : null;

        $pricesOn = \App\Models\RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id);
        $quantity = (float) ($attributes['quantity'] ?? 1);
        $agency = $jobCard->agency ?? \App\Models\Agency::withoutGlobalScopes()->find($jobCard->agency_id);
        // Pastel-style enhancement, 2026-10-05 — a catalogue item's default
        // price is always stored excl-VAT; converted here to whatever the
        // agency currently captures on job card lines (RentalJobCardVatService).
        $catalogueDefaultPrice = $catalogueItem && $agency ? $this->vat->catalogueDefaultPriceForLine($catalogueItem, $agency) : $catalogueItem?->default_price;
        $unitPrice = $pricesOn ? ($attributes['unit_price'] ?? $catalogueDefaultPrice) : null;

        // VAT type — the agent's explicit pick on this line, else the
        // catalogue item's own default, else the agency's default type.
        // Null for an agency that isn't VAT registered (nothing to pick).
        $vatTypeId = $attributes['rental_vat_type_id'] ?? $this->vat->defaultVatTypeIdFor($jobCard->agency_id, $catalogueItem);
        $customVatRate = $attributes['custom_vat_rate'] ?? $catalogueItem?->default_custom_vat_rate;

        $line = $jobCard->lines()->create([
            'agency_id' => $jobCard->agency_id,
            'rental_job_card_task_id' => $task?->id,
            'rental_catalogue_item_id' => $catalogueItem?->id,
            'code' => $catalogueItem?->code,
            // 2026-10-05 QA1 finding (Johan) — reversed from the AT-442
            // original: the Type select used to be disabled the moment a
            // catalogue item was picked, and its value silently ignored
            // ("the disabled <select> must never win over the catalogue
            // item"). Johan's own instruction this round: "the Type select
            // must be changeable" — picking an item now PRE-FILLS type from
            // the item's own kind (still happens client-side AND here, as
            // the fallback for any caller that doesn't post one), but an
            // explicitly posted type wins, same as description/unit/price
            // already work. See RentalJobCardAt442FollowUpTest's renamed
            // assertion for the behaviour this replaces.
            'type' => $attributes['type'] ?? $catalogueItem?->kind() ?? RentalCatalogueItem::TYPE_LABOUR,
            'description' => $attributes['description'] ?? $catalogueItem?->description ?? '',
            'unit' => $attributes['unit'] ?? $catalogueItem?->catalogueUnit?->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice !== null ? round($quantity * (float) $unitPrice, 2) : null,
            'rental_vat_type_id' => $vatTypeId,
            'custom_vat_rate' => $customVatRate,
            'sort_order' => (int) ($jobCard->lines()->max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $by->id,
        ]);

        $jobCard->recalcTotal();
        if ($log) {
            $jobCard->logUpdate('line_added', $by, $line->description);
        }

        return $line;
    }

    public function updateLine(RentalJobCard $jobCard, RentalJobCardLine $line, array $attributes, User $by): void
    {
        abort_unless($line->rental_job_card_id === $jobCard->id, 404);

        $pricesOn = \App\Models\RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id);
        $quantity = (float) ($attributes['quantity'] ?? $line->quantity);
        $unitPrice = $pricesOn ? ($attributes['unit_price'] ?? $line->unit_price) : null;
        $vatTypeId = array_key_exists('rental_vat_type_id', $attributes) ? $attributes['rental_vat_type_id'] : $line->rental_vat_type_id;
        $customVatRate = array_key_exists('custom_vat_rate', $attributes) ? $attributes['custom_vat_rate'] : $line->custom_vat_rate;

        $line->forceFill([
            'description' => $attributes['description'] ?? $line->description,
            'unit' => $attributes['unit'] ?? $line->unit,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice !== null ? round($quantity * (float) $unitPrice, 2) : null,
            'rental_vat_type_id' => $vatTypeId,
            'custom_vat_rate' => $customVatRate,
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
     * REBUILD, 2026-10-05 — a job card created with no source (the normal
     * case now) has no work order to ride the threshold/approval gate
     * against. Creating one HERE, lazily, the moment a quote is actually
     * sent, is the one place this feature still creates a work order after
     * the initial Save — deliberate and visible (the agent just clicked
     * "Send to owner as quote"), never a side effect of merely saving the
     * card. A card already linked to a work order (an explicit source)
     * reuses it unchanged.
     */
    private function ensureWorkOrderForQuote(RentalJobCard $jobCard, User $by): RentalWorkOrder
    {
        if ($jobCard->workOrder) {
            return $jobCard->workOrder;
        }

        $workOrder = RentalWorkOrder::create([
            'agency_id' => $jobCard->agency_id,
            'branch_id' => $jobCard->branch_id,
            'property_id' => $jobCard->property_id,
            'lease_id' => $jobCard->lease_id,
            'reported_fault_report_id' => $jobCard->rental_fault_report_id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_INTERNAL,
            'title' => $jobCard->title,
            'description' => $jobCard->title,
            'status' => RentalWorkOrder::STATUS_REPORTED,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $by->id,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(),
            'created_by_user_id' => $by->id,
        ]);

        $jobCard->forceFill(['rental_work_order_id' => $workOrder->id])->save();

        return $workOrder;
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

        // Freeze VAT (registration/rate/capture-mode/type, per line) at the
        // moment the quote goes out — a later change to any of the agency's
        // VAT settings must never alter an issued quote.
        $this->vat->snapshot($jobCard);
        $jobCard->refresh();

        $pdf = $pdfService->jobCardQuotePdf($jobCard);
        $path = 'rental-job-card-quotes/' . $jobCard->id . '/' . now()->timestamp . '.pdf';
        Storage::disk('local')->put($path, $pdf->output());

        // The landlord pays the VAT-inclusive figure, so that is both the
        // quote amount AND the figure the no-approval spend threshold
        // compares against (RentalWorkOrder::selectQuote()) — identical to
        // total_amount when the agency isn't VAT registered.
        $workOrder = $this->ensureWorkOrderForQuote($jobCard, $by);
        $quote = $workOrder->recordQuote([
            'rental_job_card_id' => $jobCard->id,
            'agency_service_provider_id' => null,
            'amount' => $this->vat->inclusiveTotal($jobCard),
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

    /**
     * REBUILD, 2026-10-05 — a job card now very often has no linked work
     * order at all, so its photos can no longer ride on
     * RentalWorkOrderService::storePhoto() (which requires one). Stored
     * directly against the card (rental_work_order_photos.rental_job_card_id);
     * also cross-referenced onto a linked work order's own
     * rental_work_order_id when one exists, same evidence pipeline either way.
     */
    public function storePhoto(RentalJobCard $jobCard, UploadedFile $file, string $photoType, User $uploadedBy, ?string $clientKey = null): RentalWorkOrderPhoto
    {
        $url = app(PropertyImageStorer::class)->store($file, $jobCard->property_id);

        $photo = RentalWorkOrderPhoto::create([
            'agency_id' => $jobCard->agency_id,
            'rental_work_order_id' => $jobCard->rental_work_order_id,
            'rental_job_card_id' => $jobCard->id,
            'photo_type' => $photoType,
            'storage_path' => $url,
            'uploaded_by_user_id' => $uploadedBy->id,
            'client_idempotency_key' => $clientKey,
            'file_size_bytes' => $file->getSize(),
        ]);

        $jobCard->logUpdate('photo_added', $uploadedBy, ucfirst(str_replace('_', ' ', $photoType)) . ' photo uploaded');

        return $photo;
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
        // A job card can reach completion without ever having a quote sent
        // (under-threshold internal jobs often won't need one) — freeze VAT
        // here too, idempotently, so a closed card's figures never move
        // even if nothing froze them earlier.
        $this->vat->snapshot($jobCard);
        $jobCard->refresh();

        $jobCard->complete($by);

        $workOrder = $jobCard->workOrder;
        if ($workOrder && $workOrder->status !== RentalWorkOrder::STATUS_COMPLETED) {
            try {
                $workOrder->complete($by, [
                    'paid_by' => RentalWorkOrder::PAID_BY_OWNER,
                    // VAT-inclusive — the actual amount the owner pays, same
                    // figure the quote/threshold already used.
                    'cost_amount' => $this->vat->inclusiveTotal($jobCard),
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
