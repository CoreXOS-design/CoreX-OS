<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §3a, amended 2026-09-25 (§0c) — a fault
 * report is its own record: what actually happened during a tenancy that
 * might need repair, with its own lifecycle (reported -> owner approval
 * where required -> work order raised (optional) -> outcome). It exists
 * whether or not a work order is ever raised from it, and survives long
 * after any work order tied to it is closed. Stage 1 built the record
 * itself, reporting, and cancel/restore. Stage 2 (this revision) builds the
 * lifecycle: requestApproval()/recordApproval()/setOutcome(), against
 * columns already defined since Stage 1 (§3a's schema is spec-complete
 * from day one, not amended per stage). Work-order linkage (§3.1's reverse
 * FK, the workOrder() relation) lands in Stage 4.
 */
class RentalFaultReport extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const REPORTED_BY_TENANT = 'tenant';
    public const REPORTED_BY_AGENT_NOTICED = 'agent_noticed';
    public const REPORTED_BY_OWNER_INSTRUCTED = 'owner_instructed';
    /**
     * AT-445 follow-up, 2026-10-05 — the landlord's own portal submission
     * ("Request work / report a problem"), distinct from `owner_instructed`
     * (an agent recording that the owner asked for something verbally/by
     * phone): this value means the landlord typed it in themselves, through
     * their own portal session. Never offered on the agent-side create form
     * (`rental-fault-reports/create.blade.php`) — only
     * `ClientLandlordRentalsController::faultReportStore()` ever sets it.
     */
    public const REPORTED_BY_LANDLORD = 'landlord';

    public const CHANNEL_PHONE = 'phone';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_IN_PERSON = 'in_person';
    public const CHANNEL_APP = 'app';
    public const CHANNEL_OTHER = 'other';

    public const STATUS_REPORTED = 'reported';
    /** Fault flow F2 - the agent is reviewing it / preparing the owner's version. The owner cannot see it yet. */
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_WORK_ORDER_RAISED = 'work_order_raised';
    public const STATUS_OWNER_HANDLING = 'owner_handling';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CANCELLED = 'cancelled';

    public const APPROVAL_NOT_REQUIRED = 'not_required';
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_DECLINED = 'declined';

    public const ROUTE_AGENCY_APPOINTS = 'agency_appoints';
    public const ROUTE_OWNER_HANDLES = 'owner_handles';

    public const OUTCOME_REPAIRED = 'repaired';
    public const OUTCOME_REPAIRED_PARTIALLY = 'repaired_partially';
    public const OUTCOME_NOT_REPAIRED = 'not_repaired';
    public const OUTCOME_OWNER_DECLINED = 'owner_declined';
    public const OUTCOME_TENANT_LIABLE = 'tenant_liable';
    /**
     * .ai/specs/rentals-faults-work-orders.md §4.4 — a tenant who follows the
     * first-aid steps and doesn't need further action still submits, so it's
     * logged as evidence even though nothing was repaired by a supplier.
     */
    public const OUTCOME_RESOLVED_BY_FIRST_AID = 'resolved_by_first_aid';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'property_id',
        'lease_id',
        'rental_inspection_item_id',
        'rental_fault_type_id',
        'reported_inspection_observation_id',
        'rental_work_order_id',
        'reported_by_type',
        'reported_by_contact_id',
        'reported_by_user_id',
        'reported_channel',
        'captured_by_user_id',
        'title',
        'description',
        'owner_title',
        'owner_description',
        'owner_agent_note',
        'owner_photo_ids',
        'owner_version_saved_at',
        'owner_version_saved_by_user_id',
        'sent_to_owner_at',
        'sent_to_owner_by_user_id',
        'status',
        'owner_approval_status',
        'approval_route',
        'outcome',
        'outcome_note',
        'repaired_at',
        'reported_at',
        'resolved_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'repaired_at' => 'date',
        'owner_photo_ids' => 'array',
        'owner_version_saved_at' => 'datetime',
        'sent_to_owner_at' => 'datetime',
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** Deleted-related-record rule (.ai/BUILD_STANDARD.md §4) — see Lease::property(). */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    /** Branch logo fallback for the landlord PDF (§"Printing", 2026-09-22). */
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

    /** §2.2 — the catalogue entry the reporter picked, if any. */
    public function faultType(): BelongsTo
    {
        return $this->belongsTo(RentalFaultType::class, 'rental_fault_type_id');
    }

    public function reportedInspectionObservation(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionObservation::class, 'reported_inspection_observation_id');
    }

    /** Stage 4 — App\Models\RentalWorkOrder now exists; see this class's own docblock. */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    /** Same reasoning as property() above — the reporting tenant/landlord contact may be archived. */
    public function reportedByContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'reported_by_contact_id')->withTrashed();
    }

    public function reportedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function capturedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
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
        return $this->hasMany(RentalFaultReportPhoto::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RentalApproval::class)->orderByDesc('created_at');
    }

    /**
     * "Who did what" — Johan, 2026-09-22: audit tracking, the headline of
     * this build. Mirrors RentalWorkOrder::updates() exactly.
     */
    public function updates(): HasMany
    {
        return $this->hasMany(RentalFaultReportUpdate::class)->orderByDesc('created_at');
    }

    /**
     * A single, plain, chronological history — every state-changing action
     * on this record, actor + action + from/to + note + when, oldest first.
     * Merges the synthetic "logged" event (this record's own creation,
     * never written as a separate row — created_by_user_id/created_at
     * already carry it, so it isn't duplicated into rental_fault_report_
     * updates), the real update rows, and the approval decisions (a
     * separate table, §3.4a, folded in here so an agent reads ONE timeline
     * rather than two disconnected lists). Read-only, computed at request
     * time — never stored, so it can never drift from the rows it reads.
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
                    RentalFaultReportUpdate::TYPE_APPROVAL_REQUESTED => 'Sent to owner for a decision',
                    RentalFaultReportUpdate::TYPE_OWNER_VERSION_SAVED => 'Owner version prepared',
                    RentalFaultReportUpdate::TYPE_APPROVAL_RECORDED => 'Approval decision recorded',
                    RentalFaultReportUpdate::TYPE_WORK_ORDER_RAISED => 'Work order raised',
                    RentalFaultReportUpdate::TYPE_OUTCOME_SET => 'Outcome set',
                    RentalFaultReportUpdate::TYPE_STATUS_CHANGE => 'Status changed',
                    RentalFaultReportUpdate::TYPE_ARCHIVED => 'Archived',
                    RentalFaultReportUpdate::TYPE_RESTORED => 'Restored',
                    RentalFaultReportUpdate::TYPE_NOTE => 'Note added',
                    default => ucfirst(str_replace('_', ' ', $update->update_type)),
                },
                'from' => $update->from_status ? ucfirst(str_replace('_', ' ', $update->from_status)) : null,
                'to' => $update->to_status ? ucfirst(str_replace('_', ' ', $update->to_status)) : null,
                'note' => $update->note,
            ]);
        }

        foreach ($this->approvals as $approval) {
            $entries->push([
                'at' => $approval->created_at,
                'actor' => $approval->recordedByUser?->name
                    ?? ($approval->recordedByContact ? trim(($approval->recordedByContact->first_name ?? '') . ' ' . ($approval->recordedByContact->last_name ?? '')) . ' (owner, on the portal)' : null),
                'action' => $approval->decision === RentalApproval::DECISION_APPROVED ? 'Approved' : 'Declined',
                'from' => null,
                'to' => $approval->approval_route ? ucfirst(str_replace('_', ' ', $approval->approval_route)) : null,
                'note' => trim(($approval->evidence_text ?? '') . ($approval->contractorLine() ? ' — ' . $approval->contractorLine() : '')) ?: null,
            ]);
        }

        return $entries->sortBy('at')->values();
    }

    /**
     * §3a schema block — deletable only while nothing has been logged
     * against it: no photo, no linked work order. Once either exists, only
     * 'cancelled' — same reasoning and shape as RentalInspection::isDeletable().
     */
    public function isDeletable(): bool
    {
        return $this->photos()->doesntExist() && $this->rental_work_order_id === null;
    }

    /** Records one line in this report's own history (updates()). */
    private function logUpdate(string $type, ?User $by, ?string $note = null, ?string $from = null, ?string $to = null): void
    {
        $this->updates()->create([
            'agency_id' => $this->agency_id,
            'update_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'created_by_user_id' => $by?->id,
        ]);
    }

    /**
     * §12/§3a — logged in error, or the issue turned out not to need
     * action. Never deleted once anything has been logged against it (§3a
     * schema, isDeletable() above) — stays visible, cancelled.
     */
    public function cancel(User $by, string $reason): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            throw new \LogicException('This fault report is already cancelled.');
        }
        if ($this->status === self::STATUS_RESOLVED) {
            // A resolved report is the closed evidence record (audit M2).
            throw new \LogicException('A resolved fault report is a permanent record and cannot be cancelled.');
        }

        $fromStatus = $this->status;
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by->id,
            'cancel_reason' => $reason,
        ])->save();

        $this->logUpdate(RentalFaultReportUpdate::TYPE_STATUS_CHANGE, $by, $reason, $fromStatus, self::STATUS_CANCELLED);
    }

    /**
     * Johan, 2026-09-22 — archive/restore captured in the same "who did
     * what" history as every other action on this record.
     */
    public function archive(User $by): void
    {
        $this->delete();
        $this->logUpdate(RentalFaultReportUpdate::TYPE_ARCHIVED, $by);
    }

    public function restoreRecord(User $by): void
    {
        $this->restore();
        $this->logUpdate(RentalFaultReportUpdate::TYPE_RESTORED, $by);
    }

    /**
     * §3a.1 — a purely agent-initiated status marker: "I've asked the owner,
     * waiting to hear back." Records no evidence (Johan's ruling requires
     * evidence only for the DECISION, §3.4a) — it exists so the list screen
     * can distinguish "nothing asked yet" from "asked, pending." Optional:
     * recordApproval() below does NOT require this to have been called
     * first — an agent who already has the written reply in hand records
     * the decision directly, without a pointless intermediate click.
     */
    public function requestApproval(User $by): void
    {
        // Fault flow F2: this IS "send to the owner". The agent has reviewed it and prepared what the owner will
        // see (saveOwnerVersion()); only now does the owner see it - in the portal, and by email.
        if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This fault report is already closed.');
        }
        if ($this->owner_approval_status === self::APPROVAL_APPROVED || $this->owner_approval_status === self::APPROVAL_DECLINED) {
            throw new \LogicException('A decision has already been recorded for this fault report.');
        }
        if ($this->sent_to_owner_at !== null && $this->owner_approval_status === self::APPROVAL_PENDING) {
            throw new \LogicException('This fault report has already been sent to the owner.');
        }

        // A caller that never prepared a separate version (API / older code paths) sends the original wording
        // unchanged; the agent screen always requires the explicit review step first (controller).
        if ($this->owner_version_saved_at === null) {
            $this->saveOwnerVersion([
                'owner_title' => $this->title,
                'owner_description' => $this->description,
                'owner_agent_note' => null,
                'owner_photo_ids' => $this->photos()->pluck('id')->all(),
            ], $by);
        }

        $this->forceFill([
            'status' => self::STATUS_AWAITING_APPROVAL,
            'owner_approval_status' => self::APPROVAL_PENDING,
            'sent_to_owner_at' => now(),
            'sent_to_owner_by_user_id' => $by->id,
        ])->save();

        $this->logUpdate(RentalFaultReportUpdate::TYPE_APPROVAL_REQUESTED, $by, null, null, self::STATUS_AWAITING_APPROVAL);

        // AT-445 - .ai/specs/rental-portal-access.md §6. A decision is now waiting in the landlord's portal.
        app(\App\Services\Rentals\RentalPortalNotificationService::class)->notifyLandlordDecisionNeeded($this);
        // Johan, 8 Oct 2026: the tenant is told their fault has gone to the owner for approval.
        app(\App\Services\Rentals\RentalPortalNotificationService::class)->notifyTenantStatusChanged($this);
    }

    /**
     * Fault flow F2 - the status in the words Johan named: reported, under agent review, sent to owner, owner decided
     * (agent-side wording).
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_REPORTED => 'Reported',
            self::STATUS_UNDER_REVIEW => 'Under agent review',
            self::STATUS_AWAITING_APPROVAL => 'Sent to owner',
            self::STATUS_APPROVED => 'Owner decided - approved',
            self::STATUS_DECLINED => 'Owner decided - declined',
            self::STATUS_OWNER_HANDLING => 'Owner decided - owner arranges the repair',
            self::STATUS_WORK_ORDER_RAISED => 'Work order raised',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    /** The same status for the OWNER's screens: an unsent report is only ever "with your agent". */
    public function ownerStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_REPORTED, self::STATUS_UNDER_REVIEW => 'With your agent',
            self::STATUS_AWAITING_APPROVAL => 'Waiting for your decision',
            self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_OWNER_HANDLING => 'You decided',
            default => $this->statusLabel(),
        };
    }

    /**
     * Fault flow F2 - the agent prepares what the owner will see: a title and description they have edited, the
     * photos they chose, and an agent note/recommendation. The tenant's ORIGINAL (title, description, photos) is never
     * touched. Only before the report is sent: once the owner can see a version, it is fixed.
     *
     * @param array{owner_title:string, owner_description?:?string, owner_agent_note?:?string, owner_photo_ids?:array<int>} $attributes
     */
    public function saveOwnerVersion(array $attributes, User $by): void
    {
        if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This fault report is already closed.');
        }
        if ($this->sent_to_owner_at !== null || in_array($this->owner_approval_status, [self::APPROVAL_APPROVED, self::APPROVAL_DECLINED], true)) {
            throw new \LogicException('This fault report has already been sent to the owner - the version they see can no longer be changed.');
        }
        $title = trim((string) ($attributes['owner_title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('The owner version needs a title.');
        }

        // Photos: only this report's own, de-duplicated, order kept.
        $own = $this->photos()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $photoIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($attributes['owner_photo_ids'] ?? [])),
            fn ($id) => in_array($id, $own, true)
        )));

        $fromStatus = $this->status;
        $this->forceFill([
            'owner_title' => $title,
            'owner_description' => $attributes['owner_description'] ?? null,
            'owner_agent_note' => $attributes['owner_agent_note'] ?? null,
            'owner_photo_ids' => $photoIds,
            'owner_version_saved_at' => now(),
            'owner_version_saved_by_user_id' => $by->id,
            'status' => $this->status === self::STATUS_REPORTED ? self::STATUS_UNDER_REVIEW : $this->status,
        ])->save();

        $this->logUpdate(
            RentalFaultReportUpdate::TYPE_OWNER_VERSION_SAVED,
            $by,
            null,
            $fromStatus !== $this->status ? $fromStatus : null,
            $fromStatus !== $this->status ? $this->status : null
        );
    }

    /**
     * What the owner is allowed to see of this fault: the agent's sanitised version only (falling back to the
     * reporter's own words ONLY for a report the owner submitted themselves). Never the tenant's original.
     *
     * @return array{title:string, description:?string, agent_note:?string, photos:\Illuminate\Support\Collection}
     */
    public function ownerVersion(): array
    {
        if ($this->owner_version_saved_at === null) {
            return [
                'title' => $this->reported_by_type === self::REPORTED_BY_LANDLORD ? (string) $this->title : 'Fault reported',
                'description' => $this->reported_by_type === self::REPORTED_BY_LANDLORD ? $this->description : null,
                'agent_note' => null,
                'photos' => collect(),
            ];
        }

        $ids = (array) ($this->owner_photo_ids ?? []);

        return [
            'title' => (string) ($this->owner_title ?: $this->title),
            'description' => $this->owner_description,
            'agent_note' => $this->owner_agent_note,
            'photos' => $this->photos()->whereIn('id', $ids ?: [0])->get(),
        ];
    }

    /**
     * Fault flow F2/F7 - the ONE visibility rule for the owner (portal list, detail, counts, API): a fault is the
     * owner's to see once the agent SENT it, once a decision exists (so the owner can read it back, whoever took
     * it), or when the owner reported it themselves. An unsent fault is invisible to them.
     */
    public function scopeVisibleToOwner(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNotNull('rental_fault_reports.sent_to_owner_at')
                ->orWhereIn('rental_fault_reports.owner_approval_status', [self::APPROVAL_PENDING, self::APPROVAL_APPROVED, self::APPROVAL_DECLINED])
                ->orWhere('rental_fault_reports.reported_by_type', self::REPORTED_BY_LANDLORD);
        });
    }

    public function isVisibleToOwner(): bool
    {
        return $this->sent_to_owner_at !== null
            || in_array($this->owner_approval_status, [self::APPROVAL_PENDING, self::APPROVAL_APPROVED, self::APPROVAL_DECLINED], true)
            || $this->reported_by_type === self::REPORTED_BY_LANDLORD;
    }

    /** The decision that settled this fault (latest row of the append-only log), or null while undecided. */
    public function decision(): ?RentalApproval
    {
        if (! in_array($this->owner_approval_status, [self::APPROVAL_APPROVED, self::APPROVAL_DECLINED], true)) {
            return null;
        }

        return $this->approvals()->with(['recordedByUser', 'recordedByContact', 'contractorSupplier'])->first();
    }

    /**
     * Fault flow F7 - the same plain read-only summary on both sides: who decided, how, when, why, and the contractor.
     *
     * @return array{decision:string, by:string, how:string, via_link:bool, at:?\Illuminate\Support\Carbon, reason:?string, route:?string, contractor:?string}|null
     */
    public function decisionSummary(): ?array
    {
        $d = $this->decision();
        if (! $d) {
            return null;
        }
        $viaLink = $d->evidence_type === RentalApproval::EVIDENCE_PORTAL;
        $by = $d->recordedByUser?->name
            ?? ($d->recordedByContact ? trim(($d->recordedByContact->first_name ?? '') . ' ' . ($d->recordedByContact->last_name ?? '')) : 'the owner');

        return [
            'decision' => $d->decision,
            'by' => $by,
            'how' => $viaLink
                ? 'Decided by the owner on the portal link'
                : 'Captured by the agent (' . str_replace('_', ' ', $d->evidence_type) . ')',
            'via_link' => $viaLink,
            'at' => $d->decided_at ?? $d->created_at,
            'reason' => $d->decision === RentalApproval::DECISION_DECLINED ? $d->evidence_text : null,
            'route' => $d->approval_route,
            'contractor' => $d->contractorLine(),
        ];
    }

    /**
     * §3.4a/§3a.1, settled 2026-09-25 — the decision, always in writing, with
     * TWO distinct outcomes when approved (§0c): agency_appoints (a work
     * order follows, Stage 4) or owner_handles (no work order, ever, for
     * this report — a fully normal, complete path). Writes the append-only
     * evidence row and updates this report's own denormalized current-value
     * columns, same "current column + log" shape §3.4 already uses for
     * rental_work_orders.status/rental_work_order_updates.
     */
    /**
     * AT-445 — $recordedBy widened to `User|Contact`: a landlord can now
     * record this decision directly through the portal (evidence_type
     * 'portal', no agent transcription), rather than only an agent
     * transcribing a verbal/whatsapp/email decision. Behaviour for an
     * existing `User` caller is completely unchanged.
     */
    public function recordApproval(User|\App\Models\Contact $recordedBy, array $attributes): RentalApproval
    {
        // Fault flow F7 - ONE decision, ever: the owner on the link and the agent on the screen race each other, so the
        // check runs on a locked fresh row. Whoever is second is told who decided and how.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($recordedBy, $attributes) {
            $fresh = static::withoutGlobalScopes()->lockForUpdate()->find($this->id);
            if ($fresh) {
                $this->setRawAttributes($fresh->getAttributes(), true);
            }

            if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CANCELLED], true)) {
                throw new \LogicException('This fault report is already closed.');
            }
            if (in_array($this->owner_approval_status, [self::APPROVAL_APPROVED, self::APPROVAL_DECLINED], true)) {
                $summary = $this->decisionSummary();
                throw new \LogicException('A decision has already been recorded for this fault report'
                    . ($summary ? ' (' . strtolower($summary['how']) . ', ' . ($summary['at']?->format('j M Y H:i') ?? '') . ')' : '') . '.');
            }
            // Once a work order has been raised the decision is spent: a second approval would reset status to
            // approved and let a second work order be raised, orphaning the first (audit M3).
            if ($this->rental_work_order_id !== null || $this->status === self::STATUS_WORK_ORDER_RAISED) {
                throw new \LogicException('A work order has already been raised for this fault report — its approval decision can no longer be changed.');
            }

            $decision = $attributes['decision'];
            $route = $attributes['approval_route'] ?? null;

            if ($decision === self::APPROVAL_APPROVED && ! in_array($route, [self::ROUTE_AGENCY_APPOINTS, self::ROUTE_OWNER_HANDLES], true)) {
                throw new \InvalidArgumentException('approval_route (agency_appoints or owner_handles) is required when the decision is approved.');
            }
            if ($decision === self::APPROVAL_DECLINED) {
                $route = null; // never meaningful on a decline
                if (trim((string) ($attributes['evidence_text'] ?? '')) === '') {
                    throw new \InvalidArgumentException('A reason is required when a fault is declined.');
                }
            }

            // Who handles the repair (F3/F4/F5). Owner's own contractor: optional name + phone. Agency contractor:
            // a supplier of THIS agency from the supplier list. A decline carries no contractor.
            $contractorSource = null;
            $contractorName = null;
            $contractorPhone = null;
            $supplierId = null;
            if ($decision === self::APPROVAL_APPROVED && $route === self::ROUTE_OWNER_HANDLES) {
                $contractorSource = RentalApproval::CONTRACTOR_OWN;
                $contractorName = trim((string) ($attributes['contractor_name'] ?? '')) ?: null;
                $contractorPhone = trim((string) ($attributes['contractor_phone'] ?? '')) ?: null;
            } elseif ($decision === self::APPROVAL_APPROVED && ! empty($attributes['agency_service_provider_id'])) {
                $supplierId = (int) $attributes['agency_service_provider_id'];
                $ok = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()
                    ->where('agency_id', $this->agency_id)->where('is_active', true)->whereNull('deleted_at')->whereKey($supplierId)->exists();
                if (! $ok) {
                    throw new \InvalidArgumentException('That contractor is not on this agency\'s supplier list.');
                }
                $contractorSource = RentalApproval::CONTRACTOR_AGENCY;
            }

            $approval = $this->approvals()->create([
                'agency_id' => $this->agency_id,
                'decision' => $decision,
                'approval_route' => $route,
                'contractor_source' => $contractorSource,
                'contractor_name' => $contractorName,
                'contractor_phone' => $contractorPhone,
                'agency_service_provider_id' => $supplierId,
                'evidence_type' => $attributes['evidence_type'],
                'evidence_text' => $attributes['evidence_text'] ?? null,
                'evidence_file_path' => $attributes['evidence_file_path'] ?? null,
                'decided_at' => $attributes['decided_at'] ?? now(),
                'recorded_by_user_id' => $recordedBy instanceof User ? $recordedBy->id : null,
                'recorded_by_contact_id' => $recordedBy instanceof \App\Models\Contact ? $recordedBy->id : null,
            ]);

            $newStatus = match (true) {
                $decision === self::APPROVAL_DECLINED => self::STATUS_DECLINED,
                $route === self::ROUTE_OWNER_HANDLES => self::STATUS_OWNER_HANDLING,
                $route === self::ROUTE_AGENCY_APPOINTS => self::STATUS_APPROVED,
                default => $this->status,
            };

            $this->forceFill([
                'status' => $newStatus,
                'owner_approval_status' => $decision === self::APPROVAL_APPROVED ? self::APPROVAL_APPROVED : self::APPROVAL_DECLINED,
                'approval_route' => $route,
            ])->save();

            // AT-445 - .ai/specs/rental-portal-access.md §6.
            app(\App\Services\Rentals\RentalPortalNotificationService::class)->notifyTenantStatusChanged($this);

            return $approval;
        });
    }

    /**
     * §3a.2/§0c — the spine of this whole record: was it repaired, and when.
     * Deliberately NOT gated on owner_approval_status, approval_route, or
     * any work order existing — Johan's own situation 3 (§1/§7) is a fault
     * report reaching an outcome having never gone through approval at all
     * (nobody fixed it), and the owner_handles route (§3a.1) reaches a
     * `repaired` outcome with no work order ever having existed. This method
     * is callable from any state except already-closed, on purpose.
     */
    /**
     * AT-445 — $by widened to nullable: a tenant's own first-aid
     * self-resolution has no staff actor at all (logUpdate() already
     * tolerates a null actor). reported_by_contact_id on the report itself
     * is the attribution that matters for that case.
     */
    public function setOutcome(array $attributes, ?User $by = null): void
    {
        if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This fault report is already closed.');
        }

        $outcome = $attributes['outcome'];
        $note = $attributes['outcome_note'] ?? null;
        $repairedAt = $attributes['repaired_at'] ?? null;

        // §17.10.6 — a fault's "repaired" outcome waits for the tenant check: it cannot be saved while the linked work
        // order is disputed, or while its latest completion round is still waiting for the tenant. Other outcomes
        // (not repaired, owner declined, …) are unaffected.
        if (in_array($outcome, [self::OUTCOME_REPAIRED, self::OUTCOME_REPAIRED_PARTIALLY], true) && $this->rental_work_order_id) {
            $this->assertTenantCheckAllowsRepairedOutcome();
        }

        // §4.4 — self-explanatory in a way not_repaired/tenant_liable are not;
        // no note required, same treatment 'repaired' itself already gets.
        if (!in_array($outcome, [self::OUTCOME_REPAIRED, self::OUTCOME_RESOLVED_BY_FIRST_AID], true) && empty($note)) {
            throw new \InvalidArgumentException('outcome_note is required unless the outcome is "repaired" or "resolved_by_first_aid".');
        }
        if (in_array($outcome, [self::OUTCOME_REPAIRED, self::OUTCOME_REPAIRED_PARTIALLY], true) && empty($repairedAt)) {
            throw new \InvalidArgumentException('repaired_at is required when the outcome is repaired or repaired_partially — Johan\'s own words: "the important part is capturing if and when the repairs were carried out."');
        }
        // §4.4 — the tenant IS reporting that it's already fixed; the
        // submission moment is the repair moment unless told otherwise.
        if ($outcome === self::OUTCOME_RESOLVED_BY_FIRST_AID && empty($repairedAt)) {
            $repairedAt = now();
        }

        $this->forceFill([
            'status' => self::STATUS_RESOLVED,
            'outcome' => $outcome,
            'outcome_note' => $note,
            'repaired_at' => $repairedAt,
            'resolved_at' => now(),
        ])->save();

        // §4/Johan 2026-09-22 — "outcome set" is the spine of this record
        // (this method's own earlier docblock) and, before this change, the
        // ONE state-changing action on this table with no actor recorded
        // anywhere. This is the fix.
        $this->logUpdate(RentalFaultReportUpdate::TYPE_OUTCOME_SET, $by, $note);

        // AT-445 — .ai/specs/rental-portal-access.md §6. Skipped for the
        // tenant's own first-aid self-resolution — notifying someone of
        // the action they just took themselves is noise, not news.
        if ($outcome !== self::OUTCOME_RESOLVED_BY_FIRST_AID) {
            app(\App\Services\Rentals\RentalPortalNotificationService::class)->notifyTenantStatusChanged($this);
        }
    }

    /**
     * §17.3.2 — why a work order CANNOT be created from this fault report right now, in words a non-technical user
     * understands; null when it can. The one place the "Create work order" gate lives, so the screen (button hidden,
     * reason shown) and RentalWorkOrderService::fromFaultReport() can never disagree.
     *
     * Allowed: reported, awaiting_approval, or approved on ANY route (W1/W5, 8 Oct 2026: agency contractor, the owner's own
     * contractor or the internal crew - every approved fault gets a work order). Refused: declined (the owner said no),
     * work_order_raised, resolved, cancelled.
     */
    public function workOrderBlockReason(): ?string
    {
        if ($this->hasLiveWorkOrder()) {
            return 'A work order has already been created for this fault report.';
        }

        return match ($this->status) {
            self::STATUS_DECLINED => 'The owner declined this repair, so a work order cannot be created from it.',
            self::STATUS_RESOLVED => 'This fault report is already resolved.',
            self::STATUS_CANCELLED => 'This fault report was cancelled.',
            // W1/W5 (8 Oct 2026): every approved fault gets a work order - whoever does the work, including the owner's own contractor.
            self::STATUS_APPROVED, self::STATUS_OWNER_HANDLING, self::STATUS_REPORTED, self::STATUS_AWAITING_APPROVAL => null,
            // Its work order was cancelled (and nothing live replaced it): the repair still has to happen, so a new one may be created.
            self::STATUS_WORK_ORDER_RAISED => null,
            default => 'A work order cannot be created from this fault report in its current state.',
        };
    }

    /** A work order that is still in play for this fault - a CANCELLED one does not count (the fault must not be stuck behind it). */
    public function hasLiveWorkOrder(): bool
    {
        if ($this->rental_work_order_id === null) {
            return $this->status === self::STATUS_WORK_ORDER_RAISED;
        }
        $linked = RentalWorkOrder::withoutGlobalScopes()->find($this->rental_work_order_id);

        return $linked !== null && $linked->status !== RentalWorkOrder::STATUS_CANCELLED;
    }

    /** §17.10.6 — the guard behind setOutcome(): refuses "repaired" while the tenant has not settled the finished work. */
    private function assertTenantCheckAllowsRepairedOutcome(): void
    {
        $workOrder = RentalWorkOrder::withoutGlobalScopes()->find($this->rental_work_order_id);
        if (! $workOrder) {
            return;
        }
        if ($workOrder->hasOpenDispute()) {
            throw new \LogicException('The tenant has reported the work as not complete — the repair cannot be marked as done until it is put right and the tenant checks it again.');
        }
        $round = $workOrder->latestCompletionRound();
        if ($round && $round->isAwaitingTenant()) {
            $due = $round->window_ends_at ? ' (answer due ' . $round->window_ends_at->format('j M Y') . ')' : '';
            throw new \LogicException("Waiting for the tenant to check the finished work{$due} — the repair can be marked as done once they answer, or when their time to answer runs out.");
        }
    }

    /**
     * §3a.1, called from RentalWorkOrderService::fromFaultReport() — the
     * agency_appoints route producing a real work order. Consolidates the
     * mutation onto the model (matching this class's own convention) and
     * captures the actor, which the inline forceFill() this replaces did
     * not (Johan, 2026-09-22 — audit tracking is the point of this build).
     */
    public function recordWorkOrderRaised(RentalWorkOrder $workOrder, User $by): void
    {
        $fromStatus = $this->status;
        $this->forceFill([
            'rental_work_order_id' => $workOrder->id,
            'status' => self::STATUS_WORK_ORDER_RAISED,
        ])->save();

        $this->logUpdate(RentalFaultReportUpdate::TYPE_WORK_ORDER_RAISED, $by, null, $fromStatus, self::STATUS_WORK_ORDER_RAISED);

        // AT-445 — .ai/specs/rental-portal-access.md §6.
        app(\App\Services\Rentals\RentalPortalNotificationService::class)->notifyTenantStatusChanged($this);
    }

    /**
     * §3a.4 — OWN/BRANCH/AGENCY scoping, same PermissionService::getDataScope()
     * + clampScope() convention as RentalInspection::scopeVisibleTo(). This is
     * the AGENCY-WIDE, property-wide view (§3a.4's own distinction) — the
     * out-inspection's attached block (Stage 5) queries lease_id directly
     * instead, deliberately narrower than this scope.
     */
    public function scopeVisibleTo(Builder $query, User $user, ?string $requestedScope = null): Builder
    {
        $scope = \App\Services\Rentals\RentalDataScope::resolve($user, 'rental_fault_reports', $requestedScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            // The agents on the record are its people too (same rule as the lease and the inspections, 8 Oct 2026): the lease's tenant-side
            // and owner-side agent, and the property's agent - not only whoever happened to create it. Otherwise an own-scoped agent is
            // notified about a fault and then gets a 403 opening it.
            $ids = $user->dataIdentityIds();

            return $query->where(fn (Builder $q) => $q
                ->whereIn('rental_fault_reports.created_by_user_id', $ids)
                ->orWhereHas('lease', fn (Builder $l) => $l->agentedBy($ids))
                ->orWhereHas('property', fn (Builder $p) => $p->whereIn('properties.agent_id', $ids)));
        }

        return $query->whereRaw('1 = 0');
    }
}
