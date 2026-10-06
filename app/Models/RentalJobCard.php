<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §14 (AT-442), rebuilt 2026-10-05 after
 * Johan rejected the original design on QA1. A job card's source is now
 * OPTIONAL — rental_work_order_id / rental_fault_report_id are both
 * nullable, and "no source — created directly" is a normal, permanent
 * state (a garden-service job has neither). A job card never creates a
 * work order on its own account any more; it only ever LINKS to one that
 * already exists (RentalJobCardService::createStandalone()), with exactly
 * one exception — sendToOwnerAsQuote() lazily creates one the moment the
 * owner-approval/threshold machinery is actually needed, never at creation
 * time. Tasks are numbered containers; each task owns its own parts &
 * labour lines (RentalJobCardLine.rental_job_card_task_id) — a line with no
 * task sits in the built-in "General" group.
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
    /**
     * §17.12 — a tenant reported finished work as not complete (§17.10). An OPEN
     * state (isClosed() is false): the job is reopened to the crew and the final
     * sign-off/close is refused until a new completion round is opened.
     */
    public const STATUS_DISPUTED = 'disputed';

    /**
     * §14.27.5 — the statuses that count as booked work for a crew: Approved,
     * Scheduled, In progress — and (§17.12) Disputed, because a reopened job must
     * reach the crew. Draft and Quoted (still waiting on the owner) are not shown
     * to a crew. ONE constant, so an agency-wide change later is a one-line edit.
     */
    public const CREW_VISIBLE_STATUSES = [self::STATUS_APPROVED, self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS, self::STATUS_DISPUTED];

    /** §14.27.5 — how a worker sign-off ("crew completed") was recorded. */
    public const SIGN_OFF_VIA_CREW_LINK = 'crew_link';
    public const SIGN_OFF_VIA_CREW_PAGE = 'crew_page';
    public const SIGN_OFF_VIA_SIGNED_COPY = 'signed_copy';
    public const SIGN_OFF_VIA_OFFICE = 'office';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'rental_work_order_id',
        'rental_fault_report_id',
        'property_id',
        'lease_id',
        'title',
        'status',
        // assigned_user_id is INTENTIONALLY still fillable (never dropped,
        // never written again by new code — see rental_crew_id below and
        // the migration's own docblock) so a mass-assignment on an OLD row
        // that already has it set never silently clears it on an unrelated
        // ->update() call elsewhere. New code sets rental_crew_id only.
        'assigned_user_id',
        'rental_crew_id',
        'scheduled_at',
        'due_at',
        'access_notes',
        'total_amount',
        // §17.4 — the agency's own cost (sum of accepted cost_total) and the card-level markups.
        'total_cost',
        'markup_all_percent',
        'markup_parts_percent',
        'markup_labour_percent',
        'vat_registered_snapshot',
        'vat_capture_mode_snapshot',
        'vat_snapshotted_at',
        'worker_signed_off_at',
        'worker_signed_off_by_user_id',
        'worker_sign_off_name',
        'worker_sign_off_via',
        'worker_sign_off_ip',
        'worker_sign_off_device',
        'landlord_crew_notice_at',
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
        'total_cost' => 'decimal:2',
        'markup_all_percent' => 'decimal:2',
        'markup_parts_percent' => 'decimal:2',
        'markup_labour_percent' => 'decimal:2',
        'vat_registered_snapshot' => 'boolean',
        'vat_snapshotted_at' => 'datetime',
        'worker_signed_off_at' => 'datetime',
        'landlord_crew_notice_at' => 'datetime',
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

    public function rentalFaultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'rental_fault_report_id');
    }

    /** "No source — created directly" when both are null. */
    public function hasSource(): bool
    {
        return $this->rental_work_order_id !== null || $this->rental_fault_report_id !== null;
    }

    /** Deleted-related-record rule (.ai/BUILD_STANDARD.md §4) — see Lease::property(). */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    /** Same reasoning as property() above. */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * 2026-10-05 — LEGACY ONLY. Agents/staff are never crew (Johan's
     * ruling); new code never writes assigned_user_id again — see
     * crew() below, the only thing assignCrew() sets now. Kept, readable,
     * for "Previously assigned: <name>" on a card that predates crews.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** withTrashed() — an archived crew still displays on the old job cards it was assigned to; it just can't be newly picked (the Assign dropdown only lists active crews). */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(RentalCrew::class, 'rental_crew_id')->withTrashed();
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

    /** Lines with no task — the built-in "General" group (e.g. a call-out fee). */
    public function generalLines(): HasMany
    {
        return $this->lines()->whereNull('rental_job_card_task_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(RentalJobCardUpdate::class)->orderByDesc('created_at');
    }

    /** §17.5.4 — "ask the crew to price this job" requests, newest first. */
    public function priceRequests(): HasMany
    {
        return $this->hasMany(RentalJobCardPriceRequest::class)->orderByDesc('requested_at')->orderByDesc('id');
    }

    /** §17.12 — an OPEN dispute reopened this card (§17.10). */
    public function isDisputed(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    /** §14 — quotes sent to the owner from this job card (rental_work_order_quotes.rental_job_card_id). */
    public function quotes(): HasMany
    {
        return $this->hasMany(RentalWorkOrderQuote::class)->orderByDesc('quote_date');
    }

    /**
     * §14.21 — every revision of the quote sent from this card, newest first
     * (current revision first, superseded ones after it). Distinct from
     * quotes(), which is ordered by date only and kept for older callers.
     */
    public function quoteRevisions(): HasMany
    {
        return $this->hasMany(RentalWorkOrderQuote::class)->orderByDesc('revision')->orderByDesc('id');
    }

    /** The one live quote the owner is being asked to act on — the newest revision not superseded. Null until a quote has been sent. */
    public function currentQuote(): ?RentalWorkOrderQuote
    {
        return $this->quoteRevisions()->whereNull('superseded_at')->first();
    }

    /**
     * §14.21 — a hash of everything the quote PDF shows: the title, the live
     * (non-archived) tasks with their live lines, and the General lines.
     * Stored on each quote at send time; compared with the card's signature
     * NOW to tell "changed since sent" regardless of which edit path made the
     * change. Quantities/prices are normalised to 2dp so a cosmetic
     * "2" vs "2.00" never counts as a change.
     */
    public function quoteContentSignature(): string
    {
        $fmt = fn ($n) => $n === null ? null : number_format((float) $n, 2, '.', '');
        $lineData = fn ($l) => [
            $l->code, $l->description, $l->type, $l->unit, $fmt($l->quantity), $fmt($l->unit_price),
            $l->rental_vat_type_id, $fmt($l->custom_vat_rate),
        ];

        $payload = [
            'title' => $this->title,
            'tasks' => $this->tasks()->with('lines')->get()
                ->map(fn ($t) => [$t->description, $t->lines->map($lineData)->all()])->all(),
            'general' => $this->generalLines()->get()->map($lineData)->all(),
        ];

        return hash('sha256', json_encode($payload));
    }

    /** §14.21 — true when a quote has been sent AND the card's content differs from what that (current) quote showed. */
    public function quoteChangedSinceSent(?RentalWorkOrderQuote $current = null): bool
    {
        $current ??= $this->currentQuote();

        return $current !== null
            && $current->content_signature !== null
            && $current->content_signature !== $this->quoteContentSignature();
    }

    /**
     * This card's own photos (RentalJobCardService::storePhoto()) — set
     * directly here regardless of whether a work order is linked. A linked
     * work order's OWN photos (uploaded via the work order screen directly)
     * are a separate, cross-referenced set — $this->workOrder?->photos.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(RentalWorkOrderPhoto::class, 'rental_job_card_id')->orderByDesc('created_at');
    }

    /** §14.28 — wet-ink signed copies, newest first; the current one is the first not superseded. */
    public function signedCopies(): HasMany
    {
        return $this->hasMany(RentalJobCardSignedCopy::class)->orderByDesc('uploaded_at')->orderByDesc('id');
    }

    /**
     * §14.28 — "Sipho Dlamini — via crew link" for the header chip and the
     * sign-off row. Null until the worker sign-off exists.
     */
    public function crewCompletionLabel(): ?string
    {
        if (! $this->worker_signed_off_at) {
            return null;
        }
        $via = match ($this->worker_sign_off_via) {
            self::SIGN_OFF_VIA_CREW_LINK => 'via crew link',
            self::SIGN_OFF_VIA_CREW_PAGE => 'via crew page',
            self::SIGN_OFF_VIA_SIGNED_COPY => 'from signed copy',
            default => 'recorded by the office',
        };

        return trim(($this->worker_sign_off_name ?: 'Crew') . ' — ' . $via);
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
                    'task_renamed' => 'Task renamed',
                    'task_ticked' => 'Task ticked',
                    'task_archived' => 'Task archived',
                    'line_added' => 'Line added',
                    'line_changed' => 'Line changed',
                    'line_archived' => 'Line archived',
                    'photo_added' => 'Photo added',
                    'quote_sent' => 'Quote sent to owner',
                    'quote_resent' => 'Quote re-sent to owner',
                    'sign_off' => 'Signed off',
                    'link_issued' => 'Crew link created',
                    'link_emailed' => 'Crew link emailed',
                    'link_revoked' => 'Crew link revoked',
                    'link_opened' => 'Crew link opened',
                    'crew_photos_added' => 'Crew photos added',
                    'crew_completed' => 'Crew marked work completed',
                    'signed_copy_uploaded' => 'Signed copy uploaded',
                    'signed_copy_superseded' => 'Signed copy superseded',
                    'landlord_notified' => 'Landlord notified',
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

    /** Completed or cancelled — a closed card is a read-only record. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    /**
     * §14.21 — lines and tasks of a closed card never change (add, edit,
     * archive, restore, tick, rename, reorder). Called at the top of every
     * RentalJobCardService method that touches them, so the web controller,
     * a direct POST and the mobile endpoint all hit the same refusal.
     */
    public function assertContentEditable(): void
    {
        if ($this->isClosed()) {
            throw new \LogicException('This job card is closed — its lines and tasks can no longer be changed.');
        }
    }

    private function assertOpen(): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This job card is already closed.');
        }
    }

    /**
     * 2026-10-05 — re-pointed at RentalCrew (Johan: "agents and staff are
     * never maintenance crew"). Never writes assigned_user_id — that
     * column is frozen, legacy-only, see assignedUser()'s own docblock.
     */
    public function assignCrew(RentalCrew $crew, User $by): void
    {
        $this->assertOpen();
        $this->update(['rental_crew_id' => $crew->id]);
        $this->logUpdate('crew_assigned', $by, $crew->name);
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

    /**
     * §14.23 — the value for the Scheduled / Due <input type="datetime-local">
     * boxes: the saved moment in the AGENCY timezone, the same zone
     * RentalJobCardController::schedule() reads the box in, so pressing Set
     * with nothing touched is an exact round trip (and changing only one box
     * can no longer blank the other). Null when nothing is saved.
     */
    public function scheduleInputValue(string $field): ?string
    {
        if (! in_array($field, ['scheduled_at', 'due_at'], true) || ! $this->{$field}) {
            return null;
        }
        $tz = $this->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');

        return $this->{$field}->copy()->setTimezone($tz)->format('Y-m-d\TH:i');
    }

    public function start(User $by): void
    {
        $this->assertOpen();
        $fromStatus = $this->status;
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
        $this->logUpdate('status_change', $by, null, $fromStatus, self::STATUS_IN_PROGRESS);
    }

    /**
     * 2026-10-05 — $by is always the AGENT recording this (crew have no
     * CoreX login to sign off themselves, same as before); $workerName is
     * who on the crew actually did the work, free text, optional — Johan:
     * "record the signing-off name/crew member as text/selection."
     */
    public function workerSignOff(User $by, ?string $workerName = null): void
    {
        $this->assertOpen();
        $this->forceFill([
            'worker_signed_off_at' => now(),
            'worker_signed_off_by_user_id' => $by->id,
            'worker_sign_off_name' => $workerName,
            'worker_sign_off_via' => self::SIGN_OFF_VIA_OFFICE,
            'worker_sign_off_ip' => null,
            'worker_sign_off_device' => null,
        ])->save();
        $this->logUpdate('sign_off', $by, $workerName ? "Worker — done ({$workerName})" : 'Worker — done');
    }

    /**
     * §14.27.5 / §14.27.6 item 5 — "crew completed": the worker sign-off
     * recorded by the crew on their link (crew_link / crew_page, no CoreX
     * user, $by null) or on their behalf from a signed paper copy
     * (signed_copy, $by = the office user who uploaded it). It records the
     * same worker_signed_off_* fields the office "Worker — done" button does,
     * plus how / from where / on what device. It NEVER completes the card —
     * the agent sign-off and complete() still do. A repeat overwrites the
     * earlier crew completion (a corrected signed copy supersedes it); the
     * earlier one stays in the history.
     */
    public function recordCrewCompletion(string $name, string $via, ?string $ip, ?string $device, ?User $by = null): void
    {
        $this->assertOpen();
        $this->forceFill([
            'worker_signed_off_at' => now(),
            'worker_signed_off_by_user_id' => $by?->id,
            'worker_sign_off_name' => $name,
            'worker_sign_off_via' => $via,
            'worker_sign_off_ip' => $ip,
            'worker_sign_off_device' => $device !== null ? mb_substr($device, 0, 255) : null,
        ])->save();

        $viaLabel = match ($via) {
            self::SIGN_OFF_VIA_CREW_LINK => 'via crew link',
            self::SIGN_OFF_VIA_CREW_PAGE => 'via crew page',
            self::SIGN_OFF_VIA_SIGNED_COPY => 'from the signed copy',
            default => 'by the office',
        };
        $this->logUpdate('crew_completed', $by, "{$name} — {$viaLabel}" . ($ip ? " (IP {$ip})" : ''));
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

    // ---- BUILD 3 BEGIN — dispute reopening (§17.10.6/§17.10.7, §17.21.5): the two methods below are Build 3's only ----

    /**
     * §17.10.6 — a tenant reported the finished work as not complete. The card becomes `disputed` (an OPEN state, so the
     * crew can reach it again); a completed card is reopened (`completed_at` cleared). The worker and agent sign-offs
     * are given up so they can be given again — and returned here as a snapshot, so the dispute round keeps what
     * the earlier sign-offs were. A cancelled card is never reopened.
     *
     * @return array<string, mixed> the sign-off details that were reset
     */
    public function reopenForDispute(string $tenantNote): array
    {
        if ($this->status === self::STATUS_CANCELLED) {
            throw new \LogicException('This job card was cancelled — it cannot be reopened.');
        }

        $snapshot = [
            'status_before' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'worker_signed_off_at' => $this->worker_signed_off_at?->toIso8601String(),
            'worker_sign_off_name' => $this->worker_sign_off_name,
            'worker_sign_off_via' => $this->worker_sign_off_via,
            'worker_signed_off_by_user_id' => $this->worker_signed_off_by_user_id,
            'agent_signed_off_at' => $this->agent_signed_off_at?->toIso8601String(),
            'agent_signed_off_by_user_id' => $this->agent_signed_off_by_user_id,
        ];

        $fromStatus = $this->status;
        $this->forceFill([
            'status' => self::STATUS_DISPUTED,
            'completed_at' => null,
            'worker_signed_off_at' => null,
            'worker_signed_off_by_user_id' => null,
            'worker_sign_off_name' => null,
            'worker_sign_off_via' => null,
            'worker_sign_off_ip' => null,
            'worker_sign_off_device' => null,
            'agent_signed_off_at' => null,
            'agent_signed_off_by_user_id' => null,
        ])->save();

        $this->logUpdate('dispute_opened', null, 'Tenant reported the work as not complete: ' . $tenantNote, $fromStatus, self::STATUS_DISPUTED);
        if ($fromStatus === self::STATUS_COMPLETED) {
            $this->logUpdate('reopened', null, 'Reopened — the earlier worker and agent sign-offs must be given again');
        }

        return $snapshot;
    }

    /** §17.10.7 — the crew (or the office) reported the work done again: back to `in_progress`. */
    public function returnFromDispute(): void
    {
        if ($this->status !== self::STATUS_DISPUTED) {
            return;
        }

        $this->update(['status' => self::STATUS_IN_PROGRESS]);
        $this->logUpdate('status_change', null, 'Work reported done again after a tenant dispute', self::STATUS_DISPUTED, self::STATUS_IN_PROGRESS);
    }

    // ---- BUILD 3 END ----

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
        // §17.21.1 — the ONE close hook (Build 3 fills the dispute check; inert in the foundation).
        if ($this->workOrder) {
            app(\App\Services\Rentals\RentalCloseGuards::class)->assertNotDisputed($this->workOrder);
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
