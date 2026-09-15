<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * .ai/specs/rental-inspections.md §3.1/§0.4 — two or more observations for
 * the SAME item, WITHIN THE SAME inspection, that disagree on condition.
 * One row per conflicting GROUP, not one per conflicting pair (§11
 * acceptance criteria) — every observation in the group is attached via
 * the pivot.
 */
class RentalInspectionDiscrepancy extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'rental_inspection_item_id',
        'detected_at',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_note',
        'accepted_observation_id',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    public function observations(): BelongsToMany
    {
        return $this->belongsToMany(
            RentalInspectionObservation::class,
            'rental_inspection_discrepancy_observations',
            'discrepancy_id',
            'observation_id',
        );
    }

    public function acceptedObservation(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionObservation::class, 'accepted_observation_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * §0.4 — resolving a discrepancy records a NEW observation and stamps
     * which observation is now accepted as current. The losing
     * observations are never touched, never removed from the pivot.
     */
    public function resolve(RentalInspectionObservation $acceptedObservation, User $resolvedBy, ?string $note = null): void
    {
        $this->forceFill([
            'resolved_at' => now(),
            'resolved_by_user_id' => $resolvedBy->id,
            'resolution_note' => $note,
            'accepted_observation_id' => $acceptedObservation->id,
        ])->save();
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * §3.1/§0.4 — detect and record a discrepancy for one item within one
     * inspection. Called after a new observation is recorded. Scans every
     * OTHER observation for the same item within the SAME inspection
     * (never across inspections — that's ordinary history, not conflict,
     * §3.1) for a differing condition. If an unresolved discrepancy for
     * this item+inspection already exists, the new observation is simply
     * added to it rather than creating a second row (§11: one row per
     * conflicting GROUP).
     */
    public static function detectFor(RentalInspectionObservation $newObservation): ?self
    {
        $conflicting = RentalInspectionObservation::query()
            ->where('rental_inspection_id', $newObservation->rental_inspection_id)
            ->where('rental_inspection_item_id', $newObservation->rental_inspection_item_id)
            ->where('id', '!=', $newObservation->id)
            ->where('condition', '!=', $newObservation->condition)
            ->get();

        if ($conflicting->isEmpty()) {
            return null;
        }

        $discrepancy = self::query()
            ->where('rental_inspection_id', $newObservation->rental_inspection_id)
            ->where('rental_inspection_item_id', $newObservation->rental_inspection_item_id)
            ->whereNull('resolved_at')
            ->first();

        if (! $discrepancy) {
            $discrepancy = self::create([
                'agency_id' => $newObservation->agency_id,
                'rental_inspection_id' => $newObservation->rental_inspection_id,
                'rental_inspection_item_id' => $newObservation->rental_inspection_item_id,
                'detected_at' => now(),
            ]);
            $discrepancy->observations()->syncWithoutDetaching($conflicting->pluck('id')->all());
        }

        $discrepancy->observations()->syncWithoutDetaching([$newObservation->id]);

        return $discrepancy;
    }
}
