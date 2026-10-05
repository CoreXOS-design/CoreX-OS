<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §14 (AT-442) — the internal counterpart
 * to "outside supplier" on a rental work order. A job card BUILDS the work
 * order it belongs to (1:1, rental_work_order_id unique) so it appears in
 * the work orders list, on the lease, and in the tenancy log like any
 * other work order — never a second, parallel ticket system.
 */
class RentalJobCard extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'rental_work_order_id',
        'property_id',
        'lease_id',
        'title',
        'status',
        'assigned_user_id',
        'scheduled_at',
        'due_at',
        'access_notes',
        'total_amount',
        'vat_registered_snapshot',
        'vat_capture_mode_snapshot',
        'vat_snapshotted_at',
        'worker_signed_off_at',
        'worker_signed_off_by_user_id',
        'agent_signed_off_at',
        'agent_signed_off_by_user_id',
        'tenant_confirmed_at',
        'tenant_confirmed_by_user_id',
        'tenant_confirmation_note',
        // AT-445 — .ai/specs/rental-portal-access.md §2. Contact-attributed
        // counterpart, written when the real tenant confirms via the portal
        // (RentalWorkOrder::confirmByTenant() mirrors onto this card).
        'tenant_confirmed_fixed',
        'tenant_confirmed_by_contact_id',
        'completed_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'due_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'vat_registered_snapshot' => 'boolean',
        'vat_snapshotted_at' => 'datetime',
        'worker_signed_off_at' => 'datetime',
        'agent_signed_off_at' => 'datetime',
        'tenant_confirmed_at' => 'datetime',
        'tenant_confirmed_fixed' => 'boolean',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function workerSignedOffByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_signed_off_by_user_id');
    }

    public function agentSignedOffByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_signed_off_by_user_id');
    }

    public function tenantConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_confirmed_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(RentalJobCardTask::class)->orderBy('sort_order')->orderBy('id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RentalJobCardLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(RentalJobCardUpdate::class)->orderByDesc('created_at');
    }

    /** §14 — quotes sent to the owner from this job card (rental_work_order_quotes.rental_job_card_id). */
    public function quotes(): HasMany
    {
        return $this->hasMany(RentalWorkOrderQuote::class)->orderByDesc('quote_date');
    }

    public function isDeletable(): bool
    {
        return $this->tasks()->doesntExist() && $this->lines()->doesntExist() && $this->updates()->doesntExist();
    }

    public function logUpdate(string $type, ?User $by, ?string $note = null, ?string $from = null, ?string $to = null): void
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
     * A single, plain, chronological history — mirrors
     * RentalWorkOrder::history() exactly: the synthetic "Logged" event plus
     * every real update row, oldest first.
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
                    'status_change' => 'Status changed',
                    'crew_assigned' => 'Crew assigned',
                    'scheduled' => 'Scheduled',
                    'task_added' => 'Task added',
                    'task_ticked' => 'Task ticked',
                    'task_archived' => 'Task archived',
                    'line_added' => 'Line added',
                    'line_changed' => 'Line changed',
                    'line_archived' => 'Line archived',
                    'quote_sent' => 'Quote sent to owner',
                    'sign_off' => 'Signed off',
                    'archived' => 'Archived',
                    'restored' => 'Restored',
                    default => ucfirst(str_replace('_', ' ', $update->update_type)),
                },
                'from' => $update->from_status ? ucfirst(str_replace('_', ' ', $update->from_status)) : null,
                'to' => $update->to_status ? ucfirst(str_replace('_', ' ', $update->to_status)) : null,
                'note' => $update->note,
            ]);
        }

        return $entries->sortBy('at')->values();
    }

    /** Recalculates total_amount from live, non-archived lines. Never trusts a client-sent total. */
    public function recalcTotal(): void
    {
        $this->forceFill(['total_amount' => $this->lines()->sum('line_total')])->save();
    }

    public function archive(User $by): void
    {
        $this->delete();
        $this->logUpdate('archived', $by);
    }

    public function restoreRecord(User $by): void
    {
        $this->restore();
        $this->logUpdate('restored', $by);
    }

    private function assertOpen(): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This job card is already closed.');
        }
    }

    public function assignCrew(User $crewMember, User $by): void
    {
        $this->assertOpen();
        $this->update(['assigned_user_id' => $crewMember->id]);
        $this->logUpdate('crew_assigned', $by, $crewMember->name);
    }

    /**
     * §14 — scheduling moves draft/approved straight to 'scheduled'. Johan's
     * brief does not require approval before scheduling can be SET (an
     * agent may book a crew member's time while a quote is still pending) —
     * only the quote/approval gate (sendToOwnerAsQuote()/applyApprovalResult())
     * governs whether the OWNER has signed off, which is tracked
     * independently via the linked work order's owner_approval_status.
     */
    public function schedule(?\DateTimeInterface $scheduledAt, ?\DateTimeInterface $dueAt, User $by): void
    {
        $this->assertOpen();
        $fromStatus = $this->status;

        $this->forceFill([
            'scheduled_at' => $scheduledAt,
            'due_at' => $dueAt,
            'status' => in_array($this->status, [self::STATUS_DRAFT, self::STATUS_QUOTED, self::STATUS_APPROVED], true)
                ? self::STATUS_SCHEDULED
                : $this->status,
        ])->save();

        if ($fromStatus !== $this->status) {
            $this->logUpdate('status_change', $by, null, $fromStatus, $this->status);
        } else {
            $this->logUpdate('scheduled', $by);
        }
    }

    public function start(User $by): void
    {
        $this->assertOpen();
        $fromStatus = $this->status;
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
        $this->logUpdate('status_change', $by, null, $fromStatus, self::STATUS_IN_PROGRESS);
    }

    public function workerSignOff(User $by): void
    {
        $this->assertOpen();
        $this->forceFill([
            'worker_signed_off_at' => now(),
            'worker_signed_off_by_user_id' => $by->id,
        ])->save();
        $this->logUpdate('sign_off', $by, 'Worker — done');
    }

    public function agentSignOff(User $by): void
    {
        $this->assertOpen();
        $this->forceFill([
            'agent_signed_off_at' => now(),
            'agent_signed_off_by_user_id' => $by->id,
        ])->save();
        $this->logUpdate('sign_off', $by, 'Agent — checked');
    }

    /** Tenant confirmation is recorded BY THE AGENT for now — AT-442 brief; tenant login is AT-445. */
    public function tenantConfirm(?string $note, User $by): void
    {
        $this->forceFill([
            'tenant_confirmed_at' => now(),
            'tenant_confirmed_by_user_id' => $by->id,
            'tenant_confirmation_note' => $note,
        ])->save();
        $this->logUpdate('sign_off', $by, 'Tenant — confirmed fixed' . ($note ? ": {$note}" : ''));
    }

    /**
     * Worker + agent sign-off are both required (tenant confirmation is
     * additional evidence, never a gate — same "never let an external
     * party's silence become an internal deadlock" principle
     * rental_work_orders' own tenant-confirmation field already carries).
     */
    public function complete(User $by): void
    {
        $this->assertOpen();
        if (! $this->worker_signed_off_at || ! $this->agent_signed_off_at) {
            throw new \LogicException('Both the worker and the agent must sign off before this job card can be completed.');
        }

        $fromStatus = $this->status;
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
        $this->logUpdate('status_change', $by, null, $fromStatus, self::STATUS_COMPLETED);
    }

    public function cancel(User $by, string $reason): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            throw new \LogicException('This job card is already cancelled.');
        }

        $fromStatus = $this->status;
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by->id,
            'cancel_reason' => $reason,
        ])->save();
        $this->logUpdate('status_change', $by, $reason, $fromStatus, self::STATUS_CANCELLED);
    }

    /**
     * After a quote is sent/selected, pulls the linked work order's
     * resulting owner_approval_status into this job card's own status —
     * 'approved' the moment the threshold gate (or a recorded decision)
     * resolves to approved; left at 'quoted' while pending; a decline does
     * NOT auto-cancel (an agent decides the next step, same discipline
     * RentalWorkOrder::recordContractorResponse() reasoning already uses
     * elsewhere in this feature family).
     */
    public function syncStatusFromWorkOrder(): void
    {
        if ($this->status !== self::STATUS_QUOTED) {
            return;
        }

        $approval = $this->workOrder?->owner_approval_status;
        if (in_array($approval, [RentalWorkOrder::APPROVAL_APPROVED, RentalWorkOrder::APPROVAL_NOT_REQUIRED], true)) {
            $fromStatus = $this->status;
            $this->update(['status' => self::STATUS_APPROVED]);
            $this->logUpdate('status_change', null, 'Owner approval resolved on the linked work order', $fromStatus, self::STATUS_APPROVED);
        }
    }

    public function scopeVisibleTo(Builder $query, User $user, ?string $requestedScope = null): Builder
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_job_cards');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            return $query->whereIn('rental_job_cards.created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotIn('rental_job_cards.status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED])
            ->whereNotNull('rental_job_cards.due_at')
            ->where('rental_job_cards.due_at', '<', now());
    }
}
