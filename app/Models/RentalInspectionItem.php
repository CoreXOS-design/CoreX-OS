<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rental-inspections.md §3.1/§3.2 — the actual thing being watched
 * over time: a whole space ("Bedroom 1") or something finer inside it. An
 * item has no condition field itself — "current condition" is always a
 * query over its observations, never a mutable column (§3.1).
 *
 * Property-scoped, never lease-scoped — a physical space outlives any one
 * tenancy. This is what lets an out-inspection carry forward a fault
 * reported under a PREVIOUS tenant (§0.2) and what lets a future work
 * order (.ai/specs/rental-work-orders.md) reach a space's full cross-
 * tenancy history via `rental_inspection_item_id`.
 */
class RentalInspectionItem extends Model
{
    use BelongsToAgency;

    public const KIND_SPACE = 'space';
    public const KIND_METER = 'meter';

    protected $fillable = [
        'agency_id',
        'property_id',
        'kind',
        'label',
        'space_type',
        'is_retired',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_retired' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** No hardcoded order here — callers (currentObservation(), fullHistory()) apply their own. */
    public function observations(): HasMany
    {
        return $this->hasMany(RentalInspectionObservation::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(RentalInspectionDiscrepancy::class);
    }

    public function scopeNotRetired(Builder $q): Builder
    {
        return $q->where('is_retired', false);
    }

    /**
     * §3.1 — "current condition is a query, never a column." The most
     * recent observation for this item that isn't sitting inside an
     * unresolved discrepancy, and never the LOSING side of a resolved one —
     * once a discrepancy is resolved, only its accepted_observation_id may
     * still count as current; the losing participant stays in the table
     * (never touched, §0.4) but must never outrank the accepted one just
     * because its created_at happens to sort later (these columns are
     * whole-second precision, so same-request ties are real). Deliberately
     * reads the WHOLE history (no lease filter) — an item's "current
     * condition" is a property-level fact, independent of which tenancy
     * last touched it.
     */
    public function currentObservation(): ?RentalInspectionObservation
    {
        $excludedObservationIds = $this->discrepancies()
            ->with('observations:id')
            ->get()
            ->flatMap(function (RentalInspectionDiscrepancy $discrepancy) {
                $participantIds = $discrepancy->observations->pluck('id');

                return is_null($discrepancy->resolved_at)
                    ? $participantIds
                    : $participantIds->reject(fn ($id) => $id === $discrepancy->accepted_observation_id);
            });

        return $this->observations()
            ->when($excludedObservationIds->isNotEmpty(), fn (Builder $q) => $q->whereNotIn('id', $excludedObservationIds->unique()))
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * §0.2/§3.2a — every observation ever recorded against this item,
     * across every lease this property has ever had, in order. The
     * cross-tenant carry-forward query, and the same shape (minus the
     * lease filter) as the within-this-tenancy comprehensive log.
     */
    public function fullHistory(): HasMany
    {
        return $this->hasMany(RentalInspectionObservation::class)->oldest('created_at');
    }
}
