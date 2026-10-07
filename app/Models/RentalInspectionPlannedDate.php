<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md §45.7 (Build I-5) — a date the AGENCY loaded for an inspection it wants (today only
 * 'interim'). Johan, 6 Oct 2026 (Q6): "no automatic scheduling" — nothing in CoreX creates, proposes or computes one of
 * these; an agent loads it deliberately, and CoreX reminds the responsible agent from it
 * (rentals:send-planned-inspection-reminders). It never emails a tenant or landlord — they are invited when the agent
 * BOOKS the inspection, through the ordinary §43 schedule flow.
 *
 * Full CRUD: add (one or several), move (planned_on), skip with a reason, archive, restore — soft delete only. Archived
 * dates are never reminded and never counted.
 */
class RentalInspectionPlannedDate extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const TYPE_INTERIM = 'interim';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_BOOKED = 'booked';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED = 'skipped';

    /** Statuses that still await an inspection — the only ones reminded, listed as due, or counted. */
    public const OPEN_STATUSES = [self::STATUS_PLANNED, self::STATUS_BOOKED];

    protected $fillable = [
        'agency_id',
        'lease_id',
        'property_id',
        'type',
        'planned_on',
        'note',
        'status',
        'skipped_reason',
        'rental_inspection_id',
        'created_by_user_id',
        'archived_by_user_id',
    ];

    protected $casts = [
        'planned_on' => 'date',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(RentalInspectionPlannedDateNotice::class, 'planned_date_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Read-time, never stored: an open date whose day has passed. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->planned_on->lt(now()->startOfDay());
    }

    /**
     * §45.7 — OWN/BRANCH/AGENCY scoping at the query layer, on top of AgencyScope, through the same
     * `rental_inspections` data scope the inspections list uses. OWN = a date the user loaded, or one on a property
     * they are the agent on (the person who is reminded about it must be able to see it).
     */
    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_inspections');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            $ids = $user->dataIdentityIds();

            return $query->where(fn (Builder $q) => $q
                ->whereIn('rental_inspection_planned_dates.created_by_user_id', $ids)
                ->orWhereHas('property', fn (Builder $p) => $p->whereIn('properties.agent_id', $ids)));
        }

        return $query->whereRaw('1 = 0');
    }

    /** Route binding goes through the same scope as every list — a direct URL by id to another agent's/branch's/agency's date is a 404. */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->newQuery()->withTrashed()->where($field ?? $this->getRouteKeyName(), $value);
        $user = request()->user();
        if ($user instanceof User) {
            $query->visibleTo($user);
        }

        return $query->first();
    }
}
