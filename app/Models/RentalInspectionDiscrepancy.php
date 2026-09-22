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
     * §3.1) for a differing condition FROM A DIFFERENT AUTHOR. If an
     * unresolved discrepancy for this item+inspection already exists, the
     * new observation is simply added to it rather than creating a second
     * row (§11: one row per conflicting GROUP).
     *
     * 2026-09-22, Johan (property 5792, live during his demo) — a single
     * agent correcting their own earlier tap on the SAME item was being
     * treated as a conflict, growing the discrepancy banner by one option
     * on every recorded condition, and the banner's own label rendered
     * the literal string "undefined" (fixed alongside this, §3.2a note in
     * the spec — the eager-load gap that caused it lived in
     * RentalInspection::tabPayloadFor(), not here). His ruling, verbatim
     * in substance: "a genuine concurrent-edit conflict (two people
     * editing the same item at once) may well deserve a prompt, but an
     * item's own history never does. Latest-wins is the rule this surface
     * already uses everywhere else." sameAuthor() below is what makes that
     * distinction — the ORIGINAL multi-agent conflict detection this
     * method was built for (§0.4: "two agents record conflicting condition
     * for the same item") is completely unaffected; only a match against
     * the SAME author is now excluded from counting as a conflict.
     */
    public static function detectFor(RentalInspectionObservation $newObservation): ?self
    {
        $conflicting = RentalInspectionObservation::query()
            ->where('rental_inspection_id', $newObservation->rental_inspection_id)
            ->where('rental_inspection_item_id', $newObservation->rental_inspection_item_id)
            ->where('id', '!=', $newObservation->id)
            ->where('condition', '!=', $newObservation->condition)
            ->get()
            ->reject(fn (RentalInspectionObservation $other) => self::sameAuthor($other, $newObservation));

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

    /**
     * 2026-09-22 — "same author" means the same real person recorded both
     * observations, whether that's an agent/user or a self-reporting
     * tenant/contact (the two are mutually exclusive per observation,
     * §3.2 of the spec). Two observations with NEITHER field set never
     * count as the same author — fails toward flagging a real conflict
     * rather than silently hiding one, since an anonymous/unset author on
     * both sides gives no actual evidence they were the same person.
     */
    private static function sameAuthor(RentalInspectionObservation $a, RentalInspectionObservation $b): bool
    {
        if ($a->observed_by_user_id !== null || $b->observed_by_user_id !== null) {
            return $a->observed_by_user_id !== null && $a->observed_by_user_id === $b->observed_by_user_id;
        }
        if ($a->observed_by_contact_id !== null || $b->observed_by_contact_id !== null) {
            return $a->observed_by_contact_id !== null && $a->observed_by_contact_id === $b->observed_by_contact_id;
        }

        return false;
    }
}
