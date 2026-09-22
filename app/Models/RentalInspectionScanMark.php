<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspection-form.md §13 — one row per (scan × item): what
 * the OMR reader detected in that item's tick-box row, and what the agent
 * confirmed on the review screen. This is NOT a parallel observation
 * store — applying a confirmed mark creates an ORDINARY
 * RentalInspectionObservation via ::record(), identical to a screen tap.
 * `applied_observation_id` is the audit-trail pointer back from that real
 * observation to the scan/page/reviewer that produced it.
 */
class RentalInspectionScanMark extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_inspection_scan_id',
        'rental_inspection_item_id',
        'page_number',
        'detected_condition_key',
        'detected_confidence',
        'ambiguous',
        'confirmed_condition_key',
        'confirmed_by_user_id',
        'confirmed_at',
        'applied_observation_id',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'detected_confidence' => 'float',
        'ambiguous' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionScan::class, 'rental_inspection_scan_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function appliedObservation(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionObservation::class, 'applied_observation_id');
    }

    /** Never guessed — a row with no detected key or that's flagged ambiguous needs an explicit human choice before it can be applied. */
    public function needsHumanDecision(): bool
    {
        return $this->ambiguous || $this->detected_condition_key === null;
    }
}
