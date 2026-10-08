<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4, Stage 4 — a work order is
 * evidence, permanently, not an operational ticket that closes and is
 * forgotten (§1). Raised directly, from an inspection observation, or from
 * an already-approved fault report (§3a.1) — the last case is the
 * "agency_appoints" route, the only route that ever produces a work order
 * at all (§0c/§3a.1: "owner_handles" never does).
 */
class RentalWorkOrder extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const REPORTED_BY_TENANT = 'tenant';
    public const REPORTED_BY_AGENT_NOTICED = 'agent_noticed';
    public const REPORTED_BY_OWNER_INSTRUCTED = 'owner_instructed';
    public const REPORTED_BY_INSPECTION = 'inspection';
    public const REPORTED_BY_FAULT_REPORT = 'fault_report';

    public const STATUS_REPORTED = 'reported';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    /**
     * .ai/specs/rental-work-orders.md §17.12 — a tenant reported finished work as
     * not complete (§17.10). An OPEN state: the final close is refused while a work
     * order is disputed (RentalCloseGuards::assertNotDisputed).
     */
    public const STATUS_DISPUTED = 'disputed';

    /**
     * §17.2 / §17.6.4 — which term the CURRENT approval relied on. Always paired
     * with a rental_approval_decisions row that cites the term and its value.
     */
    public const BASIS_NO_APPROVAL_LIMIT = 'no_approval_limit';
    public const BASIS_OWNER_DECISION = 'owner_decision';
    public const BASIS_VARIATION_TOLERANCE = 'variation_tolerance';
    public const BASIS_EMERGENCY = 'emergency_owner_agreed';
    /** Work already under way when §17 was deployed — never blocked by the new guard (§17.6.5). */
    public const BASIS_LEGACY_GRANDFATHERED = 'legacy_grandfathered';

    /** AT-442 — "who does the work" is the FIRST choice on every work order. */
    public const ASSIGNMENT_OUTSIDE_SUPPLIER = 'outside_supplier';
    public const ASSIGNMENT_INTERNAL = 'internal';
    /** W3 (8 Oct 2026) - the owner's own contractor (optional name + phone): the owner arranges and pays, the agency coordinates. */
    public const ASSIGNMENT_OWNER_CONTRACTOR = 'owner_contractor';

    public const APPROVAL_NOT_REQUIRED = 'not_required';
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_DECLINED = 'declined';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_URGENT = 'urgent';

    public const PAID_BY_OWNER = 'owner';
    public const PAID_BY_TENANT = 'tenant';
    public const PAID_BY_DEPOSIT_DEDUCTION = 'deposit_deduction';
    public const PAID_BY_NOT_YET_PAID = 'not_yet_paid';

    public const PHOTO_REPORTED = 'reported';
    public const PHOTO_IN_PROGRESS = 'in_progress';
    public const PHOTO_COMPLETED = 'completed';
    /**
     * §17.10.4 — a tenant's "not complete" photo. NOT a crew-visible gallery photo and never a client
     * "work done" photo: Build 3 must exclude it from the readers that show `photo_type != reported`
     * (CrewJobService::payload(), RentalJobCardClientViewService) and show it only inside the dispute panel.
     */
    public const PHOTO_DISPUTE = 'dispute';

    /**
     * AT-442 — matches the DB column default so a just-created instance
     * reads correctly in the SAME request without a fresh() reload (a
     * row loaded from the database always gets this right regardless;
     * this only matters for the moment right after ::create()).
     */
    protected $attributes = [
        'assignment_type' => self::ASSIGNMENT_OUTSIDE_SUPPLIER,
    ];

    protected $fillable = [
        'agency_id',
        'branch_id',
        'property_id',
        'lease_id',
        'rental_inspection_item_id',
        'agency_service_provider_id',
        'contractor_name',
        'contractor_phone',
        'appointment_at',
        'appointment_note',
        'appointment_set_at',
        'appointment_set_by_user_id',
        'appointment_set_by_contact_id',
        'owner_approval_status',
        'assignment_type',
        'trade_type',
        'title',
        'description',
        'status',
        'priority',
        'reported_by_type',
        'reported_by_contact_id',
        'reported_by_user_id',
        'reported_inspection_observation_id',
        'reported_fault_report_id',
        'reported_at',
        'ordered_at',
        'completed_at',
        'completion_notes',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'paid_by',
        'cost_amount',
        'created_by_user_id',
        // AT-445 — .ai/specs/rental-portal-access.md §2. Tenant's own
        // 'fixed'/'not fixed' sign-off, contact-attributed.
        'tenant_confirmed_at',
        'tenant_confirmed_fixed',
        'tenant_confirmed_by_contact_id',
        'tenant_confirmation_note',
        // §17.6 — the amount the owner (or the no-approval limit) covered, and the term relied on.
        'approved_amount',
        'approval_basis',
        'emergency_approval_id',
        // §17.9.1a — per-work-order override of the agency's external-quote fee (null = inherit).
        'external_markup_type',
        'external_markup_value',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'ordered_at' => 'datetime',
        'appointment_at' => 'datetime',
        'appointment_set_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        // NAMING TRAP (§17.13): this is the OWNER-FACING (selling) final amount for an internal job and
        // the contractor's final invoice for an external one — never the agency's own cost.
        'cost_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'external_markup_value' => 'decimal:2',
        'tenant_confirmed_at' => 'datetime',
        'tenant_confirmed_fixed' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        // withTrashed(): an archived property must not null this relation —
        // thresholdFor() etc. need it, and a work order is evidence that
        // outlives the property's own archiving (audit L1).
        return $this->belongsTo(Property::class)->withTrashed();
    }

    /** Branch logo fallback for the supplier PDF (§"Printing", 2026-09-22). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Same reasoning as property() above. */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    public function inspectionItem(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    /** Same reasoning as property() above — a soft-deleted supplier must not vanish from work-order history. */
    public function isOwnerContractor(): bool
    {
        return $this->assignment_type === self::ASSIGNMENT_OWNER_CONTRACTOR;
    }

    /** W3 - who does the work, in plain words (agent wording; the portal adds its own, see RentalWorkOrderClientViewService). */
    public function whoLabel(): string
    {
        return match ($this->assignment_type) {
            self::ASSIGNMENT_INTERNAL => 'Our maintenance team',
            self::ASSIGNMENT_OWNER_CONTRACTOR => "Owner's own contractor",
            default => 'Agency contractor',
        };
    }

    /** The plain stage key (config/rental-work-order-stages.php) and its label for an audience: 'agent' | 'owner' | 'tenant'. */
    public function stageKey(): string
    {
        return app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->stageKey($this);
    }

    /**
     * THE contractor of this work order, from ONE place (Johan, 8 Oct 2026: the header said "Supplier: -" while the selected quote named one lower
     * down): the owner's own contractor; else the supplier the work order was assigned to; else the supplier of the SELECTED quote (assigned
     * only when the work order is sent). Null when nobody is chosen yet.
     */
    public function contractorLabel(): ?string
    {
        if ($this->isOwnerContractor()) {
            return $this->contractor_name ?: null;
        }
        if ($this->supplier) {
            return $this->supplier->name;
        }
        $selected = $this->relationLoaded('quotes') ? $this->quotes->firstWhere('is_selected', true) : $this->quotes()->where('is_selected', true)->first();

        return $selected?->supplier?->name;
    }

    /** One name for a raw status wherever it is printed (history, tooltips) - config/rental-work-order-stages.php `status_words`. */
    public static function statusWord(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return (string) (config("rental-work-order-stages.status_words.{$status}") ?? ucfirst(str_replace('_', ' ', $status)));
    }

    public function stageLabel(string $audience = 'agent'): string
    {
        return app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->stageLabel($this, $audience === 'agent' ? 'agent' : $audience);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DealV2\AgencyServiceProvider::class, 'agency_service_provider_id')->withTrashed();
    }

    /** Same reasoning as property() above. */
    public function reportedByContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'reported_by_contact_id')->withTrashed();
    }

    public function reportedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function reportedInspectionObservation(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionObservation::class, 'reported_inspection_observation_id');
    }

    public function reportedFaultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'reported_fault_report_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RentalWorkOrderPhoto::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(RentalWorkOrderUpdate::class)->orderByDesc('created_at');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RentalApproval::class)->orderByDesc('created_at');
    }

    /** §3.4c — several quotes can exist per work order; exactly one is_selected at a time. */
    public function quotes(): HasMany
    {
        return $this->hasMany(RentalWorkOrderQuote::class)->orderByDesc('quote_date');
    }

    /** §17.31 — the supplier's invoice documents filed against this work order (live ones; archived via onlyTrashed()). */
    public function invoices(): HasMany
    {
        return $this->hasMany(RentalWorkOrderInvoice::class)->orderByDesc('invoice_date')->orderByDesc('id');
    }

    /** AT-442 — present only when assignment_type='internal'; §14. */
    public function jobCard(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RentalJobCard::class, 'rental_work_order_id');
    }

    /** §17.6.4 — every decision the system (or the owner) made, citing the term relied on. Newest first. */
    public function approvalDecisions(): HasMany
    {
        return $this->hasMany(RentalApprovalDecision::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** §17.8 — emergency approvals, newest first (voided ones included; see activeEmergencyApproval()). */
    public function emergencyApprovals(): HasMany
    {
        return $this->hasMany(RentalEmergencyApproval::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** §17.7 — variations, newest first. */
    public function variations(): HasMany
    {
        return $this->hasMany(RentalWorkOrderVariation::class)->orderByDesc('raised_at')->orderByDesc('id');
    }

    /** §17.10 — one row per "work reported done", oldest first (round 1, 2, 3…). */
    public function completionRounds(): HasMany
    {
        return $this->hasMany(RentalWorkCompletionRound::class)->orderBy('round_no');
    }

    /** §17.8 — the one un-voided emergency approval, if any. */
    public function activeEmergencyApproval(): ?RentalEmergencyApproval
    {
        return $this->emergencyApprovals()->whereNull('voided_at')->first();
    }

    /** §17.12 — an OPEN dispute is a work order whose status is `disputed`. */
    public function hasOpenDispute(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    /** §17.7 / §17.9.4 — the owner already approved an amount for this work order (never true of emergency work). */
    public function hasApprovedBaseline(): bool
    {
        return $this->approved_amount !== null && $this->approval_basis !== self::BASIS_EMERGENCY;
    }

    /** §17.7.2 — the one variation still waiting on the owner, if any. */
    public function openVariation(): ?RentalWorkOrderVariation
    {
        return $this->variations()->where('status', RentalWorkOrderVariation::STATUS_AWAITING_OWNER)->first();
    }

    /** §17.6.4 — the latest decision row, for the "Why was this approved?" line. */
    public function latestApprovalDecision(): ?RentalApprovalDecision
    {
        return $this->approvalDecisions()->first();
    }

    /**
     * §17.28 — the label in front of the latest decision's note. The note is the reason for the CURRENT state, so the
     * label must say which state that is: "Why was this approved?" on a job still waiting for the owner (or declined,
     * or with no decision at all) claims an approval that does not exist.
     */
    public function approvalReasonLabel(): string
    {
        return match (true) {
            $this->owner_approval_status === self::APPROVAL_PENDING => 'Why is the owner\'s approval needed?',
            $this->owner_approval_status === self::APPROVAL_DECLINED => 'Why was this declined?',
            $this->approvalBasisLabel() !== null => 'Why was this approved?',
            default => 'Approval decision:',
        };
    }

    /** §17.8.3 — "Approved as emergency work on {date}" for statements and quote PDFs; null unless an un-voided emergency approval stands. */
    public function emergencyBanner(): ?string
    {
        $approval = $this->activeEmergencyApproval();

        return $approval ? 'Approved as emergency work on ' . $approval->approved_at->format('j M Y') : null;
    }

    /**
     * §17.9.5 — the line the contractor's work order carries: "Owner approval: approved on {date} — {basis in words}".
     * Never names the owner or gives their contact details.
     */
    public function ownerApprovalLine(): string
    {
        $decision = $this->approvalDecisions()
            ->whereNull('rental_work_order_variation_id')
            ->whereIn('decision', [RentalApprovalDecision::DECISION_APPROVED, RentalApprovalDecision::DECISION_AUTO_APPROVED, RentalApprovalDecision::DECISION_EMERGENCY_COVERED])
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')->first();

        $words = match ($decision?->basis ?? $this->approval_basis) {
            self::BASIS_OWNER_DECISION => 'the owner approved the quote',
            self::BASIS_NO_APPROVAL_LIMIT => "within the owner's no-approval limit",
            self::BASIS_VARIATION_TOLERANCE => "within the owner's agreed tolerance",
            self::BASIS_EMERGENCY => 'emergency work agreed by the owner',
            default => 'the owner\'s approval is on file',
        };

        return 'Owner approval: approved' . ($decision ? ' on ' . $decision->created_at->format('j M Y') : '') . ' — ' . $words;
    }

    /** §17.6.4 — plain-words label of the current approval basis (null when none recorded yet). */
    public function approvalBasisLabel(): ?string
    {
        return match ($this->approval_basis) {
            self::BASIS_NO_APPROVAL_LIMIT => "Within the owner's no-approval limit",
            self::BASIS_OWNER_DECISION => 'Approved by the owner',
            self::BASIS_VARIATION_TOLERANCE => "Within the owner's agreed tolerance",
            self::BASIS_EMERGENCY => 'Approved as emergency work',
            self::BASIS_LEGACY_GRANDFATHERED => 'Already under way before approvals were recorded',
            default => null,
        };
    }

    /**
     * A single, plain, chronological history — every state-changing action
     * on this record, actor + action + from/to + note + when, oldest first.
     * Merges the synthetic "logged" event (this record's own creation —
     * created_by_user_id/created_at already carry it, never duplicated into
     * a row), the real rental_work_order_updates rows, and the approval
     * decisions (a separate table, §3.4a, folded in so an agent reads ONE
     * timeline). Same shape as RentalFaultReport::history() — this feature's
     * two records share one audit convention, not two.
     *
     * @return \Illuminate\Support\Collection<int, array{at: \Illuminate\Support\Carbon, actor: ?string, action: string, from: ?string, to: ?string, note: ?string}>
     */
    public function history(): \Illuminate\Support\Collection
    {
        $entries = collect();

        $entries->push([
            'at' => $this->created_at,
            'actor' => $this->createdByUser?->name,
            'action' => 'Logged',
            'from' => null,
            'to' => null,
            'note' => null,
        ]);

        foreach ($this->updates as $update) {
            $entries->push([
                'at' => $update->created_at,
                'actor' => $update->createdByUser?->name,
                'action' => match ($update->update_type) {
                    'supplier_assigned' => 'Supplier assigned',
                    'supplier_changed' => 'Supplier changed',
                    'appointment_set' => 'Appointment set',
                    'appointment_changed' => 'Appointment changed',
                    'owner_progress' => 'Progress reported by the owner',
                    'status_change' => 'Status changed',
                    'note' => 'Note added',
                    'quote_captured' => 'Quote captured',
                    'quote_selected' => 'Quote selected',
                    'quote_archived' => 'Quote archived',
                    'quote_restored' => 'Quote restored',
                    'approval_rederived' => 'Approval requirement re-derived',
                    'approval_superseded' => 'Recorded decision superseded',
                    default => ucfirst(str_replace('_', ' ', $update->update_type)),
                },
                'from' => static::statusWord($update->from_status),
                'to' => static::statusWord($update->to_status),
                'note' => $update->note,
            ]);
        }

        foreach ($this->approvals as $approval) {
            $entries->push([
                'at' => $approval->created_at,
                'actor' => $approval->recordedByUser?->name,
                'action' => $approval->decision === RentalApproval::DECISION_APPROVED ? 'Approved' : 'Declined',
                'from' => null,
                'to' => null,
                'note' => $approval->evidence_text,
            ]);
        }

        return $entries->sortBy('at')->values();
    }

    /**
     * §3.1 — deletable only while nothing has been logged against it: no
     * update row, no photo. Once either exists, only 'cancelled'.
     */
    public function isDeletable(): bool
    {
        return $this->updates()->doesntExist() && $this->photos()->doesntExist();
    }

    /**
     * Johan, 2026-09-22 — archive/restore captured in the same "who did
     * what" history as every other action on this record.
     */
    public function archive(User $by): void
    {
        $this->delete();
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'archived', 'created_by_user_id' => $by->id,
        ]);
    }

    public function restoreRecord(User $by): void
    {
        $this->restore();
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'restored', 'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * §3.4a — a work order raised WITHOUT an upstream fault report has no
     * pre-satisfied approval to inherit, so it records its own decision
     * here, via the same shared rental_approvals table (§3.4a). Unlike
     * RentalFaultReport::recordApproval(), there is no "route" — a work
     * order's mere existence already means the agency-appoints path was
     * chosen (§3a.1) — so approval_route is always null at this level.
     *
     * 2026-09-22, Johan — snapshots whichever quote is currently selected
     * (if any) onto the approval row: amount and supplier name as immutable
     * text, never re-derived from the live quote later. "The landlord
     * approved this quote, for this amount, from this supplier" is
     * otherwise unanswerable once the quote is edited or a different one
     * gets selected. No quote selected at decision time (e.g. approval
     * recorded before any quote exists) leaves all three columns null —
     * correctly, there is nothing to snapshot.
     */
    /**
     * AT-445 — $recordedBy widened to `User|Contact`, same reasoning as
     * RentalFaultReport::recordApproval(): a landlord approving/declining a
     * quote directly through the portal is evidence_type 'portal', no
     * agent transcription. Existing `User` callers are unchanged.
     */
    public function recordApproval(User|\App\Models\Contact $recordedBy, array $attributes): RentalApproval
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        $decision = $attributes['decision'];
        $selectedQuote = $this->quotes()->where('is_selected', true)->first();

        $approval = $this->approvals()->create([
            'agency_id' => $this->agency_id,
            'decision' => $decision,
            'approval_route' => null,
            'evidence_type' => $attributes['evidence_type'],
            'evidence_text' => $attributes['evidence_text'] ?? null,
            'evidence_file_path' => $attributes['evidence_file_path'] ?? null,
            'decided_at' => $attributes['decided_at'] ?? now(),
            'recorded_by_user_id' => $recordedBy instanceof User ? $recordedBy->id : null,
            'recorded_by_contact_id' => $recordedBy instanceof \App\Models\Contact ? $recordedBy->id : null,
            'quote_id_at_decision' => $selectedQuote?->id,
            'quote_amount_at_decision' => $selectedQuote?->amount,
            'quote_supplier_name_at_decision' => $selectedQuote?->supplier?->name,
        ]);

        $this->forceFill([
            'owner_approval_status' => $decision === self::APPROVAL_APPROVED ? self::APPROVAL_APPROVED : self::APPROVAL_DECLINED,
        ])->save();

        // §17.6.4 — the owner's decision is a decision row too, and (when it is an approval) sets the baseline that
        // later variations are measured against: the selected quote's OWNER-FACING amount.
        app(\App\Services\Rentals\RentalApprovalGateService::class)->recordOwnerDecision(
            $this, $decision === self::APPROVAL_APPROVED, $recordedBy, (string) $attributes['evidence_type'], $selectedQuote, $approval->decided_at,
        );

        return $approval;
    }

    /**
     * §3.4c — an agent obtains a quote from a supplier before work starts.
     * Johan's ruling: "agents will obtain quotes and thats the value that
     * approval will ride against." Purely a capture — does not itself touch
     * owner_approval_status; that only happens when a quote is SELECTED
     * (selectQuote(), below).
     */
    /**
     * AT-445 — $by widened to nullable: a contractor submitting a quote
     * through their no-login secure link has neither a User nor a Contact
     * actor at all. $viaNote lets that caller say "via contractor link" in
     * the history entry instead of silently looking agent-captured.
     */
    public function recordQuote(array $attributes, ?User $by = null, ?string $viaNote = null): RentalWorkOrderQuote
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        $quote = $this->quotes()->create(array_merge($attributes, RentalWorkOrderQuote::feeAttributes($this, (float) ($attributes['amount'] ?? 0), ! empty($attributes['rental_job_card_id'])), [
            'agency_id' => $this->agency_id,
            'captured_by_user_id' => $by?->id,
        ]));

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'quote_captured',
            'note' => $this->describeQuote($quote) . ($viaNote ? " ({$viaNote})" : ''),
            'created_by_user_id' => $by?->id,
        ]);

        return $quote;
    }

    /**
     * §3.4c — THE gate: the value approval rides on is the SELECTED quote's
     * amount, never cost_amount (only ever known after the job is done,
     * complete() above). At or under the property's (or agency default)
     * threshold, the agent approves it themselves (not_required) — over it,
     * gates assignSupplier() below exactly as it already gates a directly-
     * raised work order's own recordApproval() flow.
     */
    public function selectQuote(RentalWorkOrderQuote $quote, User $by): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }
        if ($quote->rental_work_order_id !== $this->id) {
            throw new \LogicException('This quote does not belong to this work order.');
        }
        // §14.21 — a job card quote revision that a later re-send replaced is
        // history only; selecting it again would resurrect an acceptance that
        // belonged to figures the owner is no longer being asked to approve.
        if ($quote->isSuperseded()) {
            throw new \LogicException('This quote revision has been superseded by a newer one and cannot be selected.');
        }

        // 2026-09-22, Johan — a real recorded decision (approved/declined) is
        // about to be overwritten below, unconditionally, same as it always
        // has been (selectQuote() never preserves approved/declined for any
        // quote, including re-selecting the one that was actually approved —
        // that unconditional-overwrite behaviour is untouched here). What's
        // new is telling the agency it happened: log what the prior decision
        // had been recorded against, using its own snapshot if one exists —
        // an approval from before quote_id_at_decision existed has none, and
        // says so plainly rather than guessing.
        // Audit M1: callers that merely EDIT an already-selected quote (see
        // RentalWorkOrderQuoteController::update()) only reach this method
        // when the price or supplier actually changed — that is the one
        // deliberate case where a recorded approval is dropped and
        // re-approval is required, because the owner approved a different
        // price/supplier than the one now on the quote.
        // The gate decides on what the DATABASE says now (an emergency approval or an owner decision recorded a moment ago by another
        // request must not be missed because this instance was loaded earlier).
        $this->refresh();
        $gate = app(\App\Services\Rentals\RentalApprovalGateService::class);
        $emergency = $gate->isEmergency($this);

        // §17.9.4 — a work order the owner already approved an amount for keeps that approval: a HIGHER quote selected
        // now opens a variation, a lower or equal one changes nothing. (Older rows approved before approved_amount
        // existed take the amount their approval was recorded against as the baseline, when it was recorded.)
        $hasBaseline = ! $emergency && in_array($this->owner_approval_status, [self::APPROVAL_APPROVED, self::APPROVAL_NOT_REQUIRED], true)
            && $this->approved_amount !== null;
        if (! $emergency && ! $hasBaseline && $this->owner_approval_status === self::APPROVAL_APPROVED) {
            $recordedAgainst = $this->approvals()->first()?->quote_amount_at_decision;
            if ($recordedAgainst !== null) {
                $this->forceFill(['approved_amount' => $recordedAgainst, 'approval_basis' => $this->approval_basis ?? self::BASIS_OWNER_DECISION])->save();
                $hasBaseline = true;
            }
        }

        // 2026-09-22, Johan — a real recorded decision (approved/declined) is about to be overwritten below when
        // there is no approved baseline to keep (a declined quote, or an approval recorded before any quote existed).
        // What is new is telling the agency it happened: log what the prior decision had been recorded against, using
        // its own snapshot if one exists — an approval from before quote_id_at_decision existed has none, and says so
        // plainly rather than guessing.
        $wasRecordedDecision = ! $emergency && ! $hasBaseline
            && in_array($this->owner_approval_status, [self::APPROVAL_APPROVED, self::APPROVAL_DECLINED], true);
        $priorApproval       = $wasRecordedDecision ? $this->approvals()->first() : null;
        $oldStatus            = $this->owner_approval_status;

        $this->quotes()->where('id', '!=', $quote->id)->update(['is_selected' => false]);
        $quote->forceFill(['is_selected' => true])->save();

        $variation = null;
        if ($emergency) {
            // §17.8.3 — emergency work is never downgraded to "pending" by a quote or statement: the quote is recorded
            // and the owner's emergency agreement stands.
        } elseif ($hasBaseline) {
            $variation = $gate->assessExternalQuote($this, $quote, $by);
        } else {
            $gate->evaluateQuote($this, $quote->ownerFacingAmount(), $by, $quote);
        }

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'quote_selected',
            'note' => $this->describeQuote($quote), 'created_by_user_id' => $by->id,
        ]);

        if ($wasRecordedDecision) {
            $priorFor = $priorApproval?->quote_amount_at_decision !== null
                ? 'R' . number_format((float) $priorApproval->quote_amount_at_decision, 2) . ' — ' . ($priorApproval->quote_supplier_name_at_decision ?? 'Unknown supplier')
                : 'an earlier quote (recorded before amounts were tracked)';

            $this->updates()->create([
                'agency_id' => $this->agency_id, 'update_type' => 'approval_superseded',
                'from_status' => $oldStatus, 'to_status' => $this->owner_approval_status,
                'note' => ucfirst($oldStatus) . " decision (for {$priorFor}) no longer applies — {$this->owner_approval_status} against " . $this->describeQuote($quote),
                'created_by_user_id' => $by->id,
            ]);
        }

        // §17.9.3 — an EXTERNAL quote over the owner's no-approval limit goes to the owner now: one mail through the
        // agency mailbox, the contractor's document attached, the owner-facing amount and the estimate term. (A job
        // card's own quote is mailed by RentalJobCardService::sendToOwnerAsQuote(), the same single mail.)
        if (! $quote->rental_job_card_id && ! $emergency && ! $hasBaseline && $this->owner_approval_status === self::APPROVAL_PENDING
            && \App\Models\RentalPortalSetting::notifyLandlordOnDecisionNeededFor($this->agency_id)) {
            app(\App\Services\Rentals\RentalWorkOrderService::class)->sendOwnerQuote($this, $quote, $by, needsDecision: true);
        }
    }

    /**
     * §3.4c — archive/restore, soft delete only (non-negotiable #1). Archiving
     * the currently-selected quote clears is_selected — leaving a hidden
     * quote marked "selected" is exactly the invisible-state bug
     * BUILD_STANDARD's prevent-or-absorb rule exists to catch.
     *
     * 2026-09-22, Johan — owner_approval_status IS re-derived when the
     * archived quote was selected, but only when the current value is
     * DERIVED (not_required/pending — the only two values selectQuote()
     * itself can ever write). approved/declined are a RECORD OF WHAT A
     * HUMAN DID (recordApproval(), or inherited from an already-approved
     * fault report) — no amount of quote housekeeping rewrites that; those
     * two values are structurally impossible to reach any other way, so
     * checking the current value is sufficient to tell derived state from a
     * recorded decision, no separate column needed. Re-derivation looks at
     * whichever quote (if any) is selected after this archive, against the
     * property's threshold — same rule selectQuote() itself uses. No quote
     * selected falls back to not_required, the same baseline a fresh work
     * order starts at. Logged to History only when the value actually
     * changes, with the actor and the reason.
     */
    public function archiveQuote(RentalWorkOrderQuote $quote, User $by): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }
        if ($quote->rental_work_order_id !== $this->id) {
            throw new \LogicException('This quote does not belong to this work order.');
        }

        $wasSelected = (bool) $quote->is_selected;
        $quote->forceFill(['is_selected' => false])->save();
        $quote->delete();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'quote_archived',
            'note' => ($wasSelected ? 'Was selected — ' : '') . $this->describeQuote($quote), 'created_by_user_id' => $by->id,
        ]);

        if ($wasSelected && in_array($this->owner_approval_status, [self::APPROVAL_NOT_REQUIRED, self::APPROVAL_PENDING], true)
            && $this->approval_basis !== self::BASIS_EMERGENCY) {
            $oldStatus = $this->owner_approval_status;

            $nowSelected = $this->quotes()->where('id', '!=', $quote->id)->where('is_selected', true)->first();
            if ($nowSelected) {
                app(\App\Services\Rentals\RentalApprovalGateService::class)->evaluateQuote($this, $nowSelected->ownerFacingAmount(), $by, $nowSelected);
                $newStatus = $this->owner_approval_status;
                $reason    = 'now derived from ' . $this->describeQuote($nowSelected);
            } else {
                // no quote selected: the same baseline a work order starts at — nothing approved, nothing pending
                $this->forceFill([
                    'owner_approval_status' => self::APPROVAL_NOT_REQUIRED,
                    'approved_amount' => null,
                    'approval_basis' => $this->approval_basis === self::BASIS_LEGACY_GRANDFATHERED ? self::BASIS_LEGACY_GRANDFATHERED : null,
                ])->save();
                $newStatus = self::APPROVAL_NOT_REQUIRED;
                $reason    = 'no quote now selected — same baseline a work order starts at';
            }

            if ($newStatus !== $oldStatus) {
                $this->updates()->create([
                    'agency_id' => $this->agency_id, 'update_type' => 'approval_rederived',
                    'from_status' => $oldStatus, 'to_status' => $newStatus,
                    'note' => 'Selected quote archived — ' . $reason,
                    'created_by_user_id' => $by->id,
                ]);
            }
        }
    }

    public function restoreQuote(RentalWorkOrderQuote $quote, User $by): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }
        if ($quote->rental_work_order_id !== $this->id) {
            throw new \LogicException('This quote does not belong to this work order.');
        }

        $quote->restore();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'quote_restored',
            'note' => $this->describeQuote($quote), 'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * The no-approval spend threshold for this work order — the property's
     * own override, else the agency default. Null-safe: falls back to the
     * agency default if the property row is somehow unavailable.
     */
    public function spendThreshold(): float
    {
        return $this->property
            ? RentalWorkOrderSetting::thresholdFor($this->property)
            : RentalWorkOrderSetting::spendThresholdFor($this->agency_id);
    }

    /**
     * The approval gate shared by assignSupplier(), startProgress() and
     * complete() (audit H2) — one rule, one place. Pending/declined always
     * blocks. When a cost is supplied (completion), a cost above the
     * threshold additionally needs a recorded/inherited approval, even if
     * the work order was never given a quote and so sits at not_required.
     */
    private function assertApprovalAllows(string $action, ?float $cost = null): void
    {
        if (in_array($this->owner_approval_status, [self::APPROVAL_PENDING, self::APPROVAL_DECLINED], true)) {
            throw new \LogicException("Cannot {$action} while owner approval is pending or declined.");
        }
        // §17.6/§17.8 — emergency work has no threshold, and a work order with an approved amount is measured against
        // that amount by RentalCloseGuards::assertFinalCostWithinApproval() (within tolerance → auto-approved and
        // logged). This older check only guards a work order with no approved baseline at all.
        if ($cost !== null && ! $this->isOwnerContractor() && $this->approved_amount === null && $this->approval_basis !== self::BASIS_EMERGENCY
            && $cost > $this->spendThreshold() && $this->owner_approval_status !== self::APPROVAL_APPROVED) {
            throw new \LogicException('The cost of R' . number_format($cost, 2) . ' is above the approval threshold — record the owner\'s approval before ' . $action . '.');
        }
    }

    private function describeQuote(RentalWorkOrderQuote $quote): string
    {
        // AT-442 — a quote generated from an internal job card has no
        // named supplier at all; describe it as the maintenance team's own
        // quote rather than the generic "Unknown supplier" fallback, which
        // reads as a data gap rather than the deliberate internal case it is.
        $who = $quote->supplier?->name ?? ($quote->rental_job_card_id ? 'Our maintenance team' : 'Unknown supplier');

        return 'R' . number_format((float) $quote->amount, 2) . ' — ' . $who;
    }

    /**
     * §3.4 — assigning/re-assigning a supplier. Moves 'reported' straight to
     * 'ordered' the first time; re-assigning a supplier on an already-
     * ordered/in_progress work order does not re-trigger that transition.
     * Gated: refuses while owner approval is pending or declined (§3.4a) —
     * a work order can only ever exist on the agency_appoints route, so
     * this gate is only ever exercised by one raised directly (proactive
     * owner-instructed work, or straight from an inspection).
     */
    public function assignSupplier(int $agencyServiceProviderId, ?string $tradeType, User $by): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }
        $this->assertApprovalAllows('assign a supplier');
        // §17.6.5 — no work goes to a contractor without an authorisation (the owner's approval, the no-approval limit
        // or an emergency agreement).
        app(\App\Services\Rentals\RentalApprovalGateService::class)->assertAuthorised($this);

        // The approval rides on the selected quote — the supplier actually
        // ordered must be that quote's supplier (audit M1), otherwise the
        // approval snapshot names one supplier and another does the work.
        $selectedQuote = $this->quotes()->where('is_selected', true)->first();
        if ($selectedQuote && (int) $selectedQuote->agency_service_provider_id !== $agencyServiceProviderId) {
            throw new \LogicException('The selected quote is from a different supplier — select that supplier\'s quote, or the quote of the supplier being assigned, first.');
        }

        $updateType = $this->agency_service_provider_id === null ? 'supplier_assigned' : 'supplier_changed';
        $fromStatus = $this->status;
        $movingToOrdered = $this->status === self::STATUS_REPORTED;

        $this->agency_service_provider_id = $agencyServiceProviderId;
        if ($tradeType !== null) {
            $this->trade_type = $tradeType;
        }
        if ($movingToOrdered) {
            $this->status = self::STATUS_ORDERED;
            $this->ordered_at = now();
        }
        $this->save();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => $updateType, 'created_by_user_id' => $by->id,
        ]);
        if ($movingToOrdered) {
            $this->updates()->create([
                'agency_id' => $this->agency_id, 'update_type' => 'status_change',
                'from_status' => $fromStatus, 'to_status' => self::STATUS_ORDERED, 'created_by_user_id' => $by->id,
            ]);
        }
    }

    /**
     * §17.12 — the job card started, so the job is in progress. The CARD already passed the authorisation gate (including the
     * grandfathered / emergency cases), so this only mirrors the stage and logs it; it must not re-judge approval and refuse.
     */
    public function markStartedByJobCard(?User $by): void
    {
        if (! in_array($this->status, [self::STATUS_REPORTED, self::STATUS_ORDERED], true)) {
            return;
        }
        $fromStatus = $this->status;
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'status_change',
            'from_status' => $fromStatus, 'to_status' => self::STATUS_IN_PROGRESS, 'created_by_user_id' => $by?->id,
            'note' => 'Job card started',
        ]);
    }

    /** §3.4 — optional stage; a quick fix may skip straight to completed. */
    public function startProgress(?User $by, ?string $viaNote = null): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        $this->assertApprovalAllows('start work');
        // §17.6.5 — the same authorisation guard as scheduling/starting a job card.
        app(\App\Services\Rentals\RentalApprovalGateService::class)->assertAuthorised($this);

        $fromStatus = $this->status;
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'status_change',
            'from_status' => $fromStatus, 'to_status' => self::STATUS_IN_PROGRESS, 'created_by_user_id' => $by?->id,
            'note' => $viaNote,
        ]);
    }

    /**
     * §3.4 — a 'completed' photo is invited, not required, by default
     * (gated by the agency's own completion_requires_photo setting,
     * default off — corrected 2026-09-21, Johan: not every repair has a
     * meaningful photo, e.g. a gate motor). An agency may still turn this
     * on. paid_by is always required, no setting behind it.
     */
    /**
     * AT-445 — $by widened to nullable, same reasoning as recordQuote():
     * a contractor marking a job done through their secure link has no
     * User actor. $viaNote lets that caller tag the history entry.
     */
    public function complete(?User $by, array $attributes, ?string $viaNote = null): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        // §17.21.1 — the ONE close hook for the dispute rule (Build 3 fills it; inert in the foundation).
        app(\App\Services\Rentals\RentalCloseGuards::class)->assertNotDisputed($this);

        if (RentalWorkOrderSetting::completionRequiresPhotoFor($this->agency_id)
            && $this->photos()->where('photo_type', self::PHOTO_COMPLETED)->doesntExist()) {
            throw new \LogicException('At least one "completed" photo is required before this can be marked complete.');
        }

        $paidBy = $attributes['paid_by'] ?? null;
        if (empty($paidBy)) {
            throw new \InvalidArgumentException('paid_by is required before completion.');
        }

        $cost = $attributes['cost_amount'] ?? $this->cost_amount;
        $this->assertApprovalAllows('completing this work order', $cost !== null ? (float) $cost : null);
        // §17.21.1 — the ONE close hook for "final cost vs the approved amount" (Build 2 fills it; inert in the foundation).
        app(\App\Services\Rentals\RentalCloseGuards::class)->assertFinalCostWithinApproval($this, $cost !== null ? (float) $cost : null, $by);

        $fromStatus = $this->status;
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'completion_notes' => $attributes['completion_notes'] ?? $this->completion_notes,
            'paid_by' => $paidBy,
            'cost_amount' => $attributes['cost_amount'] ?? $this->cost_amount,
        ])->save();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'status_change',
            'from_status' => $fromStatus, 'to_status' => self::STATUS_COMPLETED,
            'created_by_user_id' => $by?->id,
            'note' => $viaNote,
        ]);

        // Johan, 8 Oct 2026: the tenant is told "work completed" (once - the fault outcome that may follow is the same step).
        if ($this->reported_fault_report_id && ($fault = RentalFaultReport::withoutGlobalScopes()->find($this->reported_fault_report_id))) {
            app(\App\Services\Rentals\RentalPortalNotificationService::class)->notifyTenantStatusChanged($fault);
        }

        // §17.16 — whichever route closed it (office Complete form, job-card close, contractor link), the
        // final-statement listener (Build 2) hears about it here. No listener is registered by the foundation.
        \App\Events\Rentals\RentalWorkOrderClosed::dispatch($this, $by?->id);

        // Johan, 8 Oct 2026: the fault resolves by itself (outcome Repaired, editable) - nobody goes back to pick an outcome by hand.
        $this->resolveReportedFault($by);
    }

    /** The fault this work order was made from closes itself once the work order is completed (see RentalFaultReport::resolveFromCompletedWorkOrder). */
    public function resolveReportedFault(?User $by = null): void
    {
        if ($this->status !== self::STATUS_COMPLETED || ! $this->reported_fault_report_id) {
            return;
        }
        RentalFaultReport::withoutGlobalScopes()->find($this->reported_fault_report_id)?->resolveFromCompletedWorkOrder($this, $by);
    }

    /**
     * AT-445 — .ai/specs/rental-portal-access.md §2. The tenant's own
     * "fixed"/"not fixed" sign-off on a completed job, contact-attributed
     * (never a User — the existing job-card tenant_confirmed_by_user_id
     * stays as the agent-recorded stand-in it already is). Mirrors onto
     * the 1:1 job card when one exists (AT-442, assignment_type=internal)
     * so both surfaces agree; an outside_supplier work order has no job
     * card, so only the work order itself is updated.
     */
    public function confirmByTenant(\App\Models\Contact $contact, bool $fixed, ?string $note = null): void
    {
        if ($this->status !== self::STATUS_COMPLETED) {
            throw new \LogicException('Only a completed work order can be confirmed by the tenant.');
        }

        $this->forceFill([
            'tenant_confirmed_at' => now(),
            'tenant_confirmed_fixed' => $fixed,
            'tenant_confirmed_by_contact_id' => $contact->id,
            'tenant_confirmation_note' => $note,
        ])->save();

        $this->updates()->create([
            'agency_id' => $this->agency_id,
            'update_type' => 'note',
            'note' => 'Tenant confirmed via portal: ' . ($fixed ? 'fixed' : 'not fixed') . ($note ? (' — ' . $note) : ''),
        ]);

        $jobCard = $this->jobCard;
        if ($jobCard) {
            $jobCard->forceFill([
                'tenant_confirmed_at' => now(),
                'tenant_confirmed_fixed' => $fixed,
                'tenant_confirmed_by_contact_id' => $contact->id,
                'tenant_confirmation_note' => $note,
            ])->save();
        }
    }

    // ---- BUILD 3 BEGIN — completion check & dispute (§17.10, §17.21.5): methods below are Build 3's only ----

    /** §17.10 — the newest completion round (highest round_no), or null when work has never been reported done. */
    public function latestCompletionRound(): ?RentalWorkCompletionRound
    {
        return $this->completionRounds()->reorder()->orderByDesc('round_no')->first();
    }

    /**
     * §17.10.6 — a tenant said the finished work is not complete. A completed work order is reopened
     * (`completed_at` cleared); every other open stage simply becomes `disputed`. Logged as `dispute_opened`
     * with the tenant's note. Cancelled work orders cannot be disputed.
     */
    public function markDisputed(string $tenantNote): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            throw new \LogicException('This work order was cancelled — it cannot be reopened.');
        }

        $fromStatus = $this->status;
        $this->forceFill(['status' => self::STATUS_DISPUTED, 'completed_at' => null])->save();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'dispute_opened',
            'from_status' => $fromStatus, 'to_status' => self::STATUS_DISPUTED,
            'note' => 'Tenant reported the work as not complete: ' . $tenantNote,
        ]);
    }

    /** §17.10.7 — work was reported done again after a dispute: back to `in_progress`. */
    public function returnFromDispute(): void
    {
        if ($this->status !== self::STATUS_DISPUTED) {
            return;
        }

        $this->forceFill(['status' => self::STATUS_IN_PROGRESS])->save();
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'status_change',
            'from_status' => self::STATUS_DISPUTED, 'to_status' => self::STATUS_IN_PROGRESS,
            'note' => 'Work reported done again after a tenant dispute',
        ]);
    }

    /**
     * §17.10.4 — the legacy `tenant_confirmed_*` columns on the work order (and its job card) are kept as
     * MIRRORS of the latest round's answer, so every older reader (reports, the portal alias) still agrees.
     */
    public function mirrorCompletionAnswer(bool $fixed, ?string $note, ?int $contactId): void
    {
        $this->forceFill([
            'tenant_confirmed_at' => now(),
            'tenant_confirmed_fixed' => $fixed,
            'tenant_confirmed_by_contact_id' => $contactId,
            'tenant_confirmation_note' => $note,
        ])->save();

        $jobCard = $this->jobCard;
        if ($jobCard) {
            $jobCard->forceFill([
                'tenant_confirmed_at' => now(),
                'tenant_confirmed_fixed' => $fixed,
                'tenant_confirmed_by_contact_id' => $contactId,
                'tenant_confirmation_note' => $note,
            ])->save();
        }
    }

    // ---- BUILD 3 END ----

    /** §3.4 — never deleted once anything has been logged against it. */
    public function cancel(User $by, string $reason): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            throw new \LogicException('This work order is already cancelled.');
        }
        if ($this->status === self::STATUS_COMPLETED) {
            // A completed work order is permanent evidence (audit M2).
            throw new \LogicException('A completed work order is a permanent record and cannot be cancelled.');
        }

        $fromStatus = $this->status;
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by->id,
            'cancel_reason' => $reason,
        ])->save();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'status_change',
            'from_status' => $fromStatus, 'to_status' => self::STATUS_CANCELLED, 'created_by_user_id' => $by->id,
        ]);

        // The fault it was made from goes back to "appoint a contractor" instead of staying pointed at this dead work order.
        if ($this->reported_fault_report_id) {
            RentalFaultReport::withoutGlobalScopes()->find($this->reported_fault_report_id)?->workOrderWasCancelled($this, $by);
        }

        // §17.7.2 — cancelling the work order withdraws any request still waiting on the owner.
        app(\App\Services\Rentals\RentalApprovalGateService::class)->withdrawOpenVariations($this, 'The work order was cancelled.', $by);

        // The card and its work order are one job (§17.10.9): cancelling one cancels the other, or the crew keeps a live card
        // (and link) for a job that no longer exists. The card's own cancel() sees this work order already cancelled and stops.
        $card = $this->jobCard;
        if ($card && ! in_array($card->status, [RentalJobCard::STATUS_CANCELLED, RentalJobCard::STATUS_COMPLETED], true)) {
            $card->cancel($by, $reason);
        }
    }

    /** §3.4 — a free-text elaboration, not tied to a status change. */
    public function addNote(string $note, User $by): void
    {
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'note', 'note' => $note, 'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * §6 — the "overdue" list-screen filter: ordered/in_progress with no
     * status change past the agency's own overdue_reminder_days. Reads the
     * model's own `updated_at`, bumped by every save() this class performs
     * (assignSupplier/startProgress/complete/cancel all call save()) —
     * a reliable proxy for "last time anything changed" without a
     * correlated subquery against rental_work_order_updates.
     */
    public function scopeOverdue(Builder $query, int $days): Builder
    {
        return $query->whereIn('rental_work_orders.status', [self::STATUS_ORDERED, self::STATUS_IN_PROGRESS])
            ->where('rental_work_orders.updated_at', '<=', now()->subDays($days));
    }

    /**
     * §17.12 / §17.17 — the "Awaiting owner" list filter: the work order's quote (or approval) is out with the owner and he has
     * not decided (`owner_approval_status = pending`). A closed (completed / cancelled) work order is no longer waiting on anyone,
     * so it is left out — the filter lists what the office can still chase.
     */
    public function scopeAwaitingOwner(Builder $query): Builder
    {
        return $query->where('rental_work_orders.owner_approval_status', self::APPROVAL_PENDING)
            ->whereNotIn('rental_work_orders.status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    /**
     * §17.7 / §17.17 — the "Variation pending" list filter: extra work has been put to the owner (an open
     * `awaiting_owner` variation) and he has not yet answered. Closed work orders are left out, as above.
     */
    public function scopeVariationPending(Builder $query): Builder
    {
        return $query->whereHas('variations', fn ($v) => $v->awaitingOwner())
            ->whereNotIn('rental_work_orders.status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    /**
     * §6 — OWN/BRANCH/AGENCY scoping, same convention as
     * RentalFaultReport::scopeVisibleTo()/RentalInspection::scopeVisibleTo().
     */
    public function scopeVisibleTo(Builder $query, User $user, ?string $requestedScope = null): Builder
    {
        $scope = \App\Services\Rentals\RentalDataScope::resolve($user, 'rental_work_orders', $requestedScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            // The agents on the record are its people too (same rule as the lease and the inspections, 8 Oct 2026): the lease's tenant-side
            // and owner-side agent, and the property's agent - not only whoever happened to create it. Otherwise an own-scoped agent is
            // notified about a work order and then gets a 403 opening it.
            $ids = $user->dataIdentityIds();

            return $query->where(fn (Builder $q) => $q
                ->whereIn('rental_work_orders.created_by_user_id', $ids)
                ->orWhereHas('lease', fn (Builder $l) => $l->agentedBy($ids))
                ->orWhereHas('property', fn (Builder $p) => $p->whereIn('properties.agent_id', $ids)));
        }

        return $query->whereRaw('1 = 0');
    }
}
