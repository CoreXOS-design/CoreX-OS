<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalWorkOrderOwnerMail;
use App\Mail\Rentals\RentalWorkOrderSupplierMail;
use App\Mail\Rentals\RentalWorkOrderTenantMail;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;

/**
 * .ai/specs/rental-work-orders.md §3/§4/§11/§13, Stage 4 — status
 * transitions, the completion gate, and the update-log writer live on the
 * model itself (RentalWorkOrder, matching RentalInspection/RentalFaultReport's
 * own established pattern in this codebase); this service is where a work
 * order gets CREATED — directly, or from an already-approved fault report —
 * and where every notification (internal + external mail) actually fires.
 */
class RentalWorkOrderService
{
    /**
     * §3.2 — a work order raised directly: owner-instructed proactive work,
     * straight from an inspection observation, or (per §3.2's own flagged
     * design call) a rare agent-bypass tenant report that skips a fault
     * report entirely.
     */
    public function report(Property $property, array $attributes): RentalWorkOrder
    {
        $workOrder = RentalWorkOrder::create(array_merge($attributes, [
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => $attributes['owner_approval_status'] ?? RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => $attributes['reported_at'] ?? now(),
        ]));

        $this->notifyCreated($workOrder);
        $this->notifyOwner($workOrder, RentalWorkOrderOwnerMail::STAGE_CREATED);
        $this->notifyTenant($workOrder);

        return $workOrder;
    }

    /**
     * §3a.1/§0c — the agency_appoints route. The owner is not asked twice:
     * this work order inherits 'approved' directly from the fault report
     * that already satisfied it. The tenant is NOT notified here — they
     * were already notified at fault-report creation (§3a/§4).
     */
    public function fromFaultReport(RentalFaultReport $faultReport, User $by, array $attributes): RentalWorkOrder
    {
        if ($faultReport->approval_route !== RentalFaultReport::ROUTE_AGENCY_APPOINTS) {
            throw new \LogicException('A work order can only be raised from a fault report approved via the agency_appoints route.');
        }
        if (in_array($faultReport->status, [
            RentalFaultReport::STATUS_WORK_ORDER_RAISED,
            RentalFaultReport::STATUS_RESOLVED,
            RentalFaultReport::STATUS_CANCELLED,
        ], true)) {
            throw new \LogicException('This fault report has already moved past the point a work order can be raised from it.');
        }

        $workOrder = RentalWorkOrder::create(array_merge($attributes, [
            'agency_id' => $faultReport->agency_id,
            'branch_id' => $faultReport->branch_id,
            'property_id' => $faultReport->property_id,
            'lease_id' => $faultReport->lease_id,
            'rental_inspection_item_id' => $faultReport->rental_inspection_item_id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_FAULT_REPORT,
            'reported_fault_report_id' => $faultReport->id,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED,
            'status' => RentalWorkOrder::STATUS_REPORTED,
            'reported_at' => now(),
            'created_by_user_id' => $by->id,
        ]));

        $faultReport->recordWorkOrderRaised($workOrder, $by);

        $this->notifyCreated($workOrder);
        $this->notifyOwner($workOrder, RentalWorkOrderOwnerMail::STAGE_CREATED);

        return $workOrder;
    }

    /** §3a.3/§3.1 — same image pipeline as every other photo in this feature family. */
    public function storePhoto(RentalWorkOrder $workOrder, UploadedFile $file, string $photoType, User $uploadedBy, ?string $clientKey = null): \App\Models\RentalWorkOrderPhoto
    {
        $url = app(PropertyImageStorer::class)->store($file, $workOrder->property_id);

        return $workOrder->photos()->create([
            'agency_id' => $workOrder->agency_id,
            'photo_type' => $photoType,
            'storage_path' => $url,
            'uploaded_by_user_id' => $uploadedBy->id,
            'client_idempotency_key' => $clientKey,
            'file_size_bytes' => $file->getSize(),
        ]);
    }

    /** §4 — fires to the property's assigned agent. */
    public function notifyCreated(RentalWorkOrder $workOrder): void
    {
        $this->fireInternal($workOrder, 'rental_work_order.created', 'Work order logged — ' . $this->addressFor($workOrder), $workOrder->title);
    }

    /** §4 — fires to the property's assigned agent as confirmation. */
    public function notifyCompleted(RentalWorkOrder $workOrder): void
    {
        $this->fireInternal($workOrder, 'rental_work_order.completed', 'Work order completed — ' . $this->addressFor($workOrder), $workOrder->title);
        $this->notifyOwner($workOrder, RentalWorkOrderOwnerMail::STAGE_COMPLETED);
    }

    /**
     * §4/§6 — fires to the assigned agent (and branch manager, [cc4 design
     * call]) when overdue. Keys the dispatcher's own dedup off the work
     * order's `updated_at` — a PERSISTENT condition, not a discrete event —
     * so a scan re-running every 30 minutes notifies once per actual
     * status/state change, not once per scan tick. (See notifyCreated()/
     * notifyCompleted(), which correctly use `now()` instead: those really
     * are one-time, genuinely-new facts each time they fire.)
     */
    public function notifyOverdue(RentalWorkOrder $workOrder): void
    {
        $this->fireInternal($workOrder, 'rental_work_order.overdue', 'Work order overdue — ' . $this->addressFor($workOrder), $workOrder->title, $workOrder->updated_at);
    }

    private function fireInternal(RentalWorkOrder $workOrder, string $eventKey, string $title, string $body, $thresholdHitAt = null): void
    {
        $property = $workOrder->property()->with('agent')->first();
        if (!$property || !$property->agent_id || !$property->agent) {
            return;
        }

        app(NotificationDispatcher::class)->fire(
            $property->agent,
            $eventKey,
            $workOrder,
            [
                'title' => $title,
                'body' => $body,
                'action_url' => route('corex.rental-work-orders.show', $workOrder->id),
                'severity' => 'info',
                'threshold_hit_at' => $thresholdHitAt ?? now(),
            ]
        );
    }

    /**
     * §4 — skipped, not attempted, if the property genuinely has no owner
     * attached (the known, non-bug portal-import-stock state, §2).
     */
    public function notifyOwner(RentalWorkOrder $workOrder, string $stage): void
    {
        $owner = $workOrder->property?->sellerOwnerContact();
        if (!$owner || !$owner->email) {
            return;
        }

        Mail::to($owner->email)->send(new RentalWorkOrderOwnerMail($workOrder, $stage, $owner->first_name ?? ''));
    }

    /**
     * §4 — only for a work order raised WITHOUT an upstream fault report;
     * see RentalWorkOrderTenantMail's own docblock. Skipped for the vacancy
     * case (no lease) per §3.1a — there is no tenant to notify.
     */
    public function notifyTenant(RentalWorkOrder $workOrder): void
    {
        if ($workOrder->reported_fault_report_id !== null) {
            return; // already notified at fault-report creation
        }
        if (!$workOrder->lease_id) {
            return; // vacancy — no tenant, §3.1a
        }

        $tenant = $workOrder->lease?->tenants()->with('contact')->orderByDesc('is_primary')->first()?->contact;
        if (!$tenant || !$tenant->email) {
            return;
        }

        Mail::to($tenant->email)->send(new RentalWorkOrderTenantMail($workOrder, $tenant->first_name ?? ''));
    }

    /**
     * §4 — "can even email the supplier," only once one is actually
     * assigned. Resolves the specific provider-contact email if one
     * exists, the provider's own email otherwise — same resolution the
     * existing COC-work-order supplier picker already uses.
     */
    public function notifySupplier(RentalWorkOrder $workOrder): void
    {
        $provider = $workOrder->supplier()->with('serviceContacts')->first();
        if (!$provider) {
            return;
        }

        $email = $provider->serviceContacts->first()?->email ?: $provider->email;
        if (!$email) {
            return;
        }

        Mail::to($email)->send(new RentalWorkOrderSupplierMail($workOrder));
    }

    private function addressFor(RentalWorkOrder $workOrder): string
    {
        $property = $workOrder->property;

        return $property?->buildDisplayAddress() ?: ($property?->title ?: ('Property #' . $workOrder->property_id));
    }
}
