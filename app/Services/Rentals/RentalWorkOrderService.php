<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalContractorWorkOrderMail;
use App\Mail\Rentals\RentalOwnerFinalStatementMail;
use App\Mail\Rentals\RentalOwnerQuoteMail;
use App\Mail\Rentals\RentalOwnerVariationAutoMail;
use App\Mail\Rentals\RentalOwnerVariationMail;
use App\Mail\Rentals\RentalWorkOrderOwnerMail;
use App\Mail\Rentals\RentalWorkOrderTenantMail;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderSetting;
use App\Models\RentalWorkOrderVariation;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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
     * §17.3 (R0) — "Create work order" from a fault report. The gate is relaxed (§17.3.2): a work order may be
     * created from a fault in status reported, awaiting_approval, or approved (agency_appoints) — the owner can only
     * approve a NUMBER once the work order exists (§17.9 step order) — and is refused, in plain words, for a fault
     * the owner declined or is handling themselves, or one that is already past that point
     * ({@see RentalFaultReport::workOrderBlockReason()} is the one place the rule lives).
     *
     * §17.3.3 — NO inherited approval: the new work order starts `not_required` with no approved amount;
     * authorisation is decided by the gate when a quote is selected, the card is scheduled/started, or an emergency
     * approval is captured. The tenant is NOT notified here — they were already notified at fault-report creation.
     */
    public function fromFaultReport(RentalFaultReport $faultReport, User $by, array $attributes): RentalWorkOrder
    {
        if ($reason = $faultReport->workOrderBlockReason()) {
            throw new \LogicException($reason);
        }

        // W3 (8 Oct 2026): the owner's own contractor is "appointed" from the start - the owner arranged them, the agency
        // quotes and authorises nothing (RentalApprovalGateService::authoriseToProceed). Every other route starts as before.
        $ownerContractor = ($attributes['assignment_type'] ?? null) === RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR;

        $workOrder = RentalWorkOrder::create(array_merge($attributes, [
            'agency_id' => $faultReport->agency_id,
            'branch_id' => $faultReport->branch_id,
            'property_id' => $faultReport->property_id,
            'lease_id' => $faultReport->lease_id,
            'rental_inspection_item_id' => $faultReport->rental_inspection_item_id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_FAULT_REPORT,
            'reported_fault_report_id' => $faultReport->id,
            // §17.3.3 — approval no longer rides the fault: not_required, approved_amount null.
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'status' => $ownerContractor ? RentalWorkOrder::STATUS_ORDERED : RentalWorkOrder::STATUS_REPORTED,
            'ordered_at' => $ownerContractor ? now() : null,
            'reported_at' => now(),
            'created_by_user_id' => $by->id,
        ]));
        if ($ownerContractor) {
            $workOrder->updates()->create([
                'agency_id' => $workOrder->agency_id, 'update_type' => 'status_change',
                'from_status' => RentalWorkOrder::STATUS_REPORTED, 'to_status' => RentalWorkOrder::STATUS_ORDERED,
                'note' => "Owner's own contractor" . ($workOrder->contractor_name ? ': ' . $workOrder->contractor_name : '') . ' - arranged by the owner',
                'created_by_user_id' => $by->id,
            ]);
        }

        $faultReport->recordWorkOrderRaised($workOrder, $by);

        $this->notifyCreated($workOrder);
        $this->notifyOwner($workOrder, RentalWorkOrderOwnerMail::STAGE_CREATED);

        return $workOrder;
    }

    /**
     * Johan, 8 Oct 2026 - "no title, no description. choose a contractor. that should create the work order." THE action behind the fault
     * screen's Create work order: the agent only chooses WHO does the work; everything else comes from the fault - the title and description
     * the owner saw ({@see RentalFaultReport::workOrderDraft()}), property, lease (so the tenant), the photos and the owner's decision, all
     * editable on the work order afterwards.
     *
     * $choice: `assignment_type` (internal | outside_supplier | owner_contractor), `agency_service_provider_id` (outside_supplier), and for the
     * owner's own contractor `contractor_name` / `contractor_phone` (used only when the fault carries no decision to take them from).
     *
     * The owner's decision rules the route: owner approved with THEIR contractor -> the work order is for that contractor, exactly as
     * decided; owner chose "the agency appoints" -> the agent picks the agency's contractor or the internal crew, never the owner's own.
     * One work order per fault: the fault row is locked, the block reason re-read on the locked row, and everything - work order, job card,
     * photos, history - is written in ONE transaction, so a double click, two agents, or a failure half-way leave exactly one complete work
     * order or none.
     *
     * @param array<string, mixed> $choice
     * @return array{work_order: RentalWorkOrder, job_card: ?\App\Models\RentalJobCard}
     *
     * @throws \LogicException with a plain sentence when it cannot be done (already created, declined, closed, wrong route)
     */
    public function createFromFaultDecision(RentalFaultReport $fault, User $by, array $choice): array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($fault, $by, $choice) {
            $locked = RentalFaultReport::withoutGlobalScopes()->lockForUpdate()->findOrFail($fault->id);
            if ($reason = $locked->workOrderBlockReason()) {
                throw new \LogicException($reason);
            }

            $draft = $locked->workOrderDraft();
            $decision = $locked->decision();
            $approved = $decision && $decision->decision === \App\Models\RentalApproval::DECISION_APPROVED;
            $ownersOwn = $approved && ($decision->contractor_source === \App\Models\RentalApproval::CONTRACTOR_OWN || $locked->approval_route === RentalFaultReport::ROUTE_OWNER_HANDLES);

            $type = $choice['assignment_type'] ?? ($ownersOwn ? RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR : RentalWorkOrder::ASSIGNMENT_INTERNAL);
            if ($ownersOwn) {
                $type = RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR;   // the owner decided who: the agent only confirms
            } elseif ($approved && $type === RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR) {
                throw new \LogicException('The owner chose for the agency to appoint the contractor - pick one of the agency\'s contractors or the internal crew.');
            }

            $attributes = ['title' => $draft['title'], 'description' => $draft['description'], 'assignment_type' => $type];
            if ($type !== RentalWorkOrder::ASSIGNMENT_INTERNAL && ($trade = app(RentalFaultContractorService::class)->tradeCodeFor($locked))) {
                $attributes['trade_type'] = $trade;
            }

            $supplierId = null;
            if ($type === RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER) {
                $supplierId = (int) ($choice['agency_service_provider_id'] ?? 0);
                $ok = $supplierId > 0 && \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()
                    ->where('agency_id', $locked->agency_id)->where('is_active', true)->whereNull('deleted_at')->maintenanceContractors()->whereKey($supplierId)->exists();
                if (! $ok) {
                    throw new \LogicException('Choose which contractor does the work.');
                }
                $attributes['agency_service_provider_id'] = $supplierId;
            } elseif ($type === RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR) {
                $source = $ownersOwn ? $decision : null;
                $attributes['contractor_name'] = trim((string) ($source?->contractor_name ?? ($choice['contractor_name'] ?? ''))) ?: null;
                $attributes['contractor_phone'] = trim((string) ($source?->contractor_phone ?? ($choice['contractor_phone'] ?? ''))) ?: null;
            }

            $jobCard = null;
            if ($type === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
                $jobCard = app(RentalJobCardService::class)->createFromFaultReport($locked, $attributes, $by);
                $workOrder = $jobCard->workOrder()->withoutGlobalScopes()->firstOrFail();
            } else {
                $workOrder = $this->fromFaultReport($locked, $by, $attributes);
                if ($supplierId) {
                    $workOrder->updates()->create([
                        'agency_id' => $workOrder->agency_id, 'update_type' => 'supplier_assigned', 'created_by_user_id' => $by->id,
                        'note' => 'Contractor chosen by the agent when the work order was created. Ordering still follows the usual quote and authorisation steps.',
                    ]);
                }
            }

            // The photos the owner saw travel with the work order (same stored pictures, not copies of the files).
            foreach ($draft['photos'] as $photo) {
                $workOrder->photos()->create([
                    'agency_id' => $workOrder->agency_id, 'photo_type' => RentalWorkOrder::PHOTO_REPORTED,
                    'storage_path' => $photo->storage_path, 'uploaded_by_user_id' => $by->id, 'file_size_bytes' => $photo->file_size_bytes,
                ]);
            }

            $summary = $locked->decisionSummary();
            $workOrder->updates()->create([
                'agency_id' => $workOrder->agency_id, 'update_type' => 'note', 'created_by_user_id' => $by->id,
                'note' => 'Created from fault report #' . $locked->id . ($summary
                    ? ' - owner ' . ($summary['decision'] === \App\Models\RentalApproval::DECISION_APPROVED ? 'approved' : 'declined') . ' (' . strtolower($summary['how']) . ', ' . ($summary['at']?->format('j M Y') ?? '') . ')'
                        . ($summary['contractor'] ? ' - ' . $summary['contractor'] : '')
                    : ' - no owner decision recorded yet') . '. Title, description and photos taken from the fault.',
            ]);

            return ['work_order' => $workOrder, 'job_card' => $jobCard];
        });
    }

    /**
     * §3a.3/§3.1 — same image pipeline as every other photo in this
     * feature family. AT-445 — $uploadedBy widened to nullable: a
     * contractor uploading "after" photos through their no-login secure
     * link has no User actor at all.
     */
    public function storePhoto(RentalWorkOrder $workOrder, UploadedFile $file, string $photoType, ?User $uploadedBy = null, ?string $clientKey = null): \App\Models\RentalWorkOrderPhoto
    {
        $url = app(PropertyImageStorer::class)->store($file, $workOrder->property_id);

        return $workOrder->photos()->create([
            'agency_id' => $workOrder->agency_id,
            'photo_type' => $photoType,
            'storage_path' => $url,
            'uploaded_by_user_id' => $uploadedBy?->id,
            'client_idempotency_key' => $clientKey,
            'file_size_bytes' => $file->getSize(),
        ]);
    }

    /**
     * §17.3.4 — ONE creation announcement, the same on every path (fault, work-order form, inspection follow-up,
     * the job-card alias, a card created with no work order): `rental_work_order.created` to the property's agent.
     * (The paths that already announce through report() / fromFaultReport() keep doing so via notifyCreated(), which
     * this calls — it is the same event, never fired twice for one work order.)
     */
    public function announceCreated(RentalWorkOrder $workOrder): void
    {
        $this->notifyCreated($workOrder);
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
        // §17.16 — the owner is told by the final statement (SendOwnerFinalStatement, on RentalWorkOrderClosed), whichever
        // route closed the work order — no longer a plain mail from here.
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
     * RETIRED by .ai/specs/rental-work-orders.md §17.16 (Build 2): the plain owner mails (created / quote revised /
     * completed) are replaced by RentalOwnerQuoteMail, RentalOwnerVariationMail and RentalOwnerFinalStatementMail, all sent
     * through the agency mailbox path. Nothing is sent from here any more; the method stays only so the creation paths
     * that still call it (report(), fromFaultReport()) keep working — an owner is no longer mailed at creation (§17.3.4).
     */
    public function notifyOwner(RentalWorkOrder $workOrder, string $stage): void
    {
        // Intentionally empty — see the docblock.
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

    // ───────────────────────── W2/W6 (8 Oct 2026) - the appointment, set by the agent or the owner ─────────────────────────

    /**
     * Book (or re-book) the repair: ONE method for the agent screen, the owner's portal and the job-card booking mirror.
     * Every set/change is a row in the work order's history (who, from -> to, note) and the tenant is emailed - once per
     * real change; saving the same date and note again changes nothing and sends nothing.
     *
     * @throws \LogicException the work order is finished or cancelled
     */
    public function setAppointment(RentalWorkOrder $workOrder, \DateTimeInterface $at, ?string $note, User|\App\Models\Contact $by, ?string $viaNote = null): bool
    {
        if (in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed - an appointment can no longer be set.');
        }

        // Stored in the application timezone (Eloquent formats a datetime in the Carbon's OWN zone and reads it back in the app zone).
        $when = \Illuminate\Support\Carbon::instance($at)->setTimezone(config('app.timezone'))->startOfMinute();
        $note = trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 500) : null;
        $previous = $workOrder->appointment_at;
        if ($previous && $previous->getTimestamp() === $when->getTimestamp() && ($workOrder->appointment_note ?? null) === $note) {
            return false;
        }

        $byUser = $by instanceof User ? $by : null;
        $byContact = $by instanceof \App\Models\Contact ? $by : null;
        $who = $byUser ? $byUser->name : trim(($byContact->first_name ?? '') . ' ' . ($byContact->last_name ?? '')) . ' (owner, on the portal)';

        \Illuminate\Support\Facades\DB::transaction(function () use ($workOrder, $when, $note, $previous, $byUser, $byContact, $who, $viaNote) {
            $workOrder->forceFill([
                'appointment_at' => $when,
                'appointment_note' => $note,
                'appointment_set_at' => now(),
                'appointment_set_by_user_id' => $byUser?->id,
                'appointment_set_by_contact_id' => $byContact?->id,
            ])->save();

            $workOrder->updates()->create([
                'agency_id' => $workOrder->agency_id,
                'update_type' => $previous ? 'appointment_changed' : 'appointment_set',
                'note' => trim(($previous ? 'From ' . $previous->format('D j M Y H:i') . ' to ' : '') . $when->format('D j M Y H:i')
                    . ($note ? ' - ' . $note : '') . ' (set by ' . $who . ($viaNote ? ', ' . $viaNote : '') . ')'),
                'created_by_user_id' => $byUser?->id,
            ]);
        });

        $fresh = $workOrder->fresh();
        $this->notifyTenantAppointment($fresh, (bool) $previous);
        if ($byContact) {
            $this->notifyAgentOfOwnerAction(
                $fresh, 'rental_work_order.owner_appointment',
                ($previous ? 'Owner changed the repair appointment' : 'Owner set the repair appointment') . ' - ' . $this->addressFor($fresh),
                $fresh->title . ': ' . $when->format('D j M Y H:i') . ($note ? ' - ' . $note : ''),
                $fresh->appointment_set_at   // the fact's own moment: a real change is a new fact, a repeat save never gets here
            );
        }

        return true;
    }

    /** W2 - the tenant is told when the repair appointment is set or changed (the existing tenant-notification setting). */
    public function notifyTenantAppointment(RentalWorkOrder $workOrder, bool $changed): void
    {
        if (! $workOrder->lease_id || ! \App\Models\RentalPortalSetting::notifyTenantOnStatusChangeFor($workOrder->agency_id)) {
            return;
        }

        foreach ($workOrder->lease?->tenantContacts() ?? [] as $tenant) {
            if ($tenant->email) {
                Mail::to($tenant->email)->send(new \App\Mail\Rentals\RentalWorkOrderAppointmentMail($workOrder, $tenant->first_name ?? '', $changed, $tenant));
            }
        }
    }

    /**
     * W6 - the owner reports progress from the portal: 'started' (work has begun) or 'finished' (the work is done - the
     * normal tenant check follows). Same service paths and gates as the office; the owner is the recorded actor.
     * An internal job's progress comes from the agency's own job card, never from the owner.
     *
     * @throws \LogicException when the work order cannot move that way
     */
    public function recordOwnerProgress(RentalWorkOrder $workOrder, \App\Models\Contact $owner, string $action, ?string $note = null): void
    {
        if ($workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
            throw new \LogicException("This job is done by the agency's own team - its progress is updated by them.");
        }
        $name = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
        $via = 'told by the owner' . ($name !== '' ? ' (' . $name . ')' : '') . ' on the portal';

        if ($action === 'started') {
            if ($workOrder->status === RentalWorkOrder::STATUS_IN_PROGRESS) {
                return;
            }
            $workOrder->startProgress(null, ucfirst($via));
            $workOrder->updates()->create([
                'agency_id' => $workOrder->agency_id, 'update_type' => 'owner_progress',
                'note' => 'Work started - ' . $via . (trim((string) $note) !== '' ? ': ' . trim((string) $note) : ''),
            ]);
            $this->notifyAgentOfOwnerAction(
                $workOrder->fresh(), 'rental_work_order.owner_progress',
                'Owner reported the work started - ' . $this->addressFor($workOrder), $workOrder->title, now()
            );

            return;
        }

        $round = app(\App\Services\Rentals\RentalCompletionService::class)->recordOwnerReportedDone($workOrder, $owner, $note);
        // The same job reported done twice opens ONE tenant check, so it is ONE message to the agent.
        if ($round->wasRecentlyCreated) {
            $this->notifyAgentOfOwnerAction(
                $workOrder->fresh(), 'rental_work_order.owner_progress',
                'Owner reported the work finished - ' . $this->addressFor($workOrder), $workOrder->title . ' (the tenant has been asked to check)', $round->opened_at ?? now()
            );
        }
    }

    /**
     * Follow-up (8 Oct 2026): when the OWNER acts on a work order from the portal, the responsible agent - the lease's
     * owner-side agent, else the property's agent (the rule the portal's "who to call" uses) - gets the in-app alert and
     * the email, through the one NotificationDispatcher, so each person's own settings (event on/off, channels, open hours)
     * decide. $hitAt is the fact's own moment: the dispatcher will not tell the same agent the same fact twice.
     */
    public function notifyAgentOfOwnerAction(RentalWorkOrder $workOrder, string $eventKey, string $title, string $body, $hitAt): void
    {
        $property = $workOrder->property()->withoutGlobalScopes()->first();
        if (! $property) {
            return;
        }
        $agent = app(LeaseAgentService::class)->responsibleUser($workOrder->lease, $property, (int) $workOrder->agency_id, LeaseAgentService::SIDE_OWNER);
        if (! $agent) {
            return;
        }

        app(NotificationDispatcher::class)->fire($agent, $eventKey, $workOrder, [
            'title' => $title,
            'body' => $body,
            'action_url' => route('corex.rental-work-orders.show', $workOrder->id),
            'severity' => 'info',
            'threshold_hit_at' => $hitAt,
        ]);
    }

    // ───────────────────────── Build 2 mails (§17.16) — all through the agency mailbox path ─────────────────────────

    /** The owner's email contacts: the lease's landlords, else the property's landlord/lessor contacts, else its owner contact. */
    public function ownerRecipients(RentalWorkOrder $workOrder): \Illuminate\Support\Collection
    {
        $property = $workOrder->property;
        $lease = $workOrder->lease;
        $landlords = $lease ? $lease->landlordContacts() : collect();
        if ($landlords->isEmpty() && $property) {
            $landlords = $property->contactsForRole('landlord')->merge($property->contactsForRole('lessor'))->unique('id');
        }
        if ($landlords->isEmpty() && $property && ($fallback = $property->landlordContact())) {
            $landlords = collect([$fallback]);
        }

        return $landlords->filter(fn ($c) => ! empty($c->email))->unique('email')->values();
    }

    /** The agent a mail is sent AS: whoever pressed the button, else the property's agent, else the creator; null = shared mailer. */
    private function senderFor(RentalWorkOrder $workOrder, ?User $by): ?User
    {
        if ($by) {
            return $by;
        }
        $property = $workOrder->property;
        $agent = $property?->agent_id ? User::withoutGlobalScopes()->find($property->agent_id) : null;

        return $agent ?? ($workOrder->created_by_user_id ? User::withoutGlobalScopes()->find($workOrder->created_by_user_id) : null);
    }

    private function dispatchMail(?string $email, \App\Mail\Signatures\BaseSignatureMail $mail): bool
    {
        if (! $email) {
            return false;
        }
        try {
            app(RentalMailDispatcher::class)->send($email, $mail);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Rentals maintenance mail failed', ['mail' => $mail::class, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function noteSent(RentalWorkOrder $workOrder, ?User $by, string $note): void
    {
        $workOrder->updates()->create(['agency_id' => $workOrder->agency_id, 'update_type' => 'note', 'note' => $note, 'created_by_user_id' => $by?->id]);
    }

    /**
     * §17.16 / §17.9.3 — a quote reaches the owner: the quote document attached (not when the agency's fee is on the
     * contractor's own document — it would show the agency's margin), the owner-facing amount, the estimate term and, when
     * $needsDecision, the "your approval is needed" wording with the portal pointer. Returns how many owners were mailed.
     */
    public function sendOwnerQuote(RentalWorkOrder $workOrder, RentalWorkOrderQuote $quote, ?User $by, bool $needsDecision = false): int
    {
        $sent = 0;
        $contents = null;
        $filename = null;
        $hasFee = (float) $quote->fee_amount > 0;
        if ($quote->document_storage_path && ! $hasFee && Storage::disk('local')->exists($quote->document_storage_path)) {
            $contents = Storage::disk('local')->get($quote->document_storage_path);
            $ext = pathinfo($quote->document_storage_path, PATHINFO_EXTENSION) ?: 'pdf';
            $filename = 'Quote - ' . trim((string) preg_replace('#[\\\\/:*?"<>|]+#', ' ', $this->addressFor($workOrder))) . '.' . $ext;
        }
        $term = trim((string) $quote->term_text) !== '' ? (string) $quote->term_text : RentalWorkOrderSetting::quoteEstimateTermFor($workOrder->agency_id);
        $agent = $this->senderFor($workOrder, $by);

        foreach ($this->ownerRecipients($workOrder) as $owner) {
            $mail = new RentalOwnerQuoteMail($workOrder, $quote, (string) ($owner->first_name ?? ''), $needsDecision, $contents, $filename, $term, $agent, $owner);
            if ($this->dispatchMail($owner->email, $mail)) {
                $sent++;
            }
        }
        if ($sent > 0) {
            $this->noteSent($workOrder, $by, 'Quote emailed to the owner' . ($needsDecision ? ' (approval needed)' : '') . ($filename ? ' — document attached' : ''));
        }

        return $sent;
    }

    /** §17.7.4 — the request for extra work, with the variation notice PDF attached. Sets mail_sent_at. */
    public function sendOwnerVariation(RentalWorkOrderVariation $variation, ?User $by): int
    {
        $workOrder = $variation->workOrder()->with(['property', 'lease'])->firstOrFail();
        $pdfService = app(RentalDocumentPdfService::class);
        $contents = $pdfService->variationNoticePdf($variation)->output();
        $filename = $pdfService->variationNoticeFilename($variation);
        $agent = $this->senderFor($workOrder, $by);

        // §17.9.4 — a HIGHER external quote: the contractor's revised quote document rides with the request (not when the agency's fee is on
        // it — the contractor's own document would show the agency's margin; the notice PDF carries the owner-facing figures).
        $quote = $variation->quote;
        $quoteDoc = null;
        if ($variation->origin === RentalWorkOrderVariation::ORIGIN_EXTERNAL_QUOTE && $quote && $quote->document_storage_path
            && (float) $quote->fee_amount <= 0 && Storage::disk('local')->exists($quote->document_storage_path)) {
            $quoteDoc = [Storage::disk('local')->get($quote->document_storage_path), 'Revised Quote - ' . trim((string) preg_replace('#[\\\\/:*?"<>|]+#', ' ', $this->addressFor($workOrder))) . '.' . (pathinfo($quote->document_storage_path, PATHINFO_EXTENSION) ?: 'pdf')];
        }

        $sent = 0;
        foreach ($this->ownerRecipients($workOrder) as $owner) {
            $mail = new RentalOwnerVariationMail($variation, $workOrder, (string) ($owner->first_name ?? ''), $contents, $filename, (int) $variation->revision > 1, $agent, $owner);
            if ($quoteDoc) {
                $mail->withAttachment($quoteDoc[0], $quoteDoc[1]);
            }
            if ($this->dispatchMail($owner->email, $mail)) {
                $sent++;
            }
        }
        if ($sent > 0) {
            $variation->forceFill(['mail_sent_at' => now()])->save();
            $this->noteSent($workOrder, $by, 'Request for extra work emailed to the owner (revision ' . $variation->revision . ') — variation notice attached');
        }

        return $sent;
    }

    /** §17.7.2 — information only: extra work went ahead within the owner's terms (agency setting notify_landlord_on_auto_variation). */
    public function sendOwnerVariationAuto(RentalWorkOrderVariation $variation, ?User $by): int
    {
        $workOrder = $variation->workOrder()->with(['property', 'lease'])->firstOrFail();
        $lines = $variation->lines()->where('office_status', \App\Models\RentalJobCardLine::OFFICE_ACCEPTED)->orderBy('id')->get()->map(fn ($l) => [
            'description' => (string) $l->description,
            'quantity' => rtrim(rtrim(number_format((float) $l->quantity, 2, '.', ''), '0'), '.'),
            'total' => number_format((float) ($l->line_total ?? 0), 2),
        ])->all();
        $within = $variation->term_basis === \App\Models\RentalApprovalDecision::TERM_VARIATION_TOLERANCE
            ? 'within the ' . rtrim(rtrim(number_format((float) $variation->term_value, 2, '.', ''), '0'), '.') . ' % tolerance you agreed'
            : 'within your no-approval limit of R' . number_format((float) $variation->term_value, 2);
        $agent = $this->senderFor($workOrder, $by);

        $sent = 0;
        foreach ($this->ownerRecipients($workOrder) as $owner) {
            $mail = new RentalOwnerVariationAutoMail($variation, $workOrder, (string) ($owner->first_name ?? ''), $lines, $within, $agent);
            if ($this->dispatchMail($owner->email, $mail)) {
                $sent++;
            }
        }
        if ($sent > 0) {
            $variation->forceFill(['mail_sent_at' => now()])->save();
            $this->noteSent($workOrder, $by, 'Owner told the extra work was approved within their agreed terms');
        }

        return $sent;
    }

    /**
     * §17.9.5 — "Send work order to contractor": the work order PDF (with "Owner approval: approved on {date} — {basis}") to
     * the contractor's contact(s), replacing the plain supplier mail. Returns true when a mail went out; false when the
     * contractor has no email on file (the work order is still assigned — the agent sends the printout).
     */
    public function sendContractorWorkOrder(RentalWorkOrder $workOrder, User $by): bool
    {
        $provider = $workOrder->supplier()->with('serviceContacts')->first();
        if (! $provider) {
            return false;
        }
        $contact = $provider->serviceContacts->first();
        $email = $contact?->email ?: $provider->email;
        if (! $email) {
            return false;
        }

        $pdfService = app(RentalDocumentPdfService::class);
        $mail = new RentalContractorWorkOrderMail(
            $workOrder,
            (string) ($contact?->name ?: $provider->name),
            $pdfService->workOrderContractorPdf($workOrder)->output(),
            $pdfService->workOrderContractorFilename($workOrder),
            $workOrder->ownerApprovalLine(),
            $this->senderFor($workOrder, $by),
        );
        $sent = $this->dispatchMail($email, $mail);
        $workOrder->updates()->create([
            'agency_id' => $workOrder->agency_id, 'update_type' => 'work_order_sent', 'created_by_user_id' => $by->id,
            'note' => $sent ? 'Work order emailed to ' . ($provider->name ?: 'the contractor') . ' — ' . $workOrder->ownerApprovalLine() : 'Work order could not be emailed to the contractor',
        ]);

        return $sent;
    }

    /** §17.8.3 / §17.16 — the owner's final statement when the work order closes (listener on RentalWorkOrderClosed). */
    public function sendOwnerFinalStatement(RentalWorkOrder $workOrder): int
    {
        $workOrder->loadMissing(['property', 'lease', 'agency']);
        $pdfService = app(RentalDocumentPdfService::class);
        $contents = $pdfService->finalStatementPdf($workOrder)->output();
        $filename = $pdfService->finalStatementFilename($workOrder);
        $agent = $this->senderFor($workOrder, null);

        $sent = 0;
        foreach ($this->ownerRecipients($workOrder) as $owner) {
            $mail = new RentalOwnerFinalStatementMail($workOrder, (string) ($owner->first_name ?? ''), $contents, $filename, $workOrder->emergencyBanner(), $agent);
            if ($this->dispatchMail($owner->email, $mail)) {
                $sent++;
            }
        }
        $this->noteSent($workOrder, null, $sent > 0 ? 'Final statement emailed to the owner' : 'Final statement not emailed — no owner email on file');

        return $sent;
    }

    /** §17.16 — in-app note to the property's agent that extra work was raised (rental_work_order.variation_raised). */
    public function notifyVariationRaised(RentalWorkOrderVariation $variation): void
    {
        $workOrder = $variation->workOrder()->with('property')->first();
        if (! $workOrder) {
            return;
        }
        $what = $variation->isAwaitingOwner() ? 'needs the owner' : 'auto-approved within the owner\'s terms';
        $this->fireInternal(
            $workOrder,
            'rental_work_order.variation_raised',
            'Extra work ' . $what . ' — ' . $this->addressFor($workOrder),
            $workOrder->title . ': extra R' . number_format((float) $variation->extra_amount, 2) . ', new total R' . number_format((float) $variation->new_total, 2),
            now(),
        );
    }

    private function addressFor(RentalWorkOrder $workOrder): string
    {
        $property = $workOrder->property;

        return $property?->buildDisplayAddress() ?: ($property?->title ?: ('Property #' . $workOrder->property_id));
    }
}
