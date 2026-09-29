<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspection-form.md §7 — an agent's own judgement on one
 * item's in-vs-out comparison finding on a specific out-inspection.
 * Deliberately does NOT store the classification itself (unchanged/
 * improved/declined/...) — that's always re-derived at read time by
 * RentalInspectionComparisonService, never a column here, for the same
 * reason RentalInspectionItem::currentObservation() is a query, not a
 * cached field: it can never drift out of sync with the observations it
 * describes.
 *
 * No amount, no currency, no deduction field anywhere on this model. That
 * half of the feature is explicitly not built until Johan rules on it
 * (§7.2/§10 of the spec) — this table only ever records "is this wear and
 * tear or a genuine flagged difference," never "how much."
 */
class RentalInspectionItemFinding extends Model
{
    use BelongsToAgency;

    public const DISPOSITION_WEAR_AND_TEAR = 'wear_and_tear';
    public const DISPOSITION_FLAGGED = 'flagged';

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'rental_inspection_item_id',
        'disposition',
        'note',
        'recorded_by_user_id',
        'recorded_at',
        'superseded_at',
        'superseded_by_finding_id',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** The row this one was replaced by, if any. Never null'd out — the chain is the record. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_finding_id');
    }

    /**
     * Record or correct an agent's judgement for one item on one
     * out-inspection. If a live (non-superseded) finding already exists for
     * this item on this inspection, it is superseded first — never edited
     * in place, matching RentalInspectionSignature::supersedeWetInk()'s own
     * transaction shape.
     */
    public static function record(RentalInspection $outInspection, RentalInspectionItem $item, string $disposition, string $note, \App\Models\User $recordedBy): self
    {
        if (! in_array($disposition, [self::DISPOSITION_WEAR_AND_TEAR, self::DISPOSITION_FLAGGED], true)) {
            throw new \InvalidArgumentException("Unknown disposition: {$disposition}");
        }
        if (trim($note) === '') {
            throw new \InvalidArgumentException('A finding requires a note — the agent\'s reasoning is the record.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($outInspection, $item, $disposition, $note, $recordedBy) {
            $existing = self::where('rental_inspection_id', $outInspection->id)
                ->where('rental_inspection_item_id', $item->id)
                ->whereNull('superseded_at')
                ->first();

            $replacement = self::create([
                'agency_id' => $outInspection->agency_id,
                'rental_inspection_id' => $outInspection->id,
                'rental_inspection_item_id' => $item->id,
                'disposition' => $disposition,
                'note' => $note,
                'recorded_by_user_id' => $recordedBy->id,
                'recorded_at' => now(),
            ]);

            if ($existing) {
                $existing->forceFill([
                    'superseded_at' => now(),
                    'superseded_by_finding_id' => $replacement->id,
                ])->save();
            }

            return $replacement;
        });
    }
}
